<?php
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_supervisor.php';
require_once __DIR__ . '/_pos_integrity.php';
require_once __DIR__ . '/_pos_returns.php';
require_once __DIR__ . '/_promotion_engine.php';
$uid = requireUser();
$conn = getDB();
$tenant = tenantContext($uid);
$method = $_SERVER['REQUEST_METHOD'];

function buildCuentaFilter($conn, $uid, $column) {
    $ids = getUsuariosCuentaIds($conn, $uid);
    if (empty($ids)) {
        return ['sql' => "{$column} IN (0)", 'params' => [], 'types' => ''];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return ['sql' => "{$column} IN ({$ph})", 'params' => $ids, 'types' => str_repeat('i', count($ids))];
}

function getOpenSesion($conn, $uid) {
    $sql = "SELECT * FROM sesion WHERE id_user = ? AND fecha_cierre IS NULL ORDER BY id_sesion DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function getOpenCaja($conn, $uid) {
    $sql = "SELECT c.*,s.id_sucursal FROM pos_caja c LEFT JOIN sucursal s ON s.id_cuenta=c.id_cuenta AND s.activo=1 AND (s.nombre=c.sucursal OR s.codigo=c.sucursal) WHERE c.id_user = ? AND c.estado = 'ABIERTA' ORDER BY c.id_caja DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function insertAuditoria($conn, $uid, $accion, $detalle, $idRef = null, $tablaRef = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $sql = "INSERT INTO pos_auditoria (id_user, accion, detalle, id_referencia, tabla_referencia, ip)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return;
        $stmt->bind_param('ississ', $uid, $accion, $detalle, $idRef, $tablaRef, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        // Audit telemetry never interrupts checkout or cash operations.
    }
}

// ============================================================
// ROUTER
// ============================================================
if ($method === 'GET') {
    // Accept both names while older POS clients still send `accion`.
    $action = $_GET['action'] ?? $_GET['accion'] ?? '';
    if ($action !== 'permisos_usuario' && !verificarPermiso('pos','ver')) {
        json(['error'=>true,'message'=>'Permiso denegado: pos.ver'],403);
    }
    if ($action === 'clientes') requirePermission('pos','asociar_cliente');

    switch ($action) {
        case 'dashboard':           GET_dashboard();          break;
        case 'productos':           GET_productos();          break;
        case 'clientes':            GET_clientes();           break;
        case 'caja_estado':         GET_caja_estado();        break;
        case 'ventas_hoy':          GET_ventas_hoy();         break;
        case 'historial':           GET_historial();          break;
        case 'promociones':         GET_promociones();        break;
        case 'cotizaciones':        GET_cotizaciones();       break;
        case 'reservas':            GET_reservas();           break;
        case 'venta_detalle':       GET_venta_detalle();      break;
        case 'documento':           GET_documento();          break;
        case 'reporte_ventas_hora': GET_reporte_ventas_hora();break;
        case 'reporte_ventas_cajero':GET_reporte_ventas_cajero();break;
        case 'reporte_productos_top':GET_reporte_productos_top();break;
        case 'config_boleta':       GET_config_boleta();      break;
        case 'permisos_usuario':    GET_permisos_usuario();   break;
        default:
            json(['error' => true, 'message' => 'Acción no válida'], 400);
    }
} elseif ($method === 'POST') {
    requirePermission('pos', 'ver');
    $data = getJsonInput();
    if (!$data) {
        json(['error' => true, 'message' => 'Datos JSON requeridos'], 400);
    }
    // `action` is canonical; `accion` keeps cached/legacy clients working.
    $action = $data->action ?? $data->accion ?? '';
    $postPermissions = [
        'caja_movimiento'=>['pos','abrir_caja'],
        'cliente_crear'=>['crm','crear'],
        'promociones_evaluar'=>['pos','realizar_venta'],
        'promocion_validar'=>['pos','realizar_venta'],
        'cotizacion_crear'=>['pos','realizar_venta'],
        'cotizacion_eliminar'=>['pos','realizar_venta'],
        'reserva_crear'=>['pos','realizar_venta'],
        'reserva_cumplir'=>['pos','realizar_venta'],
        'reserva_cancelar'=>['pos','realizar_venta'],
    ];
    if (isset($postPermissions[$action])) requirePermission($postPermissions[$action][0],$postPermissions[$action][1]);

    switch ($action) {
        case 'caja_abrir':          if (!verificarPermiso('pos','abrir_caja')) json(['error'=>true,'message'=>'Permiso denegado'],403); POST_caja_abrir($data); break;
        case 'caja_cerrar':         if (!verificarPermiso('pos','cerrar_caja')) json(['error'=>true,'message'=>'Permiso denegado'],403); POST_caja_cerrar($data); break;
        case 'caja_movimiento':     POST_caja_movimiento($data);  break;
        case 'venta_crear':         if (!verificarPermiso('pos','realizar_venta')) json(['error'=>true,'message'=>'Permiso denegado'],403); POST_venta_crear($data); break;
        case 'venta_anular':        POST_venta_anular($data); break;
        case 'cliente_crear':       POST_cliente_crear($data);    break;
        case 'promocion_crear':     if (!verificarPermiso('pos','crear_promocion')) json(['error'=>true,'message'=>'Permiso denegado: pos.crear_promocion'],403); POST_promocion_crear($data);  break;
        case 'promociones_evaluar': POST_promociones_evaluar($data);break;
        case 'promocion_validar':   POST_promocion_validar($data);break;
        case 'promocion_eliminar':  if (!verificarPermiso('pos','crear_promocion')) json(['error'=>true,'message'=>'Permiso denegado: pos.crear_promocion'],403); POST_promocion_eliminar($data);break;
        case 'cotizacion_crear':    POST_cotizacion_crear($data);  break;
        case 'cotizacion_convertir':json(['error'=>true,'message'=>'Actualice el POS: la cotización debe cargarse al carrito y cobrarse por el flujo normal.'],409);break;
        case 'cotizacion_eliminar': POST_cotizacion_eliminar($data);break;
        case 'reserva_crear':       POST_reserva_crear($data);     break;
        case 'reserva_cumplir':     POST_reserva_cumplir($data);   break;
        case 'reserva_cancelar':    POST_reserva_cancelar($data);  break;
        case 'devolucion_crear':    POST_devolucion_crear_v2($data);  break;
        default:
            json(['error' => true, 'message' => 'Acción no válida'], 400);
    }
} else {
    json(['error' => true, 'message' => 'Método no permitido'], 405);
}

// ============================================================
// GET HANDLERS
// ============================================================

function GET_dashboard() {
    global $conn, $uid, $tenant;

    $cuenta = buildCuentaFilter($conn, $uid, 's.id_user');

    // ventas_hoy
    $sql = "SELECT COUNT(*) AS ventas, COALESCE(SUM(p.precio_total),0) AS total, COALESCE(SUM(p.pago_total),0) AS pago
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE DATE(p.fecha) = CURDATE() AND s.fecha_cierre IS NULL AND p.anulado = 0
            AND {$cuenta['sql']}";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $ventasHoy = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ventas_mes
    $sql = "SELECT COUNT(*) AS ventas, COALESCE(SUM(p.precio_total),0) AS total
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE MONTH(p.fecha) = MONTH(CURDATE()) AND YEAR(p.fecha) = YEAR(CURDATE()) AND p.anulado = 0
            AND {$cuenta['sql']}";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $ventasMes = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // cajas_abiertas
    $num = 0;
    $sql = "SELECT COUNT(*) AS total FROM pos_caja WHERE id_user = ? AND estado = 'ABIERTA'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $num = (int) $row['total'];
    }
    $stmt->close();

    // stock_bajo
    $stockBajo = 0;
    $sql = "SELECT COUNT(*) AS total FROM producto WHERE id_user = ? AND activo = 1 AND cantidad <= stock_minimo AND cantidad > 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stockBajo = (int) $row['total'];
    }
    $stmt->close();

    // sin_stock
    $sinStock = 0;
    $sql = "SELECT COUNT(*) AS total FROM producto WHERE id_user = ? AND activo = 1 AND cantidad <= 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $sinStock = (int) $row['total'];
    }
    $stmt->close();

    // promociones_activas
    $promoActivas = 0;
    $sql = "SELECT COUNT(*) AS total FROM pos_promocion WHERE id_user = ? AND activo = 1 AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $promoActivas = (int) $row['total'];
    }
    $stmt->close();

    // total_clientes (cuenta scope)
    $cuentaCli = buildCuentaFilter($conn, $uid, 'id_user');
    $totalClientes = 0;
    $sql = "SELECT COUNT(*) AS total FROM cliente WHERE {$cuentaCli['sql']}";
    $stmt = $conn->prepare($sql);
    if ($cuentaCli['params']) {
        $stmt->bind_param($cuentaCli['types'], ...$cuentaCli['params']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $totalClientes = (int) $row['total'];
    }
    $stmt->close();

    // devoluciones_hoy (cuenta scope)
    $devolucionesHoy = 0;
    $sql = "SELECT COUNT(*) AS total FROM pos_devolucion WHERE {$cuentaCli['sql']} AND DATE(fecha) = CURDATE()";
    $stmt = $conn->prepare($sql);
    if ($cuentaCli['params']) {
        $stmt->bind_param($cuentaCli['types'], ...$cuentaCli['params']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $devolucionesHoy = (int) $row['total'];
    }
    $stmt->close();

    // anuladas_hoy (cuenta scope)
    $anuladasHoy = 0;
    $sql = "SELECT COUNT(*) AS total FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE p.anulado = 1 AND DATE(p.fecha) = CURDATE()
            AND {$cuenta['sql']}";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $anuladasHoy = (int) $row['total'];
    }
    $stmt->close();

    json([
        'ventas_hoy'         => $ventasHoy,
        'ventas_mes'         => $ventasMes,
        'cajas_abiertas'     => $num,
        'stock_bajo'         => $stockBajo,
        'sin_stock'          => $sinStock,
        'promociones_activas'=> $promoActivas,
        'total_clientes'     => $totalClientes,
        'devoluciones_hoy'   => $devolucionesHoy,
        'anuladas_hoy'       => $anuladasHoy,
    ]);
}

