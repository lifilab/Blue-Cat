<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
$conn = getDB();
$context = requireTenantContext();
$accountId = $context->accountId;
$input = getJsonInput();
$accion = $input['accion'] ?? $_GET['accion'] ?? '';

function requierePermiso($modulo, $accion) {
    requirePermission($modulo, $accion);
}

function coreLog($conn, $uid, $accion, $entidad, $id_entidad = null, $detalle = null, $nivel = 'INFO', $va = null, $vn = null) {
    try {
        $idCuenta = tenantContext($uid)->accountId;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $vn = $detalle !== null ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : $vn;
        $stmt = $conn->prepare("INSERT INTO core_auditoria (id_cuenta,id_user,accion,entidad,id_entidad,valor_anterior,valor_nuevo,ip,user_agent,nivel) VALUES (?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) return;
        $stmt->bind_param("iississsss", $idCuenta, $uid, $accion, $entidad, $id_entidad, $va, $vn, $ip, $ua, $nivel);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        // Audit telemetry never interrupts the business operation.
    }
}

function coreIsLockoutCritical(string $module, string $action): bool {
    return $module === 'configuracion' && in_array($action, ['gestionar_roles','gestionar_usuarios'], true);
}


// La navegación necesita sidebar sin acceso a Configuración. El resto de este
// endpoint pertenece al panel administrativo y se autoriza también en servidor.
$accionesPublicas = ['sidebar', 'validar_licencia', 'sesiones_activas', 'sesion_cerrar', 'auditoria'];
$accionesRoles = ['roles','permisos','rol_permisos','usuario_roles','rol_crear','rol_permiso_toggle','usuario_rol_toggle'];
$accionesUsuarios = ['usuarios','usuario_crear','usuario_editar'];
$accionesEspecializadas = array_merge($accionesRoles,$accionesUsuarios,['usuario_cambiar_password']);
if (!in_array($accion, $accionesPublicas, true)) {
    requierePermiso('configuracion', 'ver');
    $accionesLectura = ['dashboard','empresas','sucursales','roles','permisos','rol_permisos','usuario_roles','usuarios','monedas','impuestos','numeraciones','parametros','planes','suscripciones','modulos','plan_modulos','sesiones_activas','config_boleta'];
    if (!in_array($accion, $accionesLectura, true) && !in_array($accion,$accionesEspecializadas,true)) {
        requierePermiso('configuracion', 'editar');
    }
}

if (in_array($accion, $accionesRoles, true)) requierePermiso('configuracion', 'gestionar_roles');
if (in_array($accion, $accionesUsuarios, true)) requierePermiso('configuracion', 'gestionar_usuarios');
if ($accion === 'usuario_cambiar_password') requierePermiso('usuarios', 'restablecer_password');
if ($accion === 'sesiones_activas') requierePermiso('seguridad', 'ver_sesiones');
if ($accion === 'sesion_cerrar') requierePermiso('seguridad', 'revocar_sesiones');
if ($accion === 'auditoria') requierePermiso('seguridad', 'ver_auditoria');

switch ($accion) {

// ═══ DASHBOARD ═══
case 'dashboard':
    $d = [];
    $d['empresas'] = (int)$conn->query("SELECT COUNT(*) t FROM empresa WHERE id_cuenta={$accountId} AND activo=1")->fetch_assoc()['t'];
    $d['sucursales'] = (int)$conn->query("SELECT COUNT(*) t FROM sucursal WHERE id_cuenta={$accountId} AND activo=1")->fetch_assoc()['t'];
    $d['usuarios_activos'] = (int)$conn->query("SELECT COUNT(*) t FROM usuario WHERE id_cuenta={$accountId} AND activo=1")->fetch_assoc()['t'];
    $d['roles'] = (int)$conn->query("SELECT COUNT(*) t FROM rol WHERE id_cuenta={$accountId} AND activo=1")->fetch_assoc()['t'];
    $d['permisos'] = (int)$conn->query("SELECT COUNT(*) t FROM permiso")->fetch_assoc()['t'];
    $d['monedas'] = (int)$conn->query("SELECT COUNT(*) t FROM moneda WHERE activo=1")->fetch_assoc()['t'];
    $d['impuestos'] = (int)$conn->query("SELECT COUNT(*) t FROM impuesto WHERE activo=1")->fetch_assoc()['t'];
    $d['auditoria_hoy'] = (int)$conn->query("SELECT COUNT(*) t FROM core_auditoria WHERE id_cuenta={$accountId} AND DATE(created_at)=CURDATE()")->fetch_assoc()['t'];
    $d['errores_hoy'] = (int)$conn->query("SELECT COUNT(*) t FROM core_auditoria WHERE id_cuenta={$accountId} AND nivel='ERROR' AND DATE(created_at)=CURDATE()")->fetch_assoc()['t'];
    $r = $conn->query("SELECT nombre,nombre_completo,ultimo_acceso FROM usuario WHERE id_cuenta={$accountId} AND activo=1 AND ultimo_acceso IS NOT NULL ORDER BY ultimo_acceso DESC LIMIT 10");
    $accesos = []; while ($f = $r->fetch_assoc()) $accesos[] = $f;
    $d['ultimos_accesos'] = $accesos;
    $r = $conn->query("SELECT accion,entidad,created_at FROM core_auditoria WHERE id_cuenta={$accountId} ORDER BY created_at DESC LIMIT 20");
    $logs = []; while ($f = $r->fetch_assoc()) $logs[] = $f;
    $d['ultimos_logs'] = $logs;
    json($d);
    break;

// ═══ EMPRESAS ═══
case 'empresas':
    $items = []; $r = $conn->query("SELECT * FROM empresa WHERE id_cuenta={$accountId} ORDER BY razon_social");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'empresa_crear':
    $razon = $input['razon_social'] ?? '';
    if (!$razon) json(['error' => 'Razón social requerida'], 400);
    $nombre_comercial = $input['nombre_comercial'] ?? '';
    $rut = $input['rut'] ?? '';
    $giro = $input['giro'] ?? '';
    $representante_legal = $input['representante_legal'] ?? '';
    $direccion = $input['direccion'] ?? '';
    $ciudad = $input['ciudad'] ?? '';
    $pais = $input['pais'] ?? 'Chile';
    $telefono = $input['telefono'] ?? '';
    $correo = $input['correo'] ?? '';
    $moneda_base = $input['moneda_base'] ?? 'CLP';
    $stmt = $conn->prepare("INSERT INTO empresa (id_cuenta,razon_social,nombre_comercial,rut,giro,representante_legal,direccion,ciudad,pais,telefono,correo,moneda_base) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssssssssss", $accountId, $razon, $nombre_comercial, $rut, $giro, $representante_legal, $direccion, $ciudad, $pais, $telefono, $correo, $moneda_base);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'empresa', $id, ['razon_social' => $razon]);
    json(['success' => true, 'id' => $id], 201);
    break;

case 'empresa_editar':
    $id = (int)($input['id'] ?? 0);
    requireTenantEntity($conn,$context,'empresa',$id);
    $allowed = ['razon_social', 'nombre_comercial', 'rut', 'giro', 'representante_legal', 'direccion', 'ciudad', 'pais', 'telefono', 'correo', 'moneda_base', 'activo'];
    $fields = []; $params = []; $types = '';
    foreach ($input as $k => $v) {
        if (in_array($k, $allowed)) { $fields[] = "$k=?"; $params[] = $v; $types .= 's'; }
    }
    if (!count($fields)) json(['error' => 'Sin campos'], 400);
    $params[] = $id; $types .= 'i';
    $stmt = $conn->prepare("UPDATE empresa SET " . implode(',', $fields) . " WHERE id_empresa=? AND id_cuenta={$accountId}");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'EDITAR', 'empresa', $id, $input);
    json(['success' => true]);
    break;

