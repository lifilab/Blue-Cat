<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

$sql = "SELECT validar_sesion FROM usuario WHERE id_user = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la consulta de sesión.'), 500);
    $conn->close();
    exit();
}

$stmt->bind_param("i", $idUser);

if (!$stmt->execute()) {
    responderJson(respuestaError('Error al ejecutar la consulta de sesión.'), 500);
    $stmt->close();
    $conn->close();
    exit();
}

$result = $stmt->get_result();
$row = $result->fetch_assoc();

$stmt->close();
$conn->close();

if (!$row) {
    responderJson(respuestaError('No se encontró el usuario de la sesión.'), 404);
    exit();
}

responderJson(array(
    'ok' => true,
    'validar_sesion' => (int) $row['validar_sesion'],
));
