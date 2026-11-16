<?php
/** Inicio de sesión (formulario). */
require __DIR__ . '/config.php';

$verificar = $_SESSION['verificar'] ?? '';
$msg       = $_SESSION['msg'] ?? '';
unset($_SESSION['verificar'], $_SESSION['msg']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar sesión · Gestor de Archivos</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f3f4f6; display: grid; place-items: center; min-height: 100vh; margin: 0; }
    form { background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.08); width: 320px; display: grid; gap: .75rem; }
    input { padding: .6rem; border: 1px solid #d1d5db; border-radius: 8px; }
    button { padding: .7rem; border: 0; border-radius: 999px; background: #111827; color: #fff; cursor: pointer; }
    .msg { color: #b91c1c; text-align: center; }
    .ok { color: #15803d; text-align: center; }
    a { text-align: center; font-size: .9rem; color: #2563eb; }
  </style>
</head>
<body>
  <form method="post" action="process.php">
    <h2>Iniciar sesión</h2>
    <p class="msg"><?= htmlspecialchars($verificar) ?></p>
    <p class="ok"><?= htmlspecialchars($msg) ?></p>
    Usuario: <input type="text" name="usuario" required maxlength="64" autofocus>
    Contraseña: <input type="password" name="contraseña" required>
    <button type="submit" name="login" value="1">Iniciar sesión</button>
    <a href="register.php">No tengo cuenta · Crear cuenta</a>
  </form>
</body>
</html>