// ═══ SUCURSALES ═══
case 'sucursales':
    $items = []; $r = $conn->query("SELECT s.*,e.razon_social empresa FROM sucursal s JOIN empresa e ON s.id_empresa=e.id_empresa AND e.id_cuenta=s.id_cuenta WHERE s.id_cuenta={$accountId} ORDER BY s.nombre");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'sucursal_crear':
    $nombre = $input['nombre'] ?? '';
    if (!$nombre) json(['error' => 'Nombre requerido'], 400);
    $id_empresa = (int)($input['id_empresa'] ?? 0);
    requireTenantEntity($conn,$context,'empresa',$id_empresa);
    $codigo = $input['codigo'] ?? '';
    $direccion = $input['direccion'] ?? '';
    $responsable = $input['responsable'] ?? '';
    $telefono = $input['telefono'] ?? '';
    $correo = $input['correo'] ?? '';
    $stmt = $conn->prepare("INSERT INTO sucursal (id_cuenta,id_empresa,codigo,nombre,direccion,responsable,telefono,correo) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iissssss", $accountId, $id_empresa, $codigo, $nombre, $direccion, $responsable, $telefono, $correo);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'sucursal', $id, ['nombre' => $nombre]);
    json(['success' => true, 'id' => $id], 201);
    break;

case 'sucursal_editar':
    $id = (int)($input['id'] ?? 0);
    requireTenantEntity($conn,$context,'sucursal',$id);
    $allowed = ['nombre', 'codigo', 'direccion', 'responsable', 'telefono', 'correo', 'activo'];
    $fields = []; $params = []; $types = '';
    foreach ($input as $k => $v) { if (in_array($k, $allowed)) { $fields[] = "$k=?"; $params[] = $v; $types .= 's'; } }
    if (!count($fields)) json(['error' => 'Sin campos'], 400);
    $params[] = $id; $types .= 'i';
    $stmt = $conn->prepare("UPDATE sucursal SET " . implode(',', $fields) . " WHERE id_sucursal=? AND id_cuenta={$accountId}");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'EDITAR', 'sucursal', $id, $input);
    json(['success' => true]);
    break;

// ═══ ROLES ═══
case 'roles':
    // Las plantillas globales provisionan cuentas, pero no son asignables.
    // Exponerlas junto a los roles locales duplicaba nombres y conteos.
    $items = []; $r = $conn->query("SELECT r.*,0 solo_lectura,(SELECT COUNT(*) FROM usuario_rol ur JOIN usuario u ON u.id_user=ur.id_user WHERE ur.id_rol=r.id_rol AND u.id_cuenta={$accountId}) usuarios FROM rol r WHERE r.id_cuenta={$accountId} AND r.activo=1 ORDER BY r.nombre");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'rol_crear':
    $nombre = $input['nombre'] ?? '';
    if (!$nombre) json(['error' => 'Nombre requerido'], 400);
    $descripcion = $input['descripcion'] ?? '';
    $stmt = $conn->prepare("INSERT INTO rol (id_cuenta,nombre,descripcion,es_sistema,es_plantilla) VALUES (?,?,?,0,0)");
    $stmt->bind_param("iss", $accountId, $nombre, $descripcion);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'rol', $id, ['nombre' => $nombre]);
    json(['success' => true, 'id' => $id], 201);
    break;

