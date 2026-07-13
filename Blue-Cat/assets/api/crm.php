<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
$conn = getDB();
$accountId = tenantContext($uid)->accountId;
$input = getJsonInput();
$accion = $input['accion'] ?? '';

function crmLog($conn, $uid, $accion, $entidad, $id_entidad = null, $detalle = null, $nivel = 'INFO') {
    $det = $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO cliente_auditoria (id_user, accion, entidad, id_entidad, valor_nuevo, ip, nivel) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("ississs", $uid, $accion, $entidad, $id_entidad, $det, $ip, $nivel);
    $stmt->execute();
    $stmt->close();
}

function requierePermiso($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error'=>'Permiso denegado: '.$modulo.'.'.$accion], 403);
    }
}

$crmReadActions = ['dashboard','clientes','cliente_obtener','cliente','actividades','creditos','etiquetas','cliente_etiquetas','reporte_abc','reporte_morosidad','auditoria'];
$crmCreateActions = ['contacto_crear','direccion_crear','actividad_crear'];
$crmEditActions = ['credito_crear','credito_editar','actividad_completar','cliente_etiqueta_toggle'];
if (in_array($accion, $crmReadActions, true)) requierePermiso('crm','ver');
if (in_array($accion, $crmCreateActions, true)) requierePermiso('crm','crear');
if (in_array($accion, $crmEditActions, true)) requierePermiso('crm','editar');
if ($accion === 'contacto_eliminar') requierePermiso('crm','eliminar');

