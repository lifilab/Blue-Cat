<?php
require_once __DIR__ . '/_db.php';

iniciarSesionSiHaceFalta();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../index.html");
    exit();
}

if (!isset($_POST['username'], $_POST['password'])) {
    responderJson(respuestaError('Se esperaban datos de nombre de usuario y contraseña.'), 400);
    exit();
}

$conn = conectarBaseDeDatos();
$username = trim($_POST['username']);
$password = $_POST['password'];

$sql = "SELECT * FROM usuario WHERE nombre = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la consulta de inicio de sesión.'), 500);
    $conn->close();
    exit();
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    responderJson(respuestaError('Error: El usuario no existe.'), 401);
    $stmt->close();
    $conn->close();
    exit();
}

$row = $result->fetch_assoc();

if (!password_verify($password, $row['password'])) {
    responderJson(respuestaError('Error: Contraseña incorrecta.'), 401);
    $stmt->close();
    $conn->close();
    exit();
}

$idUser = (int) $row['id_user'];
$_SESSION['user_id'] = $idUser;

$sqlUpdate = "UPDATE usuario SET validar_sesion = 1 WHERE id_user = ?";
$stmtUpdate = $conn->prepare($sqlUpdate);

if ($stmtUpdate) {
    $stmtUpdate->bind_param("i", $idUser);
    $stmtUpdate->execute();
    $stmtUpdate->close();
}

$stmt->close();
$conn->close();

responderJson(respuestaOk('Inicio de sesión exitoso para el usuario: ' . $row['nombre'], array(
    'id_user' => $idUser,
    'nombre' => $row['nombre'],
)));
