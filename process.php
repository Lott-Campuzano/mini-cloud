<?php
/**
 * Login (dos pasos) y verificación del token.
 *
 * Paso 1 · POST login=1 con usuario y contraseña.
 *   - Si la contraseña es correcta se genera un token, se guarda en la
 *     base de datos y se entrega al usuario (por correo si hay SMTP
 *     configurado en el entorno; en local se muestra en pantalla).
 *   - Redirige a verificar.php.
 *
 * Paso 2 · POST verificar=1 con usuario, token y el campo CSRF token-u.
 *   - Al verificar se inicia la sesión y entra a index.php.
 */

require __DIR__ . '/config.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/** ¿La IP está bloqueada? Muestra el aviso y corta si lo está. */
function is_ip_blocked(PDO $pdo, string $ip): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM ips_bloqueadas WHERE ip = :ip');
    $stmt->execute(['ip' => $ip]);
    $ip_data = $stmt->fetch();

    if ($ip_data && $ip_data['bloqueado_hasta'] && ahora() < $ip_data['bloqueado_hasta']) {
        $_SESSION['verificar'] = 'Tu IP ha sido bloqueada hasta: ' . $ip_data['bloqueado_hasta'];
        header('Location: verificar.php');
        exit;
    }
    return $ip_data ?: null;
}

/** Entrega el token: por correo si hay SMTP, en pantalla si no. */
function entregar_token(string $email, string $user, string $token): void
{
    $smtp = smtp_config();

    if ($smtp) {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require $autoload;
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $smtp['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtp['user'];
                $mail->Password   = $smtp['pass'];
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $smtp['port'];
                $mail->setFrom($smtp['from'], 'proyecto SAW');
                $mail->addAddress($email, $user);
                $mail->isHTML(true);
                $mail->Subject = 'Tu token de acceso';
                $mail->Body    = "Hola <strong>" . htmlspecialchars($user) . "</strong>, tu token es: <b>$token</b>";
                $mail->send();
                $_SESSION['msg'] = 'Se ha enviado el token a tu correo.';
                return;
            } catch (\Throwable $e) {
                // Sin correo disponible: cae al modo local.
            }
        }
    }

    // Modo local: el token se muestra en la página de verificación.
    $_SESSION['token-mostrar'] = $token;
}

$pdo = db();