// ═══ PERMISOS ═══
case 'permisos':
    $items = []; $r = $conn->query("SELECT * FROM permiso ORDER BY modulo, accion");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'rol_permisos':
    $id_rol = (int)($input['id_rol'] ?? $_GET['id_rol'] ?? 0);
    if (!$id_rol) json(['error' => 'ID rol requerido'], 400);
    requireTenantRole($conn,$context,$id_rol,false);
    $items = []; $stmt = $conn->prepare("SELECT p.*, CASE WHEN rp.id_rol_permiso IS NOT NULL THEN 1 ELSE 0 END AS asignado FROM permiso p LEFT JOIN rol_permiso rp ON p.id_permiso=rp.id_permiso AND rp.id_rol=? ORDER BY p.modulo, p.accion"); $stmt->bind_param("i", $id_rol); $stmt->execute(); $r = $stmt->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'rol_permiso_toggle':
    $id_rol = (int)($input['id_rol'] ?? 0);
    $id_permiso = (int)($input['id_permiso'] ?? 0);
    if (!$id_rol || !$id_permiso) json(['error' => 'Datos requeridos'], 400);
    requireTenantRole($conn,$context,$id_rol,true);
    $stmt = $conn->prepare("SELECT id_rol_permiso FROM rol_permiso WHERE id_rol=? AND id_permiso=?"); $stmt->bind_param("ii", $id_rol, $id_permiso); $stmt->execute(); $r = $stmt->get_result(); $stmt->close();
    if ($r->num_rows) {
        $stmt=$conn->prepare('SELECT modulo,accion FROM permiso WHERE id_permiso=?');$stmt->bind_param('i',$id_permiso);$stmt->execute();$permission=$stmt->get_result()->fetch_assoc();$stmt->close();
        if ($permission && coreIsLockoutCritical($permission['modulo'],$permission['accion'])) {
            $stmt=$conn->prepare("SELECT COUNT(DISTINCT u.id_user) total FROM usuario u JOIN usuario_rol ur ON ur.id_user=u.id_user JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE u.id_cuenta=? AND u.activo=1 AND p.modulo=? AND p.accion=? AND NOT(rp.id_rol=? AND rp.id_permiso=?)");
            $stmt->bind_param('issii',$accountId,$permission['modulo'],$permission['accion'],$id_rol,$id_permiso);$stmt->execute();$remaining=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
            if ($remaining===0) json(['error'=>'No puede quitar el último acceso administrativo de la cuenta.'],409);
        }
        $stmt = $conn->prepare("DELETE FROM rol_permiso WHERE id_rol=? AND id_permiso=?"); $stmt->bind_param("ii", $id_rol, $id_permiso); $stmt->execute(); $stmt->close();
        coreLog($conn, $uid, 'QUITAR_PERMISO', 'rol_permiso', null, ['id_rol' => $id_rol, 'id_permiso' => $id_permiso]);
        json(['success' => true, 'estado' => 'quitado']);
    } else {
        $stmt = $conn->prepare("INSERT INTO rol_permiso (id_rol, id_permiso) VALUES (?,?)"); $stmt->bind_param("ii", $id_rol, $id_permiso); $stmt->execute(); $stmt->close();
        coreLog($conn, $uid, 'ASIGNAR_PERMISO', 'rol_permiso', null, ['id_rol' => $id_rol, 'id_permiso' => $id_permiso]);
        json(['success' => true, 'estado' => 'asignado']);
    }
    break;

case 'usuario_roles':
    $uid_target = (int)($input['id_user'] ?? $_GET['id_user'] ?? 0);
    if (!$uid_target) json(['error' => 'ID usuario requerido'], 400);
    requireTenantUser($conn,$context,$uid_target);
    $items = []; $stmt = $conn->prepare("SELECT r.*, CASE WHEN ur.id_usuario_rol IS NOT NULL THEN 1 ELSE 0 END AS asignado FROM rol r LEFT JOIN usuario_rol ur ON r.id_rol=ur.id_rol AND ur.id_user=? WHERE r.id_cuenta=? AND r.es_plantilla=0 ORDER BY r.nombre"); $stmt->bind_param("ii", $uid_target,$accountId); $stmt->execute(); $r = $stmt->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'usuario_rol_toggle':
    $uid_target = (int)($input['id_user'] ?? 0);
    $id_rol = (int)($input['id_rol'] ?? 0);
    if (!$uid_target || !$id_rol) json(['error' => 'Datos requeridos'], 400);
    requireTenantUser($conn,$context,$uid_target);
    requireTenantRole($conn,$context,$id_rol,true);
    $stmt = $conn->prepare("SELECT id_usuario_rol FROM usuario_rol WHERE id_user=? AND id_rol=?"); $stmt->bind_param("ii", $uid_target, $id_rol); $stmt->execute(); $r = $stmt->get_result(); $stmt->close();
    if ($r->num_rows) {
        $stmt=$conn->prepare("SELECT COUNT(DISTINCT u.id_user) total FROM usuario u JOIN usuario_rol ur ON ur.id_user=u.id_user JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE u.id_cuenta=? AND u.activo=1 AND p.modulo='configuracion' AND p.accion='gestionar_roles' AND NOT(ur.id_user=? AND ur.id_rol=?)");
        $stmt->bind_param('iii',$accountId,$uid_target,$id_rol);$stmt->execute();$remainingAdmins=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
        if ($remainingAdmins===0) json(['error'=>'No puede quitar el rol del último administrador de la cuenta.'],409);
        $stmt = $conn->prepare("DELETE FROM usuario_rol WHERE id_user=? AND id_rol=?"); $stmt->bind_param("ii", $uid_target, $id_rol); $stmt->execute(); $stmt->close();
        coreLog($conn, $uid, 'QUITAR_ROL', 'usuario_rol', null, ['id_user' => $uid_target, 'id_rol' => $id_rol]);
        json(['success' => true, 'estado' => 'quitado']);
    } else {
        $stmt = $conn->prepare("INSERT INTO usuario_rol (id_user, id_rol) VALUES (?,?)"); $stmt->bind_param("ii", $uid_target, $id_rol); $stmt->execute(); $stmt->close();
        coreLog($conn, $uid, 'ASIGNAR_ROL', 'usuario_rol', null, ['id_user' => $uid_target, 'id_rol' => $id_rol]);
        json(['success' => true, 'estado' => 'asignado']);
    }
    break;

