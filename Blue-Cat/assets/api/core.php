<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
$conn = getDB();
$context = requireTenantContext();
$accountId = $context->accountId;
$input = getJsonInput();
$accion = $input['accion'] ?? $_GET['accion'] ?? '';

function requierePermiso($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error' => 'Permiso denegado: ' . $modulo . '.' . $accion], 403);
    }
}

function coreLog($conn, $uid, $accion, $entidad, $id_entidad = null, $detalle = null, $nivel = 'INFO', $va = null, $vn = null) {
    $idCuenta = tenantContext($uid)->accountId;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $vn = $detalle !== null ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : $vn;
    $stmt = $conn->prepare("INSERT INTO core_auditoria (id_cuenta,id_user,accion,entidad,id_entidad,valor_anterior,valor_nuevo,ip,user_agent,nivel) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iississsss", $idCuenta, $uid, $accion, $entidad, $id_entidad, $va, $vn, $ip, $ua, $nivel);
    $stmt->execute();
    $stmt->close();
}


// La navegación necesita sidebar sin acceso a Configuración. El resto de este
// endpoint pertenece al panel administrativo y se autoriza también en servidor.
$accionesPublicas = ['sidebar', 'validar_licencia'];
if (!in_array($accion, $accionesPublicas, true)) {
    requierePermiso('configuracion', 'ver');
    $accionesLectura = ['dashboard','empresas','sucursales','roles','permisos','rol_permisos','usuario_roles','usuarios','monedas','impuestos','numeraciones','parametros','planes','suscripciones','modulos','plan_modulos','sesiones_activas','config_boleta'];
    if (!in_array($accion, $accionesLectura, true)) {
        requierePermiso('configuracion', 'editar');
    }
}

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
    $items = []; $r = $conn->query("SELECT r.*,(r.id_cuenta IS NULL OR r.es_plantilla=1) solo_lectura,(SELECT COUNT(*) FROM usuario_rol ur JOIN usuario u ON u.id_user=ur.id_user WHERE ur.id_rol=r.id_rol AND u.id_cuenta={$accountId}) usuarios FROM rol r WHERE r.id_cuenta={$accountId} OR r.id_cuenta IS NULL ORDER BY r.nombre");
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
    if (!$nombre || !$correo || !$password) json(['error' => 'Nombre, correo y contraseña requeridos'], 400);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $nombre_completo = $input['nombre_completo'] ?? '';
    $cargo = $input['cargo'] ?? '';
    $telefono = $input['telefono'] ?? '';
    $id_sucursal = (int)($input['id_sucursal'] ?? 0);
    $id_cuenta=$accountId; if($id_sucursal>0) requireTenantEntity($conn,$context,'sucursal',$id_sucursal);
    $stmt = $conn->prepare("INSERT INTO usuario (nombre, correo, password, nombre_completo, cargo, telefono, id_sucursal, id_cuenta, validar_sesion) VALUES (?,?,?,?,?,?,?,?,1)");
    $stmt->bind_param("ssssssii", $nombre, $correo, $hash, $nombre_completo, $cargo, $telefono, $id_sucursal, $id_cuenta);
    $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
    coreLog($conn, $uid, 'CREAR', 'usuario', $id, ['nombre' => $nombre, 'correo' => $correo]);
    json(['success' => true, 'id' => $id], 201);
    break;

