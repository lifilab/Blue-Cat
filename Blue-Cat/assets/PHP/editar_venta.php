<?php
require_once __DIR__ . '/_db.php';

$id_user = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

$requestBody = json_decode(file_get_contents("php://input"), true);
$id_pedido = isset($requestBody['id_pedido']) ? (int) $requestBody['id_pedido'] : 0;
$items_keep = isset($requestBody['items_keep']) ? $requestBody['items_keep'] : [];
$items_remove = isset($requestBody['items_remove']) ? $requestBody['items_remove'] : [];
$pagos = isset($requestBody['pagos']) ? $requestBody['pagos'] : [];

if ($id_pedido === 0) { echo "ID de pedido no válido"; exit; }
if (count($items_keep) === 0) { echo "Debe quedar al menos un producto"; exit; }

$conn->begin_transaction();

try {
    // ── Handle removed items (returns) ──
    foreach ($items_remove as $item) {
        $id_producto = (int) $item['id_producto'];
        $cantidad = (int) $item['cantidad'];

        // Restore stock
        $stmt = $conn->prepare("UPDATE producto SET cantidad = cantidad + ? WHERE id_producto = ?");
        $stmt->bind_param("ii", $cantidad, $id_producto);
        $stmt->execute();
        $stmt->close();

        // Delete from detalle_pedido
        $stmt = $conn->prepare("DELETE FROM detalle_pedido WHERE id_pedido = ? AND id_producto = ?");
        $stmt->bind_param("ii", $id_pedido, $id_producto);
        $stmt->execute();
        $stmt->close();
    }

    // ── Handle kept items (update qty/price) ──
    foreach ($items_keep as $item) {
        $id_producto = (int) $item['id_producto'];
        $new_cantidad = (int) $item['cantidad'];
        $new_precio = (int) $item['precio_total'];

        // Get original qty from detalle_pedido
        $stmt = $conn->prepare("SELECT cantidad_pedida FROM detalle_pedido WHERE id_pedido = ? AND id_producto = ?");
        $stmt->bind_param("ii", $id_pedido, $id_producto);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row) {
            $old_cantidad = (int) $row['cantidad_pedida'];
            $diff = $old_cantidad - $new_cantidad;

            if ($diff > 0) {
                // Returned some quantity — restore stock
                $stmt = $conn->prepare("UPDATE producto SET cantidad = cantidad + ? WHERE id_producto = ?");
                $stmt->bind_param("ii", $diff, $id_producto);
                $stmt->execute();
                $stmt->close();
            } elseif ($diff < 0) {
                // Added more quantity — deduct stock
                $diff_abs = abs($diff);
                $stmt = $conn->prepare("UPDATE producto SET cantidad = cantidad - ? WHERE id_producto = ?");
                $stmt->bind_param("ii", $diff_abs, $id_producto);
                $stmt->execute();
                $stmt->close();
            }

            // Update detalle_pedido
            $stmt = $conn->prepare("UPDATE detalle_pedido SET cantidad_pedida = ?, precio_total = ? WHERE id_pedido = ? AND id_producto = ?");
            $stmt->bind_param("iiii", $new_cantidad, $new_precio, $id_pedido, $id_producto);
            $stmt->execute();
            $stmt->close();
        } else {
            // New product added (shouldn't happen in current flow, but handle gracefully)
            $stmt = $conn->prepare("INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad_pedida, precio_total) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiii", $id_pedido, $id_producto, $new_cantidad, $new_precio);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ── Recalculate total from kept items ──
    $nuevo_precio = 0;
    foreach ($items_keep as $item) {
        $nuevo_precio += (int) $item['precio_total'];
    }

    // ── Update payments ──
    $total_pago = 0;
    foreach ($pagos as $pago) {
        $total_pago += (int) $pago['monto'];
    }
    $diferencia = $total_pago - $nuevo_precio;

    $stmt = $conn->prepare("UPDATE pedido SET precio_total = ?, pago_total = ?, diferencia = ? WHERE id_pedido = ?");
    $stmt->bind_param("iiii", $nuevo_precio, $total_pago, $diferencia, $id_pedido);
    $stmt->execute();
    $stmt->close();

    // Delete old payment records and insert new ones
    $stmt = $conn->prepare("DELETE FROM metodo_de_pago WHERE id_pedido = ?");
    $stmt->bind_param("i", $id_pedido);
    $stmt->execute();
    $stmt->close();

    foreach ($pagos as $pago) {
        $stmt = $conn->prepare("INSERT INTO metodo_de_pago (id_pedido, nombre_metodo_pago, monto) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $id_pedido, $pago['metodo'], $pago['monto']);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    echo "Venta actualizada correctamente";
} catch (Exception $e) {
    $conn->rollback();
    echo "Error al actualizar la venta: " . $e->getMessage();
}

$conn->close();
?>