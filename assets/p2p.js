/**
 * Motor de intercambio P2P (tipo torrent) sobre WebRTC.
 *
 * - Señalización a través de senalizar.php (buzón en la base de datos).
 * - Canal de datos WebRTC entre las dos máquinas: los archivos viajan
 *   directos de máquina a máquina, sin pasar por el servidor.
 * - Cada archivo se parte en chunks de 16 KiB y el receptor verifica el
 *   hash SHA-256 antes de aceptarlo, igual que hace un cliente torrent.
 * - Lo recibido se registra en el repositorio colectivo vía guardar.php,
 *   así el archivo se propaga: quien lo recibe vuelve a poder compartirlo.
 */
(function () {
  'use strict';

  const CHUNK = 16 * 1024;          // 16 KiB por chunk
  const BUFFER_LIMITE = 1 << 20;    // 1 MiB en vuelo como máximo
  const POLL_MS = 2000;             // sondeo de señalización
  const LATIDO_MS = 20000;          // presencia

  const conexiones = new Map();     // usuario → {pc, dc, iniciador}
  const transferencias = new Map(); // id → estado de transferencia
  const recibos = new Map();        // usuario → receptor en curso

  const P2P = {
    onUsuarios: null,
    onTransferencia: null,
    onArchivoRecibido: null,
  };

  window.P2P = P2P;

  // ---------- utilidades ----------

  const hex = (buffer) =>
    [...new Uint8Array(buffer)].map((b) => b.toString(16).padStart(2, '0')).join('');

  async function sha256(buffer) {
    return hex(await crypto.subtle.digest('SHA-256', buffer));
  }

  function notificarTransferencia(estado) {
    if (typeof P2P.onTransferencia === 'function') P2P.onTransferencia({ ...estado });
  }

  function nuevaTransferencia(direccion, con, nombre, tamano) {
    const id = 't' + Math.random().toString(36).slice(2, 10);
    const estado = {
      id, direccion, con, nombre, tamano,
      progreso: 0, estado: direccion === 'envio' ? 'preparando' : 'esperando',
    };
    transferencias.set(id, estado);
    notificarTransferencia(estado);
    return estado;
  }

  function actualizar(t) {
    notificarTransferencia(t);
  }

  // ---------- señalización ----------

  async function senalizarAccion(accion, cuerpo) {
    const url = 'senalizar.php?accion=' + accion;
    const opciones = cuerpo
      ? {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(cuerpo),
        }
      : {};
    const res = await fetch(url, opciones);
    if (!res.ok) throw new Error('Señalización falló: ' + res.status);
    return res.json();
  }

  function enviarSenal(para, tipo, datos) {
    return senalizarAccion('enviar', { para, tipo, datos });
  }

  async function sondear() {
    let data;
    try {
      data = await senalizarAccion('poll');
    } catch {
      return;
    }
    for (const m of data.mensajes || []) {
      try {
        await procesarSenal(m.de, m.tipo, m.datos);
      } catch (e) {
        console.warn('Señal ignorada', m.tipo, e);
      }
    }
  }

  async function latido() {
    try {
      const data = await senalizarAccion('usuarios');
      if (typeof P2P.onUsuarios === 'function') P2P.onUsuarios(data.usuarios || []);
    } catch { /* sin conexión: se reintenta */ }
  }

  // ---------- conexiones WebRTC ----------

  function crearConexion(peer, iniciador) {
    let c = conexiones.get(peer);
    if (c) return c;

    const pc = new RTCPeerConnection({
      iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
    });

    c = { pc, dc: null, iniciador, enColaIce: [] };
    conexiones.set(peer, c);

    pc.onicecandidate = (e) => {
      if (e.candidate) {
        enviarSenal(peer, 'ice', { candidate: e.candidate.toJSON() }).catch(() => {});
      }
    };

    if (iniciador) {
      const dc = pc.createDataChannel('archivos', { ordered: true });
      prepararCanal(c, dc, peer);
      negociar(c, peer);
    } else {
      pc.ondatachannel = (e) => prepararCanal(c, e.channel, peer);
    }

    return c;
  }

  async function negociar(c, peer) {
    try {
      const oferta = await c.pc.createOffer();
      await c.pc.setLocalDescription(oferta);
      await enviarSenal(peer, 'oferta', { sdp: c.pc.localDescription });
    } catch (e) {
      console.warn('No se pudo ofertar a', peer, e);
    }
  }

  function prepararCanal(c, dc, peer) {
    c.dc = dc;
    dc.bufferedAmountLowThreshold = CHUNK * 16;

    dc.onopen = () => {
      // En envíos que quedaron esperando la conexión, arranca ahora.
      const pendiente = transferencias.get('_pendiente_' + peer);
      if (pendiente) {
        transferencias.delete('_pendiente_' + peer);
        transmitirArchivo(c.dc, pendiente).catch((e) => {
          pendiente.estado = 'error: ' + e.message;
          actualizar(pendiente);
        });
      }
    };

    dc.onmessage = (e) => {
      if (typeof e.data === 'string') {
        procesarControl(peer, JSON.parse(e.data));
      } else {
        recibirChunk(peer, e.data);
      }
    };
  }

  async function procesarSenal(de, tipo, datos) {
    const c = crearConexion(de, false);

    if (tipo === 'oferta') {
      await c.pc.setRemoteDescription(datos.sdp);
      for (const cand of c.enColaIce) {
        try { await c.pc.addIceCandidate(cand); } catch { /* candidato tardío inválido */ }
      }
      c.enColaIce.length = 0;
      const respuesta = await c.pc.createAnswer();
      await c.pc.setLocalDescription(respuesta);
      await enviarSenal(de, 'respuesta', { sdp: c.pc.localDescription });
    } else if (tipo === 'respuesta') {
      await c.pc.setRemoteDescription(datos.sdp);
      for (const cand of c.enColaIce) {
        try { await c.pc.addIceCandidate(cand); } catch { /* candidato tardío inválido */ }
      }
      c.enColaIce.length = 0;
    } else if (tipo === 'ice') {
      if (!datos.candidate) return;
      if (!c.pc.remoteDescription) {
        c.enColaIce.push(datos.candidate); // llegó antes que la oferta/respuesta
      } else {
        try { await c.pc.addIceCandidate(datos.candidate); } catch { /* ignorar */ }
      }
    }
  }

  // ---------- protocolo de transferencia ----------

  function procesarControl(peer, msg) {
    if (msg.t === 'meta') {
      const t = nuevaTransferencia('recepcion', peer, msg.nombre, msg.tamano);
      t.hash = msg.hash;
      t.total = msg.total;
      t.chunks = [];
      t.recibidos = 0;
      t.estado = 'recibiendo';
      recibos.set(peer, t);
    } else if (msg.t === 'fin') {
      const t = recibos.get(peer);
      if (t) finalizarRecepcion(peer, t);
    }
  }

  function recibirChunk(peer, chunk) {
    const t = recibos.get(peer);
    if (!t) return;
    t.chunks.push(chunk);
    t.recibidos += chunk.byteLength;
    t.progreso = Math.min(100, Math.round((t.recibidos / t.tamano) * 100));
    actualizar(t);
  }

  async function finalizarRecepcion(peer, t) {
    recibos.delete(peer);
    try {
      const blob = new Blob(t.chunks);
      if (blob.size !== t.tamano) throw new Error('tamaño incompleto');

      const hashReal = await sha256(await blob.arrayBuffer());
      if (hashReal !== t.hash) throw new Error('el contenido no coincide con el hash');

      const fd = new FormData();
      fd.append('file', blob, t.nombre);
      fd.append('hash', t.hash);
      fd.append('nombre', t.nombre);

      const res = await fetch('guardar.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'no se pudo registrar');

      t.estado = 'completado';
      t.progreso = 100;
      actualizar(t);
      if (typeof P2P.onArchivoRecibido === 'function') P2P.onArchivoRecibido(data.file);
    } catch (e) {
      t.estado = 'error: ' + e.message;
      actualizar(t);
    }
  }

  async function esperarBuffer(dc) {
    if (dc.bufferedAmount < BUFFER_LIMITE) return;
    await new Promise((resolve) => {
      const fin = () => {
        dc.removeEventListener('bufferedamountlow', fin);
        resolve();
      };
      dc.addEventListener('bufferedamountlow', fin);
    });
  }

  async function transmitirArchivo(dc, t) {
    t.estado = 'enviando';
    actualizar(t);

    const buffer = await t.archivo.arrayBuffer();
    const hash = await sha256(buffer);
    t.hash = hash;

    const total = Math.ceil(buffer.byteLength / CHUNK);
    dc.send(JSON.stringify({ t: 'meta', nombre: t.nombre, tamano: buffer.byteLength, hash, total }));

    for (let i = 0; i < total; i++) {
      await esperarBuffer(dc);
      dc.send(buffer.slice(i * CHUNK, (i + 1) * CHUNK));
      t.progreso = Math.round(((i + 1) / total) * 100);
      actualizar(t);
    }

    dc.send(JSON.stringify({ t: 'fin' }));
    t.estado = 'completado';
    t.progreso = 100;
    actualizar(t);
  }

  // ---------- API pública ----------

  /** Envía un archivo a otro usuario conectado (crea la conexión si hace falta). */
  P2P.enviarA = function (peer, archivo) {
    const t = nuevaTransferencia('envio', peer, archivo.name, archivo.size);
    t.archivo = archivo;

    const c = crearConexion(peer, true);
    if (c.dc && c.dc.readyState === 'open') {
      transmitirArchivo(c.dc, t).catch((e) => {
        t.estado = 'error: ' + e.message;
        actualizar(t);
      });
    } else {
      transferencias.set('_pendiente_' + peer, t); // arranca al abrirse el canal
    }
    return t;
  };

  /** Arranca los bucles de presencia y señalización. */
  P2P.iniciar = function () {
    latido();
    setInterval(latido, LATIDO_MS);
    setInterval(sondear, POLL_MS);
    window.addEventListener('pagehide', () => {
      navigator.sendBeacon && navigator.sendBeacon('senalizar.php?accion=salir');
    });
  };

  P2P.salir = function () {
    navigator.sendBeacon && navigator.sendBeacon('senalizar.php?accion=salir');
  };
})();
