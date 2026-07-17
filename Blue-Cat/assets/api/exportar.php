<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_db.php';
$uid = requireUser();
requirePermission('facturas', 'exportar');
$conn = getDB();

$formato = $_GET['formato'] ?? 'json';
$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = $_GET['tipo'] ?? 'factura';

if ($tipo === 'facturas' && !$id_factura) {
    exportListado($conn, $uid, $formato);
} elseif ($id_factura) {
    exportFactura($conn, $uid, $id_factura, $formato);
} else {
    json(['error'=>'Parámetros inválidos'], 400);
}

function exportFactura($conn, $uid, $id, $formato) {
    $accountId = tenantContext($uid)->accountId;
    $stmt = $conn->prepare("SELECT f.*, c.razon_social, c.rut, c.nombre as cliente_nombre, c.direccion, c.correo FROM factura f LEFT JOIN cliente c ON f.id_cliente = c.id_cliente AND c.id_cuenta=f.id_cuenta WHERE f.id_factura = ? AND f.id_cuenta = ?");
    $stmt->bind_param("ii", $id, $accountId);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$f) json(['error'=>'No encontrada'], 404);

    $stmt = $conn->prepare("SELECT * FROM factura_detalle WHERE id_factura = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $f['detalle'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM factura_pago WHERE id_factura = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $f['pagos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($formato === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="factura_'.$f['numero'].'.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Producto', 'Cantidad', 'Precio', 'Descuento', 'Neto', 'IVA', 'Total']);
        foreach ($f['detalle'] as $d) {
            fputcsv($out, [$d['producto'], $d['cantidad'], $d['precio'], $d['descuento'], $d['neto'], $d['iva'], $d['total']]);
        }
        fputcsv($out, ['','','','','','','']);
        fputcsv($out, ['Total', '', '', '', '', '', $f['total']]);
        fclose($out);
        exit;
    }

    if ($formato === 'xml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="factura_'.$f['numero'].'.xml"');
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Factura></Factura>');
        $xml->addChild('Numero', $f['numero']);
        $xml->addChild('Folio', $f['folio']);
        $xml->addChild('Fecha', $f['fecha_emision']);
        $xml->addChild('Cliente', $f['razon_social']);
        $xml->addChild('RUT', $f['rut']);
        $xml->addChild('Total', $f['total']);
        $det = $xml->addChild('Detalle');
        foreach ($f['detalle'] as $d) {
            $item = $det->addChild('Item');
            $item->addChild('Producto', htmlspecialchars($d['producto']));
            $item->addChild('Cantidad', $d['cantidad']);
            $item->addChild('Precio', $d['precio']);
            $item->addChild('Total', $d['total']);
        }
        echo $xml->asXML();
        exit;
    }

    json($f);
}

function exportListado($conn, $uid, $formato) {
    $estado = $_GET['estado'] ?? '';
    $desde = $_GET['desde'] ?? '';
    $hasta = $_GET['hasta'] ?? '';

    $accountId = tenantContext($uid)->accountId;
    $sql = "SELECT f.*, c.razon_social, c.rut FROM factura f LEFT JOIN cliente c ON f.id_cliente = c.id_cliente AND c.id_cuenta=f.id_cuenta WHERE f.id_cuenta=?";
    $params = [$accountId]; $types = 'i';
    if ($estado) { $sql .= " AND f.estado=?"; $params[]=$estado; $types.='s'; }
    if ($desde)  { $sql .= " AND f.fecha_emision>=?"; $params[]=$desde; $types.='s'; }
    if ($hasta)  { $sql .= " AND f.fecha_emision<=?"; $params[]=$hasta.' 23:59:59'; $types.='s'; }
    $sql .= " ORDER BY f.id_factura DESC";
    $stmt=$conn->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

    if ($formato === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="facturas.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Número','Folio','Cliente','RUT','Estado','Total','Pagado','Saldo','Fecha Emisión']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['numero'], $r['folio'], $r['razon_social'], $r['rut'], $r['estado'], $r['total'], $r['pagado'], $r['saldo'], $r['fecha_emision']]);
        }
        fclose($out);
        exit;
    }

    json($rows);
}
?>
