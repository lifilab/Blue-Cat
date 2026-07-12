<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

if (!usuarioTienePermiso($conn, $idUser, 'ventas', 'cuadre')) {
    $conn->close();
    responderJson(respuestaError('No tiene permiso para ver el cuadre de caja.'), 403);
    exit();
}

$sqlSesion = $conn->prepare("
    SELECT
        s.id_sesion,
        s.monto_apertura,
        s.empleado,
        s.nota,
        s.fecha_ingreso,
        s.fecha_cierre AS sesion_fecha_cierre,
        c.id_caja,
        c.estado AS estado_caja,
        c.monto_actual,
        c.monto_cierre,
        c.fecha_cierre AS caja_fecha_cierre
    FROM sesion s
    LEFT JOIN pos_caja c ON c.id_sesion = s.id_sesion
    WHERE s.id_user = ?
    ORDER BY
        CASE WHEN c.estado = 'ABIERTA' THEN 0 ELSE 1 END,
        s.id_sesion DESC
    LIMIT 1
");

if (!$sqlSesion) {
    responderJson(respuestaError('Error al preparar la consulta de sesión de caja.'), 500);
    $conn->close();
    exit();
}

$sqlSesion->bind_param("i", $idUser);
$sqlSesion->execute();
$resultSesion = $sqlSesion->get_result();
$rowSesion = $resultSesion->fetch_assoc();
$sqlSesion->close();

if (!$rowSesion) {
    $conn->close();
    responderJson(array(
        'ok' => true,
        'empleado' => 'No disponible',
        'nota' => 'No disponible',
        'fecha_apertura' => 'No disponible',
        'fecha_cierre' => null,
        'monto_apertura' => 0,
        'efectivo' => 0,
        'tarjeta' => 0,
        'transferencia' => 0,
        'ventas_total' => 0,
        'monto_total' => 0,
        'monto_actual_caja' => 0,
        'estado_caja' => 'SIN_CAJA',
    ));
    exit();
}

$idSesion = (int) $rowSesion['id_sesion'];
$montoApertura = (float) $rowSesion['monto_apertura'];
$empleado = $rowSesion['empleado'] !== '' ? $rowSesion['empleado'] : 'No disponible';
$nota = $rowSesion['nota'] !== '' ? $rowSesion['nota'] : 'No disponible';
$fechaApertura = $rowSesion['fecha_ingreso'];
$fechaCierre = $rowSesion['caja_fecha_cierre'] ?: $rowSesion['sesion_fecha_cierre'];
$idCaja = isset($rowSesion['id_caja']) ? (int) $rowSesion['id_caja'] : 0;
$estadoCaja = $rowSesion['estado_caja'] ?: 'SIN_CAJA';
$montoActualCaja = isset($rowSesion['monto_actual']) ? (float) $rowSesion['monto_actual'] : 0;

$efectivo = 0;
$tarjeta = 0;
$transferencia = 0;
$ventasTotal = 0;

$sqlTotales = $conn->prepare("
    SELECT
        COALESCE(SUM(p.precio_total), 0) AS ventas_total,
        COALESCE(SUM(mp.efectivo), 0) AS efectivo,
        COALESCE(SUM(mp.tarjeta), 0) AS tarjeta,
        COALESCE(SUM(mp.transferencia), 0) AS transferencia
    FROM pedido p
    LEFT JOIN (
        SELECT
            m.id_pedido,
            SUM(CASE WHEN m.nombre_metodo_pago = 'Efectivo' THEN m.monto - COALESCE(p2.diferencia, 0) ELSE 0 END) AS efectivo,
            SUM(CASE WHEN m.nombre_metodo_pago = 'Tarjeta' THEN m.monto ELSE 0 END) AS tarjeta,
            SUM(CASE WHEN m.nombre_metodo_pago = 'Transferencia' THEN m.monto ELSE 0 END) AS transferencia
        FROM metodo_de_pago m
        INNER JOIN pedido p2 ON p2.id_pedido = m.id_pedido
        GROUP BY m.id_pedido
    ) mp ON mp.id_pedido = p.id_pedido
    WHERE p.id_sesion = ?
      AND COALESCE(p.anulado, 0) = 0
");

if ($sqlTotales) {
    $sqlTotales->bind_param("i", $idSesion);
    $sqlTotales->execute();
    $rowTotales = $sqlTotales->get_result()->fetch_assoc();
    $ventasTotal = (float) $rowTotales['ventas_total'];
    $efectivo = (float) $rowTotales['efectivo'];
    $tarjeta = (float) $rowTotales['tarjeta'];
    $transferencia = (float) $rowTotales['transferencia'];
    $sqlTotales->close();
}

$montoTotal = $montoApertura + $efectivo + $tarjeta + $transferencia;

$conn->close();

responderJson(array(
    'ok' => true,
    'id_sesion' => $idSesion,
    'id_caja' => $idCaja,
    'estado_caja' => $estadoCaja,
    'empleado' => $empleado,
    'nota' => $nota,
    'fecha_apertura' => $fechaApertura,
    'fecha_cierre' => $fechaCierre,
    'monto_apertura' => $montoApertura,
    'efectivo' => $efectivo,
    'tarjeta' => $tarjeta,
    'transferencia' => $transferencia,
    'ventas_total' => $ventasTotal,
    'monto_total' => $montoTotal,
    'monto_actual_caja' => $montoActualCaja,
));
