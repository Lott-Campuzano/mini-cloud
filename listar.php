<?php
/** Lista completa del repositorio: todos los archivos, para todos los usuarios. */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

exigir_sesion();

$stmt = db()->query('SELECT hash, nombre, tamano, subido_por, fecha FROM archivos ORDER BY fecha DESC, nombre ASC');

echo json_encode([
    'success' => true,
    'files'   => $stmt->fetchAll(),
], JSON_UNESCAPED_UNICODE);
