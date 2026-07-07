<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
$conn = getDB();
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
    $sql = "SELECT * FROM pos_caja WHERE id_user = ? AND estado = 'ABIERTA' ORDER BY id_caja DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function insertAuditoria($conn, $uid, $accion, $detalle, $idRef = null, $tablaRef = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $sql = "INSERT INTO pos_auditoria (id_user, accion, detalle, id_referencia, tabla_referencia, ip)
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ississ', $uid, $accion, $detalle, $idRef, $tablaRef, $ip);
    $stmt->execute();
    $stmt->close();
}

function requirePermisoApi($modulo, $accion, $message) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error' => true, 'message' => $message], 403);
    }
}

// ============================================================
// ROUTER
// ============================================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

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
        case 'reporte_ventas_hora': GET_reporte_ventas_hora();break;
        case 'reporte_ventas_cajero':GET_reporte_ventas_cajero();break;
        case 'reporte_productos_top':GET_reporte_productos_top();break;
        case 'config_boleta':       GET_config_boleta();      break;
        case 'permisos_usuario':    GET_permisos_usuario();   break;
        default:
            json(['error' => true, 'message' => 'Acción no válida'], 400);
    }
} elseif ($method === 'POST') {
    $data = getJsonInput();
    if (!$data) {
        json(['error' => true, 'message' => 'Datos JSON requeridos'], 400);
    }
    $action = $data->action ?? '';

    switch ($action) {
        case 'caja_abrir':          POST_caja_abrir($data);       break;
        case 'caja_cerrar':         POST_caja_cerrar($data);      break;
        case 'caja_movimiento':     POST_caja_movimiento($data);  break;
        case 'venta_crear':         POST_venta_crear($data);      break;
        case 'venta_anular':        POST_venta_anular($data);     break;
        case 'cliente_crear':       POST_cliente_crear($data);    break;
        case 'promocion_crear':     POST_promocion_crear($data);  break;
        case 'promocion_validar':   POST_promocion_validar($data);break;
        case 'promocion_eliminar':  POST_promocion_eliminar($data);break;
        case 'cotizacion_crear':    POST_cotizacion_crear($data);  break;
        case 'cotizacion_convertir':POST_cotizacion_convertir($data);break;
        case 'cotizacion_eliminar': POST_cotizacion_eliminar($data);break;
        case 'reserva_crear':       POST_reserva_crear($data);     break;
        case 'reserva_cumplir':     POST_reserva_cumplir($data);   break;
        case 'reserva_cancelar':    POST_reserva_cancelar($data);  break;
        case 'devolucion_crear':    POST_devolucion_crear($data);  break;
        case 'config_boleta_guardar':POST_config_boleta_guardar($data);break;
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
    global $conn, $uid;

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
    global $conn, $uid;

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
    global $conn, $uid;
    $search = $_GET['q'] ?? '';
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = max(1, min(100, (int) ($_GET['por_pagina'] ?? 20)));
    $offset = ($pagina - 1) * $porPagina;

    $cuenta = buildCuentaFilter($conn, $uid, 'id_user');
    $where = $cuenta['sql'];
    $params = $cuenta['params'];
    $types  = $cuenta['types'];

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
    global $conn, $uid;
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
    global $conn, $uid;
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
    global $conn, $uid;
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
    global $conn, $uid;

    $cuenta = buildCuentaFilter($conn, $uid, 'pp.id_user');
    $sql = "SELECT pp.*,
                   GROUP_CONCAT(DISTINCT CONCAT(ppp.id_producto, ':', pr.nombre_producto) SEPARATOR '||') AS productos
            FROM pos_promocion pp
            LEFT JOIN pos_promocion_producto ppp ON pp.id_promocion = ppp.id_promocion
            LEFT JOIN producto pr ON ppp.id_producto = pr.id_producto
            WHERE {$cuenta['sql']}
            GROUP BY pp.id_promocion
            ORDER BY pp.id_promocion DESC";
    $stmt = $conn->prepare($sql);
    if ($cuenta['params']) {
        $stmt->bind_param($cuenta['types'], ...$cuenta['params']);
    }
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
    global $conn, $uid;

    $sql = "SELECT * FROM pos_cotizacion WHERE id_user = ? ORDER BY id_cotizacion DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
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
        $sql = "SELECT * FROM pos_cotizacion_detalle WHERE id_cotizacion = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $cid);
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
    global $conn, $uid;

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
    global $conn, $uid;
    $pedidoId = (int) ($_GET['id'] ?? 0);
    if ($pedidoId <= 0) {
        json(['error' => true, 'message' => 'ID de pedido requerido'], 400);
    }

    $sql = "SELECT p.*, s.empleado
            FROM pedido p
            JOIN sesion s ON p.id_sesion = s.id_sesion
            WHERE p.id_pedido = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $result = $stmt->get_result();
    $venta = $result->fetch_assoc();
    $stmt->close();

    if (!$venta) {
        json(['error' => true, 'message' => 'Pedido no encontrado'], 404);
    }

    // Items
    $sql = "SELECT dp.*, p.nombre_producto, p.codigo_de_barras
            FROM detalle_pedido dp
            JOIN producto p ON dp.id_producto = p.id_producto
            WHERE dp.id_pedido = ?";
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

    json(['venta' => $venta]);
}

function GET_reporte_ventas_hora() {
    global $conn, $uid;
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
    global $conn, $uid;
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
    global $conn, $uid;
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
    global $conn, $uid;

    $sql = "SELECT * FROM config_boleta WHERE id_user = ? AND activo = 1 ORDER BY id_config DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $config = $result->fetch_assoc();
    $stmt->close();

    json(['config' => $config ?: null]);
}

function validarLogoBoleta($logo) {
    if ($logo === null || $logo === '') {
        return $logo;
    }

    if (!is_string($logo)) {
        json(['error' => true, 'message' => 'Logo invalido'], 400);
    }

    if (strlen($logo) > 3000000) {
        json(['error' => true, 'message' => 'Logo demasiado grande'], 400);
    }

    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp|gif);base64,[A-Za-z0-9+\/=]+$/', $logo)) {
        json(['error' => true, 'message' => 'Formato de logo no permitido'], 400);
    }

    return $logo;
}

