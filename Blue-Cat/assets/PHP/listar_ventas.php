<?php
require_once __DIR__ . '/_db.php';

$id_user = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

$sql_sesion = $conn->prepare("SELECT MAX(id_sesion) AS id_sesion FROM sesion WHERE id_user = ?");
$sql_sesion->bind_param("i", $id_user);
$sql_sesion->execute();
$result_sesion = $sql_sesion->get_result();
$row_sesion = $result_sesion->fetch_assoc();
$id_sesion = (int) ($row_sesion['id_sesion'] ?? 0);

$ventas = [];

if ($id_sesion > 0) {
    $sql_pedidos = $conn->prepare("SELECT id_pedido, precio_total, pago_total, diferencia, fecha, anulado, cliente_nombre, tipo_documento FROM pedido WHERE id_sesion = ? ORDER BY id_pedido ASC");
    $sql_pedidos->bind_param("i", $id_sesion);
    $sql_pedidos->execute();
    $result_pedidos = $sql_pedidos->get_result();

    while ($pedido = $result_pedidos->fetch_assoc()) {
        $id_pedido = (int) $pedido['id_pedido'];

        $sql_detalle = $conn->prepare("
            SELECT dp.id_producto, dp.cantidad_pedida, dp.precio_total, p.nombre_producto
            FROM detalle_pedido dp
            JOIN producto p ON dp.id_producto = p.id_producto
            WHERE dp.id_pedido = ?
        ");
        $sql_detalle->bind_param("i", $id_pedido);
        $sql_detalle->execute();
        $result_detalle = $sql_detalle->get_result();
        $items = [];
        while ($det = $result_detalle->fetch_assoc()) {
            $items[] = [
                'id_producto' => $det['id_producto'],
                'nombre' => $det['nombre_producto'],
                'cantidad' => (int) $det['cantidad_pedida'],
                'precio_total' => (int) $det['precio_total']
            ];
        }
        $sql_detalle->close();

        $sql_pagos = $conn->prepare("SELECT nombre_metodo_pago, monto FROM metodo_de_pago WHERE id_pedido = ?");
        $sql_pagos->bind_param("i", $id_pedido);
        $sql_pagos->execute();
        $result_pagos = $sql_pagos->get_result();
        $pagos = [];
        while ($pag = $result_pagos->fetch_assoc()) {
            $pagos[] = [
                'metodo' => $pag['nombre_metodo_pago'],
                'monto' => (int) $pag['monto']
            ];
        }
        $sql_pagos->close();

        $ventas[] = [
            'id_pedido' => $id_pedido,
            'precio_total' => (int) $pedido['precio_total'],
            'pago_total' => (int) $pedido['pago_total'],
            'diferencia' => (int) $pedido['diferencia'],
            'fecha' => $pedido['fecha'],
            'anulado' => (int) ($pedido['anulado'] ?? 0),
            'cliente_nombre' => $pedido['cliente_nombre'] ?? '',
            'tipo_documento' => $pedido['tipo_documento'] ?? 'BOLETA',
            'items' => $items,
            'pagos' => $pagos
        ];
    }
    $sql_pedidos->close();
}

$conn->close();
header('Content-Type: application/json');
echo json_encode($ventas);
?>