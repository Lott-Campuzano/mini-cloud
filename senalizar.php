<?php
/**
 * Señalización WebRTC para el intercambio P2P (tipo torrent).
 *
 * El navegador no puede conectarse a otro navegador sin intercambiar
 * antes sus "ofertas" SDP y candidatos ICE: eso es la señalización.
 * Este endpoint hace de buzón entre máquinas usando la base de datos:
 *
 *   GET  senalizar.php?accion=usuarios   → latido (presencia) + usuarios en línea
 *   GET  senalizar.php?accion=poll       → mensajes de señalización pendientes
 *   POST senalizar.php?accion=enviar     → {para, tipo, datos} para otro usuario
 *   POST senalizar.php?accion=salir      → abandona la lista de presencia
 *
 * Tipos de mensaje: oferta, respuesta, ice (estándar de WebRTC).
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = exigir_sesion();
$pdo     = db();
$accion  = $_GET['accion'] ?? 'usuarios';

/** Marca al usuario como presente y devuelve quién está en línea (90 s). */
function presencia(PDO $pdo, string $usuario): array
{
    $stmt = $pdo->prepare('DELETE FROM presencia WHERE usr = :usr');
    $stmt->execute(['usr' => $usuario]);

    $stmt = $pdo->prepare('INSERT INTO presencia (usr, visto) VALUES (:usr, :visto)');
    $stmt->execute(['usr' => $usuario, 'visto' => ahora()]);

    // Nota: el filtro de 90 s se aplica en PHP para ser compatible con SQLite y MySQL.
    $limite = date('Y-m-d H:i:s', time() - 90);
    $stmt = $pdo->query('SELECT usr, visto FROM presencia');
    $stmt = $pdo->query('SELECT usr, visto FROM presencia');
    $enLinea = [];
    foreach ($stmt->fetchAll() as $fila) {
        if ($fila['usr'] !== $usuario && $fila['visto'] > $limite) {
            $enLinea[] = $fila['usr'];
        }
    }
    sort($enLinea);

    return $enLinea;
}

switch ($accion) {
    case 'usuarios':
        echo json_encode([
            'success' => true,
            'usuarios' => presencia($pdo, $usuario),
        ]);
        break;

    case 'poll':
        // Limpia señales viejas (más de 10 min) de paso.
        $stmt = $pdo->prepare('DELETE FROM senal WHERE para = :yo AND fecha < :vieja');
        $stmt->execute(['yo' => $usuario, 'vieja' => date('Y-m-d H:i:s', time() - 600)]);

        $stmt = $pdo->prepare('SELECT id, de, tipo, datos FROM senal WHERE para = :yo ORDER BY id ASC');
        $stmt->execute(['yo' => $usuario]);
        $mensajes = $stmt->fetchAll();

        if ($mensajes) {
            $ids = array_column($mensajes, 'id');
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $del = $pdo->prepare("DELETE FROM senal WHERE id IN ($marcadores)");
            $del->execute($ids);
        }

        echo json_encode([
            'success'  => true,
            'mensajes' => array_map(function ($m) {
                return [
                    'de'    => $m['de'],
                    'tipo'  => $m['tipo'],
                    'datos' => json_decode($m['datos'], true),
                ];
            }, $mensajes),
        ]);
        break;

    case 'enviar':
        $data  = json_decode(file_get_contents('php://input') ?: '[]', true);
        $para  = (string) ($data['para'] ?? '');
        $tipo  = (string) ($data['tipo'] ?? '');
        $datos = $data['datos'] ?? null;

        if ($para === '' || !in_array($tipo, ['oferta', 'respuesta', 'ice'], true)) {
            responder_error('Mensaje de señalización inválido');
        }

        $stmt = $pdo->prepare('INSERT INTO senal (de, para, tipo, datos, fecha) VALUES (:de, :para, :tipo, :datos, :fecha)');
        $stmt->execute([
            'de'    => $usuario,
            'para'  => $para,
            'tipo'  => $tipo,
            'datos' => json_encode($datos),
            'fecha' => ahora(),
        ]);

        echo json_encode(['success' => true]);
        break;

    case 'salir':
        $stmt = $pdo->prepare('DELETE FROM presencia WHERE usr = :usr');
        $stmt->execute(['usr' => $usuario]);
        echo json_encode(['success' => true]);
        break;

    default:
        responder_error('Acción desconocida');
}