function POST_config_boleta_guardar($data) {
    global $conn, $uid;

    requirePermisoApi('configuracion', 'editar', 'No tiene permiso para editar configuracion.');

    $nombreEmpresa = trim((string) ($data->nombre_empresa ?? ''));
    $rutEmpresa = trim((string) ($data->rut_empresa ?? ''));
    $direccion = trim((string) ($data->direccion ?? ''));
    $telefono = trim((string) ($data->telefono ?? ''));
    $email = trim((string) ($data->email ?? ''));
    $logo = property_exists($data, 'logo') ? validarLogoBoleta($data->logo) : null;
    $mensajePie = trim((string) ($data->mensaje_pie ?? ''));
    $mensajeAgradecimiento = trim((string) ($data->mensaje_agradecimiento ?? ''));
    $mostrarRutCliente = !empty($data->mostrar_rut_cliente) ? 1 : 0;
    $mostrarDesgloseIva = !empty($data->mostrar_desglose_iva) ? 1 : 0;
    $mostrarDescuento = !empty($data->mostrar_descuento) ? 1 : 0;
    $ivaPorcentaje = isset($data->iva_porcentaje) ? (float) $data->iva_porcentaje : 19.00;

    if ($nombreEmpresa === '') {
        json(['error' => true, 'message' => 'Nombre de empresa requerido'], 400);
    }

    if ($ivaPorcentaje < 0 || $ivaPorcentaje > 100) {
        json(['error' => true, 'message' => 'IVA invalido'], 400);
    }

    $conn->begin_transaction();
    try {
        $sql = "SELECT id_config, logo FROM config_boleta WHERE id_user = ? AND activo = 1 ORDER BY id_config DESC LIMIT 1 FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $actual = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($logo === null && $actual) {
            $logo = $actual['logo'];
        }

        if ($actual) {
            $idConfig = (int) $actual['id_config'];
            $sql = "UPDATE config_boleta
                    SET nombre_empresa = ?, rut_empresa = ?, direccion = ?, telefono = ?, email = ?, logo = ?,
                        mensaje_pie = ?, mensaje_agradecimiento = ?, mostrar_rut_cliente = ?,
                        mostrar_desglose_iva = ?, mostrar_descuento = ?, iva_porcentaje = ?
                    WHERE id_config = ? AND id_user = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'ssssssssiiidii',
                $nombreEmpresa,
                $rutEmpresa,
                $direccion,
                $telefono,
                $email,
                $logo,
                $mensajePie,
                $mensajeAgradecimiento,
                $mostrarRutCliente,
                $mostrarDesgloseIva,
                $mostrarDescuento,
                $ivaPorcentaje,
                $idConfig,
                $uid
            );
            $idReferencia = $idConfig;
        } else {
            $sql = "INSERT INTO config_boleta
                    (id_user, nombre_empresa, rut_empresa, direccion, telefono, email, logo, mensaje_pie,
                     mensaje_agradecimiento, mostrar_rut_cliente, mostrar_desglose_iva, mostrar_descuento,
                     iva_porcentaje, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'issssssssiiid',
                $uid,
                $nombreEmpresa,
                $rutEmpresa,
                $direccion,
                $telefono,
                $email,
                $logo,
                $mensajePie,
                $mensajeAgradecimiento,
                $mostrarRutCliente,
                $mostrarDesgloseIva,
                $mostrarDescuento,
                $ivaPorcentaje
            );
            $idReferencia = 0;
        }

        if (!$stmt || !$stmt->execute()) {
            throw new Exception('No se pudo guardar la plantilla de boleta.');
        }

        if ($idReferencia === 0) {
            $idReferencia = $conn->insert_id;
        }

        $stmt->close();

        insertAuditoria($conn, $uid, 'config_boleta_guardar', 'Plantilla de boleta actualizada', $idReferencia, 'config_boleta');
        $conn->commit();

        json(['success' => true, 'ok' => true, 'message' => 'Plantilla de boleta actualizada correctamente']);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 500);
    }
}

