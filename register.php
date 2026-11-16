<?php
/** Registro de cuentas (formulario). */
require __DIR__ . '/config.php';

$msg = $_SESSION['verificar'] ?? '';
unset($_SESSION['verificar']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crear cuenta · Gestor de Archivos</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f3f4f6; display: grid; place-items: center; min-height: 100vh; margin: 0; }
    form { background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.08); width: 320px; display: grid; gap: .75rem; }
    input { padding: .6rem; border: 1px solid #d1d5db; border-radius: 8px; }
    button { padding: .7rem; border: 0; border-radius: 999px; background: #111827; color: #fff; cursor: pointer; }
    .msg { color: #b91c1c; text-align: center; }
    a { text-align: center; font-size: .9rem; color: #2563eb; }
  </style>
</head>
<body>
  <form method="post" action="sign.php">
    <h2>Crear cuenta</h2>
    <p class="msg"><?= htmlspecialchars($msg) ?></p>
    Usuario: <input type="text" name="usuario" required maxlength="64">
    Correo: <input type="email" name="email" required maxlength="190">
    Contraseña: <input type="password" name="contraseña" required>
    <button type="submit">Registrarme</button>
    <a href="login.php">Ya tengo cuenta · Iniciar sesión</a>
  </form>
</body>
</html>
