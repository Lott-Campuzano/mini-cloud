<?php
/** Repositorio de archivos colectivo con intercambio P2P (tipo torrent). */
require __DIR__ . '/config.php';

$usuario = usuario_actual();
if ($usuario === null) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Repositorio · Gestor de Archivos</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, sans-serif; background: #f3f4f6; margin: 0; min-height: 100vh; padding: 1.5rem; }
    .wrap { max-width: 860px; margin: 0 auto; display: grid; gap: 1rem; }
    header.barra { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    header.barra h1 { font-size: 1.25rem; margin: 0; }
    .quien { color: #6b7280; font-size: .9rem; }
    button { font: inherit; padding: .55rem 1.1rem; border: 0; border-radius: 999px; background: #111827; color: #fff; cursor: pointer; }
    button.sec { background: #e5e7eb; color: #111827; }
    button.mini { padding: .3rem .8rem; font-size: .85rem; background: #e5e7eb; color: #111827; }
    button.danger { color: #b91c1c; background: #fee2e2; }
    #drop { border: 2px dashed #d1d5db; border-radius: 16px; padding: 2.5rem 1rem; text-align: center; color: #6b7280; background: #fff; cursor: pointer; transition: border-color .2s, background .2s; }
    #drop.sobre { border-color: #2563eb; background: #eff6ff; color: #2563eb; }
    .panel { background: #fff; border-radius: 16px; padding: 1rem 1.25rem; box-shadow: 0 8px 20px rgba(0,0,0,.06); }
    .panel h2 { font-size: 1rem; margin: 0 0 .75rem; }
    table { width: 100%; border-collapse: collapse; font-size: .92rem; }
    th { text-align: left; color: #6b7280; font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; padding: .4rem .5rem; border-bottom: 1px solid #e5e7eb; }
    td { padding: .55rem .5rem; border-bottom: 1px solid #f3f4f6; word-break: break-all; }
    td.mono { font-family: ui-monospace, monospace; font-size: .78rem; color: #6b7280; }
    td.acc { white-space: nowrap; text-align: right; }
    td.acc a { color: #2563eb; text-decoration: none; margin-right: .4rem; }
    .pares { display: flex; flex-wrap: wrap; gap: .5rem; }
    .par { display: inline-flex; align-items: center; gap: .5rem; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 999px; padding: .3rem .9rem; font-size: .85rem; }
    .transferencia { padding: .5rem .75rem; border-radius: 10px; background: #eff6ff; font-size: .85rem; margin-top: .5rem; }
    .transferencia .barra-progreso { height: 6px; background: #dbeafe; border-radius: 3px; overflow: hidden; margin-top: .4rem; }
    .transferencia .barra-progreso > div { height: 100%; background: #2563eb; width: 0; transition: width .15s; }
    .vacio { color: #9ca3af; text-align: center; padding: 1.5rem; }
    #toasts { position: fixed; bottom: 1rem; left: 50%; transform: translateX(-50%); display: grid; gap: .5rem; z-index: 50; }
    .toast { background: #111827; color: #fff; padding: .6rem 1.1rem; border-radius: 10px; box-shadow: 0 8px 20px rgba(0,0,0,.2); font-size: .9rem; opacity: 0; transition: opacity .3s; }
    .toast.on { opacity: 1; }
    .toast.err { background: #7f1d1d; }
  </style>
</head>
<body>
  <div class="wrap">

    <header class="barra">
      <div>
        <h1>Repositorio colectivo</h1>
        <span class="quien">Conectado como <strong><?= htmlspecialchars($usuario) ?></strong></span>
      </div>
      <div style="display:flex; gap:.5rem;">
        <button id="refrescar" class="sec">Refrescar</button>
        <button id="salir">Cerrar sesión</button>
      </div>
    </header>

    <div id="drop">
      <p style="margin:0; font-size:1.05rem;">Arrastra tus archivos aquí</p>
      <p style="margin:.3rem 0 0; font-size:.85rem;">o haz clic para seleccionar · se comparten con todos los usuarios del repositorio</p>
      <input type="file" id="file-input" multiple style="display:none">
    </div>

    <div class="panel" id="panel-transferencias" style="display:none">
      <h2>Transferencias P2P</h2>
      <div id="transferencias"></div>
    </div>

    <div class="panel">
      <h2>Archivos del repositorio</h2>
      <table>
        <thead>
          <tr><th>Nombre</th><th>Tamaño</th><th>Subido por</th><th>Fecha</th><th></th></tr>
        </thead>
        <tbody id="lista-archivos">
          <tr><td colspan="5" class="vacio">Cargando…</td></tr>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <h2>Máquinas conectadas (P2P)</h2>
      <div class="pares" id="pares"><span class="quien">Buscando usuarios en línea…</span></div>
    </div>

  </div>

  <div id="toasts"></div>

  <script>
    window.USUARIO = <?= json_encode($usuario) ?>;
  </script>
  <script src="assets/p2p.js"></script>
  <script src="assets/app.js"></script>
</body>
</html>
