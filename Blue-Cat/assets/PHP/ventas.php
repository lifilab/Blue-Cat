<?php
require_once __DIR__ . '/_db.php';

function ventasBindParams($stmt, $types, $params)
{
    $refs = array($types);
    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    return call_user_func_array(array($stmt, 'bind_param'), $refs);
}

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

requerirPermiso($conn, $idUser, 'ventas', 'ver', 'No tiene permiso para ver ventas.');

$puedeVerTodos = usuarioTienePermiso($conn, $idUser, 'ventas', 'ver_todos');
$usuariosPermitidos = $puedeVerTodos ? obtenerUsuariosCuentaIds($conn, $idUser) : array($idUser);

if (empty($usuariosPermitidos)) {
    $usuariosPermitidos = array($idUser);
}

$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'siempre';
$periodosPermitidos = array('hoy', 'semana', 'mes', 'siempre');

if (!in_array($periodo, $periodosPermitidos, true)) {
    $periodo = 'siempre';
}

$limite = isset($_GET['limit']) ? (int) $_GET['limit'] : 300;
$limite = max(1, min($limite, 500));

$filtroFecha = '';
if ($periodo === 'hoy') {
    $filtroFecha = ' AND DATE(p.fecha) = CURDATE()';
} elseif ($periodo === 'semana') {
    $filtroFecha = ' AND p.fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($periodo === 'mes') {
    $filtroFecha = ' AND p.fecha >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
}

$placeholders = implode(',', array_fill(0, count($usuariosPermitidos), '?'));
$sql = "
    SELECT
        p.id_pedido,
        p.id_sesion,
        p.id_caja,
        p.tipo_documento,
        p.cliente_nombre,
        p.precio_total,
        p.pago_total,
        p.diferencia,
        p.fecha,
        s.id_user,
        COALESCE(NULLIF(TRIM(s.empleado), ''), NULLIF(TRIM(u.nombre_completo), ''), u.nombre) AS empleado,
        u.nombre AS usuario,
        COALESCE(mp.efectivo, 0) AS efectivo,
        COALESCE(mp.tarjeta, 0) AS tarjeta,
        COALESCE(mp.transferencia, 0) AS transferencia,
        COALESCE(mp.metodos_pago, '') AS metodos_pago
    FROM pedido p
    INNER JOIN sesion s ON s.id_sesion = p.id_sesion
    INNER JOIN usuario u ON u.id_user = s.id_user
    LEFT JOIN (
        SELECT
            id_pedido,
            SUM(CASE WHEN nombre_metodo_pago = 'Efectivo' THEN monto ELSE 0 END) AS efectivo,
            SUM(CASE WHEN nombre_metodo_pago = 'Tarjeta' THEN monto ELSE 0 END) AS tarjeta,
            SUM(CASE WHEN nombre_metodo_pago = 'Transferencia' THEN monto ELSE 0 END) AS transferencia,
            GROUP_CONCAT(CONCAT(nombre_metodo_pago, ': ', monto) SEPARATOR ', ') AS metodos_pago
        FROM metodo_de_pago
        GROUP BY id_pedido
    ) mp ON mp.id_pedido = p.id_pedido
    WHERE s.id_user IN ($placeholders)
      AND COALESCE(p.anulado, 0) = 0
      $filtroFecha
    ORDER BY p.fecha DESC, p.id_pedido DESC
    LIMIT ?
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    responderJson(respuestaError('No se pudo preparar la consulta de ventas.'), 500);
    $conn->close();
    exit;
}

$params = $usuariosPermitidos;
$params[] = $limite;
$types = str_repeat('i', count($usuariosPermitidos)) . 'i';
ventasBindParams($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();

$ventas = array();
$totales = array(
    'cantidad' => 0,
    'monto_total' => 0,
    'efectivo' => 0,
    'tarjeta' => 0,
    'transferencia' => 0,
);

while ($row = $result->fetch_assoc()) {
    $venta = array(
        'id_pedido' => (int) $row['id_pedido'],
        'id_sesion' => (int) $row['id_sesion'],
        'id_caja' => isset($row['id_caja']) ? (int) $row['id_caja'] : null,
        'tipo_documento' => $row['tipo_documento'],
        'cliente_nombre' => $row['cliente_nombre'],
        'precio_total' => (int) $row['precio_total'],
        'pago_total' => (int) $row['pago_total'],
        'diferencia' => (int) $row['diferencia'],
        'fecha' => $row['fecha'],
        'id_user' => (int) $row['id_user'],
        'empleado' => $row['empleado'],
        'usuario' => $row['usuario'],
        'efectivo' => (int) $row['efectivo'],
        'tarjeta' => (int) $row['tarjeta'],
        'transferencia' => (int) $row['transferencia'],
        'metodos_pago' => $row['metodos_pago'],
    );

    $ventas[] = $venta;
    $totales['cantidad']++;
    $totales['monto_total'] += $venta['precio_total'];
    $totales['efectivo'] += $venta['efectivo'];
    $totales['tarjeta'] += $venta['tarjeta'];
    $totales['transferencia'] += $venta['transferencia'];
}

$stmt->close();
$conn->close();

responderJson(respuestaOk('Ventas cargadas correctamente.', array(
    'puede_ver_todos' => $puedeVerTodos,
    'periodo' => $periodo,
    'usuarios_consultados' => $usuariosPermitidos,
    'totales' => $totales,
    'ventas' => $ventas,
)));
