<?php
/** Procesa el registro: guarda la cuenta en la tabla usuarios. */
require __DIR__ . '/config.php';

$user  = trim((string) ($_POST['usuario'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$pass  = (string) ($_POST['contraseña'] ?? '');

if ($user === '' || $email === '' || $pass === '') {
    $_SESSION['verificar'] = 'Todos los campos son obligatorios.';
    header('Location: register.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['verificar'] = 'El correo no es válido.';
    header('Location: register.php');
    exit;
}

$pdo = db();

// ¿El usuario o el correo ya existen?
$stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE usr = :user OR email = :email');
$stmt->execute(['user' => $user, 'email' => $email]);

if ((int) $stmt->fetchColumn() > 0) {
    $_SESSION['verificar'] = 'Este usuario o correo ya existe.';
    header('Location: register.php');
    exit;
}

$stmt = $pdo->prepare('INSERT INTO usuarios (usr, email, pass) VALUES (:user, :email, :pass)');
$stmt->execute([
    'user'  => $user,
    'email' => $email,
    'pass'  => password_hash($pass, PASSWORD_DEFAULT),
]);

$_SESSION['msg'] = 'Cuenta creada con éxito. Inicia sesión.';
header('Location: login.php');
exit;
