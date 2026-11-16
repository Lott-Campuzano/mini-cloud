<?php
/** Descarga de un archivo del repositorio por su hash. */

require __DIR__ . '/config.php';

exigir_sesion();

$hash = (string) ($_GET['hash'] ?? '');
$ruta = ruta_de_hash($hash);

if ($ruta === null || !is_file($ruta)) {
    responder_error('El archivo no existe', 404);
}

$stmt = db()->prepare('SELECT nombre FROM archivos WHERE hash = :hash');
$stmt->execute(['hash' => $hash]);
$fila = $stmt->fetch();
$nombre = $fila['nombre'] ?? ($hash . '.bin');

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nombre) . '"');
header('X-Content-Type-Options: nosniff');

readfile($ruta);
exit;
