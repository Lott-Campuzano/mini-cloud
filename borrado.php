<?php
/** Borrado de un archivo del repositorio (solo quien lo subió). */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = exigir_sesion();

try {
    $data = json_decode(file_get_contents('php://input') ?: '[]', true);
    $hash = (string) ($data['hash'] ?? '');

    $ruta = ruta_de_hash($hash);
    if ($ruta === null) {
        throw new Exception('Hash inválido');
    }

    $stmt = db()->prepare('SELECT subido_por FROM archivos WHERE hash = :hash');
    $stmt->execute(['hash' => $hash]);
    $fila = $stmt->fetch();

    if (!$fila) {
        throw new Exception('El archivo no existe');
    }
    if ($fila['subido_por'] !== $usuario) {
        throw new Exception('Solo quien subió el archivo puede borrarlo', 403);
    }

    $stmt = db()->prepare('DELETE FROM archivos WHERE hash = :hash');
    $stmt->execute(['hash' => $hash]);

    if (is_file($ruta) && !unlink($ruta)) {
        throw new Exception('Error al eliminar el archivo del disco');
    }

    echo json_encode(['success' => true, 'message' => 'Archivo eliminado correctamente']);
} catch (Throwable $e) {
    responder_error($e->getMessage(), (int) ($e->getCode() ?: 400));
}