switch ($accion) {

// ═══ DASHBOARD ═══
case 'dashboard':
    $d = [];
    $d['total_clientes'] = (int)$conn->query("SELECT COUNT(*) AS t FROM cliente WHERE id_cuenta=$accountId")->fetch_assoc()['t'];
    $d['clientes_activos'] = (int)$conn->query("SELECT COUNT(*) AS t FROM cliente WHERE id_cuenta=$accountId AND activo=1")->fetch_assoc()['t'];
    $d['clientes_nuevos_hoy'] = (int)$conn->query("SELECT COUNT(*) AS t FROM cliente WHERE id_cuenta=$accountId AND DATE(fecha_creacion)=CURDATE()")->fetch_assoc()['t'];
    $d['clientes_nuevos_mes'] = (int)$conn->query("SELECT COUNT(*) AS t FROM cliente WHERE id_cuenta=$accountId AND YEAR(fecha_creacion)=YEAR(CURDATE()) AND MONTH(fecha_creacion)=MONTH(CURDATE())")->fetch_assoc()['t'];
    $d['clientes_vip'] = (int)$conn->query("SELECT COUNT(*) AS t FROM cliente WHERE id_cuenta=$accountId AND estado='vip'")->fetch_assoc()['t'];
    $d['clientes_morosos'] = (int)$conn->query("SELECT COUNT(DISTINCT id_cliente) AS t FROM factura WHERE id_cuenta=$accountId AND (estado='VENCIDA' OR estado='PENDIENTE') AND id_cliente IS NOT NULL")->fetch_assoc()['t'];
    $r = $conn->query("SELECT COALESCE(SUM(limite_credito),0) AS total_otorgado, COALESCE(SUM(credito_utilizado),0) AS total_utilizado FROM cliente_credito cr JOIN cliente c ON c.id_cliente=cr.id_cliente WHERE c.id_cuenta=$accountId");
    $cred = $r->fetch_assoc();
    $d['credito_total_otorgado'] = (int)$cred['total_otorgado'];
    $d['credito_utilizado'] = (int)$cred['total_utilizado'];
    $d['actividades_pendientes'] = (int)$conn->query("SELECT COUNT(*) AS t FROM cliente_actividad a JOIN cliente c ON c.id_cliente=a.id_cliente WHERE c.id_cuenta=$accountId AND a.estado='pendiente'")->fetch_assoc()['t'];
    $r = $conn->query("SELECT COALESCE(SUM(p.precio_total),0) AS t FROM pedido p WHERE p.id_cuenta=$accountId AND p.id_cliente IS NOT NULL AND MONTH(p.fecha)=MONTH(CURDATE()) AND YEAR(p.fecha)=YEAR(CURDATE()) AND p.anulado=0");
    $d['ventas_mes'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT id_cliente, codigo, rut, razon_social, nombre, correo, telefono, ciudad, estado, fecha_creacion FROM cliente WHERE id_cuenta=$accountId ORDER BY fecha_creacion DESC LIMIT 5");
    $d['ultimos_clientes'] = $r->fetch_all(MYSQLI_ASSOC);
    $r = $conn->query("SELECT c.id_cliente, c.codigo, c.razon_social, c.nombre, COALESCE(SUM(p.precio_total),0) AS total_mes FROM cliente c JOIN pedido p ON c.id_cliente=p.id_cliente AND p.id_cuenta=c.id_cuenta WHERE c.id_cuenta=$accountId AND MONTH(p.fecha)=MONTH(CURDATE()) AND YEAR(p.fecha)=YEAR(CURDATE()) AND p.anulado=0 GROUP BY c.id_cliente, c.codigo, c.razon_social, c.nombre ORDER BY total_mes DESC LIMIT 5");
    $d['clientes_top'] = $r->fetch_all(MYSQLI_ASSOC);
    $r = $conn->query("SELECT categoria, COUNT(*) AS total FROM cliente WHERE id_cuenta=$accountId AND categoria IS NOT NULL AND categoria != '' GROUP BY categoria ORDER BY total DESC");
    $d['chart_categorias'] = $r->fetch_all(MYSQLI_ASSOC);
    $r = $conn->query("SELECT ciudad, COUNT(*) AS total FROM cliente WHERE id_cuenta=$accountId AND ciudad IS NOT NULL AND ciudad != '' GROUP BY ciudad ORDER BY total DESC");
    $d['chart_ciudades'] = $r->fetch_all(MYSQLI_ASSOC);
    json($d);

// ═══ CLIENTES (list) ═══
case 'clientes':
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = min(100, (int)($input['limit'] ?? 25));
    $offset = ($page - 1) * $limit;
    $q = $input['q'] ?? '';
    $estado = $input['estado'] ?? '';
    $categoria = $input['categoria'] ?? '';
    $tipo = $input['tipo'] ?? '';
    $ciudad = $input['ciudad'] ?? '';

    $where = ['c.id_cuenta=?'];
    $filterParams = [$accountId];
    $filterTypes = 'i';

    if ($q) {
        $where[] = "(c.razon_social LIKE ? OR c.rut LIKE ? OR c.nombre LIKE ? OR c.correo LIKE ? OR c.telefono LIKE ? OR c.codigo LIKE ?)";
        $likeQ = "%$q%";
        for ($i = 0; $i < 6; $i++) { $filterParams[] = $likeQ; $filterTypes .= 's'; }
    }
    if ($estado) {
        $where[] = "c.estado=?";
        $filterParams[] = $estado; $filterTypes .= 's';
    }
    if ($categoria) {
        $where[] = "c.categoria=?";
        $filterParams[] = $categoria; $filterTypes .= 's';
    }
    if ($tipo) {
        $where[] = "c.tipo=?";
        $filterParams[] = $tipo; $filterTypes .= 's';
    }
    if ($ciudad) {
        $where[] = "c.ciudad=?";
        $filterParams[] = $ciudad; $filterTypes .= 's';
    }
    $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    if ($filterParams) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM cliente c $whereClause");
        $stmt->bind_param($filterTypes, ...$filterParams);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
    } else {
        $r = $conn->query("SELECT COUNT(*) AS t FROM cliente c");
    }
    $total = (int)$r->fetch_assoc()['t'];

    $allParams = $filterParams;
    $allTypes = $filterTypes;
    $allParams[] = $limit;
    $allTypes .= 'i';
    $allParams[] = $offset;
    $allTypes .= 'i';

    $sql = "SELECT c.*,
        (SELECT COALESCE(SUM(p.precio_total),0) FROM pedido p WHERE p.id_cliente=c.id_cliente AND p.anulado=0) AS total_compras,
        (SELECT MAX(p.fecha) FROM pedido p WHERE p.id_cliente=c.id_cliente) AS ultima_compra
        FROM cliente c
        $whereClause
        ORDER BY c.razon_social ASC
        LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $items = $r->fetch_all(MYSQLI_ASSOC);
    json(['items' => $items, 'total' => $total, 'page' => $page]);

// ═══ CLIENTE (get single) ═══
case 'cliente_obtener':
case 'cliente':
    $id = (int)($input['id'] ?? $input['id_cliente'] ?? 0);
    if (!$id) json(['error' => 'ID requerido'], 400);

    $stmt = $conn->prepare("SELECT * FROM cliente WHERE id_cliente=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $c = $r->fetch_assoc();
    if (!$c) json(['error' => 'Cliente no encontrado'], 404);

    $stmt = $conn->prepare("SELECT * FROM cliente_contacto WHERE id_cliente=? ORDER BY principal DESC, nombre ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $c['contactos'] = $r->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT * FROM cliente_direccion WHERE id_cliente=? ORDER BY principal DESC, tipo ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $c['direcciones'] = $r->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT * FROM cliente_credito WHERE id_cliente=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $c['credito'] = $r->fetch_assoc();

    $stmt = $conn->prepare("SELECT * FROM cliente_actividad WHERE id_cliente=? ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $c['actividades'] = $r->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT id_factura, numero, tipo, total, saldo, estado, fecha_emision, fecha_vencimiento FROM factura WHERE id_cliente=? ORDER BY id_factura DESC LIMIT 5");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $c['facturas'] = $r->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT id_pedido, precio_total, pago_total, diferencia, fecha, anulado FROM pedido WHERE id_cliente=? ORDER BY id_pedido DESC LIMIT 5");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $c['pedidos'] = $r->fetch_all(MYSQLI_ASSOC);

    json($c);

// ═══ CLIENTE CREAR ═══
case 'cliente_crear':
    requierePermiso('crm','crear');
    $razon = $input['razon_social'] ?? '';
    if (!$razon) json(['error' => 'Razón social requerida'], 400);

    $rut = $input['rut'] ?? '';
    if ($rut) {
        $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE rut=? AND id_cuenta=$accountId");
        $stmt->bind_param("s", $rut);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        if ($r->num_rows) json(['error' => 'El RUT ya está registrado'], 400);
    }

    $stmt = $conn->prepare("INSERT INTO cliente (id_user, id_cuenta, rut, razon_social, nombre, direccion, ciudad, comuna, correo, telefono, giro, tipo, categoria, origen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iissssssssssss",
        $uid,
        $accountId,
        $input['rut'] ?? '',
        $razon,
        $input['nombre'] ?? '',
        $input['direccion'] ?? '',
        $input['ciudad'] ?? '',
        $input['comuna'] ?? '',
        $input['correo'] ?? '',
        $input['telefono'] ?? '',
        $input['giro'] ?? '',
        $input['tipo'] ?? '',
        $input['categoria'] ?? '',
        $input['origen'] ?? ''
    );
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();

    $codigo = 'CLI-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("UPDATE cliente SET codigo=? WHERE id_cliente=?");
    $stmt->bind_param("si", $codigo, $id);
    $stmt->execute();
    $stmt->close();

    crmLog($conn, $uid, 'CREAR', 'cliente', $id, ['razon_social' => $razon, 'codigo' => $codigo]);
    json(['success' => true, 'id_cliente' => $id, 'codigo' => $codigo], 201);

// ═══ CLIENTE EDITAR ═══
case 'cliente_editar':
    requierePermiso('crm','editar');
    $id = (int)($input['id_cliente'] ?? 0);
    if (!$id) json(['error' => 'ID requerido'], 400);

    $allowed = ['rut','razon_social','nombre','direccion','ciudad','comuna','correo','telefono','giro','tipo','categoria','clasificacion','origen','moneda','canal','estado','id_vendedor','lista_precios'];
    $sets = []; $params = []; $types = '';
    foreach ($input as $k => $v) {
        if (in_array($k, $allowed)) {
            $sets[] = "$k = ?";
            $params[] = $v;
            $types .= 's';
        }
    }
    if (empty($sets)) json(['error' => 'Sin campos para actualizar'], 400);

    if (isset($input['rut']) && $input['rut']) {
        $rutEditar = $input['rut'];
        $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE rut=? AND id_cuenta=$accountId AND id_cliente!=?");
        $stmt->bind_param("si", $rutEditar, $id);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        if ($r->num_rows) json(['error' => 'El RUT ya está registrado por otro cliente'], 400);
    }

    $params[] = $id; $types .= 'i';
    $params[] = $accountId; $types .= 'i';
    $stmt = $conn->prepare("UPDATE cliente SET " . implode(', ', $sets) . " WHERE id_cliente = ? AND id_cuenta = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    crmLog($conn, $uid, 'EDITAR', 'cliente', $id, $input);
    json(['success' => true]);

// ═══ CLIENTE ELIMINAR (soft delete) ═══
case 'cliente_eliminar':
    requierePermiso('crm','eliminar');
    $id = (int)($input['id_cliente'] ?? 0);
    if (!$id) json(['error' => 'ID requerido'], 400);

    $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE id_cliente=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);
    $stmt = $conn->prepare("UPDATE cliente SET activo=0, estado='inactivo' WHERE id_cliente=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    crmLog($conn, $uid, 'ELIMINAR', 'cliente', $id, ['activo' => 0]);
    json(['success' => true]);

// ═══ CONTACTO CREAR ═══
case 'contacto_crear':
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    if (!$id_cliente) json(['error' => 'ID cliente requerido'], 400);
    $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE id_cliente=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id_cliente, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);

    $es_principal = (int)($input['principal'] ?? 0);
    if ($es_principal) {
        $stmt = $conn->prepare("UPDATE cliente_contacto SET principal=0 WHERE id_cliente=?");
        $stmt->bind_param("i", $id_cliente);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("INSERT INTO cliente_contacto (id_cliente, nombre, apellido, cargo, correo, telefono, whatsapp, principal) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issssssi",
        $id_cliente,
        $input['nombre'] ?? '',
        $input['apellido'] ?? '',
        $input['cargo'] ?? '',
        $input['correo'] ?? '',
        $input['telefono'] ?? '',
        $input['whatsapp'] ?? '',
        $es_principal
    );
    $stmt->execute();
    $id_contacto = (int)$conn->insert_id;
    $stmt->close();

    crmLog($conn, $uid, 'CREAR', 'cliente_contacto', $id_contacto, ['id_cliente' => $id_cliente, 'nombre' => $input['nombre'] ?? '']);
    json(['success' => true, 'id' => $id_contacto], 201);

// ═══ CONTACTO ELIMINAR ═══
case 'contacto_eliminar':
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error' => 'ID requerido'], 400);

    $stmt = $conn->prepare("SELECT cc.id_contacto FROM cliente_contacto cc JOIN cliente c ON cc.id_cliente=c.id_cliente WHERE cc.id_contacto=? AND c.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $contacto = $r->fetch_assoc();
    if (!$contacto) json(['error' => 'No autorizado'], 403);

    $stmt = $conn->prepare("DELETE FROM cliente_contacto WHERE id_contacto=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    crmLog($conn, $uid, 'ELIMINAR', 'cliente_contacto', $id, $contacto);
    json(['success' => true]);

// ═══ DIRECCION CREAR ═══
case 'direccion_crear':
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    if (!$id_cliente) json(['error' => 'ID cliente requerido'], 400);
    $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE id_cliente=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id_cliente, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);

    $es_principal = (int)($input['principal'] ?? 0);
    if ($es_principal) {
        $stmt = $conn->prepare("UPDATE cliente_direccion SET principal=0 WHERE id_cliente=?");
        $stmt->bind_param("i", $id_cliente);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("INSERT INTO cliente_direccion (id_cliente, tipo, direccion, ciudad, comuna, principal) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("issssi",
        $id_cliente,
        $input['tipo'] ?? 'facturacion',
        $input['direccion'] ?? '',
        $input['ciudad'] ?? '',
        $input['comuna'] ?? '',
        $es_principal
    );
    $stmt->execute();
    $id_dir = (int)$conn->insert_id;
    $stmt->close();

    crmLog($conn, $uid, 'CREAR', 'cliente_direccion', $id_dir, ['id_cliente' => $id_cliente, 'tipo' => $input['tipo'] ?? 'facturacion']);
    json(['success' => true, 'id' => $id_dir], 201);

// ═══ CREDITO CREAR/EDITAR (upsert) ═══
case 'credito_crear':
case 'credito_editar':
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    if (!$id_cliente) json(['error' => 'ID cliente requerido'], 400);
    $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE id_cliente=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id_cliente, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);

    $limite = (int)($input['limite_credito'] ?? 0);
    $dias = (int)($input['dias_credito'] ?? 30);
    $condiciones = $input['condiciones_pago'] ?? '';
    $bloqueado = (int)($input['bloqueado'] ?? 0);

    $stmt = $conn->prepare("SELECT id_credito FROM cliente_credito WHERE id_cliente=?");
    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if ($r->num_rows) {
        $stmt = $conn->prepare("UPDATE cliente_credito SET limite_credito=?, dias_credito=?, condiciones_pago=?, bloqueado=? WHERE id_cliente=?");
        $stmt->bind_param("iisii", $limite, $dias, $condiciones, $bloqueado, $id_cliente);
        $stmt->execute();
        $stmt->close();
        crmLog($conn, $uid, 'EDITAR', 'cliente_credito', $id_cliente, $input);
    } else {
        $stmt = $conn->prepare("INSERT INTO cliente_credito (id_cliente, limite_credito, dias_credito, condiciones_pago, bloqueado) VALUES (?,?,?,?,?)");
        $stmt->bind_param("iiisi", $id_cliente, $limite, $dias, $condiciones, $bloqueado);
        $stmt->execute();
        $stmt->close();
        crmLog($conn, $uid, 'CREAR', 'cliente_credito', $id_cliente, $input);
    }
    json(['success' => true]);

// ═══ ACTIVIDAD CREAR ═══
case 'actividad_crear':
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    if (!$id_cliente) json(['error' => 'ID cliente requerido'], 400);
    $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE id_cliente=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id_cliente, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);

    $stmt = $conn->prepare("INSERT INTO cliente_actividad (id_cliente, id_user, tipo, asunto, descripcion, fecha_planificada) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("iissss",
        $id_cliente,
        $uid,
        $input['tipo'] ?? 'nota',
        $input['asunto'] ?? '',
        $input['descripcion'] ?? '',
        $input['fecha_planificada'] ?? null
    );
    $stmt->execute();
    $id_act = (int)$conn->insert_id;
    $stmt->close();

    crmLog($conn, $uid, 'CREAR', 'cliente_actividad', $id_act, ['id_cliente' => $id_cliente, 'asunto' => $input['asunto'] ?? '', 'tipo' => $input['tipo'] ?? 'nota']);
    json(['success' => true, 'id' => $id_act], 201);

// ═══ ACTIVIDAD COMPLETAR ═══
case 'actividad_completar':
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT a.id_actividad FROM cliente_actividad a JOIN cliente c ON a.id_cliente=c.id_cliente WHERE a.id_actividad=? AND c.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);

    $stmt = $conn->prepare("UPDATE cliente_actividad SET estado='completada', fecha_realizada=NOW() WHERE id_actividad=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    crmLog($conn, $uid, 'COMPLETAR', 'cliente_actividad', $id);
    json(['success' => true]);

// ═══ ACTIVIDADES (list) ═══
case 'actividades':
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = min(200, (int)($input['limit'] ?? 50));
    $offset = ($page-1)*$limit;
    $r = $conn->query("SELECT COUNT(*) as t FROM cliente_actividad a JOIN cliente c ON c.id_cliente=a.id_cliente WHERE c.id_cuenta=$accountId");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt = $conn->prepare("SELECT a.*, c.nombre as cliente_nombre, c.razon_social FROM cliente_actividad a JOIN cliente c ON a.id_cliente=c.id_cliente WHERE c.id_cuenta=$accountId ORDER BY a.fecha_planificada DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);
    break;

// ═══ CREDITOS (list) ═══
case 'creditos':
    $items = []; 
    $r = $conn->query("SELECT cr.*, c.nombre as cliente, c.razon_social FROM cliente_credito cr JOIN cliente c ON cr.id_cliente=c.id_cliente WHERE c.id_cuenta=$accountId ORDER BY c.razon_social");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);
    break;

// ═══ ETIQUETAS ═══
case 'etiquetas':
    $r = $conn->query("SELECT * FROM cliente_etiqueta WHERE id_cuenta=$accountId ORDER BY nombre");
    $items = [];
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

// ═══ CLIENTE ETIQUETAS ═══
case 'cliente_etiquetas':
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    if (!$id_cliente) json(['error' => 'ID cliente requerido'], 400);
    requireTenantEntity($conn, tenantContext($uid), 'cliente', $id_cliente);

    $stmt = $conn->prepare("SELECT e.*, CASE WHEN rel.id_rel IS NOT NULL THEN 1 ELSE 0 END AS asignado FROM cliente_etiqueta e LEFT JOIN cliente_etiqueta_rel rel ON e.id_etiqueta=rel.id_etiqueta AND rel.id_cliente=? WHERE e.id_cuenta=$accountId ORDER BY e.nombre");
    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $items = [];
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

// ═══ CLIENTE ETIQUETA TOGGLE ═══
case 'cliente_etiqueta_toggle':
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    $id_etiqueta = (int)($input['id_etiqueta'] ?? 0);
    if (!$id_cliente || !$id_etiqueta) json(['error' => 'Datos requeridos'], 400);
    $stmt = $conn->prepare("SELECT id_cliente FROM cliente WHERE id_cliente=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id_cliente, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);
    $stmt = $conn->prepare("SELECT id_etiqueta FROM cliente_etiqueta WHERE id_etiqueta=? AND id_cuenta=?");
    $stmt->bind_param("ii", $id_etiqueta, $accountId);
    $stmt->execute();
    $tagResult = $stmt->get_result();
    $stmt->close();
    if (!$tagResult->num_rows) json(['error'=>'No autorizado'], 403);

    $stmt = $conn->prepare("SELECT id_rel FROM cliente_etiqueta_rel WHERE id_cliente=? AND id_etiqueta=?");
    $stmt->bind_param("ii", $id_cliente, $id_etiqueta);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if ($r->num_rows) {
        $stmt = $conn->prepare("DELETE FROM cliente_etiqueta_rel WHERE id_cliente=? AND id_etiqueta=?");
        $stmt->bind_param("ii", $id_cliente, $id_etiqueta);
        $stmt->execute();
        $stmt->close();
        crmLog($conn, $uid, 'QUITAR_ETIQUETA', 'cliente_etiqueta_rel', null, ['id_cliente' => $id_cliente, 'id_etiqueta' => $id_etiqueta]);
        json(['success' => true, 'estado' => 'quitada']);
    } else {
        $stmt = $conn->prepare("INSERT INTO cliente_etiqueta_rel (id_cliente, id_etiqueta) VALUES (?, ?)");
        $stmt->bind_param("ii", $id_cliente, $id_etiqueta);
        $stmt->execute();
        $stmt->close();
        crmLog($conn, $uid, 'ASIGNAR_ETIQUETA', 'cliente_etiqueta_rel', null, ['id_cliente' => $id_cliente, 'id_etiqueta' => $id_etiqueta]);
        json(['success' => true, 'estado' => 'asignada']);
    }

// ═══ REPORTE ABC ═══
case 'reporte_abc':
    $r = $conn->query("SELECT c.id_cliente, c.nombre, c.razon_social, COALESCE(SUM(p.precio_total),0) AS total_compras FROM cliente c JOIN pedido p ON c.id_cliente=p.id_cliente AND p.id_cuenta=c.id_cuenta WHERE c.id_cuenta=$accountId AND p.anulado=0 GROUP BY c.id_cliente, c.nombre, c.razon_social ORDER BY total_compras DESC");
    $items = [];
    while ($f = $r->fetch_assoc()) $items[] = $f;

    $gran_total = 0;
    foreach ($items as $it) $gran_total += $it['total_compras'];

    $acum = 0;
    foreach ($items as &$it) {
        $acum += $it['total_compras'];
        $it['porcentaje'] = $gran_total > 0 ? round(($it['total_compras'] / $gran_total) * 100, 2) : 0;
        $it['porcentaje_acumulado'] = $gran_total > 0 ? round(($acum / $gran_total) * 100, 2) : 0;
        $it['clasificacion'] = $it['porcentaje_acumulado'] <= 80 ? 'A' : ($it['porcentaje_acumulado'] <= 95 ? 'B' : 'C');
    }
    unset($it);

    json(['items' => $items, 'gran_total' => $gran_total]);

// ═══ REPORTE MOROSIDAD ═══
case 'reporte_morosidad':
    $r = $conn->query("SELECT c.id_cliente, c.razon_social, c.nombre, c.rut, c.correo, c.telefono, f.id_factura, f.numero, f.tipo, f.total, f.saldo, f.estado, f.fecha_emision, f.fecha_vencimiento, DATEDIFF(CURDATE(), f.fecha_vencimiento) AS dias_vencidos FROM factura f JOIN cliente c ON f.id_cliente=c.id_cliente WHERE f.id_cuenta=$accountId AND c.id_cuenta=$accountId AND (f.estado='VENCIDA' OR f.estado='PENDIENTE') ORDER BY f.fecha_vencimiento ASC");
    $items = [];
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

// ═══ AUDITORIA ═══
case 'auditoria':
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = min(200, (int)($input['limit'] ?? 100));
    $offset = ($page - 1) * $limit;

    $r = $conn->query("SELECT COUNT(*) AS t FROM cliente_auditoria a JOIN usuario u ON u.id_user=a.id_user WHERE u.id_cuenta=$accountId");
    $total = (int)$r->fetch_assoc()['t'];

    $stmt = $conn->prepare("SELECT a.*, u.nombre AS user_nombre FROM cliente_auditoria a LEFT JOIN usuario u ON a.id_user=u.id_user WHERE u.id_cuenta=$accountId ORDER BY a.created_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $items = [];
    while ($f = $r->fetch_assoc()) $items[] = $f;

    json(['items' => $items, 'total' => $total, 'page' => $page]);

// ═══ DEFAULT ═══
default:
    json(['error' => 'Acción no válida: ' . $accion], 400);
}