function GET_productos() {
    global $conn, $uid, $tenant;

    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = max(1, min(100, (int) ($_GET['por_pagina'] ?? 20)));
    $offset    = ($pagina - 1) * $porPagina;
    $search    = $_GET['q'] ?? '';
    $cat       = $_GET['cat'] ?? '';

    $cuenta = buildCuentaFilter($conn, $uid, 'p.id_user');

    $where = "{$cuenta['sql']} AND p.activo = 1";
    $params = $cuenta['params'];
    $types  = $cuenta['types'];

    if ($search !== '') {
        $searchParam = "%{$search}%";
        $where .= " AND (p.nombre_producto LIKE ? OR p.codigo_de_barras LIKE ? OR p.categoria LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types  .= 'sss';
    }
    if ($cat !== '') {
        $where .= " AND p.categoria = ?";
        $params[] = $cat;
        $types  .= 's';
    }

    // Count
    $countSql = "SELECT COUNT(*) AS total FROM producto p WHERE {$where}";
    $stmt = $conn->prepare($countSql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $totalRow = $stmt->get_result()->fetch_assoc();
    $total = (int) $totalRow['total'];
    $stmt->close();

    // Data
    $dataSql = "SELECT p.id_producto, p.nombre_producto, p.precio_venta, p.codigo_de_barras, p.cantidad, p.categoria,
                       p.sku, p.tipo, p.tipo_venta, p.imagen,
                       um.abreviatura AS unidad_abrev
                FROM producto p
                LEFT JOIN unidad_medida um ON p.id_unidad = um.id_unidad
                WHERE {$where}
                ORDER BY p.nombre_producto ASC
                LIMIT ? OFFSET ?";
    $dataParams = $params;
    $dataTypes  = $types;
    $limitVar   = $porPagina;
    $offsetVar  = $offset;
    $dataParams[] = $limitVar;
    $dataParams[] = $offsetVar;
    $dataTypes  .= 'ii';

    $stmt = $conn->prepare($dataSql);
    if ($dataParams) {
        $stmt->bind_param($dataTypes, ...$dataParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $productos[] = $row;
    }
    $stmt->close();

    // Categorías
    $catParams = $cuenta['params'];
    $catTypes  = $cuenta['types'];
    $catSql = "SELECT DISTINCT categoria FROM producto p WHERE {$cuenta['sql']} AND p.activo = 1 AND p.categoria IS NOT NULL AND p.categoria != '' ORDER BY categoria";
    $stmt = $conn->prepare($catSql);
    if ($catParams) {
        $stmt->bind_param($catTypes, ...$catParams);
    }
    $stmt->execute();
    $catResult = $stmt->get_result();
    $categorias = [];
    while ($row = $catResult->fetch_assoc()) {
        $categorias[] = $row['categoria'];
    }
    $stmt->close();

    json([
        'productos'  => $productos,
        'total'      => $total,
        'pagina'     => $pagina,
        'categorias' => $categorias,
    ]);
}

function GET_clientes() {
    global $conn, $uid, $tenant;
    $search = $_GET['q'] ?? '';
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = max(1, min(100, (int) ($_GET['por_pagina'] ?? 20)));
    $offset = ($pagina - 1) * $porPagina;

    $where = 'id_cuenta=? AND COALESCE(activo,1)=1';
    $params = [$tenant->accountId];
    $types  = 'i';

    if ($search !== '') {
        $searchParam = "%{$search}%";
        $where .= " AND (nombre LIKE ? OR rut LIKE ? OR razon_social LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types  .= 'sss';
    }

    $countSql = "SELECT COUNT(*) AS total FROM cliente WHERE {$where}";
    $stmt = $conn->prepare($countSql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $dataParams   = $params;
    $dataTypes    = $types;
    $dataParams[] = $porPagina;
    $dataParams[] = $offset;
    $dataTypes   .= 'ii';

    $sql = "SELECT * FROM cliente WHERE {$where} ORDER BY nombre ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($dataParams) {
        $stmt->bind_param($dataTypes, ...$dataParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $clientes = [];
    while ($row = $result->fetch_assoc()) {
        $clientes[] = $row;
    }
    $stmt->close();

    json(['clientes' => $clientes, 'total' => $total, 'pagina' => $pagina]);
}

function GET_caja_estado() {
    global $conn, $uid, $tenant;
    $caja = getOpenCaja($conn, $uid);
    if (!$caja) {
        json(['abierta' => false, 'caja' => null, 'movimientos' => []]);
    }

    $movimientos = [];
    $sql = "SELECT * FROM pos_movimiento_caja WHERE id_caja = ? ORDER BY fecha DESC LIMIT 10";
    $stmt = $conn->prepare($sql);
    $idCaja = (int) $caja['id_caja'];
    $stmt->bind_param('i', $idCaja);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $movimientos[] = $row;
    }
    $stmt->close();

    json(['abierta' => true, 'caja' => $caja, 'movimientos' => $movimientos]);
}

function GET_ventas_hoy() {
    global $conn, $uid, $tenant;
    $cuenta = buildCuentaFilter($conn, $uid, 's.id_user');
    $sql = "SELECT p.*, s.empleado
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE DATE(p.fecha) = CURDATE() AND p.anulado = 0 AND {$cuenta['sql']}
            ORDER BY p.fecha DESC";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $ventas = [];
    $pedidoIds = [];
    while ($row = $result->fetch_assoc()) {
        $ventas[] = $row;
        $pedidoIds[] = (int) $row['id_pedido'];
    }
    $stmt->close();

    // Items and pagos for each venta
    foreach ($ventas as &$venta) {
        $pid = (int) $venta['id_pedido'];
        $venta['items'] = [];
        $sql = "SELECT dp.*, p.nombre_producto, p.codigo_de_barras
                FROM detalle_pedido dp
                JOIN producto p ON dp.id_producto = p.id_producto
                WHERE dp.id_pedido = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($item = $r->fetch_assoc()) {
            $venta['items'][] = $item;
        }
        $stmt->close();

        $venta['pagos'] = [];
        $sql = "SELECT * FROM metodo_de_pago WHERE id_pedido = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($pago = $r->fetch_assoc()) {
            $venta['pagos'][] = $pago;
        }
        $stmt->close();
    }
    unset($venta);

    json(['ventas' => $ventas, 'total' => count($ventas)]);
}

function GET_historial() {
    global $conn, $uid, $tenant;
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = max(1, min(100, (int) ($_GET['por_pagina'] ?? 20)));
    $offset    = ($pagina - 1) * $porPagina;
    $cuenta = buildCuentaFilter($conn, $uid, 's.id_user');

    $countSql = "SELECT COUNT(*) AS total FROM pedido p
                 JOIN sesion s ON p.id_sesion = s.id_sesion
                 WHERE {$cuenta['sql']}";
    $stmt = $conn->prepare($countSql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $sql = "SELECT p.*, s.empleado
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE {$cuenta['sql']}
            ORDER BY p.fecha DESC
            LIMIT ? OFFSET ?";
    $dataParams   = $cuenta['params'];
    $dataTypes    = $cuenta['types'];
    $dataParams[] = $porPagina;
    $dataParams[] = $offset;
    $dataTypes   .= 'ii';

    $stmt = $conn->prepare($sql);
    if ($dataParams) {
        $stmt->bind_param($dataTypes, ...$dataParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $ventas = [];
    while ($row = $result->fetch_assoc()) {
        $ventas[] = $row;
    }
    $stmt->close();

    json(['ventas' => $ventas, 'total' => $total, 'pagina' => $pagina]);
}

function GET_promociones() {
    global $conn, $uid, $tenant;

    $sql = "SELECT pp.*,
                   GROUP_CONCAT(DISTINCT CONCAT(ppp.id_producto, ':', pr.nombre_producto) SEPARATOR '||') AS productos
            FROM pos_promocion pp
            LEFT JOIN pos_promocion_producto ppp ON pp.id_promocion = ppp.id_promocion
            LEFT JOIN producto pr ON ppp.id_producto = pr.id_producto
            WHERE pp.id_cuenta = ?
            GROUP BY pp.id_promocion
            ORDER BY pp.id_promocion DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $tenant->accountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $promociones = [];
    while ($row = $result->fetch_assoc()) {
        $promociones[] = $row;
    }
    $stmt->close();

    json(['promociones' => $promociones]);
}

function GET_cotizaciones() {
    global $conn, $uid, $tenant;

    $sql = "SELECT * FROM pos_cotizacion WHERE id_cuenta = ? ORDER BY id_cotizacion DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $tenant->accountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $cotizaciones = [];
    while ($row = $result->fetch_assoc()) {
        $cotizaciones[] = $row;
    }
    $stmt->close();

    // Attach items to each
    foreach ($cotizaciones as &$cot) {
        $cid = (int) $cot['id_cotizacion'];
        $cot['items'] = [];
        $sql = "SELECT d.*,p.cantidad stock_actual,p.tipo_venta,p.unidad_abrev,p.activo producto_activo
                FROM pos_cotizacion_detalle d LEFT JOIN producto p ON p.id_producto=d.id_producto AND p.id_cuenta=?
                WHERE d.id_cotizacion = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $tenant->accountId,$cid);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($item = $r->fetch_assoc()) {
            $cot['items'][] = $item;
        }
        $stmt->close();
    }
    unset($cot);

    json(['cotizaciones' => $cotizaciones]);
}

function GET_reservas() {
    global $conn, $uid, $tenant;

    $sql = "SELECT * FROM pos_reserva WHERE id_user = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservas = [];
    while ($row = $result->fetch_assoc()) {
        $reservas[] = $row;
    }
    $stmt->close();

    json(['reservas' => $reservas]);
}

function GET_venta_detalle() {
    global $conn, $uid, $tenant;
    $pedidoId = (int) ($_GET['id'] ?? 0);
    if ($pedidoId <= 0) {
        json(['error' => true, 'message' => 'ID de pedido requerido'], 400);
    }

    $sql = "SELECT p.*, s.empleado, s.id_user AS sesion_user
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE p.id_pedido = ? AND p.id_cuenta = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $pedidoId, $tenant->accountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $venta = $result->fetch_assoc();
    $stmt->close();

    if (!$venta) {
        json(['error' => true, 'message' => 'Pedido no encontrado'], 404);
    }
    if ((int)$venta['sesion_user'] !== $uid && !verificarPermiso('ventas','ver_todos')) {
        json(['error' => true, 'message' => 'No autorizado para ver esta venta'], 403);
    }

    // Items
    $sql = "SELECT dp.*, p.nombre_producto, p.codigo_de_barras,
                   COALESCE(SUM(dd.cantidad),0) AS cantidad_devuelta,
                   dp.cantidad_pedida-COALESCE(SUM(dd.cantidad),0) AS cantidad_disponible_devolucion
            FROM detalle_pedido dp
            JOIN producto p ON dp.id_producto = p.id_producto
            LEFT JOIN pos_devolucion_detalle dd ON dd.id_detalle_pedido=dp.id_detalle_pedido
            WHERE dp.id_pedido = ? GROUP BY dp.id_detalle_pedido";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $result = $stmt->get_result();
    $venta['items'] = [];
    while ($row = $result->fetch_assoc()) {
        $venta['items'][] = $row;
    }
    $stmt->close();

    // Pagos
    $sql = "SELECT * FROM metodo_de_pago WHERE id_pedido = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $result = $stmt->get_result();
    $venta['pagos'] = [];
    while ($row = $result->fetch_assoc()) {
        $venta['pagos'][] = $row;
    }
    $stmt->close();

    json($venta);
}

function GET_documento() {
    global $conn,$uid,$tenant;
    $orderId=(int)($_GET['id']??0);
    if($orderId<=0)json(['error'=>true,'message'=>'ID de pedido requerido'],400);
    $stmt=$conn->prepare("SELECT d.contenido_json,s.id_user sesion_user
        FROM pos_documento_snapshot d JOIN pedido p ON p.id_pedido=d.id_pedido
        JOIN sesion s ON s.id_sesion=p.id_sesion
        WHERE d.id_pedido=? AND d.id_cuenta=? LIMIT 1");
    $stmt->bind_param('ii',$orderId,$tenant->accountId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row)json(['error'=>true,'message'=>'Documento no encontrado'],404);
    if((int)$row['sesion_user']!==$uid&&!verificarPermiso('ventas','ver_todos'))json(['error'=>true,'message'=>'No autorizado'],403);
    $document=json_decode($row['contenido_json'],true);
    if(!is_array($document))json(['error'=>true,'message'=>'Documento almacenado inválido'],500);
    $stmt=$conn->prepare('SELECT logo FROM config_boleta WHERE id_cuenta=? AND activo=1 ORDER BY id_config DESC LIMIT 1');
    $stmt->bind_param('i',$tenant->accountId);$stmt->execute();$config=$stmt->get_result()->fetch_assoc();$stmt->close();
    insertAuditoria($conn,$uid,'documento_consultar',"Documento de venta #{$orderId} consultado para impresión",$orderId,'pedido');
    json(['documento'=>$document,'logo'=>$config['logo']??'']);
}

function GET_reporte_ventas_hora() {
    global $conn, $uid, $tenant;
    $cuenta = buildCuentaFilter($conn, $uid, 's.id_user');

    $sql = "SELECT HOUR(p.fecha) AS hora, COUNT(*) AS total_ventas, COALESCE(SUM(p.precio_total),0) AS total
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE DATE(p.fecha) = CURDATE() AND p.anulado = 0 AND {$cuenta['sql']}
            GROUP BY HOUR(p.fecha)
            ORDER BY hora ASC";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    json(['reporte' => $data]);
}

function GET_reporte_ventas_cajero() {
    global $conn, $uid, $tenant;
    $cuenta = buildCuentaFilter($conn, $uid, 's.id_user');

    $sql = "SELECT s.empleado, COUNT(*) AS total_ventas, COALESCE(SUM(p.precio_total),0) AS total
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE DATE(p.fecha) = CURDATE() AND p.anulado = 0 AND {$cuenta['sql']}
            GROUP BY s.empleado
            ORDER BY total DESC";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    json(['reporte' => $data]);
}

function GET_reporte_productos_top() {
    global $conn, $uid, $tenant;
    $cuenta = buildCuentaFilter($conn, $uid, 'p.id_user');

    $sql = "SELECT pr.id_producto, pr.nombre_producto, SUM(dp.cantidad_pedida) AS total_cantidad, SUM(dp.precio_total) AS total_venta
            FROM detalle_pedido dp
            JOIN pedido pe ON dp.id_pedido = pe.id_pedido
            JOIN producto pr ON dp.id_producto = pr.id_producto
            WHERE pe.anulado = 0 AND {$cuenta['sql']}
            GROUP BY dp.id_producto
            ORDER BY total_cantidad DESC
            LIMIT 20";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    json(['reporte' => $data]);
}

function GET_config_boleta() {
    global $conn, $uid, $tenant;

    $sql = "SELECT * FROM config_boleta WHERE id_cuenta = ? AND activo = 1 ORDER BY id_config DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $tenant->accountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $config = $result->fetch_assoc();
    $stmt->close();

    json(['config' => $config ?: null]);
}

function GET_permisos_usuario() {
    global $conn, $uid, $tenant;

    $sql = "SELECT p.modulo, p.accion, p.descripcion
            FROM permiso p
            JOIN rol_permiso rp ON p.id_permiso = rp.id_permiso
            JOIN usuario_rol ur ON rp.id_rol = ur.id_rol
            WHERE ur.id_user = ?
            ORDER BY p.modulo, p.accion";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $permisos = [];
    $modulos = [];
    while ($row = $result->fetch_assoc()) {
        $mod = $row['modulo'];
        if (!isset($modulos[$mod])) {
            $modulos[$mod] = [];
        }
        $modulos[$mod][] = [
            'accion'      => $row['accion'],
            'descripcion' => $row['descripcion'],
        ];
    }
    $stmt->close();

    foreach ($modulos as $modulo => $acciones) {
        $permisos[] = ['modulo' => $modulo, 'acciones' => $acciones];
    }

    json(['permisos' => $permisos]);
}

// ============================================================
// POST HANDLERS
// ============================================================

function POST_caja_abrir($data) {
    global $conn, $uid, $tenant;

    $codigo         = strtoupper(trim((string)($data->codigo ?? '')));
    $nombreCaja     = $data->nombre ?? $data->nombre_caja ?? 'Caja Principal';
    $sucursal       = $data->sucursal ?? 'Principal';
    $montoApertura  = (int) ($data->monto_apertura ?? 0);
    if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $codigo)) {
        json(['error'=>true,'message'=>'Código de caja inválido. Use letras, números, guion o guion bajo.'],400);
    }
    if ($montoApertura<0) json(['error'=>true,'message'=>'El monto de apertura no puede ser negativo.'],400);

    // Get open caja with FOR UPDATE
    $conn->begin_transaction();

    try {
        $sql="INSERT INTO pos_caja_fisica(id_cuenta,codigo,nombre,sucursal,activo) VALUES(?,?,?,?,1)
              ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),sucursal=VALUES(sucursal)";
        $stmt=$conn->prepare($sql);$stmt->bind_param('isss',$tenant->accountId,$codigo,$nombreCaja,$sucursal);$stmt->execute();$stmt->close();
        $stmt=$conn->prepare("SELECT id_caja_fisica FROM pos_caja_fisica WHERE id_cuenta=? AND codigo=? AND activo=1 FOR UPDATE");
        $stmt->bind_param('is',$tenant->accountId,$codigo);$stmt->execute();$physical=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$physical)throw new Exception('La caja física no está activa.');
        $physicalId=(int)$physical['id_caja_fisica'];

        $sql = "SELECT id_caja FROM pos_caja WHERE id_user = ? AND id_cuenta=? AND estado = 'ABIERTA' FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $uid,$tenant->accountId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_assoc()) {
            $stmt->close();
            throw new Exception('Ya existe una caja abierta. Ciérrela primero.');
        }
        $stmt->close();

        $stmt=$conn->prepare("SELECT id_caja FROM pos_caja WHERE id_caja_fisica=? AND estado='ABIERTA' FOR UPDATE");
        $stmt->bind_param('i',$physicalId);$stmt->execute();$occupied=$stmt->get_result()->fetch_assoc();$stmt->close();
        if($occupied)throw new Exception('Esta caja física ya está abierta por otro cajero.');

        // Get employee name
        $sql = "SELECT nombre FROM usuario WHERE id_user = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $userRow = $result->fetch_assoc();
        $empleado = $userRow['nombre'] ?? '';
        $stmt->close();

        // INSERT sesion
        $sql = "INSERT INTO sesion (id_user, id_cuenta, fecha_ingreso, monto_apertura, empleado)
                VALUES (?, ?, NOW(), ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiis', $uid, $tenant->accountId, $montoApertura, $empleado);
        $stmt->execute();
        $idSesion = $conn->insert_id;
        $stmt->close();

        // INSERT pos_caja
        $sql = "INSERT INTO pos_caja (id_user, id_cuenta, id_caja_fisica, codigo, nombre, sucursal, estado, monto_apertura, monto_actual, fecha_apertura, id_sesion)
                VALUES (?, ?, ?, ?, ?, ?, 'ABIERTA', ?, ?, NOW(), ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiisssiii', $uid, $tenant->accountId, $physicalId, $codigo, $nombreCaja, $sucursal, $montoApertura, $montoApertura, $idSesion);
        $stmt->execute();
        $idCaja = $conn->insert_id;
        $stmt->close();

        // INSERT pos_movimiento_caja
        $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo)
                VALUES (?, ?, 'APERTURA', 'Apertura de caja', ?, 'EFECTIVO')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iii', $idCaja, $uid, $montoApertura);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE usuario SET validar_sesion=2 WHERE id_user=?');
        $stmt->bind_param('i',$uid); $stmt->execute(); $stmt->close();

        insertAuditoria($conn, $uid, 'caja_abrir', "Caja {$idCaja} abierta con monto {$montoApertura}", $idCaja, 'pos_caja');

        $conn->commit();

        // Fetch created caja
        $sql = "SELECT * FROM pos_caja WHERE id_caja = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $idCaja);
        $stmt->execute();
        $result = $stmt->get_result();
        $caja = $result->fetch_assoc();
        $stmt->close();

        json(['success' => true, 'caja' => $caja]);

    } catch (Throwable $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_caja_cerrar($data) {
    global $conn, $uid, $tenant;

    $montoReal = (int) ($data->monto_real ?? 0);

    $conn->begin_transaction();

    try {
        $stmt=$conn->prepare("SELECT * FROM pos_caja WHERE id_user=? AND id_cuenta=? AND estado='ABIERTA' ORDER BY id_caja DESC LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii',$uid,$tenant->accountId);$stmt->execute();$caja=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$caja) {
            throw new Exception('No hay caja abierta.');
        }
        $idCaja = (int) $caja['id_caja'];
        $idSesion = (int) $caja['id_sesion'];

        // Calculate esperado: monto_apertura + SUM(INGRESO) - SUM(EGRESO), excluding CIERRE
        $esperado = (int) $caja['monto_apertura'];

        $sql = "SELECT COALESCE(SUM(CASE WHEN tipo = 'INGRESO' OR tipo = 'APERTURA' THEN monto ELSE 0 END), 0) AS ingresos,
                       COALESCE(SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END), 0) AS egresos
                FROM pos_movimiento_caja
                WHERE id_caja = ? AND tipo != 'CIERRE' AND UPPER(TRIM(metodo)) = 'EFECTIVO'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $idCaja);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $esperado = (int) $row['ingresos'] - (int) $row['egresos'];
        $diferencia = $montoReal - $esperado;
        if ($diferencia !== 0) {
            $ctx=['entidad_tipo'=>'pos_caja','entidad_id'=>(string)$idCaja,'esperado'=>$esperado,'real'=>$montoReal,'diferencia'=>$diferencia];
            supervisorRequire('pos.cierre_diferencia',$ctx,$data->supervisor_token ?? null);
        }

        // UPDATE pos_caja
        $sql = "UPDATE pos_caja SET estado = 'CERRADA', monto_cierre = ?, fecha_cierre = NOW() WHERE id_caja = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $montoReal, $idCaja);
        $stmt->execute();
        $stmt->close();

        // INSERT CIERRE movimiento
        $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo)
                VALUES (?, ?, 'CIERRE', 'Cierre de caja', ?, 'EFECTIVO')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iii', $idCaja, $uid, $montoReal);
        $stmt->execute();
        $stmt->close();

        // UPDATE sesion
        $sql = "UPDATE sesion SET fecha_cierre = NOW() WHERE id_sesion = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $idSesion);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE usuario SET validar_sesion=1 WHERE id_user=?');
        $stmt->bind_param('i',$uid); $stmt->execute(); $stmt->close();

        insertAuditoria($conn, $uid, 'caja_cerrar', "Caja {$idCaja} cerrada. Esperado: {$esperado}, Real: {$montoReal}, Dif: {$diferencia}", $idCaja, 'pos_caja');

        $conn->commit();

        json(['success' => true, 'esperado' => $esperado, 'monto_real' => $montoReal, 'diferencia' => $diferencia]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_caja_movimiento($data) {
    global $conn, $uid, $tenant;

    $tipo     = strtoupper($data->tipo ?? 'INGRESO');
    $monto    = (int) ($data->monto ?? 0);
    $concepto = $data->concepto ?? '';
    try {
        $metodo = posCanonicalPaymentMethod($data->metodo ?? 'EFECTIVO');
    } catch (InvalidArgumentException $e) {
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
    $referencia = $data->referencia ?? null;
    $idPedido = $data->id_pedido ?? null;

    if ($monto <= 0) {
        json(['error' => true, 'message' => 'Monto debe ser mayor a 0'], 400);
    }
    if (!in_array($tipo, ['INGRESO', 'EGRESO'])) {
        json(['error' => true, 'message' => 'Tipo debe ser INGRESO o EGRESO'], 400);
    }
    if ($metodo !== 'EFECTIVO') {
        json(['error' => true, 'message' => 'Los ingresos y retiros manuales de caja deben ser en efectivo.'], 400);
    }

    $caja = getOpenCaja($conn, $uid);
    if (!$caja) {
        json(['error' => true, 'message' => 'No hay caja abierta'], 400);
    }
    $idCaja = (int) $caja['id_caja'];

    if ($tipo === 'EGRESO') {
        $ctx=['entidad_tipo'=>'pos_caja','entidad_id'=>(string)$idCaja,'tipo'=>$tipo,'monto'=>$monto,'concepto'=>$concepto];
        supervisorRequire('pos.retiro_caja',$ctx,$data->supervisor_token ?? null);
    }
    $conn->begin_transaction();

    try {
        $stmt=$conn->prepare("SELECT id_caja FROM pos_caja WHERE id_caja=? AND id_user=? AND id_cuenta=? AND estado='ABIERTA' FOR UPDATE");
        $stmt->bind_param('iii',$idCaja,$uid,$tenant->accountId);$stmt->execute();$lockedCaja=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$lockedCaja)throw new Exception('La caja fue cerrada antes de registrar el movimiento.');
        $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, referencia, id_pedido)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iississi', $idCaja, $uid, $tipo, $concepto, $monto, $metodo, $referencia, $idPedido);
        $stmt->execute();
        $stmt->close();

        // Update caja monto_actual
        if ($tipo === 'INGRESO') {
            $sql = "UPDATE pos_caja SET monto_actual = monto_actual + ? WHERE id_caja = ?";
        } else {
            $sql = "UPDATE pos_caja SET monto_actual = monto_actual - ? WHERE id_caja = ?";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $monto, $idCaja);
        $stmt->execute();
        $stmt->close();

        insertAuditoria($conn, $uid, 'caja_movimiento', "{$tipo}: {$monto} - {$concepto}", $idCaja, 'pos_caja');

        $conn->commit();

        json(['success' => true, 'message' => 'Movimiento registrado']);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_venta_crear($data) {
    global $conn, $uid, $tenant;

    $items          = $data->items ?? [];
    $pagos          = $data->pagos ?? [];
    $idempotencyKey = trim((string) ($data->idempotency_key ?? ''));
    $requestHash    = posSaleRequestHash($data);
    $tipoDocumento  = $data->tipo_documento ?? 'BOLETA';
    $clienteData    = $data->cliente ?? null;
    $idCliente      = $data->id_cliente ?? posPaymentValue($clienteData, 'id_cliente');
    $clienteNombre  = $data->cliente_nombre ?? posPaymentValue($clienteData, 'nombre', '');
    $clienteRut     = $data->cliente_rut ?? posPaymentValue($clienteData, 'rut', '');
    $clienteCorreo  = $data->cliente_correo ?? posPaymentValue($clienteData, 'correo', '');
    $clienteTelefono = $data->cliente_telefono ?? posPaymentValue($clienteData, 'telefono', '');
    $idPromocion    = $data->id_promocion ?? null;
    $idCotizacion   = (int)($data->id_cotizacion ?? 0);
    $descuentoTotal = 0;
    $couponCodes    = (array)($data->cupones ?? []);

    if (empty($items)) {
        json(['error' => true, 'message' => 'Se requiere al menos un producto'], 400);
    }
    if (empty($pagos)) {
        json(['error' => true, 'message' => 'Se requiere al menos un método de pago'], 400);
    }

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,63}$/', $idempotencyKey)) {
        json(['error' => true, 'message' => 'La venta requiere una clave de idempotencia válida.'], 400);
    }

    $sql = "SELECT i.solicitud_hash, i.estado, p.id_pedido, p.precio_total, p.monto_recibido, p.vuelto, p.folio, p.numero_documento
            FROM pos_venta_idempotencia i
            LEFT JOIN pedido p ON p.id_pedido=i.id_pedido
            WHERE i.id_cuenta=? AND i.clave=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $tenant->accountId, $idempotencyKey);
    $stmt->execute();
    $previous = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($previous) {
        if (!hash_equals((string) $previous['solicitud_hash'], $requestHash)) {
            json(['error' => true, 'message' => 'La clave de idempotencia ya fue usada con otra venta.'], 409);
        }
        if ($previous['estado'] === 'COMPLETADA' && $previous['id_pedido']) {
            json([
                'success' => true,
                'id_pedido' => (int) $previous['id_pedido'],
                'total' => (int) $previous['precio_total'],
                'monto_recibido' => (int) $previous['monto_recibido'],
                'cambio' => (int) $previous['vuelto'],
                'folio' => (int) $previous['folio'],
                'numero_documento' => $previous['numero_documento'],
                'idempotent_replay' => true,
            ]);
        }
    }

    // Validate promocion if descuento > 0
    if ($descuentoTotal > 0) {
        if (!$idPromocion) {
            json(['error' => true, 'message' => 'Descuento sin promoción asociada'], 400);
        }
        $sql = "SELECT * FROM pos_promocion WHERE id_promocion = ? AND id_cuenta = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $pid = (int) $idPromocion;
        $stmt->bind_param('ii', $pid, $tenant->accountId);
        $stmt->execute();
        $result = $stmt->get_result();
        $promo = $result->fetch_assoc();
        $stmt->close();

        if (!$promo) {
            json(['error' => true, 'message' => 'Promoción no encontrada o inactiva'], 400);
        }

        // Check date validity
        $hoy = date('Y-m-d');
        if ($promo['fecha_inicio'] && $promo['fecha_inicio'] > $hoy) {
            json(['error' => true, 'message' => 'Promoción aún no comienza'], 400);
        }
        if ($promo['fecha_fin'] && $promo['fecha_fin'] < $hoy) {
            json(['error' => true, 'message' => 'Promoción expirada'], 400);
        }
    }

    $priceOverrides=[];
    $basePrices=[];
    foreach ($items as $item) {
        $idp=(int)($item->id_producto??0); $sent=(int)($item->precio_unitario??0);
        $stmt=$conn->prepare('SELECT precio_venta FROM producto WHERE id_producto=? AND id_cuenta=? AND activo=1');
        $stmt->bind_param('ii',$idp,$tenant->accountId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) json(['error'=>true,'message'=>'Producto no encontrado o inactivo'],400);
        $base=(int)$row['precio_venta'];
        $basePrices[$idp]=$base;
        if ($sent!==$base) $priceOverrides[]=['id_producto'=>$idp,'precio_base'=>$base,'precio_nuevo'=>$sent];
    }
    if ($priceOverrides) {
        $ctx=['entidad_tipo'=>'venta','entidad_id'=>'nueva','cambios'=>$priceOverrides];
        supervisorRequire('pos.cambiar_precio',$ctx,$data->supervisor_token ?? null);
    }
    $authorizedPrices=$basePrices;
    foreach($priceOverrides as $override)$authorizedPrices[(int)$override['id_producto']]=(int)$override['precio_nuevo'];
    $conn->begin_transaction();
    try {
        // The unique key serializes simultaneous retries. INSERT IGNORE waits
        // for an in-flight transaction using the same key.
        $sql = "INSERT IGNORE INTO pos_venta_idempotencia
                    (id_cuenta,id_user,clave,solicitud_hash,estado)
                VALUES (?,?,?,?,'PROCESANDO')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiss', $tenant->accountId, $uid, $idempotencyKey, $requestHash);
        $stmt->execute();
        $reserved = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$reserved) {
            $conn->rollback();
            $stmt = $conn->prepare("SELECT i.solicitud_hash,i.estado,p.id_pedido,p.precio_total,p.monto_recibido,p.vuelto,p.folio,p.numero_documento FROM pos_venta_idempotencia i LEFT JOIN pedido p ON p.id_pedido=i.id_pedido WHERE i.id_cuenta=? AND i.clave=? LIMIT 1");
            $stmt->bind_param('is', $tenant->accountId, $idempotencyKey);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$existing || !hash_equals((string) $existing['solicitud_hash'], $requestHash)) {
                json(['error' => true, 'message' => 'La clave de idempotencia ya fue usada con otra venta.'], 409);
            }
            json([
                'success' => true,
                'id_pedido' => (int) $existing['id_pedido'],
                'total' => (int) $existing['precio_total'],
                'monto_recibido' => (int) $existing['monto_recibido'],
                'cambio' => (int) $existing['vuelto'],
                'folio' => (int) $existing['folio'],
                'numero_documento' => $existing['numero_documento'],
                'idempotent_replay' => true,
            ]);
        }

        // Get session with FOR UPDATE
        $sql = "SELECT * FROM sesion WHERE id_user = ? AND fecha_cierre IS NULL ORDER BY id_sesion DESC LIMIT 1 FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $sesion = $result->fetch_assoc();
        $stmt->close();

        if (!$sesion) {
            throw new Exception('No hay sesión activa. Abra la caja primero.');
        }
        $idSesion = (int) $sesion['id_sesion'];

        // Get default bodega
        $idBodega = getDefaultBodega($conn);
        if ($idBodega === 0) {
            throw new Exception('No se encontró una bodega activa.');
        }

        // Lock the cashier's open register for the complete sale.
        $requestedCaja = (int) ($data->id_caja ?? 0);
        $sql = "SELECT * FROM pos_caja WHERE id_user=? AND id_cuenta=? AND estado='ABIERTA' ORDER BY id_caja DESC LIMIT 1 FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $uid, $tenant->accountId);
        $stmt->execute();
        $caja = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$caja || ($requestedCaja > 0 && $requestedCaja !== (int) $caja['id_caja'])) {
            throw new Exception('No hay una caja abierta válida para esta venta.');
        }
        $idCaja = (int) $caja['id_caja'];
        $saleSucursal=(int)($data->id_sucursal??0);
        if($saleSucursal>0){$stmt=$conn->prepare('SELECT id_sucursal FROM sucursal WHERE id_sucursal=? AND id_cuenta=? AND activo=1 AND (nombre=? OR codigo=?)');$stmt->bind_param('iiss',$saleSucursal,$tenant->accountId,$caja['sucursal'],$caja['sucursal']);$stmt->execute();$validBranch=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$validBranch)throw new Exception('La sucursal no corresponde a la caja abierta.');}
        else{$stmt=$conn->prepare('SELECT id_sucursal FROM sucursal WHERE id_cuenta=? AND activo=1 AND (nombre=? OR codigo=?) ORDER BY id_sucursal LIMIT 1');$stmt->bind_param('iss',$tenant->accountId,$caja['sucursal'],$caja['sucursal']);$stmt->execute();$branch=$stmt->get_result()->fetch_assoc();$stmt->close();$saleSucursal=(int)($branch['id_sucursal']??0);}

        if ($idCotizacion>0) {
            $stmt=$conn->prepare('SELECT id_cotizacion FROM pos_cotizacion WHERE id_cotizacion=? AND id_cuenta=? AND convertida=0 FOR UPDATE');
            $stmt->bind_param('ii',$idCotizacion,$tenant->accountId);$stmt->execute();$quote=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$quote)throw new Exception('La cotización no existe o ya fue convertida.');
        }

        if ((int)$idCliente > 0) {
            $clientIdInt=(int)$idCliente;
            $stmt=$conn->prepare('SELECT id_cliente,nombre,rut,correo,telefono FROM cliente WHERE id_cliente=? AND id_cuenta=? AND COALESCE(activo,1)=1 FOR UPDATE');
            $stmt->bind_param('ii',$clientIdInt,$tenant->accountId);$stmt->execute();$clientRow=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$clientRow)throw new Exception('El cliente seleccionado no existe, está inactivo o pertenece a otra cuenta.');
            $idCliente=$clientIdInt;$clienteNombre=$clientRow['nombre'];$clienteRut=$clientRow['rut'];$clienteCorreo=$clientRow['correo'];$clienteTelefono=$clientRow['telefono'];
        } else {
            $idCliente=null;
        }

        // The rule engine owns eligibility and monetary calculation. It also
        // locks products/promotions so the preview cannot diverge at checkout.
        $promotionEvaluation=promotionEvaluate($conn,$tenant->accountId,$uid,(array)$items,$idCliente,$couponCodes,[
            'id_sucursal'=>$saleSucursal,'canal'=>trim((string)($data->canal??'POS'))?:'POS','price_overrides'=>$authorizedPrices
        ],true);
        $precioTotal=(int)$promotionEvaluation['subtotal'];
        $descuentoTotal=(int)$promotionEvaluation['descuento'];
        $promotionLineMap=[];
        foreach($promotionEvaluation['lineas'] as $promotionLine)$promotionLineMap[(int)$promotionLine['id_producto']]=$promotionLine;

        // Recalculate promotions from server-owned data. The browser may display
        // a discount, but it cannot decide its monetary value.
        if (false && $descuentoTotal > 0) { // Reemplazado por promotionEvaluate().
            $stmt = $conn->prepare("SELECT * FROM pos_promocion WHERE id_promocion=? AND id_cuenta=? AND activo=1 FOR UPDATE");
            $promoId = (int) $idPromocion;
            $stmt->bind_param('ii', $promoId, $tenant->accountId);
            $stmt->execute();
            $lockedPromo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$lockedPromo) throw new Exception('Promoción no disponible.');
            if ((int) $lockedPromo['monto_minimo'] > $precioTotal) {
                throw new Exception('La venta no alcanza el monto mínimo de la promoción.');
            }
            $expectedDiscount = 0;
            if ($lockedPromo['tipo'] === 'PORCENTAJE') {
                $expectedDiscount = (int) round($precioTotal * (int) $lockedPromo['valor'] / 100);
            } elseif ($lockedPromo['tipo'] === 'FIJO') {
                $expectedDiscount = min((int) $lockedPromo['valor'], $precioTotal);
            }
            if ($descuentoTotal !== $expectedDiscount) {
                throw new Exception('El descuento cambió. Vuelva a aplicar la promoción.');
            }
        }

        // Apply descuento
        $precioFinal = $precioTotal - $descuentoTotal;
        if ($precioFinal < 0) {
            $precioFinal = 0;
        }

        $paymentSummary = posNormalizePayments((array) $pagos, $precioFinal);
        $pagosNormalizados = $paymentSummary['pagos'];
        $pagoTotal = $paymentSummary['pago_total'];
        $montoRecibido = $paymentSummary['monto_recibido'];
        $vuelto = $paymentSummary['vuelto'];
        $diferenciaPedido = 0;
        $document = posNextFolio($conn,$tenant->accountId,$tipoDocumento);
        $folio=$document['folio'];$numeroDocumento=$document['numero'];$tipoDocumento=$document['tipo'];

        // INSERT pedido
        $sql = "INSERT INTO pedido (id_cuenta, id_sesion, id_cliente, id_caja, id_bodega, tipo_documento,
                    folio, numero_documento, cliente_nombre, cliente_rut, cliente_correo, cliente_telefono,
                    precio_total, pago_total, monto_recibido, vuelto, diferencia, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiiiisisssssiiiii', $tenant->accountId, $idSesion, $idCliente, $idCaja, $idBodega, $tipoDocumento,
            $folio,$numeroDocumento,
            $clienteNombre, $clienteRut, $clienteCorreo, $clienteTelefono,
            $precioFinal, $pagoTotal, $montoRecibido, $vuelto, $diferenciaPedido);
        $stmt->execute();
        $idPedido = $conn->insert_id;
        $stmt->close();

        // Process items
        $receiptItems=[];
        $promotionRemainingDiscount=[];$promotionRemainingQuantity=[];
        foreach($promotionLineMap as $productId=>$line){$promotionRemainingDiscount[$productId]=(int)$line['descuento'];$promotionRemainingQuantity[$productId]=(float)$line['cantidad'];}
        foreach ($items as $item) {
            $idProducto     = (int) ($item->id_producto ?? 0);
            $cantidad       = (float) ($item->cantidad ?? 0);
            $precioUnitario = (int) ($item->precio_unitario ?? 0);
            $itemOriginalTotal = (int) round($precioUnitario * $cantidad);
            $remainingQty=$promotionRemainingQuantity[$idProducto]??$cantidad;
            $remainingDiscount=$promotionRemainingDiscount[$idProducto]??0;
            $itemDiscount=$cantidad+0.0001>=$remainingQty?$remainingDiscount:(int)round($remainingDiscount*$cantidad/max(.001,$remainingQty));
            $itemDiscount=min($itemDiscount,$itemOriginalTotal);
            $itemTotal=$itemOriginalTotal-$itemDiscount;
            $promotionRemainingDiscount[$idProducto]=max(0,$remainingDiscount-$itemDiscount);
            $promotionRemainingQuantity[$idProducto]=max(0,$remainingQty-$cantidad);

            if ($idProducto <= 0 || $cantidad <= 0) {
                throw new Exception('Ítem inválido: producto o cantidad no válidos.');
            }

            // Check product exists and get data
            $sql = "SELECT * FROM producto WHERE id_producto = ? AND id_cuenta = ? AND activo = 1 FOR UPDATE";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $idProducto, $tenant->accountId);
            $stmt->execute();
            $result = $stmt->get_result();
            $producto = $result->fetch_assoc();
            $stmt->close();

            if (!$producto) {
                throw new Exception("Producto ID {$idProducto} no encontrado o inactivo.");
            }
            if ($precioUnitario <= 0) {
                throw new Exception('El precio unitario debe ser mayor a cero.');
            }
            if (!isset($basePrices[$idProducto]) || (int) $producto['precio_venta'] !== $basePrices[$idProducto]) {
                throw new Exception('El precio del producto cambió. Recargue el POS y vuelva a intentar.');
            }

            $tipoVenta = $producto['tipo_venta'] ?? 'UNIDAD';
            $cantidadStock = posNormalizeStockQuantity(
                $cantidad,
                $tipoVenta,
                "El producto \"{$producto['nombre_producto']}\""
            );
            $cantidad = $cantidadStock;
            $costoUnit = (int) round(((float) ($producto['costo_promedio'] ?? 0)) * 100);
            $linePromotion=$promotionLineMap[$idProducto]??[];
            $receiptItems[]=['id_producto'=>$idProducto,'codigo'=>$producto['codigo_de_barras'],'sku'=>$producto['sku'],'nombre'=>$producto['nombre_producto'],'cantidad'=>$cantidad,
                'precio_original'=>$precioUnitario,'descuento'=>$itemDiscount,'precio_final_promedio'=>$cantidad>0?(int)round($itemTotal/$cantidad):0,
                'subtotal_original'=>$itemOriginalTotal,'subtotal'=>$itemTotal,'promociones'=>$linePromotion['promociones']??[],'unidades_beneficiadas'=>$linePromotion['unidades_beneficiadas']??0];

            // INSERT detalle_pedido
            $sql = "INSERT INTO detalle_pedido (id_pedido,id_producto,cantidad_pedida,precio_unitario_original,descuento,precio_unitario_final,precio_total)
                    VALUES (?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($sql);
            $cantidadPedida = $cantidad;
            $precioFinalPromedio=$cantidad>0?(int)round($itemTotal/$cantidad):0;
            $stmt->bind_param('iidiiii',$idPedido,$idProducto,$cantidadPedida,$precioUnitario,$itemDiscount,$precioFinalPromedio,$itemTotal);
            $stmt->execute();
            $stmt->close();

            // Stock y kardex deben mover exactamente la misma cantidad decimal.
            descontarStock($conn, $idProducto, $idBodega, $cantidadStock);

            // Kardex
            actualizarKardex($conn, $uid, $idProducto, $idBodega, 'VENTA', $idPedido, 'PEDIDO', 0, $cantidadStock, $costoUnit, "Venta #{$idPedido}");
        }

        // INSERT pagos
        foreach ($pagosNormalizados as $pago) {
            $metodoPago = $pago['metodo'];
            $montoPago  = $pago['monto'];
            $referenciaPago = $pago['referencia'] ?: null;

            $sql = "INSERT INTO metodo_de_pago (id_pedido, nombre_metodo_pago, monto)
                    VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isi', $idPedido, $metodoPago, $montoPago);
            $stmt->execute();
            $stmt->close();

            // monto_actual represents physical cash only.
            if ($idCaja) {
                if ($metodoPago === 'EFECTIVO') {
                    $sql = "UPDATE pos_caja SET monto_actual = monto_actual + ? WHERE id_caja = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('ii', $montoPago, $idCaja);
                    $stmt->execute();
                    $stmt->close();
                }

                // INSERT movimiento caja
                $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, referencia, id_pedido)
                        VALUES (?, ?, 'INGRESO', 'Venta', ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iiissi', $idCaja, $uid, $montoPago, $metodoPago, $referenciaPago, $idPedido);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Persist each applied rule and its exact allocation. This makes the
        // receipt, reports and future returns reproducible.
        foreach($promotionEvaluation['aplicadas'] as $application){
            $promoIdInt=(int)$application['id_promocion'];$applicationDiscount=(int)$application['descuento'];
            $stmt=$conn->prepare("INSERT INTO pos_descuento(id_pedido,id_promocion,tipo,monto,motivo,autorizado_por) VALUES(?,?,'PROMOCION',?,'Motor de promociones',?)");
            $stmt->bind_param('iiii',$idPedido,$promoIdInt,$applicationDiscount,$uid);$stmt->execute();$stmt->close();
            $applicationJson=json_encode($application,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$applications=(float)$application['aplicaciones'];$promoCode=(string)$application['codigo'];
            $stmt=$conn->prepare('INSERT INTO pos_promocion_aplicacion(id_cuenta,id_pedido,id_promocion,id_cliente,id_user,codigo,aplicaciones,descuento,detalle_json) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iiiiisdis',$tenant->accountId,$idPedido,$promoIdInt,$idCliente,$uid,$promoCode,$applications,$applicationDiscount,$applicationJson);$stmt->execute();$stmt->close();
            $stmt=$conn->prepare('UPDATE pos_promocion SET usado=usado+1 WHERE id_promocion=? AND id_cuenta=?');$stmt->bind_param('ii',$promoIdInt,$tenant->accountId);$stmt->execute();$stmt->close();
            promotionAudit($conn,$tenant->accountId,$uid,$promoIdInt,$idPedido,$idCliente,'APLICADA','Condiciones cumplidas',$applicationDiscount,$application);
        }
        foreach($promotionEvaluation['rechazadas'] as $rejection){
            if(!in_array(strtoupper((string)($rejection['codigo']??'')),array_map('strtoupper',$couponCodes),true))continue;
            $rejectedId=isset($rejection['id_promocion'])?(int)$rejection['id_promocion']:null;
            promotionAudit($conn,$tenant->accountId,$uid,$rejectedId,$idPedido,$idCliente,'RECHAZADA',(string)$rejection['motivo'],0,$rejection);
        }

        if($idCotizacion>0){
            $stmt=$conn->prepare('UPDATE pos_cotizacion SET convertida=1,id_pedido=? WHERE id_cotizacion=? AND id_cuenta=? AND convertida=0');
            $stmt->bind_param('iii',$idPedido,$idCotizacion,$tenant->accountId);$stmt->execute();$converted=$stmt->affected_rows===1;$stmt->close();
            if(!$converted)throw new RuntimeException('No se pudo vincular la cotización a la venta.');
        }

        $stmt=$conn->prepare('SELECT * FROM config_boleta WHERE id_cuenta=? AND activo=1 ORDER BY id_config DESC LIMIT 1');
        $stmt->bind_param('i',$tenant->accountId);$stmt->execute();$receiptConfig=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();
        // The logo is loaded from the current account asset when reprinting; it
        // is not duplicated into every sale snapshot.
        unset($receiptConfig['logo']);
        $snapshot=json_encode([
            'version'=>1,'id_pedido'=>$idPedido,'folio'=>$folio,'numero_documento'=>$numeroDocumento,
            'tipo_documento'=>$tipoDocumento,'fecha'=>date('Y-m-d H:i:s'),
            'cliente'=>['nombre'=>$clienteNombre,'rut'=>$clienteRut,'correo'=>$clienteCorreo,'telefono'=>$clienteTelefono],
            'items'=>$receiptItems,'promociones'=>$promotionEvaluation['aplicadas'],'promociones_rechazadas'=>$promotionEvaluation['rechazadas'],
            'pagos'=>$pagosNormalizados,'subtotal'=>$precioTotal,'descuento'=>$descuentoTotal,
            'total'=>$precioFinal,'monto_recibido'=>$montoRecibido,'vuelto'=>$vuelto,'config'=>$receiptConfig,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($snapshot===false)throw new RuntimeException('No se pudo generar el documento de la venta.');
        $stmt=$conn->prepare('INSERT INTO pos_documento_snapshot(id_cuenta,id_pedido,contenido_json) VALUES(?,?,?)');
        $stmt->bind_param('iis',$tenant->accountId,$idPedido,$snapshot);$stmt->execute();$stmt->close();

        insertAuditoria($conn, $uid, 'venta_crear', "Pedido #{$idPedido} creado. Total: {$precioFinal}", $idPedido, 'pedido');

        $sql = "UPDATE pos_venta_idempotencia
                SET estado='COMPLETADA',id_pedido=?,completed_at=NOW()
                WHERE id_cuenta=? AND clave=? AND solicitud_hash=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiss', $idPedido, $tenant->accountId, $idempotencyKey, $requestHash);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('No se pudo completar el control de idempotencia.');
        }
        $stmt->close();

        $conn->commit();

        json([
            'success' => true,
            'id_pedido' => $idPedido,
            'total' => $precioFinal,
            'monto_recibido' => $montoRecibido,
            'cambio' => $vuelto,
            'folio' => $folio,
            'numero_documento' => $numeroDocumento,
            'subtotal' => $precioTotal,
            'descuento' => $descuentoTotal,
            'promociones' => $promotionEvaluation['aplicadas'],
            'items_documento' => $receiptItems,
            'idempotent_replay' => false,
        ]);

    } catch (Throwable $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_venta_anular($data) {
    global $conn, $uid, $tenant;

    $pedidoId = (int) ($data->id_pedido ?? 0);
    if ($pedidoId <= 0) {
        json(['error' => true, 'message' => 'ID de pedido requerido'], 400);
    }

    $motivo = trim((string) ($data->motivo ?? ''));
    if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 500) {
        json(['error' => true, 'message' => 'Ingrese un motivo de anulación entre 3 y 500 caracteres'], 400);
    }

    $ctx=['entidad_tipo'=>'pedido','entidad_id'=>(string)$pedidoId,'motivo'=>$motivo];
    supervisorRequire('pos.anular_venta',$ctx,$data->supervisor_token ?? null);

    try {
    $conn->begin_transaction();
        // Validate venta exists and not already anulada
        $sql = "SELECT p.*, s.id_user AS sesion_user
                FROM pedido p
                JOIN sesion s ON p.id_sesion = s.id_sesion
                WHERE p.id_pedido = ? AND p.id_cuenta = ?
                FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $pedidoId, $tenant->accountId);
        $stmt->execute();
        $result = $stmt->get_result();
        $pedido = $result->fetch_assoc();
        $stmt->close();

        if (!$pedido) {
            throw new Exception('Pedido no encontrado.');
        }
        if ((int) $pedido['anulado'] === 1) {
            throw new Exception('El pedido ya está anulado.');
        }
        $stmt = $conn->prepare('SELECT id_devolucion FROM pos_devolucion WHERE id_pedido=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $pedidoId);
        $stmt->execute();
        $hasReturn = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($hasReturn) {
            throw new Exception('El pedido tiene devoluciones y no puede anularse completo.');
        }

        // Verify ownership (via cuenta)
        $cuenta = buildCuentaFilter($conn, $uid, 's.id_user');
        $sql = "SELECT p.id_pedido FROM pedido p
                JOIN sesion s ON p.id_sesion = s.id_sesion
                WHERE p.id_pedido = ? AND {$cuenta['sql']}";
        $cParams = $cuenta['params'];
        $cTypes  = $cuenta['types'];
        $cParamsFull = array_merge([$pedidoId], $cParams);
        $cTypesFull  = 'i' . $cTypes;
        $stmt = $conn->prepare($sql);
        if ($cParamsFull) {
            $stmt->bind_param($cTypesFull, ...$cParamsFull);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result->fetch_assoc()) {
            $stmt->close();
            throw new Exception('No tiene permisos para anular este pedido.');
        }
        $stmt->close();

        $idBodega = (int) $pedido['id_bodega'];
        $originalCajaId=(int)($pedido['id_caja']??0);
        $stmt=$conn->prepare("SELECT estado FROM pos_caja WHERE id_caja=? AND id_cuenta=? FOR UPDATE");
        $stmt->bind_param('ii',$originalCajaId,$tenant->accountId);$stmt->execute();$originalCaja=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$originalCaja||$originalCaja['estado']!=='ABIERTA'){
            throw new Exception('La caja original está cerrada. Procese una devolución en la caja actual.');
        }

        // Get detalle
        $sql = "SELECT * FROM detalle_pedido WHERE id_pedido = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pedidoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $detalles = [];
        while ($row = $result->fetch_assoc()) {
            $detalles[] = $row;
        }
        $stmt->close();

        // Reponer stock for each detalle
        foreach ($detalles as $det) {
            $idProducto = (int) $det['id_producto'];
            $cantidad   = (float) $det['cantidad_pedida'];

            // Get product tipo_venta
            $sql = "SELECT tipo_venta, costo_promedio, nombre_producto FROM producto WHERE id_producto = ? AND id_cuenta = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $idProducto, $tenant->accountId);
            $stmt->execute();
            $res = $stmt->get_result();
            $prod = $res->fetch_assoc();
            $stmt->close();
            if (!$prod) {
                throw new Exception('Producto del pedido no encontrado en la cuenta.');
            }

            $tipoVenta = $prod['tipo_venta'];
            $costoUnit = (int) round(((float) $prod['costo_promedio']) * 100);
            $cantidadStock = posNormalizeStockQuantity($cantidad, $tipoVenta, (string) $prod['nombre_producto']);
            reponerStock($conn, $idProducto, $idBodega, $cantidadStock);
            actualizarKardex($conn, $uid, $idProducto, $idBodega, 'ANULACION', $pedidoId, 'PEDIDO', $cantidadStock, 0, $costoUnit, "Anulación pedido #{$pedidoId}: {$motivo}");
        }

        // UPDATE pedido anulado=1
        $sql = "UPDATE pedido SET anulado = 1 WHERE id_pedido = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pedidoId);
        $stmt->execute();
        $stmt->close();

        // Get pagos
        $sql = "SELECT * FROM metodo_de_pago WHERE id_pedido = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pedidoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $pagos = [];
        while ($row = $result->fetch_assoc()) {
            $pagos[] = $row;
        }
        $stmt->close();

        // Revert caja for each pago
        $idCaja = (int) $pedido['id_caja'];
        if ($idCaja > 0) {
            foreach ($pagos as $pago) {
                $montoPago = (int) $pago['monto'];
                $metodoPago = posCanonicalPaymentMethod($pago['nombre_metodo_pago'] ?? 'EFECTIVO');

                if ($metodoPago === 'EFECTIVO') {
                    $sql = "UPDATE pos_caja SET monto_actual = monto_actual - ? WHERE id_caja = ? AND id_cuenta = ? AND monto_actual >= ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('iiii', $montoPago, $idCaja, $tenant->accountId, $montoPago);
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) {
                        $stmt->close();
                        throw new Exception('La caja no tiene efectivo suficiente para anular esta venta. Procese una devolución desde una caja con saldo disponible.');
                    }
                    $stmt->close();
                }

                $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, id_pedido)
                        VALUES (?, ?, 'EGRESO', 'Anulación de venta', ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iiisi', $idCaja, $uid, $montoPago, $metodoPago, $pedidoId);
                $stmt->execute();
                $stmt->close();
            }
        }

        insertAuditoria(
            $conn,
            $uid,
            'venta_anular',
            (string) json_encode(['pedido_id'=>$pedidoId,'motivo'=>$motivo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $pedidoId,
            'pedido'
        );

        $conn->commit();

        json(['success' => true, 'message' => 'Pedido anulado correctamente']);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_cliente_crear($data) {
    global $conn, $uid, $tenant;

    $nombre    = $data->nombre ?? '';
    $rut       = $data->rut ?? '';
    $razonSocial = $data->razon_social ?? '';
    $correo    = $data->correo ?? '';
    $telefono  = $data->telefono ?? '';
    $direccion = $data->direccion ?? '';
    $ciudad    = $data->ciudad ?? '';
    $giro      = $data->giro ?? '';
    $categoria = $data->categoria ?? '';

    if (empty($nombre)) {
        json(['error' => true, 'message' => 'Nombre es requerido'], 400);
    }

    $sql = "INSERT INTO cliente (id_user, id_cuenta, codigo, rut, razon_social, nombre, correo, telefono, direccion, ciudad, giro, categoria)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $codigo = 'CLI-' . str_pad((string) rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iissssssssss', $uid, $tenant->accountId, $codigo, $rut, $razonSocial, $nombre, $correo, $telefono, $direccion, $ciudad, $giro, $categoria);
    $stmt->execute();
    $idCliente = $conn->insert_id;
    $stmt->close();

    json(['success' => true, 'id_cliente' => $idCliente]);
}

function POST_promocion_crear($data) {
    global $conn, $uid, $tenant;

    $codigo       = strtoupper(trim((string)($data->codigo ?? '')));
    $nombre       = $data->nombre ?? '';
    $tipo         = $data->tipo ?? 'DESCUENTO';
    $valor        = (int) ($data->valor ?? 0);
    $montoMinimo  = (int) ($data->monto_minimo ?? 0);
    $cantidadMinima = (int) ($data->cantidad_minima ?? 0);
    $fechaInicio  = $data->fecha_inicio ?? null;
    $fechaFin     = $data->fecha_fin ?? null;
    $diasSemana   = $data->dias_semana ?? null;
    $horaInicio   = $data->hora_inicio ?? null;
    $horaFin      = $data->hora_fin ?? null;
    $aplicaCategoria = $data->aplica_categoria ?? null;
    $aplicaMarca  = $data->aplica_marca ?? null;
    $combinable   = (int) ($data->combinable ?? 0);
    $productos    = $data->productos ?? [];
    $productosCodigos = (array)($data->productos_codigos ?? []);
    $beneficioCodigos = (array)($data->beneficio_codigos ?? []);
    $cantidadPagada = isset($data->cantidad_pagada) ? (float)$data->cantidad_pagada : null;
    $cantidadBeneficiada = isset($data->cantidad_beneficiada) ? (float)$data->cantidad_beneficiada : null;
    $prioridad = max(0,(int)($data->prioridad ?? 100));
    $requiereCodigo = (int)(bool)($data->requiere_codigo ?? false);
    $maxAplicaciones = (int)($data->max_aplicaciones_transaccion ?? 0) ?: null;
    $maxUsosCliente = (int)($data->max_usos_cliente ?? 0) ?: null;
    $segmentoCliente = trim((string)($data->segmento_cliente ?? '')) ?: null;
    $listaPrecios = trim((string)($data->lista_precios ?? '')) ?: null;
    $idSucursal = (int)($data->id_sucursal ?? 0) ?: null;
    $canal = trim((string)($data->canal ?? '')) ?: null;
    $motivo = trim((string)($data->motivo ?? '')) ?: null;
    $condicionesJson = json_encode((array)($data->condiciones ?? []),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $beneficioJson = json_encode((array)($data->beneficio ?? []),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    if (empty($nombre)) json(['error'=>true,'message'=>'Nombre requerido'],400);
    if ($codigo==='') $codigo='PROMO-'.strtoupper(substr(bin2hex(random_bytes(5)),0,10));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,29}$/',$codigo)) json(['error'=>true,'message'=>'Código inválido. Use 3 a 30 letras, números, punto, guion o guion bajo.'],400);
    $allowedTypes=['2X1','3X2','NXM','CANTIDAD','PORCENTAJE','DESCUENTO_PCT','FIJO','DESCUENTO_MONTO','PRECIO_ESPECIAL','COMPRA_X_DESCUENTO_Y','BUY_X_GET_Y','COMBO'];
    if(!in_array(strtoupper($tipo),$allowedTypes,true))json(['error'=>true,'message'=>'Tipo de promoción no soportado'],400);
    if($fechaInicio&&$fechaFin&&$fechaFin<$fechaInicio)json(['error'=>true,'message'=>'La fecha final no puede ser anterior a la inicial'],400);

    $conn->begin_transaction();
    try {
        if($idSucursal)requireTenantEntity($conn,$tenant,'sucursal',$idSucursal);
        $sql = "INSERT INTO pos_promocion (id_user,id_cuenta,codigo,nombre,tipo,valor,monto_minimo,cantidad_minima,cantidad_pagada,cantidad_beneficiada,
                    fecha_inicio,fecha_fin,dias_semana,hora_inicio,hora_fin,aplica_categoria,aplica_marca,combinable,prioridad,requiere_codigo,
                    max_aplicaciones_transaccion,max_usos_cliente,segmento_cliente,lista_precios,id_sucursal,canal,condiciones_json,beneficio_json,motivo)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iisssiidddsssssssiiiiississss',$uid,$tenant->accountId,$codigo,$nombre,$tipo,$valor,$montoMinimo,$cantidadMinima,$cantidadPagada,$cantidadBeneficiada,
            $fechaInicio,$fechaFin,$diasSemana,$horaInicio,$horaFin,$aplicaCategoria,$aplicaMarca,$combinable,$prioridad,$requiereCodigo,
            $maxAplicaciones,$maxUsosCliente,$segmentoCliente,$listaPrecios,$idSucursal,$canal,$condicionesJson,$beneficioJson,$motivo);
        $stmt->execute();
        $idPromocion = $conn->insert_id;
        $stmt->close();

        // Insert producto associations
        if (!empty($productos)) {
            $sql = "INSERT INTO pos_promocion_producto (id_promocion,id_producto,codigo_producto,sku) SELECT ?,id_producto,codigo_de_barras,sku FROM producto WHERE id_producto=? AND id_cuenta=?";
            $stmt = $conn->prepare($sql);
            foreach ($productos as $pid) {
                $productoId = (int) $pid;
                $stmt->bind_param('iii', $idPromocion, $productoId,$tenant->accountId);
                $stmt->execute();
                if($stmt->affected_rows!==1)throw new InvalidArgumentException("Producto #{$productoId} no pertenece a la cuenta.");
            }
            $stmt->close();
        }

        $insertCodes=function(array $codes,string $role)use($conn,$tenant,$idPromocion){
            $find=$conn->prepare('SELECT id_producto,codigo_de_barras,sku FROM producto WHERE id_cuenta=? AND activo=1 AND (UPPER(codigo_de_barras)=? OR UPPER(sku)=?) LIMIT 1');
            $insert=$conn->prepare('INSERT INTO pos_promocion_producto(id_promocion,id_producto,rol,codigo_producto,sku) VALUES(?,?,?,?,?)');
            foreach($codes as $raw){$code=strtoupper(trim((string)$raw));if($code==='')continue;$find->bind_param('iss',$tenant->accountId,$code,$code);$find->execute();$product=$find->get_result()->fetch_assoc();if(!$product)throw new InvalidArgumentException("No existe producto activo con código o SKU {$code}.");$productId=(int)$product['id_producto'];$barcode=$product['codigo_de_barras'];$sku=$product['sku'];$insert->bind_param('iisss',$idPromocion,$productId,$role,$barcode,$sku);$insert->execute();}
            $find->close();$insert->close();
        };
        $insertCodes($productosCodigos,'ELEGIBLE');
        $insertCodes($beneficioCodigos,'BENEFICIO');

        insertAuditoria($conn, $uid, 'promocion_crear', "Promoción \"{$nombre}\" creada", $idPromocion, 'pos_promocion');
        promotionAudit($conn,$tenant->accountId,$uid,$idPromocion,null,null,'CREADA',$motivo?:'Regla configurada',0,['tipo'=>$tipo,'codigo'=>$codigo,'productos_codigos'=>$productosCodigos,'beneficio_codigos'=>$beneficioCodigos]);

        $conn->commit();

        json(['success' => true, 'id_promocion' => $idPromocion,'codigo'=>$codigo]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_promociones_evaluar($data) {
    global $conn, $uid, $tenant;
    $items = (array) ($data->items ?? []);
    if (!$items) json(['error'=>true,'message'=>'Agregue productos para evaluar promociones.'],400);
    $idCliente = (int) ($data->id_cliente ?? 0);
    $coupons = (array) ($data->cupones ?? []);
    try {
        $result = promotionEvaluate($conn,$tenant->accountId,$uid,$items,$idCliente ?: null,$coupons,[
            'id_sucursal'=>(int)($data->id_sucursal ?? 0),
            'canal'=>trim((string)($data->canal ?? 'POS')) ?: 'POS',
            'lista_precios'=>trim((string)($data->lista_precios ?? '')),
        ]);
        json($result);
    } catch (Throwable $e) {
        json(['error'=>true,'message'=>$e->getMessage()],400);
    }
}

function POST_promocion_validar($data) {
    global $conn, $uid, $tenant;

    $codigo = $data->codigo ?? '';
    if (empty($codigo)) {
        json(['error' => true, 'message' => 'Código de promoción requerido'], 400);
    }
    try {
        $evaluation=promotionEvaluate($conn,$tenant->accountId,$uid,(array)($data->items??[]),(int)($data->id_cliente??0)?:null,[$codigo],['canal'=>'POS']);
        $applied=array_values(array_filter($evaluation['aplicadas'],fn($p)=>strcasecmp((string)$p['codigo'],(string)$codigo)===0));
        if(!$applied){$reason='La promoción no cumple sus condiciones.';foreach($evaluation['rechazadas'] as $rejection)if(strcasecmp((string)$rejection['codigo'],(string)$codigo)===0){$reason=$rejection['motivo'];break;}json(['valida'=>false,'message'=>$reason]);}
        json(['valida'=>true,'promocion'=>$applied[0],'descuento'=>$applied[0]['descuento'],'descripcion'=>$applied[0]['nombre'],'evaluacion'=>$evaluation]);
    } catch(Throwable $e){json(['valida'=>false,'message'=>$e->getMessage()]);}
}

function POST_promocion_eliminar($data) {
    global $conn, $uid, $tenant;

    $idPromocion = (int) ($data->id_promocion ?? 0);
    if ($idPromocion <= 0) {
        json(['error' => true, 'message' => 'ID de promoción requerido'], 400);
    }

    $motivo=trim((string)($data->motivo??'Desactivada desde el administrador POS'));
    $sql = "UPDATE pos_promocion SET activo=0,estado='INACTIVA',motivo=? WHERE id_promocion = ? AND id_cuenta = ? AND activo=1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sii',$motivo,$idPromocion,$tenant->accountId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json(['error' => true, 'message' => 'Promoción no encontrada'], 404);
    }

    promotionAudit($conn,$tenant->accountId,$uid,$idPromocion,null,null,'ELIMINADA',$motivo,0);
    json(['success' => true, 'message' => 'Promoción desactivada']);
}

function POST_cotizacion_crear($data) {
    global $conn, $uid, $tenant;

    $items          = $data->items ?? [];
    $codigo         = $data->codigo ?? ('COT-' . date('Ymd') . '-' . rand(100, 999));
    $idCliente      = (int)($data->id_cliente ?? 0) ?: null;
    $clienteNombre  = $data->cliente_nombre ?? '';
    $clienteRut     = $data->cliente_rut ?? '';
    $clienteCorreo  = $data->cliente_correo ?? '';
    $clienteTelefono = $data->cliente_telefono ?? '';
    $descuento      = (int) ($data->descuento ?? 0);
    $validez        = $data->validez ?? '7 días';
    $notas          = $data->notas ?? '';
    $couponCodes    = (array)($data->cupones ?? []);

    if (empty($items)) {
        json(['error' => true, 'message' => 'Se requiere al menos un producto'], 400);
    }

    $conn->begin_transaction();
    try {
        if($idCliente){$stmt=$conn->prepare('SELECT nombre,rut,correo,telefono FROM cliente WHERE id_cliente=? AND id_cuenta=? AND COALESCE(activo,1)=1');$stmt->bind_param('ii',$idCliente,$tenant->accountId);$stmt->execute();$client=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$client)throw new InvalidArgumentException('El cliente seleccionado no existe o pertenece a otra cuenta.');$clienteNombre=$client['nombre'];$clienteRut=$client['rut'];$clienteCorreo=$client['correo'];$clienteTelefono=$client['telefono'];}
        $evaluation=promotionEvaluate($conn,$tenant->accountId,$uid,(array)$items,$idCliente,$couponCodes,['canal'=>'POS','id_sucursal'=>(int)($data->id_sucursal??0)],true);
        $subtotal=(int)$evaluation['subtotal'];$descuento=(int)$evaluation['descuento'];$total=(int)$evaluation['total'];
        $quoteLines=[];foreach($evaluation['lineas'] as $line)$quoteLines[(int)$line['id_producto']]=$line;
        $couponsJson=json_encode($couponCodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$promotionsJson=json_encode($evaluation['aplicadas'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $sql = "INSERT INTO pos_cotizacion (id_user, id_cuenta, codigo, id_cliente, cliente_nombre, cliente_rut, cliente_correo, cliente_telefono,
                    subtotal, descuento, total, validez, notas,cupones_json,promociones_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?,?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iisissssiiissss', $uid, $tenant->accountId, $codigo, $idCliente, $clienteNombre, $clienteRut, $clienteCorreo, $clienteTelefono,
            $subtotal, $descuento, $total, $validez, $notas,$couponsJson,$promotionsJson);
        $stmt->execute();
        $idCotizacion = $conn->insert_id;
        $stmt->close();

        // Insert items
        $sql = "INSERT INTO pos_cotizacion_detalle (id_cotizacion, id_producto, producto, sku, cantidad, precio_unitario, descuento, subtotal)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        foreach ($items as $item) {
            $idProducto     = $item->id_producto ?? null;
            $canonicalLine=$quoteLines[(int)$idProducto]??null;
            if(!$canonicalLine)throw new InvalidArgumentException('Producto inválido en cotización.');
            $productoNombre = $canonicalLine['nombre_producto'];
            $sku            = $canonicalLine['sku']?:$canonicalLine['codigo_de_barras'];
            $cantidad       = (float) ($item->cantidad ?? 1);
            $precioUnitario = (int)$canonicalLine['precio_original'];
            $itemDesc       = (int)round((int)$canonicalLine['descuento']*$cantidad/max(.001,(float)$canonicalLine['cantidad']));
            $itemSubtotal   = (int) round($precioUnitario * $cantidad) - $itemDesc;

            $stmt->bind_param('iissdiii', $idCotizacion, $idProducto, $productoNombre, $sku, $cantidad, $precioUnitario, $itemDesc, $itemSubtotal);
            $stmt->execute();
        }
        $stmt->close();

        insertAuditoria($conn, $uid, 'cotizacion_crear', "Cotización #{$idCotizacion} creada", $idCotizacion, 'pos_cotizacion');

        $conn->commit();

        json(['success' => true, 'id_cotizacion' => $idCotizacion, 'codigo'=>$codigo, 'total' => $total]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_cotizacion_convertir($data) {
    global $conn, $uid, $tenant;

    $idCotizacion = (int) ($data->id_cotizacion ?? 0);
    if ($idCotizacion <= 0) {
        json(['error' => true, 'message' => 'ID de cotización requerido'], 400);
    }

    $conn->begin_transaction();
    try {
        // Get cotizacion
        $sql = "SELECT * FROM pos_cotizacion WHERE id_cotizacion = ? AND id_user = ? AND convertida = 0 FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $idCotizacion, $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $cot = $result->fetch_assoc();
        $stmt->close();

        if (!$cot) {
            throw new Exception('Cotización no encontrada o ya convertida.');
        }

        // Get items
        $sql = "SELECT * FROM pos_cotizacion_detalle WHERE id_cotizacion = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $idCotizacion);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();

        if (empty($items)) {
            throw new Exception('Cotización sin productos.');
        }

        // Get session and bodega
        $sql = "SELECT * FROM sesion WHERE id_user = ? AND fecha_cierre IS NULL ORDER BY id_sesion DESC LIMIT 1 FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $sesion = $result->fetch_assoc();
        $stmt->close();

        if (!$sesion) {
            throw new Exception('No hay sesión activa. Abra la caja primero.');
        }
        $idSesion = (int) $sesion['id_sesion'];

        $idBodega = getDefaultBodega($conn);
        if ($idBodega === 0) {
            throw new Exception('No se encontró una bodega activa.');
        }

        $caja = getOpenCaja($conn, $uid);
        $idCaja = $caja ? (int) $caja['id_caja'] : null;

        $idCliente = $cot['id_cliente'];
        $clienteNombre  = $cot['cliente_nombre'] ?? '';
        $clienteRut     = $cot['cliente_rut'] ?? '';
        $clienteCorreo  = $cot['cliente_correo'] ?? '';
        $clienteTelefono = $cot['cliente_telefono'] ?? '';
        $precioTotal = (int) $cot['total'];
        $tipoDocumento = $data->tipo_documento ?? 'BOLETA';
        $pagos = $data->pagos ?? [];

        if (empty($pagos)) {
            // Auto-create single EFECTIVO pago
            $pagos = [(object) ['metodo' => 'EFECTIVO', 'monto' => $precioTotal]];
        }

        $pagoTotal = 0;
        foreach ($pagos as $pago) {
            $pagoTotal += (int) ($pago->monto ?? 0);
        }
        $diferenciaPedido = $pagoTotal - $precioTotal;

        // INSERT pedido
        $sql = "INSERT INTO pedido (id_cuenta, id_sesion, id_cliente, id_caja, id_bodega, tipo_documento,
                    cliente_nombre, cliente_rut, cliente_correo, cliente_telefono,
                    precio_total, pago_total, diferencia, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiiisssssiiii', $tenant->accountId, $idSesion, $idCliente, $idCaja, $idBodega, $tipoDocumento,
            $clienteNombre, $clienteRut, $clienteCorreo, $clienteTelefono,
            $precioTotal, $pagoTotal, $diferenciaPedido);
        $stmt->execute();
        $idPedido = $conn->insert_id;
        $stmt->close();

        // Process items
        foreach ($items as $item) {
            $idProducto     = (int) ($item['id_producto'] ?? 0);
            $cantidad       = (float) ($item['cantidad'] ?? 1);
            $precioUnitario = (int) ($item['precio_unitario'] ?? 0);
            $itemTotal      = (int) ($item['subtotal'] ?? 0);

            if ($idProducto > 0) {
                // Check stock
                $sql = "SELECT tipo_venta, costo_promedio FROM producto WHERE id_producto = ? AND id_cuenta = ? AND activo = 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ii', $idProducto, $tenant->accountId);
                $stmt->execute();
                $res = $stmt->get_result();
                $prod = $res->fetch_assoc();
                $stmt->close();

                if ($prod) {
                    $tipoVenta = $prod['tipo_venta'] ?? 'UNIDAD';
                    $costoUnit = (int) round(((float) $prod['costo_promedio']) * 100);
                    $cantidadStock = posNormalizeStockQuantity($cantidad, $tipoVenta);
                    $cantidad = $cantidadStock;

                    // INSERT detalle
                    $sql = "INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad_pedida, precio_total)
                            VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('iidi', $idPedido, $idProducto, $cantidad, $itemTotal);
                    $stmt->execute();
                    $stmt->close();

                    descontarStock($conn, $idProducto, $idBodega, $cantidadStock);
                    actualizarKardex($conn, $uid, $idProducto, $idBodega, 'VENTA', $idPedido, 'PEDIDO', 0, $cantidadStock, $costoUnit, "Venta desde cotización #{$idCotizacion}");
                }
            }
        }

        // INSERT pagos
        foreach ($pagos as $pago) {
            $metodoPago = $pago->metodo ?? $pago->nombre_metodo_pago ?? 'EFECTIVO';
            $montoPago  = (int) ($pago->monto ?? 0);

            $sql = "INSERT INTO metodo_de_pago (id_pedido, nombre_metodo_pago, monto) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isi', $idPedido, $metodoPago, $montoPago);
            $stmt->execute();
            $stmt->close();

            if ($idCaja) {
                $sql = "UPDATE pos_caja SET monto_actual = monto_actual + ? WHERE id_caja = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ii', $montoPago, $idCaja);
                $stmt->execute();
                $stmt->close();

                $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, id_pedido)
                        VALUES (?, ?, 'INGRESO', 'Venta desde cotización', ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iiisi', $idCaja, $uid, $montoPago, $metodoPago, $idPedido);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Mark cotizacion as converted
        $sql = "UPDATE pos_cotizacion SET convertida = 1 WHERE id_cotizacion = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $idCotizacion);
        $stmt->execute();
        $stmt->close();

        insertAuditoria($conn, $uid, 'cotizacion_convertir', "Cotización #{$idCotizacion} convertida en Pedido #{$idPedido}", $idPedido, 'pedido');

        $conn->commit();

        json(['success' => true, 'id_pedido' => $idPedido, 'total' => $precioTotal]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_cotizacion_eliminar($data) {
    global $conn, $uid, $tenant;

    $idCotizacion = (int) ($data->id_cotizacion ?? 0);
    if ($idCotizacion <= 0) {
        json(['error' => true, 'message' => 'ID de cotización requerido'], 400);
    }

    $sql = "DELETE FROM pos_cotizacion WHERE id_cotizacion = ? AND id_cuenta = ? AND convertida=0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $idCotizacion, $tenant->accountId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json(['error' => true, 'message' => 'Cotización no encontrada'], 404);
    }

    json(['success' => true, 'message' => 'Cotización eliminada']);
}

function POST_reserva_crear($data) {
    global $conn, $uid, $tenant;

    $idCliente       = $data->id_cliente ?? null;
    $clienteNombre   = $data->cliente_nombre ?? '';
    $clienteTelefono = $data->cliente_telefono ?? '';
    $total           = (int) ($data->total ?? 0);
    $abono           = (int) ($data->abono ?? 0);
    $fechaReserva    = $data->fecha_reserva ?? date('Y-m-d');
    $fechaVencimiento = $data->fecha_vencimiento ?? null;
    $notas           = $data->notas ?? '';
    $idReferencia    = $data->id_referencia ?? null;

    $sql = "INSERT INTO pos_reserva (id_user, id_cuenta, id_cliente, id_referencia, cliente_nombre, cliente_telefono, total, abono, fecha_reserva, fecha_vencimiento, notas)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiissiisss', $uid, $tenant->accountId, $idCliente, $idReferencia, $clienteNombre, $clienteTelefono, $total, $abono, $fechaReserva, $fechaVencimiento, $notas);
    $stmt->execute();
    $idReserva = $conn->insert_id;
    $stmt->close();

    json(['success' => true, 'id_reserva' => $idReserva]);
}

function POST_reserva_cumplir($data) {
    global $conn, $uid, $tenant;

    $idReserva = (int) ($data->id_reserva ?? 0);
    if ($idReserva <= 0) {
        json(['error' => true, 'message' => 'ID de reserva requerido'], 400);
    }

    $sql = "UPDATE pos_reserva SET estado = 'CUMPLIDA' WHERE id_reserva = ? AND id_user = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $idReserva, $uid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json(['error' => true, 'message' => 'Reserva no encontrada'], 404);
    }

    json(['success' => true, 'message' => 'Reserva marcada como cumplida']);
}

function POST_reserva_cancelar($data) {
    global $conn, $uid, $tenant;

    $idReserva = (int) ($data->id_reserva ?? 0);
    if ($idReserva <= 0) {
        json(['error' => true, 'message' => 'ID de reserva requerido'], 400);
    }

    $sql = "UPDATE pos_reserva SET estado = 'CANCELADA' WHERE id_reserva = ? AND id_user = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $idReserva, $uid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json(['error' => true, 'message' => 'Reserva no encontrada'], 404);
    }

    json(['success' => true, 'message' => 'Reserva cancelada']);
}

function POST_devolucion_crear($data) {
    global $conn, $uid, $tenant;

    $pedidoId = (int) ($data->id_pedido ?? 0);
    $tipo     = $data->tipo ?? 'TOTAL';
    $motivo   = $data->motivo ?? '';
    $items    = $data->items ?? [];

    if ($pedidoId <= 0) {
        json(['error' => true, 'message' => 'ID de pedido requerido'], 400);
    }

    $ctx=['entidad_tipo'=>'pedido','entidad_id'=>(string)$pedidoId,'tipo'=>$tipo];
    supervisorRequire('pos.devolucion',$ctx,$data->supervisor_token ?? null);
    try {
        // Get pedido
    $conn->begin_transaction();
        $sql = "SELECT p.*, s.id_user AS sesion_user
                FROM pedido p
                JOIN sesion s ON p.id_sesion = s.id_sesion
                WHERE p.id_pedido = ? AND p.anulado = 0 AND p.devuelto = 0
                FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pedidoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $pedido = $result->fetch_assoc();
        $stmt->close();

        if (!$pedido) {
            throw new Exception('Pedido no encontrado, anulado o ya devuelto.');
        }

        // Verify ownership via cuenta
        $cuenta = buildCuentaFilter($conn, $uid, 's.id_user');
        $sql = "SELECT p.id_pedido FROM pedido p
                JOIN sesion s ON p.id_sesion = s.id_sesion
                WHERE p.id_pedido = ? AND {$cuenta['sql']}";
        $cParams = $cuenta['params'];
        $cTypes  = $cuenta['types'];
        $cParamsFull = array_merge([$pedidoId], $cParams);
        $cTypesFull  = 'i' . $cTypes;
        $stmt = $conn->prepare($sql);
        if ($cParamsFull) {
            $stmt->bind_param($cTypesFull, ...$cParamsFull);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result->fetch_assoc()) {
            $stmt->close();
            throw new Exception('No tiene permisos para esta devolución.');
        }
        $stmt->close();

        $idBodega = (int) $pedido['id_bodega'];

        // Calculate total monto
        $montoTotal = 0;
        foreach ($items as $item) {
            $sub = (int) ($item->subtotal ?? 0);
            $montoTotal += $sub;
        }

        // INSERT devolucion
        $sql = "INSERT INTO pos_devolucion (id_user, id_pedido, tipo, motivo, monto_devuelto)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iissi', $uid, $pedidoId, $tipo, $motivo, $montoTotal);
        $stmt->execute();
        $idDevolucion = $conn->insert_id;
        $stmt->close();

        // Process items
        foreach ($items as $item) {
            $idProducto     = (int) ($item->id_producto ?? 0);
            $cantidad       = (float) ($item->cantidad ?? 1);
            $precioUnit     = (int) ($item->precio_unitario ?? 0);
            $subtotalItem   = (int) ($item->subtotal ?? 0);

            // INSERT detalle
            $sql = "INSERT INTO pos_devolucion_detalle (id_devolucion, id_producto, cantidad, precio_unitario, subtotal)
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iidii', $idDevolucion, $idProducto, $cantidad, $precioUnit, $subtotalItem);
            $stmt->execute();
            $stmt->close();

            // Get product data
            $sql = "SELECT tipo_venta, costo_promedio FROM producto WHERE id_producto = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $idProducto);
            $stmt->execute();
            $res = $stmt->get_result();
            $prod = $res->fetch_assoc() ?: ['tipo_venta' => 'UNIDAD', 'costo_promedio' => 0];
            $stmt->close();

            $tipoVenta = $prod['tipo_venta'];
            $costoUnit = (int) round(((float) $prod['costo_promedio']) * 100);
            $cantidadStock = posNormalizeStockQuantity($cantidad, $tipoVenta);

            // Reponer stock
            reponerStock($conn, $idProducto, $idBodega, $cantidadStock);

            // Kardex
            actualizarKardex($conn, $uid, $idProducto, $idBodega, 'DEVOLUCION', $idDevolucion, 'DEVOLUCION', $cantidadStock, 0, $costoUnit, "Devolución #{$idDevolucion}, Pedido #{$pedidoId}");
        }

        // Revertir el pago en caja. Para devoluciones parciales se distribuye
        // proporcionalmente entre los métodos originales; en TOTAL se revierte todo.
        $idCaja=(int)($pedido['id_caja']??0);
        if ($idCaja>0 && $montoTotal>0) {
            $stmt=$conn->prepare('SELECT nombre_metodo_pago,monto FROM metodo_de_pago WHERE id_pedido=? ORDER BY id_metodo_de_pago');
            $stmt->bind_param('i',$pedidoId);$stmt->execute();$pagosOriginales=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            $totalOriginal=max(1,(int)($pedido['precio_total']??0));
            $restante=min($montoTotal,$totalOriginal);$cantidadPagos=count($pagosOriginales);
            foreach ($pagosOriginales as $idx=>$pago) {
                if ($restante<=0) break;
                $montoPago=(int)($pago['monto']??0);
                $montoReversa=($idx===$cantidadPagos-1)?$restante:min($restante,(int)round($montoPago*$montoTotal/$totalOriginal));
                if ($montoReversa<=0) continue;
                $metodo=posCanonicalPaymentMethod($pago['nombre_metodo_pago']??'EFECTIVO');
                $concepto="Devolución venta #{$pedidoId}";
                if ($metodo==='EFECTIVO') {
                    $stmt=$conn->prepare('UPDATE pos_caja SET monto_actual=monto_actual-? WHERE id_caja=?');
                    $stmt->bind_param('ii',$montoReversa,$idCaja);$stmt->execute();$stmt->close();
                }
                $stmt=$conn->prepare("INSERT INTO pos_movimiento_caja(id_caja,id_user,tipo,concepto,monto,metodo,id_pedido) VALUES(?,?,'EGRESO',?,?,?,?)");
                $stmt->bind_param('iisisi',$idCaja,$uid,$concepto,$montoReversa,$metodo,$pedidoId);$stmt->execute();$stmt->close();
                $restante-=$montoReversa;
            }
            if ($restante>0) throw new Exception('No se pudo distribuir completamente el monto de la devolución.');
        }

        // Mark pedido as devuelto
        $sql = "UPDATE pedido SET devuelto = 1 WHERE id_pedido = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pedidoId);
        $stmt->execute();
        $stmt->close();

        insertAuditoria($conn, $uid, 'devolucion_crear', "Devolución #{$idDevolucion} del Pedido #{$pedidoId}", $idDevolucion, 'pos_devolucion');

        $conn->commit();

        json(['success' => true, 'id_devolucion' => $idDevolucion, 'monto_devuelto' => $montoTotal]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}