// ═══ USUARIOS ═══
case 'usuarios':
    $items = [];
    $stmt = $conn->prepare("SELECT u.id_user,u.nombre,u.nombre_completo,u.correo,u.telefono,u.activo,u.fecha_creacion,u.ultimo_acceso,GROUP_CONCAT(r.nombre SEPARATOR ', ') roles FROM usuario u LEFT JOIN usuario_rol ur ON u.id_user=ur.id_user LEFT JOIN rol r ON ur.id_rol=r.id_rol WHERE u.id_cuenta=? GROUP BY u.id_user,u.nombre,u.nombre_completo,u.correo,u.telefono,u.activo,u.fecha_creacion,u.ultimo_acceso ORDER BY u.nombre");
    $stmt->bind_param("i", $accountId); $stmt->execute(); $r=$stmt->get_result();
    while ($f=$r->fetch_assoc()) $items[]=$f; $stmt->close();
    json($items);
    break;

case 'usuario_crear':
    $nombre = $input['nombre'] ?? '';
    $correo = $input['correo'] ?? '';
    $password = $input['password'] ?? '';
    if (!$nombre || !filter_var($correo, FILTER_VALIDATE_EMAIL) || !$password) json(['error' => 'Nombre, correo válido y contraseña requeridos'], 400);
    $passwordErrors = securityPasswordErrors((string)$password);
    if ($passwordErrors) json(['error' => 'La contraseña requiere '.implode(', ', $passwordErrors).'.'], 400);
    $hash = securityHashPassword((string)$password);
    $nombre_completo = $input['nombre_completo'] ?? '';
    $cargo = $input['cargo'] ?? '';
    $telefono = $input['telefono'] ?? '';
    $id_sucursal = (int)($input['id_sucursal'] ?? 0);
    $id_cuenta=$accountId; if($id_sucursal>0) requireTenantEntity($conn,$context,'sucursal',$id_sucursal);
    $stmt = $conn->prepare("INSERT INTO usuario (nombre, correo, password, nombre_completo, cargo, telefono, id_sucursal, id_cuenta, validar_sesion, password_changed_at) VALUES (?,?,?,?,?,?,?,?,0,NOW())");
    $stmt->bind_param("ssssssii", $nombre, $correo, $hash, $nombre_completo, $cargo, $telefono, $id_sucursal, $id_cuenta);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'usuario', $id, ['nombre' => $nombre, 'correo' => $correo]);
    json(['success' => true, 'id' => $id], 201);
    break;

case 'usuario_editar':
    $id = (int)($input['id'] ?? 0);
    requireTenantUser($conn,$context,$id);
    if (isset($input['id_sucursal']) && (int)$input['id_sucursal']>0) requireTenantEntity($conn,$context,'sucursal',(int)$input['id_sucursal']);
    if (isset($input['activo']) && (int)$input['activo']===0) {
        $stmt=$conn->prepare("SELECT COUNT(DISTINCT u.id_user) total FROM usuario u JOIN usuario_rol ur ON ur.id_user=u.id_user JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE u.id_cuenta=? AND u.activo=1 AND u.id_user<>? AND p.modulo='configuracion' AND p.accion='gestionar_roles'");
        $stmt->bind_param('ii',$accountId,$id);$stmt->execute();$remainingAdmins=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
        if ($remainingAdmins===0 && verificarPermiso('configuracion','gestionar_roles')) json(['error'=>'No puede desactivar el último administrador de la cuenta.'],409);
    }
    $allowed = ['nombre_completo', 'cargo', 'telefono', 'id_sucursal', 'id_departamento', 'idioma', 'activo'];
    $fields = []; $params = []; $types = '';
    foreach ($input as $k => $v) { if (in_array($k, $allowed)) { $fields[] = "$k=?"; $params[] = $v; $types .= 's'; } }
    if (!count($fields)) json(['error' => 'Sin campos'], 400);
    $params[] = $id; $types .= 'i';
    $stmt = $conn->prepare("UPDATE usuario SET " . implode(',', $fields) . " WHERE id_user=? AND id_cuenta={$accountId}");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'EDITAR', 'usuario', $id, $input);
    json(['success' => true]);
    break;

case 'usuario_cambiar_password':
    $id = (int)($input['id_user'] ?? 0);
    $password = $input['password'] ?? '';
    if (!$id || !$password) json(['error' => 'Datos incompletos'], 400);
    requireTenantUser($conn,$context,$id);
    $passwordErrors = securityPasswordErrors((string)$password);
    if ($passwordErrors) json(['error' => 'La contraseña requiere '.implode(', ', $passwordErrors).'.'], 400);
    
    $hash = securityHashPassword((string)$password);
    $stmt = $conn->prepare("UPDATE usuario SET password=?,password_changed_at=NOW(),session_version=session_version+1,validar_sesion=0,intentos_fallidos=0,bloqueado_hasta=NULL,ultimo_fallo_login=NULL WHERE id_user=? AND id_cuenta={$accountId}");
    $stmt->bind_param("si", $hash, $id);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("UPDATE core_sesion SET revoked_at=NOW(),revoked_by=?,revoke_reason='PASSWORD_RESET' WHERE id_user=? AND id_cuenta=? AND revoked_at IS NULL");
    $stmt->bind_param('iii', $uid, $id, $accountId);
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'CAMBIAR_PASSWORD', 'usuario', $id, ['password_changed' => true]);
    json(['success' => true]);
    break;

