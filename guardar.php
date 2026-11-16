<?php
/**
 * Registra en el repositorio un archivo recibido por P2P.
 * El receptor manda aquí el archivo reconstruido desde los chunks:
 * se verifica el hash antes de aceptarlo (integridad tipo torrent)
 * y con eso el archivo entra al repositorio colectivo para todos.
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = exigir_sesion();

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('No se recibió ningún archivo');
    }

    $hashEsperado = (string) ($_POST['hash'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $hashEsperado)) {
        throw new Exception('Hash inválido');
    }

    $tmp = $_FILES['file']['tmp_name'];
    if (!is_file($tmp) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('El archivo llegó incompleto o corrupto');
    }

    $hashReal = hash_file('sha256', $tmp);
    if (!hash_equals($hashEsperado, $hashReal)) {
        throw new Exception('El archivo no coincide con su hash: transferencia corrupta');
    }

    $nombre = basename((string) ($_POST['nombre'] ?? ''));
    if ($nombre === '' || $nombre === '.') {
        $nombre = 'archivo-' . substr($hashReal, 0, 8);
    }
    $tamano = (int) filesize($tmp);

    $destino = ruta_de_hash($hashReal);
    if (!is_file($destino) && !move_uploaded_file($tmp, $destino)) {
        throw new Exception('No se pudo guardar el archivo recibido');
    }

    registrar_archivo($hashReal, $nombre, $tamano, $usuario);

    echo json_encode([
        'success' => true,
        'file'    => ['hash' => $hashReal, 'nombre' => $nombre, 'tamano' => $tamano],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    responder_error($e->getMessage());
}
