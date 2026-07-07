<?php
require_once __DIR__ . '/_db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../index.html");
    exit();
}

if (!isset($_POST['new-username'], $_POST['e-mail'], $_POST['confirm-password'])) {
    responderJson(respuestaError('Error: Se esperaban datos de nombre, correo y contraseña.'), 400);
    exit();
}

$nombre = trim($_POST['new-username']);
$correo = trim($_POST['e-mail']);
$password = $_POST['confirm-password'];

if ($nombre === '' || $correo === '' || $password === '') {
    responderJson(respuestaError('Error: Nombre, correo y contraseña son obligatorios.'), 400);
    exit();
}

$conn = conectarBaseDeDatos();
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO usuario (nombre, correo, password, validar_sesion) VALUES (?, ?, ?, 0)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la creación de cuenta.'), 500);
    $conn->close();
    exit();
}

$stmt->bind_param("sss", $nombre, $correo, $hashedPassword);

if ($stmt->execute()) {
    responderJson(respuestaOk('Cuenta creada exitosamente para el usuario: ' . $nombre));
} else {
    responderJson(respuestaError('Error al crear la cuenta.'), 500);
}

$stmt->close();
$conn->close();
