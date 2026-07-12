<?php
require_once __DIR__ . '/_db.php';

iniciarSesionSiHaceFalta();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../index.html');
    exit();
}

if (!isset($_POST['username'], $_POST['password'])) {
    responderJson(respuestaError('Se esperaban datos de nombre de usuario y contraseña.'), 400);
    exit();
}

$conn = conectarBaseDeDatos();
$username = trim($_POST['username']);
$password = $_POST['password'];

if ($username === '' || $password === '') {
    responderJson(respuestaError('Usuario y contraseña son obligatorios.'), 400);
    $conn->close();
    exit();
}

$sql = "SELECT id_user, nombre, password, activo FROM usuario WHERE nombre = ? OR correo = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    responderJson(respuestaError('Error al preparar la consulta de inicio de sesión.'), 500);
    $conn->close();
    exit();
}

$stmt->bind_param('ss', $username, $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    responderJson(respuestaError('Usuario o contraseña incorrectos.'), 401);
    $stmt->close();
    $conn->close();
    exit();
}

$row = $result->fetch_assoc();

if ((int) $row['activo'] !== 1 || !password_verify($password, $row['password'])) {
    responderJson(respuestaError('Usuario o contraseña incorrectos.'), 401);
    $stmt->close();
    $conn->close();
    exit();
}

$idUser = (int) $row['id_user'];
$_SESSION['user_id'] = $idUser;
$_SESSION['user_name'] = $row['nombre'];

$sqlUpdate = "UPDATE usuario SET validar_sesion = 1, ultimo_acceso = NOW(), intentos_fallidos = 0 WHERE id_user = ?";
$stmtUpdate = $conn->prepare($sqlUpdate);
if ($stmtUpdate) {
    $stmtUpdate->bind_param('i', $idUser);
    $stmtUpdate->execute();
    $stmtUpdate->close();
}

$stmt->close();
$conn->close();

responderJson(respuestaOk('Inicio de sesión exitoso.', array(
    'id_user' => $idUser,
    'nombre' => $row['nombre'],
)));