// ═══ MONEDAS ═══
case 'monedas':
    $items = []; $r = $conn->query("SELECT * FROM moneda ORDER BY codigo");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'moneda_crear':
    $codigo = $input['codigo'];
    $nombre = $input['nombre'];
    $simbolo = $input['simbolo'] ?? '';
    $decimales = (int)($input['decimales'] ?? 0);
    $stmt = $conn->prepare("INSERT INTO moneda (codigo, nombre, simbolo, decimales) VALUES (?,?,?,?)");
    $stmt->bind_param("sssi", $codigo, $nombre, $simbolo, $decimales);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'moneda', $id, $input);
    json(['success' => true, 'id' => $id], 201);
    break;

case 'moneda_editar':
    $id = (int)($input['id'] ?? 0);
    $nombre = $input['nombre'] ?? '';
    $simbolo = $input['simbolo'] ?? '';
    $decimales = (int)($input['decimales'] ?? 0);
    $activo = (int)($input['activo'] ?? 1);
    $stmt = $conn->prepare("UPDATE moneda SET nombre=?, simbolo=?, decimales=?, activo=? WHERE id_moneda=?");
    $stmt->bind_param("ssiii", $nombre, $simbolo, $decimales, $activo, $id);
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'EDITAR', 'moneda', $id, $input);
    json(['success' => true]);
    break;

// ═══ TIPOS DE CAMBIO ═══
case 'tipos_cambio':
    $id_moneda = (int)($input['id_moneda'] ?? $_GET['id_moneda'] ?? 0);
    if ($id_moneda) {
        $stmt = $conn->prepare("SELECT tc.*, m.codigo, m.nombre AS moneda FROM tipo_cambio tc JOIN moneda m ON tc.id_moneda=m.id_moneda WHERE tc.id_moneda=? ORDER BY tc.fecha DESC LIMIT 30");
        $stmt->bind_param("i", $id_moneda);
    } else {
        $stmt = $conn->prepare("SELECT tc.*, m.codigo, m.nombre AS moneda FROM tipo_cambio tc JOIN moneda m ON tc.id_moneda=m.id_moneda ORDER BY tc.fecha DESC LIMIT 30");
    }
    $stmt->execute(); $r = $stmt->get_result(); $items = []; while ($f = $r->fetch_assoc()) $items[] = $f; $stmt->close();
    json($items);
    break;

// ═══ IMPUESTOS ═══
case 'impuestos':
    $items = []; $r = $conn->query("SELECT * FROM impuesto ORDER BY tipo, tasa");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'impuesto_crear':
    $nombre = $input['nombre'];
    $codigo = $input['codigo'];
    $tasa = $input['tasa'] ?? 0;
    $tipo = $input['tipo'] ?? 'IVA';
    $stmt = $conn->prepare("INSERT INTO impuesto (nombre, codigo, tasa, tipo) VALUES (?,?,?,?)");
    $stmt->bind_param("ssds", $nombre, $codigo, $tasa, $tipo);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'impuesto', $id, $input);
    json(['success' => true, 'id' => $id], 201);
    break;

// ═══ NUMERACIONES ═══
case 'numeraciones':
    $items = []; $r = $conn->query("SELECT * FROM numeracion ORDER BY tipo_documento");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'numeracion_editar':
    $id = (int)($input['id'] ?? 0);
    $prefijo = $input['prefijo'] ?? '';
    $siguiente_numero = (int)($input['siguiente_numero'] ?? 1);
    $activo = (int)($input['activo'] ?? 1);
    $stmt = $conn->prepare("UPDATE numeracion SET prefijo=?, siguiente_numero=?, activo=? WHERE id_numeracion=?");
    $stmt->bind_param("siii", $prefijo, $siguiente_numero, $activo, $id);
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'EDITAR', 'numeracion', $id, $input);
    json(['success' => true]);
    break;

// ═══ PARÁMETROS ═══
case 'parametros':
    $items = []; $r = $conn->query("SELECT * FROM parametro ORDER BY clave");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'parametro_editar':
    $id = (int)($input['id'] ?? 0);
    $valor = $input['valor'] ?? '';
    $stmt = $conn->prepare("UPDATE parametro SET valor=? WHERE id_parametro=?");
    $stmt->bind_param("si", $valor, $id);
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'EDITAR', 'parametro', $id, ['valor' => $valor]);
    json(['success' => true]);
    break;

// ═══ AUDITORÍA ═══
case 'auditoria':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(200, (int)($input['limit'] ?? $_GET['limit'] ?? 100));
    $offset = ($page - 1) * $limit;
    $nivel = $input['nivel'] ?? $_GET['nivel'] ?? '';
    if ($nivel) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM core_auditoria WHERE id_cuenta=? AND nivel=?"); $stmt->bind_param("is", $accountId, $nivel); $stmt->execute(); $total = (int)$stmt->get_result()->fetch_assoc()['t']; $stmt->close();
        $stmt = $conn->prepare("SELECT a.*, u.nombre AS user_nombre FROM core_auditoria a LEFT JOIN usuario u ON a.id_user=u.id_user WHERE a.id_cuenta=? AND a.nivel=? ORDER BY a.created_at DESC LIMIT ? OFFSET ?"); $stmt->bind_param("isii", $accountId, $nivel, $limit, $offset);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM core_auditoria WHERE id_cuenta=?"); $stmt->bind_param('i', $accountId); $stmt->execute(); $total = (int)$stmt->get_result()->fetch_assoc()['t']; $stmt->close();
        $stmt = $conn->prepare("SELECT a.*, u.nombre AS user_nombre FROM core_auditoria a LEFT JOIN usuario u ON a.id_user=u.id_user WHERE a.id_cuenta=? ORDER BY a.created_at DESC LIMIT ? OFFSET ?"); $stmt->bind_param("iii", $accountId, $limit, $offset);
    }
    $stmt->execute(); $items = []; $r = $stmt->get_result(); while ($f = $r->fetch_assoc()) $items[] = $f; $stmt->close();
    json(['items' => $items, 'total' => $total, 'page' => $page]);
    break;

