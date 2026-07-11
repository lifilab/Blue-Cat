<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
$conn = getDB();

$hoy = date('Y-m-d');
$mes = date('Y-m');

// KPIs
$kpis = [];

// Emitidas hoy
$stmt = $conn->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total),0) as t FROM factura WHERE id_user=? AND DATE(fecha_emision)=? AND estado NOT IN ('ANULADA','RECHAZADA')");
$stmt->bind_param("is", $uid, $hoy);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$kpis['hoy_cantidad'] = (int)$row['c'];
$kpis['hoy_monto'] = (int)$row['t'];

// Del mes
$stmt = $conn->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total),0) as t FROM factura WHERE id_user=? AND DATE_FORMAT(fecha_emision,'%Y-%m')=? AND estado NOT IN ('ANULADA','RECHAZADA')");
$stmt->bind_param("is", $uid, $mes);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$kpis['mes_cantidad'] = (int)$row['c'];
$kpis['mes_monto'] = (int)$row['t'];

// Pendientes de pago
$stmt = $conn->prepare("SELECT COUNT(*) as c, COALESCE(SUM(saldo),0) as t FROM factura WHERE id_user=? AND estado IN ('EMITIDA','ACEPTADA','PARCIAL','PENDIENTE')");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$kpis['pendientes_cantidad'] = (int)$row['c'];
$kpis['pendientes_monto'] = (int)$row['t'];

// Vencidas
$stmt = $conn->prepare("SELECT COUNT(*) as c, COALESCE(SUM(saldo),0) as t FROM factura WHERE id_user=? AND estado IN ('EMITIDA','ACEPTADA','PARCIAL','PENDIENTE','VENCIDA') AND fecha_vencimiento < ?");
$stmt->bind_param("is", $uid, $hoy);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$kpis['vencidas_cantidad'] = (int)$row['c'];
$kpis['vencidas_monto'] = (int)$row['t'];

// Pagadas
$stmt = $conn->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total),0) as t FROM factura WHERE id_user=? AND estado='PAGADA'");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$kpis['pagadas_cantidad'] = (int)$row['c'];
$kpis['pagadas_monto'] = (int)$row['t'];

// Anuladas
$stmt = $conn->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total),0) as t FROM factura WHERE id_user=? AND estado='ANULADA'");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$kpis['anuladas_cantidad'] = (int)$row['c'];
$kpis['anuladas_monto'] = (int)$row['t'];

// Por estado
$stmt = $conn->prepare("SELECT estado, COUNT(*) as cantidad, COALESCE(SUM(total),0) as monto FROM factura WHERE id_user=? GROUP BY estado");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$kpis['por_estado'] = $result->fetch_all(MYSQLI_ASSOC);

// Facturación diaria (últimos 30 días)
$stmt = $conn->prepare("SELECT DATE(fecha_emision) as fecha, COUNT(*) as cantidad, COALESCE(SUM(total),0) as monto FROM factura WHERE id_user=? AND fecha_emision >= DATE_SUB(?, INTERVAL 30 DAY) AND estado NOT IN ('ANULADA','RECHAZADA') GROUP BY DATE(fecha_emision) ORDER BY fecha ASC");
$stmt->bind_param("is", $uid, $hoy);
$stmt->execute();
$result = $stmt->get_result();
$kpis['diario'] = $result->fetch_all(MYSQLI_ASSOC);

// Facturación mensual (últimos 12 meses)
$stmt = $conn->prepare("SELECT DATE_FORMAT(fecha_emision,'%Y-%m') as mes, COUNT(*) as cantidad, COALESCE(SUM(total),0) as monto FROM factura WHERE id_user=? AND fecha_emision >= DATE_SUB(?, INTERVAL 12 MONTH) AND estado NOT IN ('ANULADA','RECHAZADA') GROUP BY DATE_FORMAT(fecha_emision,'%Y-%m') ORDER BY mes ASC");
$stmt->bind_param("is", $uid, $hoy);
$stmt->execute();
$result = $stmt->get_result();
$kpis['mensual'] = $result->fetch_all(MYSQLI_ASSOC);

// Top productos más vendidos
$stmt = $conn->prepare("SELECT d.producto, SUM(d.cantidad) as cantidad, SUM(d.total) as monto FROM factura_detalle d JOIN factura f ON d.id_factura = f.id_factura WHERE f.id_user=? AND f.estado NOT IN ('ANULADA','RECHAZADA') GROUP BY d.id_producto, d.producto ORDER BY cantidad DESC LIMIT 10");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$kpis['top_productos'] = $result->fetch_all(MYSQLI_ASSOC);

// Total clientes
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM cliente WHERE id_user=?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$kpis['total_clientes'] = (int)$result->fetch_assoc()['c'];

// Total facturas
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM factura WHERE id_user=?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$kpis['total_facturas'] = (int)$result->fetch_assoc()['c'];

json($kpis);
?>