<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

$pageNumber = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$itemsPerPage = 10;
$startIndex = ($pageNumber - 1) * $itemsPerPage;

$sql = "SELECT id_producto, nombre_producto, codigo_de_barras, precio_venta, cantidad, categoria
        FROM producto
        WHERE id_user = ?
        LIMIT ?, ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la consulta de productos.'), 500);
    $conn->close();
    exit();
}

$stmt->bind_param("iii", $idUser, $startIndex, $itemsPerPage);
$stmt->execute();
$result = $stmt->get_result();

$productos = array();
while ($row = $result->fetch_assoc()) {
    $productos[] = $row;
}

$stmt->close();
$conn->close();

responderJson($productos);
