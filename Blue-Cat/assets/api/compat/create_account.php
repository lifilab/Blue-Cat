<?php
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../index.html');
    exit();
}

if (!isset($_POST['new-username'], $_POST['e-mail'], $_POST['new-password'], $_POST['confirm-password'])) {
    responderJson(respuestaError('Se esperaban datos de nombre, correo y contraseña.'), 400);
    exit();
}

$nombre = trim($_POST['new-username']);
$correo = trim($_POST['e-mail']);
$password = $_POST['new-password'];
$confirmPassword = $_POST['confirm-password'];

if ($nombre === '' || $correo === '' || $password === '') {
    responderJson(respuestaError('Nombre, correo y contraseña son obligatorios.'), 400);
    exit();
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    responderJson(respuestaError('El correo electrónico no es válido.'), 400);
    exit();
}

if ($password !== $confirmPassword) {
    responderJson(respuestaError('Las contraseñas no coinciden.'), 400);
    exit();
}

if (strlen($password) < 6) {
    responderJson(respuestaError('La contraseña debe tener al menos 6 caracteres.'), 400);
    exit();
}

$conn = conectarBaseDeDatos();

$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM usuario WHERE nombre = ? OR correo = ?');
if (!$stmt) {
    responderJson(respuestaError('Error al validar la cuenta.'), 500);
    $conn->close();
    exit();
}
$stmt->bind_param('ss', $nombre, $correo);
$stmt->execute();
$existe = (int) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

if ($existe > 0) {
    responderJson(respuestaError('El usuario o correo ya existe.'), 409);
    $conn->close();
    exit();
}

$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM usuario');
$stmt->execute();
$esPrimerUsuario = ((int) $stmt->get_result()->fetch_assoc()['total']) === 0;
$stmt->close();

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$conn->begin_transaction();

try {
    $sql = 'INSERT INTO usuario (nombre, correo, password, validar_sesion, activo) VALUES (?, ?, ?, 0, 1)';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error al preparar la creación de cuenta.');
    }
    $stmt->bind_param('sss', $nombre, $correo, $hashedPassword);
    if (!$stmt->execute()) {
        throw new Exception('Error al crear la cuenta.');
    }
    $idUser = (int) $conn->insert_id;
    $stmt->close();

    $rol = $esPrimerUsuario ? 'Administrador' : 'Vendedor';
    $stmtRol = $conn->prepare('INSERT IGNORE INTO usuario_rol (id_user, id_rol) SELECT ?, id_rol FROM rol WHERE nombre = ? LIMIT 1');
    if ($stmtRol) {
        $stmtRol->bind_param('is', $idUser, $rol);
        $stmtRol->execute();
        $stmtRol->close();
    }

    $conn->commit();
    responderJson(respuestaOk('Cuenta creada exitosamente.', array('id_user' => $idUser, 'rol' => $rol)), 201);
} catch (Exception $e) {
    $conn->rollback();
    responderJson(respuestaError($e->getMessage()), 500);
}

$conn->close();
