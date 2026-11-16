/**
 * Interfaz del repositorio colectivo: lista, subida, borrado, descarga
 * y envío P2P de archivos entre máquinas.
 */
(function () {
  'use strict';

  const USUARIO = window.USUARIO;
  const listaEl = document.getElementById('lista-archivos');
  const paresEl = document.getElementById('pares');
  const transEl = document.getElementById('transferencias');
  const panelTransEl = document.getElementById('panel-transferencias');
  const dropEl = document.getElementById('drop');
  const inputEl = document.getElementById('file-input');

  const usuariosEnLinea = [];
  const archivos = [];

  // ---------- avisos ----------

  function toast(mensaje, error) {
    const el = document.createElement('div');
    el.className = 'toast' + (error ? ' err' : '');
    el.textContent = mensaje;
    document.getElementById('toasts').appendChild(el);
    requestAnimationFrame(() => el.classList.add('on'));
    setTimeout(() => {
      el.classList.remove('on');
      setTimeout(() => el.remove(), 350);
    }, 3200);
  }

  // ---------- lista del repositorio ----------

  function formatoTamano(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1073741824).toFixed(2) + ' GB';
  }

  function escapeHtml(texto) {
    return String(texto).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  async function cargarLista() {
    try {
      const res = await fetch('listar.php');
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'error al listar');
      archivos.length = 0;
      archivos.push(...(data.files || []));
      renderLista();
    } catch (e) {
      toast('No se pudo cargar la lista: ' + e.message, true);
    }
  }

  function renderLista() {
    if (archivos.length === 0) {
      listaEl.innerHTML = '<tr><td colspan="5" class="vacio">Aún no hay archivos. Sube el primero.</td></tr>';
      return;
    }

    listaEl.innerHTML = archivos.map((f) => `
      <tr data-hash="${escapeHtml(f.hash)}">
        <td>${escapeHtml(f.nombre)}</td>
        <td class="mono">${formatoTamano(Number(f.tamano))}</td>
        <td>${escapeHtml(f.subido_por || '—')}</td>
        <td class="mono">${escapeHtml(f.fecha || '')}</td>
        <td class="acc">
          <a href="descargar.php?hash=${escapeHtml(f.hash)}" title="Descargar">⬇ descargar</a>
          ${f.subido_por === USUARIO ? `<button class="mini danger borrar" data-hash="${escapeHtml(f.hash)}">borrar</button>` : ''}
          <button class="mini enviar" data-hash="${escapeHtml(f.hash)}">enviar P2P</button>
        </td>
      </tr>
    `).join('');
  }

  // ---------- subida ----------

  async function subirArchivos(listaArchivos) {
    if (!listaArchivos.length) return;

    const fd = new FormData();
    for (const f of listaArchivos) fd.append('file[]', f);

    try {
      const res = await fetch('carga.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'error al subir');

      const nombres = data.files.map((f) => f.nombre).join(', ');
      toast('Subido: ' + nombres);
      for (const err of data.errores || []) toast(err, true);
      await cargarLista();
    } catch (e) {
      toast('Error al subir: ' + e.message, true);
    }
  }

  dropEl.addEventListener('click', () => inputEl.click());

  inputEl.addEventListener('change', () => {
    subirArchivos([...inputEl.files]);
    inputEl.value = '';
  });

  dropEl.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropEl.classList.add('sobre');
  });
  dropEl.addEventListener('dragleave', () => dropEl.classList.remove('sobre'));
  dropEl.addEventListener('drop', (e) => {
    e.preventDefault();
    dropEl.classList.remove('sobre');
    subirArchivos([...e.dataTransfer.files]);
  });

  // ---------- borrado ----------

  listaEl.addEventListener('click', async (e) => {
    const borrar = e.target.closest('.borrar');
    if (borrar) {
      try {
        const res = await fetch('borrado.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ hash: borrar.dataset.hash }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'error al borrar');
        toast('Archivo eliminado.');
        await cargarLista();
      } catch (err) {
        toast('Error: ' + err.message, true);
      }
      return;
    }

    const enviar = e.target.closest('.enviar');
    if (enviar) enviarPorP2P(enviar.dataset.hash);
  });

  // ---------- envío P2P ----------

  function archivoPorHash(hash) {
    return archivos.find((f) => f.hash === hash);
  }

  /** El archivo se descarga del servidor y se reenvía directo a la otra máquina. */
  async function enviarPorP2P(hash) {
    const fila = archivoPorHash(hash);
    if (!fila) return;

    if (usuariosEnLinea.length === 0) {
      toast('No hay otras máquinas conectadas todavía.', true);
      return;
    }

    let destino = usuariosEnLinea[0];
    if (usuariosEnLinea.length > 1) {
      const opciones = usuariosEnLinea.map((u, i) => `${i + 1}. ${u}`).join('\n');
      const eleccion = prompt('¿A qué máquina lo envías?\n' + opciones, '1');
      if (eleccion === null) return;
      destino = usuariosEnLinea[parseInt(eleccion, 10) - 1];
      if (!destino) {
        toast('Elección inválida.', true);
        return;
      }
    }

    toast(`Descargando "${fila.nombre}" para enviarlo a ${destino}…`);

    try {
      const res = await fetch('descargar.php?hash=' + encodeURIComponent(hash));
      if (!res.ok) throw new Error('descarga falló: ' + res.status);
      const blob = await res.blob();
      const archivo = new File([blob], fila.nombre, { type: 'application/octet-stream' });
      window.P2P.enviarA(destino, archivo);
      toast(`Enviando "${fila.nombre}" a ${destino} (P2P)…`);
    } catch (e) {
      toast('Error al preparar el envío: ' + e.message, true);
    }
  }

  // ---------- presencia y transferencias ----------

  window.P2P.onUsuarios = function (lista) {
    usuariosEnLinea.length = 0;
    usuariosEnLinea.push(...lista);

    if (lista.length === 0) {
      paresEl.innerHTML = '<span class="quien">Nadie más está conectado. Abre esta página en otra máquina con tu usuario.</span>';
      return;
    }

    paresEl.innerHTML = lista.map((u) => `<span class="par">🟢 ${escapeHtml(u)}</span>`).join('');
  };

  window.P2P.onTransferencia = function (t) {
    panelTransEl.style.display = '';
    let el = transEl.querySelector('[data-id="' + t.id + '"]');

    if (!el) {
      el = document.createElement('div');
      el.className = 'transferencia';
      el.dataset.id = t.id;
      el.innerHTML = `
        <div class="linea"></div>
        <div class="barra-progreso"><div></div></div>`;
      transEl.appendChild(el);
    }

    const direccion = t.direccion === 'envio' ? '→' : '←';
    el.querySelector('.linea').textContent =
      `${direccion} ${t.nombre} · ${t.con} · ${t.estado} (${t.progreso}%)`;
    el.querySelector('.barra-progreso > div').style.width = t.progreso + '%';

    if (t.estado === 'completado' || t.estado.startsWith('error')) {
      setTimeout(() => el.remove(), t.estado.startsWith('error') ? 8000 : 4000);
    }
  };

  window.P2P.onArchivoRecibido = function (archivo) {
    toast(`Recibido por P2P: "${archivo.nombre}" · ya está en el repositorio.`);
    cargarLista();
  };

  // ---------- sesión ----------

  document.getElementById('salir').addEventListener('click', async () => {
    window.P2P.salir();
    try { localStorage.removeItem('uploadedFileNames'); } catch { /* sin almacenamiento */ }
    try { await fetch('logout.php'); } catch { /* cerrar igualmente */ }
    window.location.href = 'login.php';
  });

  document.getElementById('refrescar').addEventListener('click', cargarLista);

  // ---------- arranque ----------

  cargarLista();
  window.P2P.iniciar();
})();
