<?php
declare(strict_types=1);

function securityTestOption(string $name): ?string {
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if (str_starts_with($argument, $name.'=')) return substr($argument, strlen($name) + 1);
    }
    return null;
}

function securityTestAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function securityTestRequest(string $url, string $method, ?array $form, string $cookieFile, bool $ajax = false, bool $json = false): array {
    $curl = curl_init($url);
    $headers = $ajax ? ['X-Requested-With: XMLHttpRequest'] : [];
    if ($json) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($form !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $json ? json_encode($form) : http_build_query($form));
    $body = (string)curl_exec($curl);
    if ($body === '' && curl_errno($curl)) throw new RuntimeException(curl_error($curl));
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($body, true), $body];
}

$root = dirname(__DIR__);
$envFile = securityTestOption('--env') ?? $root.'/.env';
if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $envFile)) $envFile = $root.'/'.$envFile;
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key,$value] = array_map('trim', explode('=', $line, 2));
    $_ENV[$key] = trim($value, "\"'");
}
if (($_ENV['APP_ENV'] ?? '') !== 'test' && !in_array('--allow-production', $_SERVER['argv'], true)) {
    throw new RuntimeException('Use APP_ENV=test o confirme una prueba transitoria con --allow-production.');
}

$db = new mysqli($_ENV['DB_HOST'] ?? 'localhost', $_ENV['DB_USER'] ?? '', $_ENV['DB_PASSWORD'] ?? '', $_ENV['DB_NAME'] ?? '', (int)($_ENV['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
$base = rtrim(securityTestOption('--base-url') ?? 'http://localhost/Blue-Cat/assets/api', '/');
$suffix = bin2hex(random_bytes(6));
$username = 'st-'.$suffix;
$email = $username.'@local.test';
$password = 'Security-Test-2026';
$cookieFile = tempnam(sys_get_temp_dir(), 'bluecat-security-');
$accountId = 0;
$userId = 0;
$roleId = 0;

try {
    $stmt = $db->prepare("INSERT INTO cuenta(nombre,estado) VALUES (?,'ACTIVA')");
    $stmt->bind_param('s', $username); $stmt->execute(); $accountId=(int)$db->insert_id; $stmt->close();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion,password_changed_at) VALUES (?,?,?,?,1,0,NOW())');
    $stmt->bind_param('isss', $accountId, $username, $email, $hash); $stmt->execute(); $userId=(int)$db->insert_id; $stmt->close();
    $stmt = $db->prepare('UPDATE cuenta SET id_usuario_propietario=? WHERE id_cuenta=?');
    $stmt->bind_param('ii', $userId, $accountId); $stmt->execute(); $stmt->close();
    $roleName='Security test role';
    $stmt=$db->prepare('INSERT INTO rol(id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla) VALUES (?,?,?,1,0,0)');
    $stmt->bind_param('iss',$accountId,$roleName,$roleName);$stmt->execute();$roleId=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO rol_permiso(id_rol,id_permiso) SELECT ?,id_permiso FROM permiso WHERE modulo='configuracion' AND accion='ver'");
    $stmt->bind_param('i',$roleId);$stmt->execute();$stmt->close();
    $stmt=$db->prepare('INSERT INTO usuario_rol(id_user,id_rol) VALUES (?,?)');$stmt->bind_param('ii',$userId,$roleId);$stmt->execute();$stmt->close();

    [$status,,$body] = securityTestRequest($base.'/auth.php?accion=login', 'POST', ['username'=>$username,'password'=>$password], $cookieFile, false);
    securityTestAssert($status === 403 && str_contains($body, 'CSRF_REJECTED'), "CSRF rechaza POST sin cabecera ni token (HTTP {$status}: {$body})");

    $maxAttempts = max(3, (int)($_ENV['LOGIN_MAX_ATTEMPTS'] ?? 5));
    for ($attempt=1; $attempt<=$maxAttempts; $attempt++) {
        [$status] = securityTestRequest($base.'/auth.php?accion=login', 'POST', ['username'=>$username,'password'=>'Incorrect-'.$attempt], $cookieFile, true);
        securityTestAssert($status === 401, "intento inválido {$attempt} usa respuesta genérica");
    }
    [$status] = securityTestRequest($base.'/auth.php?accion=login', 'POST', ['username'=>$username,'password'=>$password], $cookieFile, true);
    securityTestAssert($status === 429, 'bloqueo progresivo impide login aun con clave correcta');
    $stmt=$db->prepare('UPDATE usuario SET intentos_fallidos=0,bloqueado_hasta=NULL,ultimo_fallo_login=NULL WHERE id_user=?');
    $stmt->bind_param('i',$userId);$stmt->execute();$stmt->close();

    [$status,$login] = securityTestRequest($base.'/auth.php?accion=login', 'POST', ['username'=>$username,'password'=>$password], $cookieFile, true);
    securityTestAssert($status === 200 && !empty($login['ok']), 'login válido crea una sesión autenticada');
    $stmt=$db->prepare('SELECT COUNT(*) total FROM core_sesion WHERE id_user=? AND revoked_at IS NULL AND expires_at>NOW()');
    $stmt->bind_param('i',$userId);$stmt->execute();$active=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
    securityTestAssert($active === 1, 'sesión activa se registra por dispositivo');

    [$status,$state] = securityTestRequest($base.'/auth.php?accion=estado', 'GET', null, $cookieFile);
    securityTestAssert($status === 200 && !empty($state['ok']), 'sesión registrada permite continuar');
    [$status] = securityTestRequest($base.'/pos.php?accion=productos', 'GET', null, $cookieFile);
    securityTestAssert($status === 403, 'backend niega POS aunque se manipule la interfaz');
    [$status] = securityTestRequest($base.'/core.php', 'POST', ['accion'=>'roles'], $cookieFile, true, true);
    securityTestAssert($status === 403, 'configuracion.ver no permite gestionar roles');
    $stmt=$db->prepare("INSERT INTO rol_permiso(id_rol,id_permiso) SELECT ?,id_permiso FROM permiso WHERE modulo='configuracion' AND accion='gestionar_roles'");
    $stmt->bind_param('i',$roleId);$stmt->execute();$stmt->close();
    [$status] = securityTestRequest($base.'/core.php', 'POST', ['accion'=>'roles'], $cookieFile, true, true);
    securityTestAssert($status === 200, 'checkbox gestionar_roles habilita la API real');
    $permissionId=(int)$db->query("SELECT id_permiso FROM permiso WHERE modulo='configuracion' AND accion='gestionar_roles'")->fetch_row()[0];
    [$status] = securityTestRequest($base.'/core.php', 'POST', ['accion'=>'rol_permiso_toggle','id_rol'=>$roleId,'id_permiso'=>$permissionId], $cookieFile, true, true);
    securityTestAssert($status === 409, 'no se puede quitar el último acceso administrativo');

    $stmt=$db->prepare('UPDATE core_sesion SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id_user=? AND revoked_at IS NULL');
    $stmt->bind_param('i',$userId);$stmt->execute();$stmt->close();
    [$status,,$body] = securityTestRequest($base.'/auth.php?accion=estado', 'GET', null, $cookieFile);
    securityTestAssert($status === 401, "sesión vencida por inactividad pierde acceso (HTTP {$status}: {$body})");
    [$status,$login] = securityTestRequest($base.'/auth.php?accion=login', 'POST', ['username'=>$username,'password'=>$password], $cookieFile, true);
    securityTestAssert($status === 200 && !empty($login['ok']), 'usuario puede iniciar una sesión nueva después de expirar la anterior');

    [$status] = securityTestRequest($base.'/auth.php?accion=logout', 'POST', [], $cookieFile, true);
    securityTestAssert($status === 200, 'logout termina la sesión actual');
    $stmt=$db->prepare('SELECT COUNT(*) total FROM core_sesion WHERE id_user=? AND revoked_at IS NOT NULL');
    $stmt->bind_param('i',$userId);$stmt->execute();$revoked=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
    securityTestAssert($revoked === 1, 'logout revoca la sesión en servidor');
    [$status] = securityTestRequest($base.'/auth.php?accion=estado', 'GET', null, $cookieFile);
    securityTestAssert($status === 401, 'cookie cerrada no recupera acceso');
} finally {
    if ($accountId > 0) {
        $stmt=$db->prepare('UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta=?');$stmt->bind_param('i',$accountId);$stmt->execute();$stmt->close();
    }
    if ($userId > 0) {
        $stmt=$db->prepare('DELETE FROM core_sesion WHERE id_user=?');$stmt->bind_param('i',$userId);$stmt->execute();$stmt->close();
        $stmt=$db->prepare('DELETE FROM usuario WHERE id_user=?');$stmt->bind_param('i',$userId);$stmt->execute();$stmt->close();
    }
    if ($roleId > 0) {
        $stmt=$db->prepare('DELETE FROM rol WHERE id_rol=?');$stmt->bind_param('i',$roleId);$stmt->execute();$stmt->close();
    }
    $appKey=(string)($_ENV['APP_KEY'] ?? '');if($appKey==='')$appKey='bluecat-test-only-key';
    $identityHash=hash_hmac('sha256',strtolower(trim($username)),$appKey);
    $stmt=$db->prepare('DELETE FROM auth_intento WHERE identity_hash=?');$stmt->bind_param('s',$identityHash);$stmt->execute();$stmt->close();
    if ($accountId > 0) {
        $stmt=$db->prepare('DELETE FROM cuenta WHERE id_cuenta=?');$stmt->bind_param('i',$accountId);$stmt->execute();$stmt->close();
    }
    if (is_string($cookieFile) && is_file($cookieFile)) unlink($cookieFile);
}
