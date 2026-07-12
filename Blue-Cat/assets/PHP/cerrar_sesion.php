<?php
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(respuestaError('Método de solicitud no permitido.'), 405);
    exit();
}

iniciarSesionSiHaceFalta();
$idUser = obtenerUsuarioIdSesion();

if ($idUser > 0) {
    $conn = conectarBaseDeDatos();
    $stmt = $conn->prepare('UPDATE usuario SET validar_sesion = 0 WHERE id_user = ?');
    if ($stmt) {
        $stmt->bind_param('i', $idUser);
        $stmt->execute();
        $stmt->close();
    }
    $conn->close();
}

$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

responderJson(respuestaOk('Sesión cerrada correctamente.'));
