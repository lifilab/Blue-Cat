<?php
require_once __DIR__ . '/_db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJson(respuestaError('Error: No se recibieron datos del formulario.'), 405);
    exit();
}

$idUser = requerirUsuarioAutenticado();

$monto = isset($_POST['monto']) ? (int) $_POST['monto'] : 0;
$empleado = isset($_POST['empleado']) ? trim($_POST['empleado']) : '';
$nota = isset($_POST['nota']) ? trim($_POST['nota']) : '';
$fechaHora = isset($_POST['fecha_hora']) ? trim($_POST['fecha_hora']) : date('Y-m-d H:i:s');

if ($monto < 0) {
    responderJson(respuestaError('El monto de apertura no puede ser negativo.'), 400);
    exit();
}

$conn = conectarBaseDeDatos();

if (!usuarioTienePermiso($conn, $idUser, 'pos', 'abrir_caja')) {
    $conn->close();
    responderJson(respuestaError('No tiene permiso para abrir caja.'), 403);
    exit();
}

$conn->begin_transaction();

try {
    $sqlInsert = "INSERT INTO sesion (id_user, fecha_ingreso, monto_apertura, empleado, nota) VALUES (?, ?, ?, ?, ?)";
    $stmtInsert = $conn->prepare($sqlInsert);

    if (!$stmtInsert) {
        throw new Exception('Error al preparar la apertura de caja.');
    }

    $stmtInsert->bind_param("isiss", $idUser, $fechaHora, $monto, $empleado, $nota);
    if (!$stmtInsert->execute()) {
        throw new Exception('Error al registrar la apertura de caja.');
    }

    $idSesion = $conn->insert_id;
    $stmtInsert->close();

    $codigoCaja = 'CAJA-' . str_pad((string) $idSesion, 4, '0', STR_PAD_LEFT);
    $nombreCaja = 'Caja Principal';
    $estadoCaja = 'ABIERTA';
    $sucursal = 'Principal';

    $sqlCaja = "INSERT INTO pos_caja (id_user, codigo, nombre, sucursal, estado, monto_apertura, monto_actual, fecha_apertura, id_sesion)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
    $stmtCaja = $conn->prepare($sqlCaja);

    if (!$stmtCaja) {
        throw new Exception('Error al preparar la caja POS.');
    }

    $stmtCaja->bind_param("issssiii", $idUser, $codigoCaja, $nombreCaja, $sucursal, $estadoCaja, $monto, $monto, $idSesion);
    if (!$stmtCaja->execute()) {
        throw new Exception('Error al crear la caja POS.');
    }
    $idCaja = $conn->insert_id;
    $stmtCaja->close();

    $sqlUpdate = "UPDATE usuario SET validar_sesion = 2 WHERE id_user = ?";
    $stmtUpdate = $conn->prepare($sqlUpdate);

    if (!$stmtUpdate) {
        throw new Exception('Error al preparar el estado de sesión del usuario.');
    }

    $stmtUpdate->bind_param("i", $idUser);
    if (!$stmtUpdate->execute()) {
        throw new Exception('Error al actualizar el estado de sesión del usuario.');
    }
    $stmtUpdate->close();

    $conn->commit();
    $conn->close();

    responderJson(respuestaOk('Apertura realizada exitosamente.', array(
        'id_sesion' => $idSesion,
        'id_caja' => $idCaja,
        'monto' => $monto,
        'empleado' => $empleado,
        'nota' => $nota,
        'fecha_hora' => $fechaHora,
    )));
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    responderJson(respuestaError($e->getMessage()), 500);
}