function GET_permisos_usuario() {
    global $conn, $uid;

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
    global $conn, $uid;

    $codigo         = $data->codigo ?? '';
    $nombreCaja     = $data->nombre ?? 'Caja Principal';
    $sucursal       = $data->sucursal ?? 'Principal';
    $montoApertura  = (int) ($data->monto_apertura ?? 0);

    // Get open caja with FOR UPDATE
    $conn->begin_transaction();

    try {
        $sql = "SELECT id_caja FROM pos_caja WHERE id_user = ? AND estado = 'ABIERTA' FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_assoc()) {
            $stmt->close();
            throw new Exception('Ya existe una caja abierta. Ciérrela primero.');
        }
        $stmt->close();

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
        $sql = "INSERT INTO sesion (id_user, fecha_ingreso, monto_apertura, empleado)
                VALUES (?, NOW(), ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iis', $uid, $montoApertura, $empleado);
        $stmt->execute();
        $idSesion = $conn->insert_id;
        $stmt->close();

        // INSERT pos_caja
        $sql = "INSERT INTO pos_caja (id_user, codigo, nombre, sucursal, estado, monto_apertura, monto_actual, fecha_apertura, id_sesion)
                VALUES (?, ?, ?, ?, 'ABIERTA', ?, ?, NOW(), ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isssiii', $uid, $codigo, $nombreCaja, $sucursal, $montoApertura, $montoApertura, $idSesion);
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

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_caja_cerrar($data) {
    global $conn, $uid;

    $montoReal = (int) ($data->monto_real ?? 0);

    $conn->begin_transaction();

    try {
        $caja = getOpenCaja($conn, $uid);
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
                WHERE id_caja = ? AND tipo != 'CIERRE'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $idCaja);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $esperado = (int) $row['ingresos'] - (int) $row['egresos'];
        $diferencia = $montoReal - $esperado;

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

        insertAuditoria($conn, $uid, 'caja_cerrar', "Caja {$idCaja} cerrada. Esperado: {$esperado}, Real: {$montoReal}, Dif: {$diferencia}", $idCaja, 'pos_caja');

        $conn->commit();

        json(['success' => true, 'esperado' => $esperado, 'monto_real' => $montoReal, 'diferencia' => $diferencia]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_caja_movimiento($data) {
    global $conn, $uid;

    $tipo     = strtoupper($data->tipo ?? 'INGRESO');
    $monto    = (int) ($data->monto ?? 0);
    $concepto = $data->concepto ?? '';
    $metodo   = $data->metodo ?? 'EFECTIVO';
    $referencia = $data->referencia ?? null;
    $idPedido = $data->id_pedido ?? null;

    if ($monto <= 0) {
        json(['error' => true, 'message' => 'Monto debe ser mayor a 0'], 400);
    }
    if (!in_array($tipo, ['INGRESO', 'EGRESO'])) {
        json(['error' => true, 'message' => 'Tipo debe ser INGRESO o EGRESO'], 400);
    }

    $caja = getOpenCaja($conn, $uid);
    if (!$caja) {
        json(['error' => true, 'message' => 'No hay caja abierta'], 400);
    }
    $idCaja = (int) $caja['id_caja'];

    $conn->begin_transaction();

    try {
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
    global $conn, $uid;

    $items          = $data->items ?? [];
    $pagos          = $data->pagos ?? [];
    $tipoDocumento  = $data->tipo_documento ?? 'BOLETA';
    $idCliente      = $data->id_cliente ?? null;
    $clienteNombre  = $data->cliente_nombre ?? '';
    $clienteRut     = $data->cliente_rut ?? '';
    $clienteCorreo  = $data->cliente_correo ?? '';
    $clienteTelefono = $data->cliente_telefono ?? '';
    $idPromocion    = $data->id_promocion ?? null;
    $descuentoTotal = (int) ($data->descuento ?? 0);

    if (empty($items)) {
        json(['error' => true, 'message' => 'Se requiere al menos un producto'], 400);
    }
    if (empty($pagos)) {
        json(['error' => true, 'message' => 'Se requiere al menos un método de pago'], 400);
    }

    // Validate promocion if descuento > 0
    if ($descuentoTotal > 0) {
        if (!$idPromocion) {
            json(['error' => true, 'message' => 'Descuento sin promoción asociada'], 400);
        }
        $sql = "SELECT * FROM pos_promocion WHERE id_promocion = ? AND id_user = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $pid = (int) $idPromocion;
        $stmt->bind_param('ii', $pid, $uid);
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

    $conn->begin_transaction();

    try {
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

        // Get open caja
        $caja = getOpenCaja($conn, $uid);
        $idCaja = $caja ? (int) $caja['id_caja'] : null;

        // Calculate total from items (all in cents)
        $precioTotal = 0;
        foreach ($items as $item) {
            $cantidad = (float) ($item->cantidad ?? 0);
            $precioUnitario = (int) ($item->precio_unitario ?? 0);
            $precioTotal += (int) round($precioUnitario * $cantidad);
        }

        // Apply descuento
        $precioFinal = $precioTotal - $descuentoTotal;
        if ($precioFinal < 0) {
            $precioFinal = 0;
        }

        // Calculate pago total
        $pagoTotal = 0;
        foreach ($pagos as $pago) {
            $pagoTotal += (int) ($pago->monto ?? 0);
        }

        $diferenciaPedido = $pagoTotal - $precioFinal;

        // INSERT pedido
        $sql = "INSERT INTO pedido (id_sesion, id_cliente, id_caja, id_bodega, tipo_documento,
                    cliente_nombre, cliente_rut, cliente_correo, cliente_telefono,
                    precio_total, pago_total, diferencia, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiisssssiiii', $idSesion, $idCliente, $idCaja, $idBodega, $tipoDocumento,
            $clienteNombre, $clienteRut, $clienteCorreo, $clienteTelefono,
            $precioFinal, $pagoTotal, $diferenciaPedido);
        $stmt->execute();
        $idPedido = $conn->insert_id;
        $stmt->close();

        // Process items
        foreach ($items as $item) {
            $idProducto     = (int) ($item->id_producto ?? 0);
            $cantidad       = (float) ($item->cantidad ?? 0);
            $precioUnitario = (int) ($item->precio_unitario ?? 0);
            $itemTotal      = (int) round($precioUnitario * $cantidad);

            if ($idProducto <= 0 || $cantidad <= 0) {
                throw new Exception('Ítem inválido: producto o cantidad no válidos.');
            }

            // Check product exists and get data
            $sql = "SELECT * FROM producto WHERE id_producto = ? AND activo = 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $idProducto);
            $stmt->execute();
            $result = $stmt->get_result();
            $producto = $result->fetch_assoc();
            $stmt->close();

            if (!$producto) {
                throw new Exception("Producto ID {$idProducto} no encontrado o inactivo.");
            }

            $tipoVenta = $producto['tipo_venta'] ?? 'UNIDAD';
            $costoUnit = (int) round(((float) ($producto['costo_promedio'] ?? 0)) * 100);

            // INSERT detalle_pedido
            $sql = "INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad_pedida, precio_total)
                    VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $cantidadPedida = $cantidad;
            $stmt->bind_param('iidi', $idPedido, $idProducto, $cantidadPedida, $itemTotal);
            $stmt->execute();
            $stmt->close();

            // Descontar stock
            if ($tipoVenta === 'UNIDAD') {
                $cantidadInt = (int) ceil($cantidad);
                $affected = descontarStock($conn, $idProducto, $idBodega, $cantidadInt);
                if ($affected === 0) {
                    throw new Exception("Stock insuficiente para el producto \"{$producto['nombre_producto']}\".");
                }
            } else {
                // Peso/volumen: direct SQL with DECIMAL
                $sql = "UPDATE stock SET disponible = disponible - ? WHERE id_producto = ? AND id_bodega = ? AND disponible >= ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('diid', $cantidad, $idProducto, $idBodega, $cantidad);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected === 0) {
                    throw new Exception("Stock insuficiente para el producto \"{$producto['nombre_producto']}\".");
                }
            }

            // Kardex
            $salidaKardex = ($tipoVenta === 'UNIDAD') ? (int) ceil($cantidad) : (int) round($cantidad * 1000);
            $salidaReal   = ($tipoVenta === 'UNIDAD') ? (int) ceil($cantidad) : 0;
            actualizarKardex($conn, $uid, $idProducto, $idBodega, 'VENTA', $idPedido, 'PEDIDO', 0, $salidaReal, $costoUnit, "Venta #{$idPedido}");
        }

        // INSERT pagos
        foreach ($pagos as $pago) {
            $metodoPago = $pago->metodo ?? $pago->nombre_metodo_pago ?? 'EFECTIVO';
            $montoPago  = (int) ($pago->monto ?? 0);

            $sql = "INSERT INTO metodo_de_pago (id_pedido, nombre_metodo_pago, monto)
                    VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isi', $idPedido, $metodoPago, $montoPago);
            $stmt->execute();
            $stmt->close();

            // Update caja monto_actual
            if ($idCaja) {
                $sql = "UPDATE pos_caja SET monto_actual = monto_actual + ? WHERE id_caja = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ii', $montoPago, $idCaja);
                $stmt->execute();
                $stmt->close();

                // INSERT movimiento caja
                $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, id_pedido)
                        VALUES (?, ?, 'INGRESO', 'Venta', ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iiisi', $idCaja, $uid, $montoPago, $metodoPago, $idPedido);
                $stmt->execute();
                $stmt->close();
            }
        }

        // INSERT descuento if applicable
        if ($descuentoTotal > 0 && $idPromocion) {
            $promoIdInt = (int) $idPromocion;
            $sql = "INSERT INTO pos_descuento (id_pedido, id_promocion, tipo, monto, motivo, autorizado_por)
                    VALUES (?, ?, 'PROMOCION', ?, 'Descuento por promoción', ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iiii', $idPedido, $promoIdInt, $descuentoTotal, $uid);
            $stmt->execute();
            $stmt->close();

            // Increment uso de promocion
            $sql = "UPDATE pos_promocion SET usado = usado + 1 WHERE id_promocion = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $promoIdInt);
            $stmt->execute();
            $stmt->close();
        }

        insertAuditoria($conn, $uid, 'venta_crear', "Pedido #{$idPedido} creado. Total: {$precioFinal}", $idPedido, 'pedido');

        $conn->commit();

        json(['success' => true, 'id_pedido' => $idPedido, 'total' => $precioFinal]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_venta_anular($data) {
    global $conn, $uid;

    $pedidoId = (int) ($data->id_pedido ?? 0);
    if ($pedidoId <= 0) {
        json(['error' => true, 'message' => 'ID de pedido requerido'], 400);
    }

    $conn->begin_transaction();

    try {
        // Validate venta exists and not already anulada
        $sql = "SELECT p.*, s.id_user AS sesion_user
                FROM pedido p
                JOIN sesion s ON p.id_sesion = s.id_sesion
                WHERE p.id_pedido = ?
                FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pedidoId);
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
            $sql = "SELECT tipo_venta, costo_promedio FROM producto WHERE id_producto = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $idProducto);
            $stmt->execute();
            $res = $stmt->get_result();
            $prod = $res->fetch_assoc() ?: ['tipo_venta' => 'UNIDAD', 'costo_promedio' => 0];
            $stmt->close();

            $tipoVenta = $prod['tipo_venta'];
            $costoUnit = (int) round(((float) $prod['costo_promedio']) * 100);

            if ($tipoVenta === 'UNIDAD') {
                reponerStock($conn, $idProducto, $idBodega, (int) ceil($cantidad));
            } else {
                $sql = "UPDATE stock SET disponible = disponible + ? WHERE id_producto = ? AND id_bodega = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('dii', $cantidad, $idProducto, $idBodega);
                $stmt->execute();
                $stmt->close();
            }

            $entradaKardex = ($tipoVenta === 'UNIDAD') ? (int) ceil($cantidad) : 0;
            actualizarKardex($conn, $uid, $idProducto, $idBodega, 'ANULACION', $pedidoId, 'PEDIDO', $entradaKardex, 0, $costoUnit, "Anulación pedido #{$pedidoId}");
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

                $sql = "UPDATE pos_caja SET monto_actual = monto_actual - ? WHERE id_caja = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ii', $montoPago, $idCaja);
                $stmt->execute();
                $stmt->close();

                $sql = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, id_pedido)
                        VALUES (?, ?, 'EGRESO', 'Anulación de venta', ?, 'EFECTIVO', ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iiii', $idCaja, $uid, $montoPago, $pedidoId);
                $stmt->execute();
                $stmt->close();
            }
        }

        insertAuditoria($conn, $uid, 'venta_anular', "Pedido #{$pedidoId} anulado", $pedidoId, 'pedido');

        $conn->commit();

        json(['success' => true, 'message' => 'Pedido anulado correctamente']);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_cliente_crear($data) {
    global $conn, $uid;

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

    $sql = "INSERT INTO cliente (id_user, codigo, rut, razon_social, nombre, correo, telefono, direccion, ciudad, giro, categoria)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $codigo = 'CLI-' . str_pad((string) rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issssssssss', $uid, $codigo, $rut, $razonSocial, $nombre, $correo, $telefono, $direccion, $ciudad, $giro, $categoria);
    $stmt->execute();
    $idCliente = $conn->insert_id;
    $stmt->close();

    json(['success' => true, 'id_cliente' => $idCliente]);
}

function POST_promocion_crear($data) {
    global $conn, $uid;

    requirePermisoApi('pos', 'crear_promocion', 'No tiene permiso para crear promociones.');

    $codigo       = $data->codigo ?? '';
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

    if (empty($nombre) || empty($codigo)) {
        json(['error' => true, 'message' => 'Nombre y código son requeridos'], 400);
    }

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO pos_promocion (id_user, codigo, nombre, tipo, valor, monto_minimo, cantidad_minima,
                    fecha_inicio, fecha_fin, dias_semana, hora_inicio, hora_fin, aplica_categoria, aplica_marca, combinable)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isssiiisssssssi', $uid, $codigo, $nombre, $tipo, $valor, $montoMinimo, $cantidadMinima,
            $fechaInicio, $fechaFin, $diasSemana, $horaInicio, $horaFin, $aplicaCategoria, $aplicaMarca, $combinable);
        $stmt->execute();
        $idPromocion = $conn->insert_id;
        $stmt->close();

        // Insert producto associations
        if (!empty($productos)) {
            $sql = "INSERT INTO pos_promocion_producto (id_promocion, id_producto) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            foreach ($productos as $pid) {
                $productoId = (int) $pid;
                $stmt->bind_param('ii', $idPromocion, $productoId);
                $stmt->execute();
            }
            $stmt->close();
        }

        insertAuditoria($conn, $uid, 'promocion_crear', "Promoción \"{$nombre}\" creada", $idPromocion, 'pos_promocion');

        $conn->commit();

        json(['success' => true, 'id_promocion' => $idPromocion]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_promocion_validar($data) {
    global $conn, $uid;

    $codigo = $data->codigo ?? '';
    $subtotal = (int) ($data->subtotal ?? 0);

    if (empty($codigo)) {
        json(['error' => true, 'message' => 'Código de promoción requerido'], 400);
    }

    $cuenta = buildCuentaFilter($conn, $uid, 'id_user');
    $sql = "SELECT * FROM pos_promocion WHERE codigo = ? AND {$cuenta['sql']} AND activo = 1";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$codigo], $cuenta['params']);
    $stmt->bind_param('s' . $cuenta['types'], ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $promo = $result->fetch_assoc();
    $stmt->close();

    if (!$promo) {
        json(['valida' => false, 'message' => 'Promoción no encontrada o inactiva']);
    }

    // Date validation
    $hoy = date('Y-m-d');
    if ($promo['fecha_inicio'] && $promo['fecha_inicio'] > $hoy) {
        json(['valida' => false, 'message' => 'Promoción aún no comienza']);
    }
    if ($promo['fecha_fin'] && $promo['fecha_fin'] < $hoy) {
        json(['valida' => false, 'message' => 'Promoción expirada']);
    }

    // Minimum amount
    $montoMin = (int) $promo['monto_minimo'];
    if ($montoMin > 0 && $subtotal < $montoMin) {
        json(['valida' => false, 'message' => "Requiere compra mínima de \${$montoMin}"]);
    }

    // Calculate descuento
    $tipo  = $promo['tipo'];
    $valor = (int) $promo['valor'];
    $descuento = 0;

    if ($tipo === 'PORCENTAJE') {
        $descuento = (int) round($subtotal * $valor / 100);
    } elseif ($tipo === 'FIJO') {
        $descuento = min($valor, $subtotal);
    }

    // Get associated products
    $sql = "SELECT id_producto FROM pos_promocion_producto WHERE id_promocion = ?";
    $stmt = $conn->prepare($sql);
    $pid = (int) $promo['id_promocion'];
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $result = $stmt->get_result();
    $productosIds = [];
    while ($row = $result->fetch_assoc()) {
        $productosIds[] = (int) $row['id_producto'];
    }
    $stmt->close();

    json([
        'valida'       => true,
        'promocion'    => $promo,
        'descuento'    => $descuento,
        'productos_ids'=> $productosIds,
    ]);
}

function POST_promocion_eliminar($data) {
    global $conn, $uid;

    requirePermisoApi('pos', 'crear_promocion', 'No tiene permiso para eliminar promociones.');

    $idPromocion = (int) ($data->id_promocion ?? 0);
    if ($idPromocion <= 0) {
        json(['error' => true, 'message' => 'ID de promoción requerido'], 400);
    }

    $cuenta = buildCuentaFilter($conn, $uid, 'id_user');
    $sql = "DELETE FROM pos_promocion WHERE id_promocion = ? AND {$cuenta['sql']}";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$idPromocion], $cuenta['params']);
    $stmt->bind_param('i' . $cuenta['types'], ...$params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json(['error' => true, 'message' => 'Promoción no encontrada'], 404);
    }

    json(['success' => true, 'message' => 'Promoción eliminada']);
}

function POST_cotizacion_crear($data) {
    global $conn, $uid;

    $items          = $data->items ?? [];
    $codigo         = $data->codigo ?? ('COT-' . date('Ymd') . '-' . rand(100, 999));
    $idCliente      = $data->id_cliente ?? null;
    $clienteNombre  = $data->cliente_nombre ?? '';
    $clienteRut     = $data->cliente_rut ?? '';
    $clienteCorreo  = $data->cliente_correo ?? '';
    $clienteTelefono = $data->cliente_telefono ?? '';
    $descuento      = (int) ($data->descuento ?? 0);
    $validez        = $data->validez ?? '7 días';
    $notas          = $data->notas ?? '';

    if (empty($items)) {
        json(['error' => true, 'message' => 'Se requiere al menos un producto'], 400);
    }

    $conn->begin_transaction();
    try {
        // Calculate subtotal
        $subtotal = 0;
        foreach ($items as $item) {
            $cantidad = (float) ($item->cantidad ?? 0);
            $precio   = (int) ($item->precio_unitario ?? 0);
            $subtotal += (int) round($precio * $cantidad);
        }

        $total = $subtotal - $descuento;
        if ($total < 0) {
            $total = 0;
        }

        $sql = "INSERT INTO pos_cotizacion (id_user, codigo, id_cliente, cliente_nombre, cliente_rut, cliente_correo, cliente_telefono,
                    subtotal, descuento, total, validez, notas)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isissssiiiis', $uid, $codigo, $idCliente, $clienteNombre, $clienteRut, $clienteCorreo, $clienteTelefono,
            $subtotal, $descuento, $total, $validez, $notas);
        $stmt->execute();
        $idCotizacion = $conn->insert_id;
        $stmt->close();

        // Insert items
        $sql = "INSERT INTO pos_cotizacion_detalle (id_cotizacion, id_producto, producto, sku, cantidad, precio_unitario, descuento, subtotal)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        foreach ($items as $item) {
            $idProducto     = $item->id_producto ?? null;
            $productoNombre = $item->producto ?? '';
            $sku            = $item->sku ?? '';
            $cantidad       = (float) ($item->cantidad ?? 1);
            $precioUnitario = (int) ($item->precio_unitario ?? 0);
            $itemDesc       = (int) ($item->descuento ?? 0);
            $itemSubtotal   = (int) round($precioUnitario * $cantidad) - $itemDesc;

            $stmt->bind_param('iissdiii', $idCotizacion, $idProducto, $productoNombre, $sku, $cantidad, $precioUnitario, $itemDesc, $itemSubtotal);
            $stmt->execute();
        }
        $stmt->close();

        insertAuditoria($conn, $uid, 'cotizacion_crear', "Cotización #{$idCotizacion} creada", $idCotizacion, 'pos_cotizacion');

        $conn->commit();

        json(['success' => true, 'id_cotizacion' => $idCotizacion, 'total' => $total]);

    } catch (Exception $e) {
        $conn->rollback();
        json(['error' => true, 'message' => $e->getMessage()], 400);
    }
}

function POST_cotizacion_convertir($data) {
    global $conn, $uid;

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
        $sql = "INSERT INTO pedido (id_sesion, id_cliente, id_caja, id_bodega, tipo_documento,
                    cliente_nombre, cliente_rut, cliente_correo, cliente_telefono,
                    precio_total, pago_total, diferencia, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiisssssiiii', $idSesion, $idCliente, $idCaja, $idBodega, $tipoDocumento,
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
                $sql = "SELECT tipo_venta, costo_promedio FROM producto WHERE id_producto = ? AND activo = 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $idProducto);
                $stmt->execute();
                $res = $stmt->get_result();
                $prod = $res->fetch_assoc();
                $stmt->close();

                if ($prod) {
                    $tipoVenta = $prod['tipo_venta'] ?? 'UNIDAD';
                    $costoUnit = (int) round(((float) $prod['costo_promedio']) * 100);

                    // INSERT detalle
                    $sql = "INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad_pedida, precio_total)
                            VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('iidi', $idPedido, $idProducto, $cantidad, $itemTotal);
                    $stmt->execute();
                    $stmt->close();

                    // Descontar stock
                    if ($tipoVenta === 'UNIDAD') {
                        descontarStock($conn, $idProducto, $idBodega, (int) ceil($cantidad));
                    } else {
                        $sql = "UPDATE stock SET disponible = disponible - ? WHERE id_producto = ? AND id_bodega = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param('dii', $cantidad, $idProducto, $idBodega);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $salidaKardex = ($tipoVenta === 'UNIDAD') ? (int) ceil($cantidad) : 0;
                    actualizarKardex($conn, $uid, $idProducto, $idBodega, 'VENTA', $idPedido, 'PEDIDO', 0, $salidaKardex, $costoUnit, "Venta desde cotización #{$idCotizacion}");
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
    global $conn, $uid;

    $idCotizacion = (int) ($data->id_cotizacion ?? 0);
    if ($idCotizacion <= 0) {
        json(['error' => true, 'message' => 'ID de cotización requerido'], 400);
    }

    $sql = "DELETE FROM pos_cotizacion WHERE id_cotizacion = ? AND id_user = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $idCotizacion, $uid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json(['error' => true, 'message' => 'Cotización no encontrada'], 404);
    }

    json(['success' => true, 'message' => 'Cotización eliminada']);
}

function POST_reserva_crear($data) {
    global $conn, $uid;

    $idCliente       = $data->id_cliente ?? null;
    $clienteNombre   = $data->cliente_nombre ?? '';
    $clienteTelefono = $data->cliente_telefono ?? '';
    $total           = (int) ($data->total ?? 0);
    $abono           = (int) ($data->abono ?? 0);
    $fechaReserva    = $data->fecha_reserva ?? date('Y-m-d');
    $fechaVencimiento = $data->fecha_vencimiento ?? null;
    $notas           = $data->notas ?? '';
    $idReferencia    = $data->id_referencia ?? null;

    $sql = "INSERT INTO pos_reserva (id_user, id_cliente, id_referencia, cliente_nombre, cliente_telefono, total, abono, fecha_reserva, fecha_vencimiento, notas)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiissiisss', $uid, $idCliente, $idReferencia, $clienteNombre, $clienteTelefono, $total, $abono, $fechaReserva, $fechaVencimiento, $notas);
    $stmt->execute();
    $idReserva = $conn->insert_id;
    $stmt->close();

    json(['success' => true, 'id_reserva' => $idReserva]);
}

function POST_reserva_cumplir($data) {
    global $conn, $uid;

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
    global $conn, $uid;

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
    global $conn, $uid;

    $pedidoId = (int) ($data->id_pedido ?? 0);
    $tipo     = $data->tipo ?? 'TOTAL';
    $motivo   = $data->motivo ?? '';
    $items    = $data->items ?? [];

    if ($pedidoId <= 0) {
        json(['error' => true, 'message' => 'ID de pedido requerido'], 400);
    }

    $conn->begin_transaction();
    try {
        // Get pedido
        $sql = "SELECT p.*, s.id_user AS sesion_user
                FROM pedido p
                JOIN sesion s ON p.id_sesion = s.id_sesion
                WHERE p.id_pedido = ? AND p.anulado = 0
                FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $pedidoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $pedido = $result->fetch_assoc();
        $stmt->close();

        if (!$pedido) {
            throw new Exception('Pedido no encontrado o ya anulado.');
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

            // Reponer stock
            if ($tipoVenta === 'UNIDAD') {
                reponerStock($conn, $idProducto, $idBodega, (int) ceil($cantidad));
            } else {
                $sql = "UPDATE stock SET disponible = disponible + ? WHERE id_producto = ? AND id_bodega = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('dii', $cantidad, $idProducto, $idBodega);
                $stmt->execute();
                $stmt->close();
            }

            // Kardex
            $entradaKardex = ($tipoVenta === 'UNIDAD') ? (int) ceil($cantidad) : 0;
            actualizarKardex($conn, $uid, $idProducto, $idBodega, 'DEVOLUCION', $idDevolucion, 'DEVOLUCION', $entradaKardex, 0, $costoUnit, "Devolución #{$idDevolucion}, Pedido #{$pedidoId}");
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
