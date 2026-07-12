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

if ($accion === 'login' && $method === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') authError('Usuario y contraseña son obligatorios.', 400);

    $stmt = $conn->prepare('SELECT id_user,nombre,password,activo,COALESCE(intentos_fallidos,0) intentos_fallidos FROM usuario WHERE nombre=? OR correo=? LIMIT 1');
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || (int)$user['activo'] !== 1 || !password_verify($password, $user['password'])) {
        if ($user) {
            $stmt = $conn->prepare('UPDATE usuario SET intentos_fallidos=COALESCE(intentos_fallidos,0)+1 WHERE id_user=?');
            $id = (int)$user['id_user']; $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        }
        authError('Usuario o contraseña incorrectos.', 401);
    }

    session_regenerate_id(true);
    $uid = (int)$user['id_user'];
    $_SESSION['user_id'] = $uid;
    $_SESSION['user_name'] = $user['nombre'];
    $stmt = $conn->prepare('UPDATE usuario SET validar_sesion=1,ultimo_acceso=NOW(),intentos_fallidos=0 WHERE id_user=?');
    $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close();
    authOk('Inicio de sesión exitoso.', ['id_user'=>$uid,'nombre'=>$user['nombre']]);
}

if ($accion === 'registrar' && $method === 'POST') {
    $nombre = trim((string)($_POST['new-username'] ?? ''));
    $correo = trim((string)($_POST['e-mail'] ?? ''));
    $password = (string)($_POST['new-password'] ?? '');
    $confirm = (string)($_POST['confirm-password'] ?? '');
    if ($nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) authError('Nombre y correo válido son obligatorios.', 400);
    if ($password !== $confirm || strlen($password) < 8) authError('Las contraseñas deben coincidir y tener al menos 8 caracteres.', 400);
    $total = (int)$conn->query('SELECT COUNT(*) total FROM usuario')->fetch_assoc()['total'];
    if ($total > 0 && getSessionUserId() === 0) authError('La instalación ya fue inicializada. Cree empleados desde Administración.', 403);
    if ($total > 0 && !verificarPermiso('usuarios','editar_cuentas')) authError('No tiene permiso para crear usuarios.', 403);

    $stmt = $conn->prepare('SELECT COUNT(*) total FROM usuario WHERE nombre=? OR correo=?');
    $stmt->bind_param('ss',$nombre,$correo); $stmt->execute();
    $exists=(int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    if ($exists) authError('El usuario o correo ya existe.',409);

    $hash=password_hash($password,PASSWORD_DEFAULT);
    $conn->begin_transaction();
    try {
        $stmt=$conn->prepare('INSERT INTO usuario (nombre,correo,password,validar_sesion,activo) VALUES (?,?,?,0,1)');
        $stmt->bind_param('sss',$nombre,$correo,$hash); $stmt->execute();
        $uid=(int)$conn->insert_id; $stmt->close();
        $stmt=$conn->prepare('UPDATE usuario SET id_cuenta=? WHERE id_user=? AND COALESCE(id_cuenta,0)=0');
        $stmt->bind_param('ii',$uid,$uid); $stmt->execute(); $stmt->close();
        if ($total === 0) {
            $rol='Administrador';
            $stmt=$conn->prepare('INSERT IGNORE INTO usuario_rol (id_user,id_rol) SELECT ?,id_rol FROM rol WHERE nombre=? LIMIT 1');
            $stmt->bind_param('is',$uid,$rol); $stmt->execute(); $stmt->close();
        }
        $conn->commit();
        authOk('Cuenta creada exitosamente.',['id_user'=>$uid,'rol'=>$total===0?'Administrador':''],201);
    } catch (Throwable $e) {
        $conn->rollback(); authError('No fue posible crear la cuenta.',500);
    }
}

if ($accion === 'estado' && $method === 'GET') {
    $uid=requireUser();
    $stmt=$conn->prepare('SELECT validar_sesion FROM usuario WHERE id_user=?');
    $stmt->bind_param('i',$uid); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) authError('Usuario no encontrado.',404);
    json(['ok'=>true,'validar_sesion'=>(int)$row['validar_sesion']]);
}

if ($accion === 'logout' && $method === 'POST') {
    $uid=getSessionUserId();
    if ($uid) {
        $stmt=$conn->prepare('UPDATE usuario SET validar_sesion=0 WHERE id_user=?');
        $stmt->bind_param('i',$uid); $stmt->execute(); $stmt->close();
    }
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
    authOk('Sesión cerrada correctamente.');
}

authError('Acción no válida.',400);
