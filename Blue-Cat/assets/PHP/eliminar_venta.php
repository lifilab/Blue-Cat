<?php
require_once __DIR__ . '/_db.php';

$id_user = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

$id_pedido = isset($_POST['id_pedido']) ? (int) $_POST['id_pedido'] : 0;
if ($id_pedido === 0) { echo "ID de pedido no válido"; exit; }

$conn->begin_transaction();

try {
    // Restore stock
    $sql_detalle = $conn->prepare("SELECT id_producto, cantidad_pedida FROM detalle_pedido WHERE id_pedido = ?");
    $sql_detalle->bind_param("i", $id_pedido);
    $sql_detalle->execute();
    $result_detalle = $sql_detalle->get_result();

    while ($row = $result_detalle->fetch_assoc()) {
        $id_producto = (int) $row['id_producto'];
        $cantidad = (int) $row['cantidad_pedida'];
        $sql_update = $conn->prepare("UPDATE producto SET cantidad = cantidad + ? WHERE id_producto = ?");
        $sql_update->bind_param("ii", $cantidad, $id_producto);
        $sql_update->execute();
        $sql_update->close();
    }
    $sql_detalle->close();

    // Delete payment records
    $stmt = $conn->prepare("DELETE FROM metodo_de_pago WHERE id_pedido = ?");
    $stmt->bind_param("i", $id_pedido);
    $stmt->execute();
    $stmt->close();

    // Delete order details
    $stmt = $conn->prepare("DELETE FROM detalle_pedido WHERE id_pedido = ?");
    $stmt->bind_param("i", $id_pedido);
    $stmt->execute();
    $stmt->close();

    // Reverse caja movement if applicable — get info BEFORE deleting
    $sql_caja = $conn->prepare("SELECT id_caja, precio_total FROM pedido WHERE id_pedido = ?");
    $sql_caja->bind_param("i", $id_pedido);
    $sql_caja->execute();
    $caja_info = $sql_caja->get_result()->fetch_assoc();
    $sql_caja->close();

    // Delete order
    $stmt = $conn->prepare("DELETE FROM pedido WHERE id_pedido = ?");
    $stmt->bind_param("i", $id_pedido);
    $stmt->execute();
    $stmt->close();

    if ($caja_info && $caja_info['id_caja']) {
        $id_caja = (int)$caja_info['id_caja'];
        $monto = (int)$caja_info['precio_total'];
        $conn->query("INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo) VALUES ($id_caja, $id_user, 'EGRESO', 'Eliminación venta #$id_pedido', $monto, 'Efectivo')");
        $conn->query("UPDATE pos_caja SET monto_actual = monto_actual - $monto WHERE id_caja = $id_caja");
    }

    $conn->commit();
    echo "Venta eliminada correctamente. Stock restaurado.";
} catch (Exception $e) {
    $conn->rollback();
    echo "Error al eliminar la venta: " . $e->getMessage();
}

$conn->close();
?>