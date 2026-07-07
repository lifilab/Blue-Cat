<?php
require_once __DIR__ . '/_db.php';

$idUser = requerirUsuarioAutenticado();
$conn = conectarBaseDeDatos();

$permisos = array(
    'pos' => array(
        'ver' => usuarioTienePermiso($conn, $idUser, 'pos', 'ver'),
        'abrir_caja' => usuarioTienePermiso($conn, $idUser, 'pos', 'abrir_caja'),
        'cerrar_caja' => usuarioTienePermiso($conn, $idUser, 'pos', 'cerrar_caja'),
        'realizar_venta' => usuarioTienePermiso($conn, $idUser, 'pos', 'realizar_venta'),
        'cambiar_precios' => usuarioTienePermiso($conn, $idUser, 'pos', 'cambiar_precios'),
        'crear_promocion' => usuarioTienePermiso($conn, $idUser, 'pos', 'crear_promocion'),
    ),
);

$conn->close();

responderJson(respuestaOk('Permisos obtenidos correctamente.', array(
    'permisos' => $permisos,
)));
