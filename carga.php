<?php
/**
 * Subida de archivos al repositorio colectivo.
 *
 * Acepta uno o varios archivos en el campo "file[]" (input multiple);
 * también acepta el campo simple "file" para compatibilidad.
 * Cada archivo se guarda en disco por su hash SHA-256 (content-addressed):
 * si el mismo contenido ya existe, se reutiliza y no se duplica nada.
 * El registro vive en la tabla `archivos`, visible para todos los usuarios.
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = exigir_sesion();

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('No se recibió ningún archivo');
    }

    // Con <input multiple> PHP entrega arrays por cada campo.
    $nombres = $_FILES['file']['name'];
    $esMultiple = is_array($nombres);
    $cantidad = $esMultiple ? count($nombres) : 1;

    $subidos = [];
    $errores = [];

    for ($i = 0; $i < $cantidad; $i++) {
        $error = $esMultiple ? $_FILES['file']['error'][$i] : $_FILES['file']['error'];
        $tmp   = $esMultiple ? $_FILES['file']['tmp_name'][$i] : $_FILES['file']['tmp_name'];

        if ($error !== UPLOAD_ERR_OK) {
            $errores[] = "Archivo " . ($i + 1) . ": error de subida (código $error)";
            continue;
        }

        $hash = hash_file('sha256', $tmp);
        $nombre = basename((string) ($esMultiple ? $nombres[$i] : $nombres));
        if ($nombre === '') {
            $nombre = 'archivo-' . substr($hash, 0, 8);
        }
        $tamano = (int) filesize($tmp);

        $destino = ruta_de_hash($hash);
        if ($destino === null) {
            $errores[] = "Hash inválido para el archivo " . ($i + 1);
            continue;
        }

        if (!is_file($destino) && !move_uploaded_file($tmp, $destino)) {
            $errores[] = "No se pudo guardar \"$nombre\"";
            continue;
        }

        registrar_archivo($hash, $nombre, $tamano, $usuario);
        $subidos[] = ['hash' => $hash, 'nombre' => $nombre, 'tamano' => $tamano];
    }

    if ($subidos === [] && $errores !== []) {
        responder_error($errores[0]);
    }

    echo json_encode([
        'success' => true,
        'files'   => $subidos,
        'errores' => $errores,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    responder_error($e->getMessage());
}
