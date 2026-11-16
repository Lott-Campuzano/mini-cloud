<?php
/** Cierra la sesión y sale de la lista de presencia P2P. */
require __DIR__ . '/config.php';

if (usuario_actual() !== null) {
    $stmt = db()->prepare('DELETE FROM presencia WHERE usr = :usr');
    $stmt->execute(['usr' => usuario_actual()]);
}

session_destroy();

if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json'
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    echo json_encode(['success' => true]);
    exit;
}

header('Location: login.php');
exit;
