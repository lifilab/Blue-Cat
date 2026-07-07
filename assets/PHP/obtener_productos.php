<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();
$searchText = isset($_GET['search']) ? trim($_GET['search']) : "";

$sql = "SELECT id_producto, nombre_producto, codigo_de_barras, precio_venta, cantidad, categoria
        FROM producto
        WHERE id_user = ?
        AND (nombre_producto LIKE ? OR codigo_de_barras LIKE ? OR cantidad LIKE ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la consulta de productos.'), 500);
    $conn->close();
    exit();
}

$searchPattern = "%$searchText%";
$stmt->bind_param("isss", $idUser, $searchPattern, $searchPattern, $searchPattern);
$stmt->execute();
$result = $stmt->get_result();

$productos = array();
while ($row = $result->fetch_assoc()) {
    $productos[] = $row;
}

$stmt->close();
$conn->close();

responderJson($productos);
