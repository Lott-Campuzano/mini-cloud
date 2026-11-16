<?php
/**
 * Configuración central del proyecto SAW.
 *
 * Base de datos:
 *   - Por defecto usa SQLite (archivo data/proyecto_saw.sqlite): cero
 *     configuración y permanencia garantizada en disco.
 *   - Para MySQL define estas variables de entorno antes de arrancar:
 *       SAW_DB_DRIVER=mysql
 *       SAW_DB_DSN=mysql:host=localhost;dbname=mi_base;charset=utf8mb4
 *       SAW_DB_USER=usuario
 *       SAW_DB_PASS=contraseña
 *
 * Correo (opcional, para enviar el token por email):
 *   SAW_SMTP_USER, SAW_SMTP_PASS, SAW_SMTP_HOST, SAW_SMTP_PORT
 *   Si no están definidas, el token se muestra en pantalla (modo local).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Usuario actual de la sesión (o null). */
function usuario_actual(): ?string
{
    return isset($_SESSION['usuario']) && is_string($_SESSION['usuario'])
        ? $_SESSION['usuario']
        : null;
}

/** Cierra la petición con un error JSON. */
function responder_error(string $mensaje, int $codigo = 400): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Exige sesión iniciada; si no, error 401 JSON (o redirección en páginas). */
function exigir_sesion(): string
{
    $usuario = usuario_actual();
    if ($usuario === null) {
        responder_error('Sesión no iniciada', 401);
    }
    return $usuario;
}

/** Fecha/hora local en formato comparable. */
function ahora(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Conexión PDO única con inicialización automática de tablas.
 * Todas las consultas del proyecto pasan por aquí.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = getenv('SAW_DB_DRIVER') ?: 'sqlite';

    if ($driver === 'mysql') {
        $dsn  = getenv('SAW_DB_DSN') ?: 'mysql:host=localhost;dbname=mi_base;charset=utf8mb4';
        $user = getenv('SAW_DB_USER') ?: 'user';
        $pass = getenv('SAW_DB_PASS') ?: 'pass';
        $pdo = new PDO($dsn, $user, $pass);
    } else {
        $ruta = __DIR__ . '/data/proyecto_saw.sqlite';
        if (!is_dir(dirname($ruta))) {
            mkdir(dirname($ruta), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $ruta);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    inicializar_tablas($pdo, $driver);

    return $pdo;
}

/** Crea las tablas si no existen (sintaxis según motor). */
function inicializar_tablas(PDO $pdo, string $driver): void
{
    $autoincrement = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

    $tablas = [
        "CREATE TABLE IF NOT EXISTS usuarios (
            usr     VARCHAR(64) PRIMARY KEY,
            email   VARCHAR(190),
            pass    VARCHAR(255) NOT NULL,
            token   VARCHAR(64),
            intento INT NOT NULL DEFAULT 0,
            tiempo  DATETIME NULL
        )",
        "CREATE TABLE IF NOT EXISTS ips_bloqueadas (
            ip              VARCHAR(45) PRIMARY KEY,
            intentos        INT NOT NULL DEFAULT 0,
            bloqueado_hasta DATETIME NULL
        )",
        "CREATE TABLE IF NOT EXISTS archivos (
            hash       CHAR(64) PRIMARY KEY,
            nombre     VARCHAR(255) NOT NULL,
            tamano     INT NOT NULL,
            subido_por VARCHAR(64),
            fecha      DATETIME NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS presencia (
            usr   VARCHAR(64) PRIMARY KEY,
            visto DATETIME NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS senal (
            id    {$autoincrement},
            de    VARCHAR(64) NOT NULL,
            para  VARCHAR(64) NOT NULL,
            tipo  VARCHAR(16) NOT NULL,
            datos TEXT NOT NULL,
            fecha DATETIME NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_senal_para ON senal (para)",
    ];

    foreach ($tablas as $sql) {
        $pdo->exec($sql);
    }
}

/** Carpeta donde viven los archivos, nombrados por su hash. */
function carpeta_archivos(): string
{
    $ruta = __DIR__ . '/uploads';
    if (!is_dir($ruta)) {
        mkdir($ruta, 0775, true);
    }
    return $ruta;
}

/**
 * Guarda (o reutiliza) un archivo en el repositorio y devuelve su fila.
 * El almacenamiento es por hash (content-addressed): si dos personas suben
 * el mismo contenido, solo existe una copia en disco.
 *
 * $rutaTemporal puede ser false cuando el archivo ya está en disco
 * (usado por guardar.php al re-registrar un archivo existente).
 */
function registrar_archivo(string $hash, string $nombre, int $tamano, string $subidoPor): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM archivos WHERE hash = :hash');
    $stmt->execute(['hash' => $hash]);
    $existente = $stmt->fetch();

    if ($existente) {
        return $existente;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO archivos (hash, nombre, tamano, subido_por, fecha)
         VALUES (:hash, :nombre, :tamano, :subido_por, :fecha)'
    );
    $stmt->execute([
        'hash'       => $hash,
        'nombre'     => $nombre,
        'tamano'     => $tamano,
        'subido_por' => $subidoPor,
        'fecha'      => ahora(),
    ]);

    return [
        'hash'       => $hash,
        'nombre'     => $nombre,
        'tamano'     => $tamano,
        'subido_por' => $subidoPor,
        'fecha'      => ahora(),
    ];
}

/** Ruta en disco de un hash válido (rechaza cualquier cosa sospechosa). */
function ruta_de_hash(string $hash): ?string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        return null;
    }
    return carpeta_archivos() . '/' . $hash;
}

/** Configuración SMTP desde el entorno (null si no está configurada). */
function smtp_config(): ?array
{
    $user = getenv('SAW_SMTP_USER');
    $pass = getenv('SAW_SMTP_PASS');
    if (!$user || !$pass) {
        return null;
    }
    return [
        'host'    => getenv('SAW_SMTP_HOST') ?: 'smtp.gmail.com',
        'port'    => (int) (getenv('SAW_SMTP_PORT') ?: 587),
        'user'    => $user,
        'pass'    => $pass,
        'from'    => $user,
    ];
}
