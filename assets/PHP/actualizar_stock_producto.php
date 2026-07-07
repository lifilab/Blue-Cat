<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

requerirPermiso($conn, $idUser, 'inventario', 'editar', 'No tiene permiso para actualizar stock.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(respuestaError('Metodo de solicitud no permitido.'), 405);
    $conn->close();
    exit();
}

if (!isset($_POST['productId'], $_POST['stock'])) {
    responderJson(respuestaError('Producto y stock son requeridos.'), 400);
    $conn->close();
    exit();
}

$productId = (int) $_POST['productId'];
$stockNormalizado = normalizarNumeroServidor($_POST['stock']);

if ($productId <= 0 || $stockNormalizado === null || $stockNormalizado < 0) {
    responderJson(respuestaError('El stock debe ser un numero valido y no negativo.'), 400);
    $conn->close();
    exit();
}

$nuevoStock = (int) round($stockNormalizado);

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT id_producto, nombre_producto, cantidad, precio_venta FROM producto WHERE id_producto = ? AND id_user = ? FOR UPDATE");
    if (!$stmt) {
        throw new Exception('Error al preparar la consulta del producto.');
    }

    $stmt->bind_param("ii", $productId, $idUser);
    $stmt->execute();
    $producto = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$producto) {
        throw new Exception('Producto no encontrado.');
    }

    $stockAnterior = (int) $producto['cantidad'];

    $stmt = $conn->prepare("UPDATE producto SET cantidad = ? WHERE id_producto = ? AND id_user = ?");
    if (!$stmt) {
        throw new Exception('Error al preparar la actualizacion de stock.');
    }

    $stmt->bind_param("iii", $nuevoStock, $productId, $idUser);
    if (!$stmt->execute()) {
        throw new Exception('Error al actualizar stock.');
    }
    $stmt->close();

    registrarAuditoriaCore(
        $conn,
        $idUser,
        'inventario_actualizar_stock',
        'producto',
        $productId,
        array('cantidad' => $stockAnterior),
        array('cantidad' => $nuevoStock)
    );

    $conn->commit();

    responderJson(respuestaOk('Stock actualizado correctamente.', array(
        'producto' => array(
            'id_producto' => $productId,
            'nombre_producto' => $producto['nombre_producto'],
            'precio_venta' => (int) $producto['precio_venta'],
            'cantidad' => $nuevoStock,
        ),
        'valor_anterior' => $stockAnterior,
        'valor_nuevo' => $nuevoStock,
    )));
} catch (Exception $e) {
    $conn->rollback();
    $statusCode = $e->getMessage() === 'Producto no encontrado.' ? 404 : 500;
    responderJson(respuestaError($e->getMessage()), $statusCode);
}

$conn->close();
