<?php
require_once __DIR__ . '/_db.php';

$conn = getDB();
$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function authOk(string $mensaje, array $extra = [], int $status = 200): void {
    json(array_merge(['ok' => true, 'success' => true, 'mensaje' => $mensaje], $extra), $status);
}

function authError(string $mensaje, int $status): void {
    json(['ok' => false, 'error' => true, 'mensaje' => $mensaje, 'message' => $mensaje], $status);
}

function authRecordAttempt(mysqli $conn, string $identity, string $result): void {
    try {
        $identityHash = securityHash($identity);
        $ipHash = securityHash(securityClientIp());
        $stmt = $conn->prepare('INSERT INTO auth_intento (identity_hash,ip_hash,resultado) VALUES (?,?,?)');
        $stmt->bind_param('sss', $identityHash, $ipHash, $result);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        // Authentication must still return a controlled response if telemetry fails.
    }
}

function authRecentFailures(mysqli $conn, string $identity): int {
    $identityHash = securityHash($identity);
    $ipHash = securityHash(securityClientIp());
    $windowStart = date('Y-m-d H:i:s', time() - 15 * 60);
    $stmt = $conn->prepare("SELECT SUM(identity_hash=?) identity_total,SUM(ip_hash=?) ip_total FROM auth_intento WHERE resultado IN ('FALLO','BLOQUEADO') AND created_at>=?");
    $stmt->bind_param('sss', $identityHash, $ipHash, $windowStart);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    // Identity throttling is strict; IP throttling is deliberately wider because
    // several tills can share the same local gateway/server address.
    return max((int)($row['identity_total'] ?? 0), intdiv((int)($row['ip_total'] ?? 0), 4));
}

if ($accion === 'login' && $method === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') authError('Usuario y contraseña son obligatorios.', 400);

    $maxAttempts = max(3, (int)(getenv('LOGIN_MAX_ATTEMPTS') ?: 5));
    if (authRecentFailures($conn, $username) >= ($maxAttempts * 3)) {
        authRecordAttempt($conn, $username, 'BLOQUEADO');
        authError('Demasiados intentos. Espere unos minutos antes de volver a intentar.', 429);
    }

    $stmt = $conn->prepare('SELECT u.id_user,u.nombre,u.password,u.activo,COALESCE(u.intentos_fallidos,0) intentos_fallidos,u.bloqueado_hasta,c.estado cuenta_estado FROM usuario u JOIN cuenta c ON c.id_cuenta=u.id_cuenta WHERE u.nombre=? OR u.correo=? LIMIT 1');
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($user && !empty($user['bloqueado_hasta']) && strtotime((string)$user['bloqueado_hasta']) > time()) {
        authRecordAttempt($conn, $username, 'BLOQUEADO');
        authError('Demasiados intentos. Espere unos minutos antes de volver a intentar.', 429);
    }
    if (!$user || (int)$user['activo'] !== 1 || $user['cuenta_estado'] !== 'ACTIVA' || !password_verify($password, $user['password'])) {
        authRecordAttempt($conn, $username, 'FALLO');
        if ($user) {
            $attempts = (int)$user['intentos_fallidos'] + 1;
            $lockSeconds = max(60, (int)(getenv('LOGIN_LOCKOUT_TIME') ?: 300));
            $blockedUntil = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', time() + min(3600, $lockSeconds * max(1, $attempts - $maxAttempts + 1))) : null;
            $stmt = $conn->prepare('UPDATE usuario SET intentos_fallidos=?,ultimo_fallo_login=NOW(),bloqueado_hasta=? WHERE id_user=?');
            $id = (int)$user['id_user'];
            $stmt->bind_param('isi', $attempts, $blockedUntil, $id);
            $stmt->execute();
            $stmt->close();
        }
        authError('Usuario o contraseña incorrectos.', 401);
    }

    session_regenerate_id(true);
    $uid = (int)$user['id_user'];
    $_SESSION['user_id'] = $uid;
    $_SESSION['user_name'] = $user['nombre'];
    if (securityPasswordNeedsRehash((string)$user['password'])) {
        $newHash=securityHashPassword($password);
        $stmt = $conn->prepare('UPDATE usuario SET password=?,validar_sesion=1,ultimo_acceso=NOW(),intentos_fallidos=0,bloqueado_hasta=NULL,ultimo_fallo_login=NULL WHERE id_user=?');
        $stmt->bind_param('si', $newHash, $uid);
    } else {
        $stmt = $conn->prepare('UPDATE usuario SET validar_sesion=1,ultimo_acceso=NOW(),intentos_fallidos=0,bloqueado_hasta=NULL,ultimo_fallo_login=NULL WHERE id_user=?');
        $stmt->bind_param('i', $uid);
    }
    $stmt->execute(); $stmt->close();
    securityRegisterSession($conn, $uid);
    authRecordAttempt($conn, $username, 'EXITO');
    authOk('Inicio de sesión exitoso.', ['id_user'=>$uid,'nombre'=>$user['nombre'],'csrf_token'=>securityCsrfToken()]);
}

if ($accion === 'registrar' && $method === 'POST') {
    authError('El registro local está deshabilitado. La cuenta propietaria se crea durante la instalación y los empleados se administran dentro de Blue-Cat.', 410);
}

if ($accion === 'estado' && $method === 'GET') {
    $uid=requireUser();
    $stmt=$conn->prepare('SELECT validar_sesion FROM usuario WHERE id_user=?');
    $stmt->bind_param('i',$uid); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) authError('Usuario no encontrado.',404);
    json(['ok'=>true,'validar_sesion'=>(int)$row['validar_sesion']]);
}

if ($accion === 'csrf' && $method === 'GET') {
    json(['ok'=>true,'csrf_token'=>securityCsrfToken()]);
}

if ($accion === 'logout' && $method === 'POST') {
    $uid=getSessionUserId();
    if ($uid) securityRevokeCurrentSession($conn, $uid);
    securityDestroySession();
    authOk('Sesión cerrada correctamente.');
}

authError('Acción no válida.',400);