// ═══ DEPARTAMENTOS ═══
case 'departamentos':
    $items = []; $r = $conn->query("SELECT * FROM departamento ORDER BY nombre");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

// ═══ PLANES ═══
case 'planes':
    $items = []; $r = $conn->query("SELECT * FROM plan ORDER BY nombre");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'plan_crear':
    $nombre = $input['nombre'] ?? '';
    if (!$nombre) json(['error' => 'Nombre requerido'], 400);
    $nombre_p = $input['nombre'] ?? ''; $desc_p = $input['descripcion'] ?? ''; $precio = (int)($input['precio'] ?? 0);
    $me = (int)($input['max_empresas'] ?? 1); $ms = (int)($input['max_sucursales'] ?? 1); $mu = (int)($input['max_usuarios'] ?? 5);
    $stmt = $conn->prepare("INSERT INTO plan (nombre, descripcion, precio, max_empresas, max_sucursales, max_usuarios) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("ssiiii", $nombre_p, $desc_p, $precio, $me, $ms, $mu);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'plan', $id, ['nombre' => $nombre_p]);
    json(['success' => true, 'id' => $id], 201);
    break;

// ═══ SUSCRIPCIONES ═══
case 'suscripciones':
    $items = []; $r = $conn->query("SELECT s.*, p.nombre AS plan_nombre, e.razon_social AS empresa FROM suscripcion s JOIN plan p ON s.id_plan=p.id_plan JOIN empresa e ON s.id_empresa=e.id_empresa WHERE e.id_cuenta={$accountId} ORDER BY s.created_at DESC");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'suscripcion_crear':
    $id_empresa_s = (int)($input['id_empresa'] ?? 0); $id_plan_s = (int)($input['id_plan'] ?? 0);
    if (!$id_empresa_s || !$id_plan_s) json(['error' => 'Empresa y plan requeridos'], 400);
    requireTenantEntity($conn,$context,'empresa',$id_empresa_s);
    $stmt2 = $conn->prepare("SELECT max_usuarios FROM plan WHERE id_plan=?"); $stmt2->bind_param("i", $id_plan_s); $stmt2->execute(); $r = $stmt2->get_result()->fetch_assoc(); $stmt2->close();
    $max_users = (int)($r['max_usuarios'] ?? 0);
    $r = $conn->query("SELECT COUNT(*) as t FROM usuario WHERE id_cuenta={$accountId} AND activo=1");
    $current = (int)$r->fetch_assoc()['t'];
    if ($current > $max_users) json(['error' => "Límite de usuarios excedido ($current/$max_users). Desactive usuarios o aumente el plan."], 400);
    $stmt = $conn->prepare("INSERT INTO suscripcion (id_empresa, id_plan, fecha_inicio, estado) VALUES (?,?,CURDATE(),'activa') ON DUPLICATE KEY UPDATE id_plan=?, estado='activa'");
    $stmt->bind_param("iii", $id_empresa_s, $id_plan_s, $id_plan_s);
    $stmt->execute(); $sid = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'suscripcion', $sid ?: 0, ['id_empresa' => $id_empresa_s, 'id_plan' => $id_plan_s]);
    json(['success' => true, 'id' => $sid], 201);
    break;

// ═══ MÓDULOS ═══
case 'modulos':
    $items = []; $r = $conn->query("SELECT * FROM modulo WHERE activo=1 ORDER BY orden");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'plan_modulos':
    $id_plan_m = (int)($input['id_plan'] ?? $_GET['id_plan'] ?? 0);
    if (!$id_plan_m) json(['error' => 'ID plan requerido'], 400);
    $items = []; $stmt = $conn->prepare("SELECT m.*, CASE WHEN pm.id_plan_modulo IS NOT NULL THEN 1 ELSE 0 END AS asignado FROM modulo m LEFT JOIN plan_modulo pm ON m.id_modulo=pm.id_modulo AND pm.id_plan=? ORDER BY m.orden"); $stmt->bind_param("i", $id_plan_m); $stmt->execute(); $r = $stmt->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'plan_modulo_toggle':
    $id_plan_pt = (int)($input['id_plan'] ?? 0); $id_modulo_pt = (int)($input['id_modulo'] ?? 0);
    if (!$id_plan_pt || !$id_modulo_pt) json(['error' => 'Datos requeridos'], 400);
    $stmt = $conn->prepare("SELECT id_plan_modulo FROM plan_modulo WHERE id_plan=? AND id_modulo=?"); $stmt->bind_param("ii", $id_plan_pt, $id_modulo_pt); $stmt->execute(); $r = $stmt->get_result(); $stmt->close();
    if ($r->num_rows) {
        $stmt = $conn->prepare("DELETE FROM plan_modulo WHERE id_plan=? AND id_modulo=?"); $stmt->bind_param("ii", $id_plan_pt, $id_modulo_pt); $stmt->execute(); $stmt->close();
        json(['success' => true, 'estado' => 'quitado']);
    } else {
        $stmt = $conn->prepare("INSERT INTO plan_modulo (id_plan, id_modulo) VALUES (?,?)"); $stmt->bind_param("ii", $id_plan_pt, $id_modulo_pt); $stmt->execute(); $stmt->close();
        json(['success' => true, 'estado' => 'asignado']);
    }
    break;

