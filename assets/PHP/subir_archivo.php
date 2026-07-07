<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();

if (!isset($_FILES["file"])) {
    responderJson(respuestaError('No se recibió ningún archivo.'), 400);
    exit();
}

$file = $_FILES["file"];

if ($file["error"] !== UPLOAD_ERR_OK) {
    responderJson(respuestaError('Error en la subida del archivo.', array(
        'codigo_error' => $file["error"],
    )), 400);
    exit();
}

$fileType = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
if ($fileType !== "csv") {
    responderJson(respuestaError('El archivo debe ser un CSV.'), 400);
    exit();
}

$uploadDir = __DIR__ . "/../uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$safeName = 'import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
$uploadPath = $uploadDir . $safeName;

if (!move_uploaded_file($file["tmp_name"], $uploadPath)) {
    responderJson(respuestaError('Error al subir el archivo.'), 500);
    exit();
}

$fileHandle = fopen($uploadPath, "r");
if ($fileHandle === false) {
    responderJson(respuestaError('Error al abrir el archivo CSV.'), 500);
    exit();
}

$conn = conectarBaseDeDatos();
$sql = "INSERT INTO producto (id_user, nombre_producto, precio_venta, codigo_de_barras, cantidad, categoria)
        VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    fclose($fileHandle);
    $conn->close();
    responderJson(respuestaError('Error al preparar la importación de productos.'), 500);
    exit();
}

fgetcsv($fileHandle);
$insertados = 0;
$errores = array();
$fila = 1;

while (($data = fgetcsv($fileHandle)) !== false) {
    $fila++;

    $nombre = isset($data[0]) ? trim($data[0]) : '';
    $precio = isset($data[1]) && is_numeric($data[1]) ? (int) $data[1] : 0;
    $codigoBarras = isset($data[2]) ? trim($data[2]) : '';
    $cantidad = isset($data[3]) && is_numeric($data[3]) ? (int) $data[3] : 0;
    $categoria = isset($data[4]) ? trim($data[4]) : null;

    if ($nombre === '' || $precio < 0 || $cantidad < 0) {
        $errores[] = array('fila' => $fila, 'mensaje' => 'Datos inválidos');
        continue;
    }

    $stmt->bind_param("isisis", $idUser, $nombre, $precio, $codigoBarras, $cantidad, $categoria);

    if ($stmt->execute()) {
        $insertados++;
    } else {
        $errores[] = array('fila' => $fila, 'mensaje' => 'No se pudo insertar el producto');
    }
}

$stmt->close();
$conn->close();
fclose($fileHandle);

responderJson(respuestaOk('Archivo importado correctamente.', array(
    'archivo' => $safeName,
    'insertados' => $insertados,
    'errores' => $errores,
)));
