<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJson(respuestaError('Error: Método de solicitud no permitido.'), 405);
    exit();
}

if (!isset($_POST['nombre_producto'], $_POST['precio_venta'], $_POST['codigo_de_barras'], $_POST['cantidad'], $_POST['categoria'])) {
    responderJson(respuestaError('Error: Se esperaban datos de nombre, precio de venta, código de barras, cantidad y categoría.'), 400);
    exit();
}

$nombreProducto = trim($_POST['nombre_producto']);
$precioVenta = (int) $_POST['precio_venta'];
$codigoBarras = trim($_POST['codigo_de_barras']);
$cantidad = (int) $_POST['cantidad'];
$categoria = trim($_POST['categoria']);

if ($nombreProducto === '' || $precioVenta < 0 || $cantidad < 0) {
    responderJson(respuestaError('Error: Producto, precio y cantidad son obligatorios y no pueden ser negativos.'), 400);
    exit();
}

$conn = conectarBaseDeDatos();
$sql = "INSERT INTO producto (nombre_producto, precio_venta, codigo_de_barras, cantidad, categoria, id_user)
        VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar el registro del producto.'), 500);
    $conn->close();
    exit();
}

$stmt->bind_param("sisisi", $nombreProducto, $precioVenta, $codigoBarras, $cantidad, $categoria, $idUser);

if ($stmt->execute()) {
    responderJson(respuestaOk('Producto agregado exitosamente.', array(
        'id_producto' => $conn->insert_id,
    )));
} else {
    responderJson(respuestaError('Error al agregar el producto.'), 500);
}

$stmt->close();
$conn->close();
