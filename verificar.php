<?php
/** Segundo paso: verificación del token recibido tras el login. */
require __DIR__ . '/config.php';

$verificar    = $_SESSION['verificar'] ?? '';
$tokenMostrar = $_SESSION['token-mostrar'] ?? null;
unset($_SESSION['verificar']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verificar token · Gestor de Archivos</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f3f4f6; display: grid; place-items: center; min-height: 100vh; margin: 0; }
    form { background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.08); width: 320px; display: grid; gap: .75rem; }
    input { padding: .6rem; border: 1px solid #d1d5db; border-radius: 8px; }
    button { padding: .7rem; border: 0; border-radius: 999px; background: #111827; color: #fff; cursor: pointer; }
    .msg { color: #b91c1c; text-align: center; }
    .token { background: #fef9c3; border: 1px dashed #eab308; padding: .6rem; border-radius: 8px; word-break: break-all; font-family: ui-monospace, monospace; font-size: .8rem; }
    a { text-align: center; font-size: .9rem; color: #2563eb; }
  </style>
</head>
<body>
  <?php if ($tokenMostrar): ?>
    <p class="token" style="max-width:360px">
      Modo local (sin correo configurado). Tu token:<br><strong><?= htmlspecialchars($tokenMostrar) ?></strong>
    </p>
  <?php endif; ?>
  <form method="post" action="process.php">
    <h2>Verificar token</h2>
    <p class="msg"><?= htmlspecialchars($verificar) ?></p>
    <input type="hidden" name="token-u" value="<?= htmlspecialchars($_SESSION['token-u'] ?? '') ?>">
    Usuario: <input type="text" name="usuario" required maxlength="64">
    Token: <input type="text" name="token" required maxlength="64">
    <button type="submit" name="verificar" value="1">Verificar token</button>
    <a href="login.php">Volver al inicio de sesión</a>
  </form>
</body>
</html>
