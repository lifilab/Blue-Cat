<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

requerirPermiso($conn, $idUser, 'inventario', 'editar', 'No tiene permiso para editar productos.');

if (!isset($_POST['columnIndex'], $_POST['newValue'], $_POST['productId'])) {
    responderJson(respuestaError('Datos insuficientes recibidos.'), 400);
    $conn->close();
    exit();
}

$columnIndex = (int) $_POST['columnIndex'];
$newValue = trim((string) $_POST['newValue']);
$productId = (int) $_POST['productId'];

if ($columnIndex === 3 || $columnIndex === 4) {
    responderJson(respuestaError('Stock y precio deben actualizarse desde sus funciones dedicadas.'), 400);
    $conn->close();
    exit();
}

$columnNames = array(
    1 => 'nombre_producto',
    2 => 'codigo_de_barras',
    5 => 'categoria',
);

if (!isset($columnNames[$columnIndex]) || $productId <= 0) {
    responderJson(respuestaError('Indice de columna o producto no valido.'), 400);
    $conn->close();
    exit();
}

$column = $columnNames[$columnIndex];

if ($newValue === '') {
    $sql = "UPDATE producto SET $column = NULL WHERE id_producto = ? AND id_user = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $productId, $idUser);
    }
} else {
    $sql = "UPDATE producto SET $column = ? WHERE id_producto = ? AND id_user = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sii", $newValue, $productId, $idUser);
    }
}

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la actualizacion del producto.'), 500);
    $conn->close();
    exit();
}

if ($stmt->execute()) {
    responderJson(respuestaOk('Datos actualizados correctamente.', array(
        'filas_afectadas' => $stmt->affected_rows,
    )));
} else {
    responderJson(respuestaError('Error al actualizar los datos.'), 500);
}

$stmt->close();
$conn->close();
