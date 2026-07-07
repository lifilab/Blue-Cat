<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

requerirPermiso($conn, $idUser, 'inventario', 'editar', 'No tiene permiso para actualizar precios.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(respuestaError('Metodo de solicitud no permitido.'), 405);
    $conn->close();
    exit();
}

if (!isset($_POST['productId'], $_POST['precio'])) {
    responderJson(respuestaError('Producto y precio son requeridos.'), 400);
    $conn->close();
    exit();
}

$productId = (int) $_POST['productId'];
$precioNormalizado = normalizarNumeroServidor($_POST['precio']);

if ($productId <= 0 || $precioNormalizado === null || $precioNormalizado < 0) {
    responderJson(respuestaError('El precio debe ser un numero valido y no negativo.'), 400);
    $conn->close();
    exit();
}

$nuevoPrecio = (int) round($precioNormalizado);

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

    $precioAnterior = (int) $producto['precio_venta'];

    $stmt = $conn->prepare("UPDATE producto SET precio_venta = ? WHERE id_producto = ? AND id_user = ?");
    if (!$stmt) {
        throw new Exception('Error al preparar la actualizacion de precio.');
    }

    $stmt->bind_param("iii", $nuevoPrecio, $productId, $idUser);
    if (!$stmt->execute()) {
        throw new Exception('Error al actualizar precio.');
    }
    $stmt->close();

    registrarAuditoriaCore(
        $conn,
        $idUser,
        'inventario_actualizar_precio',
        'producto',
        $productId,
        array('precio_venta' => $precioAnterior),
        array('precio_venta' => $nuevoPrecio)
    );

    $conn->commit();

    responderJson(respuestaOk('Precio actualizado correctamente.', array(
        'producto' => array(
            'id_producto' => $productId,
            'nombre_producto' => $producto['nombre_producto'],
            'precio_venta' => $nuevoPrecio,
            'cantidad' => (int) $producto['cantidad'],
        ),
        'valor_anterior' => $precioAnterior,
        'valor_nuevo' => $nuevoPrecio,
    )));
} catch (Exception $e) {
    $conn->rollback();
    $statusCode = $e->getMessage() === 'Producto no encontrado.' ? 404 : 500;
    responderJson(respuestaError($e->getMessage()), $statusCode);
}

$conn->close();