if (isset($_POST['login'])) {
    $user = trim((string) filter_input(INPUT_POST, 'usuario', FILTER_DEFAULT));
    $pass = (string) ($_POST['contraseña'] ?? '');

    if ($user === '' || $pass === '') {
        $_SESSION['verificar'] = 'Usuario y contraseña obligatorios.';
        header('Location: login.php');
        exit;
    }

    $_SESSION['token-u'] = bin2hex(random_bytes(32)); // Token CSRF del paso de verificación

    $ip_data = is_ip_blocked($pdo, $ip);

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usr = :usuario');
    $stmt->execute(['usuario' => $user]);
    $usuario = $stmt->fetch();

    if ($usuario && $usuario['tiempo'] && ahora() < $usuario['tiempo']) {
        $_SESSION['verificar'] = 'El usuario está bloqueado hasta: ' . $usuario['tiempo'];
        header('Location: login.php');
        exit;
    }

    if ($usuario && password_verify($pass, $usuario['pass'])) {
        // Login correcto: token nuevo, intentos en cero, IP sin deuda.
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'UPDATE usuarios SET token = :token, intento = 0, tiempo = NULL WHERE usr = :usuario'
        );
        $stmt->execute(['token' => $token, 'usuario' => $user]);

        entregar_token($usuario['email'] ?? '', $user, $token);

        if ($ip_data) {
            $stmt = $pdo->prepare(
                'UPDATE ips_bloqueadas SET intentos = 0, bloqueado_hasta = NULL WHERE ip = :ip'
            );
            $stmt->execute(['ip' => $ip]);
        }

        header('Location: verificar.php');
        exit;
    }

    // Login fallido: cuenta intentos por usuario y por IP.
    $usuario_intentos = $usuario ? (int) $usuario['intento'] + 1 : 0;
    $ip_intentos      = $ip_data ? (int) $ip_data['intentos'] + 1 : 1;

    if ($usuario && $usuario_intentos >= 5) {
        $bloqueo = date('Y-m-d H:i:s', time() + 600);
        $stmt = $pdo->prepare(
            'UPDATE usuarios SET intento = :intento, tiempo = :tiempo WHERE usr = :usuario'
        );
        $stmt->execute(['intento' => $usuario_intentos, 'tiempo' => $bloqueo, 'usuario' => $user]);
        $_SESSION['verificar'] = 'Usuario bloqueado por múltiples intentos fallidos (10 min).';
        header('Location: login.php');
        exit;
    } elseif ($usuario) {
        $stmt = $pdo->prepare('UPDATE usuarios SET intento = :intento WHERE usr = :usuario');
        $stmt->execute(['intento' => $usuario_intentos, 'usuario' => $user]);
    }

    if ($ip_intentos >= 10) {
        $bloqueo = date('Y-m-d H:i:s', time() + 600);
        if ($ip_data) {
            $stmt = $pdo->prepare(
                'UPDATE ips_bloqueadas SET intentos = :intentos, bloqueado_hasta = :bloqueado WHERE ip = :ip'
            );
            $stmt->execute(['intentos' => $ip_intentos, 'bloqueado' => $bloqueo, 'ip' => $ip]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO ips_bloqueadas (ip, intentos, bloqueado_hasta) VALUES (:ip, :intentos, :bloqueado)'
            );
            $stmt->execute(['ip' => $ip, 'intentos' => $ip_intentos, 'bloqueado' => $bloqueo]);
        }
        $_SESSION['verificar'] = 'IP bloqueada por múltiples intentos fallidos (10 min).';
        header('Location: login.php');
        exit;
    }

    if ($ip_data) {
        $stmt = $pdo->prepare('UPDATE ips_bloqueadas SET intentos = :intentos WHERE ip = :ip');
        $stmt->execute(['intentos' => $ip_intentos, 'ip' => $ip]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO ips_bloqueadas (ip, intentos) VALUES (:ip, :intentos)');
        $stmt->execute(['ip' => $ip, 'intentos' => $ip_intentos]);
    }

    $_SESSION['verificar'] = 'Contraseña incorrecta. Intentos restantes del usuario: '
        . max(0, 5 - $usuario_intentos)
        . '. Intentos restantes por IP: ' . max(0, 10 - $ip_intentos) . '.';
    header('Location: login.php');
    exit;
}

// ========================
// VERIFICACIÓN DEL TOKEN
// ========================
if (isset($_POST['verificar'])) {
    if (!isset($_SESSION['token-u']) || !hash_equals($_SESSION['token-u'], (string) ($_POST['token-u'] ?? ''))) {
        die('CSRF detectado');
    }

    $username       = trim((string) ($_POST['usuario'] ?? ''));
    $token_ingresado = trim((string) ($_POST['token'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usr = :username AND token = :token');
    $stmt->execute(['username' => $username, 'token' => $token_ingresado]);
    $result = $stmt->fetch();

    if ($result) {
        // Sesión iniciada y token consumido.
        $_SESSION['usuario'] = $username;

        $stmt = $pdo->prepare('UPDATE usuarios SET token = NULL, intento = 0, tiempo = NULL WHERE usr = :usuario');
        $stmt->execute(['usuario' => $username]);

        $stmt = $pdo->prepare('DELETE FROM ips_bloqueadas WHERE ip = :ip');
        $stmt->execute(['ip' => $ip]);

        unset($_SESSION['token-u'], $_SESSION['token-mostrar']);
        header('Location: index.php');
        exit;
    }

    $_SESSION['verificar'] = 'Token inválido.';
    header('Location: verificar.php');
    exit;
}

header('Location: login.php');
exit;