case 'usuario_editar':
    $id = (int)($input['id'] ?? 0);
    requireTenantUser($conn,$context,$id);
    if (isset($input['id_sucursal']) && (int)$input['id_sucursal']>0) requireTenantEntity($conn,$context,'sucursal',(int)$input['id_sucursal']);
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
    requierePermiso('usuarios', 'editar_cuentas');
    $id = (int)($input['id_user'] ?? 0);
    $password = $input['password'] ?? '';
    if (!$id || !$password) json(['error' => 'Datos incompletos'], 400);
    requireTenantUser($conn,$context,$id);
    if (strlen($password) < 6) json(['error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
    
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE usuario SET password=? WHERE id_user=? AND id_cuenta={$accountId}");
    $stmt->bind_param("si", $hash, $id);
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
        $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM core_auditoria WHERE nivel=?"); $stmt->bind_param("s", $nivel); $stmt->execute(); $total = (int)$stmt->get_result()->fetch_assoc()['t']; $stmt->close();
        $stmt = $conn->prepare("SELECT a.*, u.nombre AS user_nombre FROM core_auditoria a LEFT JOIN usuario u ON a.id_user=u.id_user WHERE nivel=? ORDER BY a.created_at DESC LIMIT ? OFFSET ?"); $stmt->bind_param("sii", $nivel, $limit, $offset);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM core_auditoria"); $stmt->execute(); $total = (int)$stmt->get_result()->fetch_assoc()['t']; $stmt->close();
        $stmt = $conn->prepare("SELECT a.*, u.nombre AS user_nombre FROM core_auditoria a LEFT JOIN usuario u ON a.id_user=u.id_user ORDER BY a.created_at DESC LIMIT ? OFFSET ?"); $stmt->bind_param("ii", $limit, $offset);
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
    $items = []; $r = $conn->query("SELECT s.*, p.nombre AS plan_nombre, e.razon_social AS empresa FROM suscripcion s JOIN plan p ON s.id_plan=p.id_plan JOIN empresa e ON s.id_empresa=e.id_empresa ORDER BY s.created_at DESC");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'suscripcion_crear':
    $id_empresa_s = (int)($input['id_empresa'] ?? 0); $id_plan_s = (int)($input['id_plan'] ?? 0);
    if (!$id_empresa_s || !$id_plan_s) json(['error' => 'Empresa y plan requeridos'], 400);
    $stmt2 = $conn->prepare("SELECT max_usuarios FROM plan WHERE id_plan=?"); $stmt2->bind_param("i", $id_plan_s); $stmt2->execute(); $r = $stmt2->get_result()->fetch_assoc(); $stmt2->close();
    $max_users = (int)($r['max_usuarios'] ?? 0);
    $r = $conn->query("SELECT COUNT(*) as t FROM usuario WHERE activo=1");
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
    $r = $conn->query("SELECT m.* FROM modulo m 
        JOIN plan_modulo pm ON m.id_modulo=pm.id_modulo 
        JOIN suscripcion s ON pm.id_plan=s.id_plan 
        WHERE s.estado='activa' AND m.activo=1 AND m.codigo != 'dashboard'
        ORDER BY m.orden");
    while ($f = $r->fetch_assoc()) {
        $stmt = $conn->prepare("SELECT COUNT(*) as t FROM permiso p JOIN rol_permiso rp ON p.id_permiso=rp.id_permiso JOIN usuario_rol ur ON rp.id_rol=ur.id_rol WHERE ur.id_user=? AND p.modulo=? AND p.accion='ver'");
        $modulo_codigo = $f['codigo'];
        $stmt->bind_param("is", $uid, $modulo_codigo);
        $stmt->execute();
        $has = (int)$stmt->get_result()->fetch_assoc()['t'] > 0;
        $stmt->close();
        if ($has) {
            $modulos[] = ['codigo'=>$f['codigo'],'nombre'=>$f['nombre'],'icono'=>$f['icono'],'ruta'=>$f['ruta']];
        }
    }
    $stmt2 = $conn->prepare("SELECT DISTINCT p.modulo, p.accion FROM permiso p 
        JOIN rol_permiso rp ON p.id_permiso=rp.id_permiso 
        JOIN usuario_rol ur ON rp.id_rol=ur.id_rol 
        WHERE ur.id_user=?"); $stmt2->bind_param("i", $uid); $stmt2->execute(); $r = $stmt2->get_result();
    $permisos = [];
    while ($f = $r->fetch_assoc()) {
        $permisos[$f['modulo']][] = $f['accion'];
    }
    json(['modulos' => $modulos, 'permisos' => $permisos, 'usuario' => $uid]);
    break;

// ═══ LICENCIA ═══
case 'validar_licencia':
    $r = $conn->query("SELECT COALESCE(p.max_usuarios + COALESCE(s.usuarios_extra,0), 5) AS max_u, 
        (SELECT COUNT(*) FROM usuario WHERE activo=1) AS current_u
        FROM plan p JOIN suscripcion s ON p.id_plan=s.id_plan WHERE s.estado='activa' LIMIT 1")->fetch_assoc();
    $disponible = max(0, (int)$r['max_u'] - (int)$r['current_u']);
    json(['max_usuarios' => (int)$r['max_u'], 'actual' => (int)$r['current_u'], 'disponible' => $disponible, 'puede_crear' => $disponible > 0]);
    break;

// ═══ SESIONES ACTIVAS ═══
case 'sesiones_activas':
    $items = [];
    $r = $conn->query("SELECT sl.id_sesion_log, sl.id_user, u.nombre, u.nombre_completo, sl.accion, sl.ip, sl.created_at 
        FROM sesion_log sl LEFT JOIN usuario u ON sl.id_user=u.id_user 
        WHERE sl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY sl.created_at DESC LIMIT 100");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

case 'sesion_cerrar':
    $id_sesion = (int)($input['id_sesion'] ?? 0);
    if (!$id_sesion) json(['error'=>'ID requerido'],400);
    $stmt = $conn->prepare("UPDATE sesion_log SET accion=CONCAT(accion,'_CERRADA') WHERE id_sesion_log=?"); $stmt->bind_param("i", $id_sesion); $stmt->execute(); $stmt->close();
    coreLog($conn, $uid, 'CERRAR_SESION', 'sesion_log', $id_sesion);
    json(['success'=>true]);
    break;

// ═══ CONFIGURACIÓN DE BOLETAS ═══
case 'config_boleta':
    $stmt = $conn->prepare("SELECT * FROM config_boleta WHERE id_user=? AND activo=1 LIMIT 1");
    $stmt->bind_param("i", $uid);
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
    $nombre_empresa = $input['nombre_empresa'] ?? '';
    if (!$nombre_empresa) json(['error' => 'Nombre de empresa requerido'], 400);
    
    $rut_empresa = $input['rut_empresa'] ?? '';
    $direccion = $input['direccion'] ?? '';
    $telefono = $input['telefono'] ?? '';
    $email = $input['email'] ?? '';
    $logo = $input['logo'] ?? '';
    $mensaje_pie = $input['mensaje_pie'] ?? '';
    $mensaje_agradecimiento = $input['mensaje_agradecimiento'] ?? '¡Gracias por su compra!';
    $mostrar_rut_cliente = (int)($input['mostrar_rut_cliente'] ?? 0);
    $mostrar_desglose_iva = (int)($input['mostrar_desglose_iva'] ?? 1);
    $mostrar_descuento = (int)($input['mostrar_descuento'] ?? 1);
    $iva_porcentaje = (float)($input['iva_porcentaje'] ?? 19.00);
    
    // Verificar si ya existe configuración
    $stmt = $conn->prepare("SELECT id_config FROM config_boleta WHERE id_user=? AND activo=1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    
    if ($r->num_rows) {
        // Actualizar
        $id_config = $r->fetch_assoc()['id_config'];
        $stmt = $conn->prepare("UPDATE config_boleta SET nombre_empresa=?, rut_empresa=?, direccion=?, telefono=?, email=?, logo=?, mensaje_pie=?, mensaje_agradecimiento=?, mostrar_rut_cliente=?, mostrar_desglose_iva=?, mostrar_descuento=?, iva_porcentaje=? WHERE id_config=?");
        $stmt->bind_param("ssssssssiiidi", $nombre_empresa, $rut_empresa, $direccion, $telefono, $email, $logo, $mensaje_pie, $mensaje_agradecimiento, $mostrar_rut_cliente, $mostrar_desglose_iva, $mostrar_descuento, $iva_porcentaje, $id_config);
    } else {
        // Insertar
        $stmt = $conn->prepare("INSERT INTO config_boleta (id_user, nombre_empresa, rut_empresa, direccion, telefono, email, logo, mensaje_pie, mensaje_agradecimiento, mostrar_rut_cliente, mostrar_desglose_iva, mostrar_descuento, iva_porcentaje) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("issssssssiiid", $uid, $nombre_empresa, $rut_empresa, $direccion, $telefono, $email, $logo, $mensaje_pie, $mensaje_agradecimiento, $mostrar_rut_cliente, $mostrar_desglose_iva, $mostrar_descuento, $iva_porcentaje);
    }
    
    $stmt->execute();
    $stmt->close();
    coreLog($conn, $uid, 'GUARDAR', 'config_boleta', null, ['nombre_empresa' => $nombre_empresa]);
    json(['success' => true]);
    break;

default:
    json(['error' => 'Acción no válida: ' . $accion], 400);
}
