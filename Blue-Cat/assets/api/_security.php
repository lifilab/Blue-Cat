<?php
declare(strict_types=1);

function securityIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function securityStartSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    $secure = securityIsHttps();
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    session_name('BLUECATSESSID');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

function securityHeaders(): void
{
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' data: https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if (securityIsHttps()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function securityRequireTransport(): void
{
    if (PHP_SAPI === 'cli') return;
    $forceHttps = filter_var((string)(getenv('FORCE_HTTPS') ?: 'false'), FILTER_VALIDATE_BOOLEAN);
    if (!$forceHttps || securityIsHttps()) return;
    http_response_code(426);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => true,
        'code' => 'HTTPS_REQUIRED',
        'message' => 'Este servidor requiere una conexión HTTPS confiable.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function securityClientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'local'), 0, 45);
}

function securityHash(string $value): string
{
    $key = trim((string)(getenv('APP_KEY') ?: ''));
    if ($key === '') {
        if ((string)getenv('APP_ENV') !== 'test') {
            throw new RuntimeException('APP_KEY no configurada. Ejecute scripts/ensure-app-key.php antes de iniciar Blue-Cat.');
        }
        $key = 'bluecat-test-only-key';
    }
    return hash_hmac('sha256', strtolower(trim($value)), $key);
}

function securityCsrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $token = $_SESSION['csrf_token'];
    if (!headers_sent()) {
        setcookie('XSRF-TOKEN', $token, [
            'expires' => 0, 'path' => '/', 'secure' => securityIsHttps(),
            'httponly' => false, 'samesite' => 'Lax',
        ]);
    }
    return $token;
}

function securityRequestIsSameOrigin(): bool
{
    $source = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') return true;
    $sourceHost = strtolower((string)(parse_url($source, PHP_URL_HOST) ?? ''));
    $requestHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    return $sourceHost !== '' && hash_equals($requestHost, $sourceHost);
}

function securityRequireCsrf(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET','HEAD','OPTIONS'], true)) return;
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $validToken = $token !== '' && isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
    if (securityRequestIsSameOrigin() && ($validToken || $ajax)) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>true,'code'=>'CSRF_REJECTED','message'=>'Solicitud rechazada por proteccion CSRF. Recargue la pagina.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function securitySessionLifetimeSeconds(): int
{
    return max(300, (int)(getenv('SESSION_LIFETIME') ?: 120) * 60);
}

function securityRegisterSession(mysqli $db, int $userId): void
{
    $stmt = $db->prepare("SELECT u.id_cuenta,u.session_version FROM usuario u JOIN cuenta c ON c.id_cuenta=u.id_cuenta AND c.estado='ACTIVA' WHERE u.id_user=? AND u.activo=1 LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) throw new RuntimeException('Usuario inactivo.');
    $accountId = (int)$user['id_cuenta'];
    $version = (int)$user['session_version'];
    $sessionHash = securityHash(session_id());
    $ipHash = securityHash(securityClientIp());
    $uaHash = securityHash((string)($_SERVER['HTTP_USER_AGENT'] ?? 'local'));
    $expires = date('Y-m-d H:i:s', time() + securitySessionLifetimeSeconds());
    $stmt = $db->prepare('INSERT INTO core_sesion (id_cuenta,id_user,session_hash,session_version,ip_hash,user_agent_hash,expires_at) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('iisisss', $accountId, $userId, $sessionHash, $version, $ipHash, $uaHash, $expires);
    $stmt->execute();
    $stmt->close();
    $_SESSION['security_session_hash'] = $sessionHash;
    $_SESSION['last_activity_at'] = time();
}

function securityValidateSession(mysqli $db, int $userId): bool
{
    $now = time();
    $last = (int)($_SESSION['last_activity_at'] ?? 0);
    if ($last > 0 && ($now - $last) > securitySessionLifetimeSeconds()) return false;
    $sessionHash = (string)($_SESSION['security_session_hash'] ?? securityHash(session_id()));
    try {
        $stmt = $db->prepare(
            "SELECT cs.id_sesion FROM core_sesion cs JOIN usuario u ON u.id_user=cs.id_user
             JOIN cuenta c ON c.id_cuenta=u.id_cuenta
             WHERE cs.session_hash=? AND cs.id_user=? AND cs.revoked_at IS NULL AND cs.expires_at>NOW()
               AND cs.session_version=u.session_version AND u.activo=1 AND c.estado='ACTIVA' LIMIT 1"
        );
        $stmt->bind_param('si', $sessionHash, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return getenv('APP_ENV') === 'test' && getenv('BLUECAT_TEST_SESSION') === '1';
        $expires = date('Y-m-d H:i:s', $now + securitySessionLifetimeSeconds());
        $id = (int)$row['id_sesion'];
        $stmt = $db->prepare('UPDATE core_sesion SET last_activity_at=NOW(),expires_at=? WHERE id_sesion=?');
        $stmt->bind_param('si', $expires, $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['security_session_hash'] = $sessionHash;
        $_SESSION['last_activity_at'] = $now;
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function securityRevokeCurrentSession(mysqli $db, int $userId, string $reason = 'LOGOUT'): void
{
    $hash = (string)($_SESSION['security_session_hash'] ?? securityHash(session_id()));
    try {
        $stmt = $db->prepare('UPDATE core_sesion SET revoked_at=NOW(),revoked_by=?,revoke_reason=? WHERE session_hash=? AND revoked_at IS NULL');
        $stmt->bind_param('iss', $userId, $reason, $hash);
        $stmt->execute();
        $stmt->close();
        $stmt = $db->prepare('UPDATE usuario u SET validar_sesion=EXISTS(SELECT 1 FROM core_sesion cs WHERE cs.id_user=u.id_user AND cs.revoked_at IS NULL AND cs.expires_at>NOW()) WHERE u.id_user=?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        // Revocation failure must not prevent local cookie destruction.
    }
}

function securityDestroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'=>time() - 42000, 'path'=>$p['path'], 'domain'=>$p['domain'], 'secure'=>$p['secure'],
            'httponly'=>$p['httponly'], 'samesite'=>$p['samesite'] ?? 'Lax',
        ]);
        setcookie('XSRF-TOKEN', '', ['expires'=>time()-42000,'path'=>'/','secure'=>securityIsHttps(),'httponly'=>false,'samesite'=>'Lax']);
    }
    session_destroy();
}

function securityPasswordErrors(string $password): array
{
    $errors = [];
    if (strlen($password) < 10) $errors[] = 'al menos 10 caracteres';
    if (!preg_match('/[a-z]/', $password)) $errors[] = 'una minuscula';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'una mayuscula';
    if (!preg_match('/\d/', $password)) $errors[] = 'un numero';
    $common = ['password123','contrasena123','1234567890','qwerty12345','bluecat123'];
    if (in_array(strtolower($password), $common, true)) $errors[] = 'una clave no comun';
    return $errors;
}

function securityHashPassword(string $password): string
{
    $cost = max(10, min(14, (int)(getenv('BCRYPT_ROUNDS') ?: 12)));
    return password_hash($password, PASSWORD_BCRYPT, ['cost'=>$cost]);
}

function securityPasswordNeedsRehash(string $hash): bool
{
    $cost = max(10, min(14, (int)(getenv('BCRYPT_ROUNDS') ?: 12)));
    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost'=>$cost]);
}

securityRequireTransport();
securityHeaders();
securityStartSession();
securityCsrfToken();
securityRequireCsrf();
