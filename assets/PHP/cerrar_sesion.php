<?php
require_once __DIR__ . '/_db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJson(respuestaError('Error: Método de solicitud no permitido.'), 405);
    exit();
}

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

$sqlUpdate = "UPDATE usuario SET validar_sesion = 1 WHERE id_user = ?";
$stmtUpdate = $conn->prepare($sqlUpdate);

if (!$stmtUpdate) {
    responderJson(respuestaError('Error al preparar el cierre de sesión.'), 500);
    $conn->close();
    exit();
}

$stmtUpdate->bind_param("i", $idUser);

if ($stmtUpdate->execute()) {
    responderJson(respuestaOk('Sesión cerrada correctamente.'));
} else {
    responderJson(respuestaError('Error al cerrar la sesión.'), 500);
}

$stmtUpdate->close();
$conn->close();
