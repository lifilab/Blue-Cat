<?php
require_once __DIR__ . '/_db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJson(respuestaError('Error: Método de solicitud no permitido.'), 405);
    exit();
}

if (!isset($_POST["monto"], $_POST["empleado"])) {
    responderJson(respuestaError('Error: Se requieren el monto y el empleado para abrir la sesión.'), 400);
    exit();
}

$idUser = requerirUsuarioAutenticado();
$monto = (int) $_POST["monto"];
$empleado = trim($_POST["empleado"]);
$nota = isset($_POST["nota"]) ? trim($_POST["nota"]) : "";

$conn = conectarBaseDeDatos();

if (!usuarioTienePermiso($conn, $idUser, 'pos', 'abrir_caja')) {
    $conn->close();
    responderJson(respuestaError('No tiene permiso para abrir caja.'), 403);
    exit();
}

$sql = "INSERT INTO sesion (id_user, fecha_ingreso, monto_apertura, empleado, nota) VALUES (?, NOW(), ?, ?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la apertura de sesión.'), 500);
    $conn->close();
    exit();
}

$stmt->bind_param("iiss", $idUser, $monto, $empleado, $nota);

if ($stmt->execute()) {
    responderJson(respuestaOk('Apertura de sesión realizada con éxito.'));
} else {
    responderJson(respuestaError('Error al abrir la sesión.'), 500);
}

$stmt->close();
$conn->close();