// ═══ SIDEBAR ═══
case 'sidebar':
    $modulos = [[
        'codigo' => 'inicio',
        'nombre' => 'Inicio',
        'icono' => 'fa-home',
        'ruta' => 'Inicio.html'
    ]];
    // Una cuenta puede tener varias empresas con el mismo plan. La navegación
    // representa capacidades de la cuenta, no una fila por suscripción.
    $stmtModulos = $conn->prepare("SELECT DISTINCT
            m.id_modulo, m.codigo, m.nombre, m.icono, m.ruta, m.orden
        FROM modulo m
        JOIN plan_modulo pm ON m.id_modulo=pm.id_modulo
        JOIN suscripcion s ON pm.id_plan=s.id_plan
        JOIN empresa e ON e.id_empresa=s.id_empresa
        WHERE e.id_cuenta=?
          AND e.activo=1
          AND s.estado='activa'
          AND (s.fecha_fin IS NULL OR s.fecha_fin >= CURDATE())
          AND m.activo=1
          AND m.codigo != 'dashboard'
          AND EXISTS (
              SELECT 1
              FROM permiso p
              JOIN rol_permiso rp ON rp.id_permiso=p.id_permiso
              JOIN usuario_rol ur ON ur.id_rol=rp.id_rol
              JOIN rol role_access ON role_access.id_rol=ur.id_rol
              JOIN usuario actor ON actor.id_user=ur.id_user
              WHERE ur.id_user=?
                AND p.modulo=m.codigo
                AND p.accion='ver'
                AND role_access.activo=1
                AND (role_access.id_cuenta IS NULL OR role_access.id_cuenta=actor.id_cuenta)
          )
        ORDER BY m.orden, m.nombre");
    $stmtModulos->bind_param("ii", $accountId, $uid);
    $stmtModulos->execute();
    $r = $stmtModulos->get_result();
    while ($f = $r->fetch_assoc()) {
        $modulos[] = ['codigo'=>$f['codigo'],'nombre'=>$f['nombre'],'icono'=>$f['icono'],'ruta'=>$f['ruta']];
    }
    $stmtModulos->close();
    $stmt2 = $conn->prepare("SELECT DISTINCT p.modulo, p.accion FROM permiso p 
        JOIN rol_permiso rp ON p.id_permiso=rp.id_permiso
        JOIN usuario_rol ur ON rp.id_rol=ur.id_rol
        JOIN rol role_access ON role_access.id_rol=ur.id_rol
        JOIN usuario actor ON actor.id_user=ur.id_user
        WHERE ur.id_user=? AND role_access.activo=1
          AND (role_access.id_cuenta IS NULL OR role_access.id_cuenta=actor.id_cuenta)");
    $stmt2->bind_param("i", $uid); $stmt2->execute(); $r = $stmt2->get_result();
    $permisos = [];
    while ($f = $r->fetch_assoc()) {
        $permisos[$f['modulo']][] = $f['accion'];
    }
    json(['modulos' => $modulos, 'permisos' => $permisos, 'usuario' => $uid]);
    break;

// ═══ LICENCIA ═══
case 'validar_licencia':
    $stmt = $conn->prepare("SELECT COALESCE(MAX(p.max_usuarios + COALESCE(s.usuarios_extra,0)),5) AS max_u,
        (SELECT COUNT(*) FROM usuario WHERE id_cuenta=? AND activo=1) AS current_u
        FROM plan p JOIN suscripcion s ON p.id_plan=s.id_plan
        JOIN empresa e ON e.id_empresa=s.id_empresa
        WHERE e.id_cuenta=? AND s.estado='activa'");
    $stmt->bind_param('ii', $accountId, $accountId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $disponible = max(0, (int)$r['max_u'] - (int)$r['current_u']);
    json(['max_usuarios' => (int)$r['max_u'], 'actual' => (int)$r['current_u'], 'disponible' => $disponible, 'puede_crear' => $disponible > 0]);
    break;

// ═══ SESIONES ACTIVAS ═══
case 'sesiones_activas':
    $items = [];
    $stmt = $conn->prepare("SELECT cs.id_sesion,cs.id_user,u.nombre,u.nombre_completo,
        CASE WHEN cs.revoked_at IS NULL AND cs.expires_at>NOW() THEN 'ACTIVA' ELSE 'CERRADA' END accion,
        cs.created_at,cs.last_activity_at,cs.expires_at,cs.revoked_at,cs.revoke_reason
        FROM core_sesion cs JOIN usuario u ON u.id_user=cs.id_user
        WHERE cs.id_cuenta=? ORDER BY cs.last_activity_at DESC LIMIT 100");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt->close();
    json($items);
    break;

case 'sesion_cerrar':
    $id_sesion = (int)($input['id_sesion'] ?? 0);
    if (!$id_sesion) json(['error'=>'ID requerido'],400);
    $stmt = $conn->prepare("UPDATE core_sesion SET revoked_at=NOW(),revoked_by=?,revoke_reason='ADMIN_REVOKE' WHERE id_sesion=? AND id_cuenta=? AND revoked_at IS NULL");
    $stmt->bind_param("iii", $uid, $id_sesion, $accountId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) { $stmt->close(); json(['error'=>'Sesión no encontrada o ya cerrada'],404); }
    $stmt->close();
    coreLog($conn, $uid, 'CERRAR_SESION', 'core_sesion', $id_sesion);
    json(['success'=>true]);
    break;

// ═══ CONFIGURACIÓN DE BOLETAS ═══
case 'config_boleta':
    $stmt = $conn->prepare("SELECT * FROM config_boleta WHERE id_cuenta=? AND activo=1 ORDER BY id_config DESC LIMIT 1");
    $stmt->bind_param("i", $accountId);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r->num_rows) {
        json($r->fetch_assoc());
    } else {
        // Retornar valores por defecto
        json([
            'nombre_empresa' => 'Mi Empresa',
            'rut_empresa' => '',
            'direccion' => '',
            'telefono' => '',
            'email' => '',
            'logo' => '',
            'mensaje_pie' => '',
            'mensaje_agradecimiento' => '¡Gracias por su compra!',
            'mostrar_rut_cliente' => 0,
            'mostrar_desglose_iva' => 1,
            'mostrar_descuento' => 1,
            'iva_porcentaje' => 19.00
        ]);
    }
    break;

case 'config_boleta_guardar':
    $nombre_empresa = trim((string)($input['nombre_empresa'] ?? ''));
    if (!$nombre_empresa) json(['error' => 'Nombre de empresa requerido'], 400);
    if (strlen($nombre_empresa) > 150) json(['error' => 'Nombre de empresa demasiado largo'], 400);
    
    $rut_empresa = $input['rut_empresa'] ?? '';
    $direccion = $input['direccion'] ?? '';
    $telefono = $input['telefono'] ?? '';
    $email = $input['email'] ?? '';
    $logo = trim((string)($input['logo'] ?? ''));
    if ($logo !== '') {
        if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $logo, $logoMatch)) {
            json(['error' => 'Logo invalido. Use PNG, JPG o WebP'], 400);
        }
        $logoBin = base64_decode($logoMatch[2], true);
        if ($logoBin === false || strlen($logoBin) > 2 * 1024 * 1024) {
            json(['error' => 'El logo no puede superar 2 MB'], 400);
        }
        $logoInfo = @getimagesizefromstring($logoBin);
        if (!$logoInfo || $logoInfo[0] > 2000 || $logoInfo[1] > 2000) {
            json(['error' => 'Logo invalido o dimensiones mayores a 2000x2000 px'], 400);
        }
        $mimeMap = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_WEBP => 'webp'];
        if (!isset($mimeMap[$logoInfo[2]]) || $mimeMap[$logoInfo[2]] !== strtolower($logoMatch[1])) {
            json(['error' => 'El contenido del logo no coincide con su formato'], 400);
        }
    }
    $mensaje_pie = $input['mensaje_pie'] ?? '';
    $mensaje_agradecimiento = $input['mensaje_agradecimiento'] ?? '¡Gracias por su compra!';
    $mostrar_rut_cliente = (int)($input['mostrar_rut_cliente'] ?? 0);
    $mostrar_desglose_iva = (int)($input['mostrar_desglose_iva'] ?? 1);
    $mostrar_descuento = (int)($input['mostrar_descuento'] ?? 1);
    $iva_porcentaje = (float)($input['iva_porcentaje'] ?? 19.00);
    if ($iva_porcentaje < 0 || $iva_porcentaje > 100) json(['error' => 'IVA fuera de rango'], 400);
    
    // Verificar si ya existe configuración
    $stmt = $conn->prepare("SELECT id_config FROM config_boleta WHERE id_cuenta=? AND activo=1");
    $stmt->bind_param("i", $accountId);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    
    if ($r->num_rows) {
        // Actualizar
        $id_config = $r->fetch_assoc()['id_config'];
        $stmt = $conn->prepare("UPDATE config_boleta SET id_user=?, nombre_empresa=?, rut_empresa=?, direccion=?, telefono=?, email=?, logo=?, mensaje_pie=?, mensaje_agradecimiento=?, mostrar_rut_cliente=?, mostrar_desglose_iva=?, mostrar_descuento=?, iva_porcentaje=? WHERE id_config=? AND id_cuenta=?");
        $stmt->bind_param("issssssssiiidii", $uid, $nombre_empresa, $rut_empresa, $direccion, $telefono, $email, $logo, $mensaje_pie, $mensaje_agradecimiento, $mostrar_rut_cliente, $mostrar_desglose_iva, $mostrar_descuento, $iva_porcentaje, $id_config, $accountId);
    } else {
        // Insertar
        $stmt = $conn->prepare("INSERT INTO config_boleta (id_cuenta, id_user, nombre_empresa, rut_empresa, direccion, telefono, email, logo, mensaje_pie, mensaje_agradecimiento, mostrar_rut_cliente, mostrar_desglose_iva, mostrar_descuento, iva_porcentaje) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("iissssssssiiid", $accountId, $uid, $nombre_empresa, $rut_empresa, $direccion, $telefono, $email, $logo, $mensaje_pie, $mensaje_agradecimiento, $mostrar_rut_cliente, $mostrar_desglose_iva, $mostrar_descuento, $iva_porcentaje);
    }
    
    $stmt->execute();
    $stmt->close();
    $savedConfigId = $id_config ?? (int)$conn->insert_id;
    coreLog($conn, $uid, 'GUARDAR', 'config_boleta', $savedConfigId, ['nombre_empresa' => $nombre_empresa, 'logo' => $logo !== '']);
    json(['success' => true, 'logo_guardado' => $logo !== '']);
    break;

default:
    json(['error' => 'Acción no válida: ' . $accion], 400);
}
