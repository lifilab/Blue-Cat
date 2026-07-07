<?php
require_once __DIR__ . '/_db.php';

function decodificarCampoJson($requestBody, $key)
{
    if (!isset($requestBody[$key])) {
        return null;
    }

    if (is_array($requestBody[$key])) {
        return $requestBody[$key];
    }

    if (is_string($requestBody[$key])) {
        $decoded = json_decode($requestBody[$key], true);
        return is_array($decoded) ? $decoded : null;
    }

    return null;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJson(respuestaError('Método de solicitud no permitido.'), 405);
    exit();
}

$idUser = requerirUsuarioAutenticado();
$requestBody = json_decode(file_get_contents("php://input"), true);

if (!is_array($requestBody)) {
    responderJson(respuestaError('Solicitud inválida.'), 400);
    exit();
}

$saleData = decodificarCampoJson($requestBody, 'saleData');
$paymentRecords = decodificarCampoJson($requestBody, 'paymentRecords');
$cartItemsArray = decodificarCampoJson($requestBody, 'cartItemsArray');

if (!is_array($saleData) || !is_array($paymentRecords) || !is_array($cartItemsArray) || count($cartItemsArray) === 0) {
    responderJson(respuestaError('Datos de venta incompletos.'), 400);
    exit();
}

$totalPrice = isset($saleData['totalPrice']) ? (int) round((float) $saleData['totalPrice']) : 0;
$totalPayment = isset($saleData['totalPayment']) ? (int) round((float) $saleData['totalPayment']) : 0;
$change = isset($saleData['change']) ? (int) round((float) $saleData['change']) : 0;

if ($totalPrice <= 0 || $totalPayment < 0) {
    responderJson(respuestaError('Totales de venta inválidos.'), 400);
    exit();
}

$conn = conectarBaseDeDatos();

if (!usuarioTienePermiso($conn, $idUser, 'pos', 'realizar_venta')) {
    $conn->close();
    responderJson(respuestaError('No tiene permiso para realizar ventas.'), 403);
    exit();
}

$puedeCambiarPrecios = usuarioTienePermiso($conn, $idUser, 'pos', 'cambiar_precios');
$conn->begin_transaction();

try {
    $sqlSesion = "SELECT id_sesion FROM sesion WHERE id_user = ? AND fecha_cierre IS NULL ORDER BY id_sesion DESC LIMIT 1 FOR UPDATE";
    $stmtSesion = $conn->prepare($sqlSesion);
    if (!$stmtSesion) {
        throw new Exception('Error al preparar la sesión de caja.');
    }

    $stmtSesion->bind_param("i", $idUser);
    if (!$stmtSesion->execute()) {
        throw new Exception('Error al consultar la sesión de caja.');
    }

    $resultSesion = $stmtSesion->get_result();
    $rowSesion = $resultSesion->fetch_assoc();
    $stmtSesion->close();

    if (!$rowSesion) {
        throw new Exception('No hay sesión de caja abierta para el usuario.');
    }

    $idSesion = (int) $rowSesion['id_sesion'];
    $idCaja = null;

    $sqlCaja = "SELECT id_caja FROM pos_caja WHERE id_user = ? AND id_sesion = ? AND estado = 'ABIERTA' ORDER BY id_caja DESC LIMIT 1 FOR UPDATE";
    $stmtCaja = $conn->prepare($sqlCaja);
    if ($stmtCaja) {
        $stmtCaja->bind_param("ii", $idUser, $idSesion);
        if ($stmtCaja->execute()) {
            $resultCaja = $stmtCaja->get_result();
            $rowCaja = $resultCaja->fetch_assoc();
            if ($rowCaja) {
                $idCaja = (int) $rowCaja['id_caja'];
            }
        }
        $stmtCaja->close();
    }

    if ($idCaja !== null) {
        $sqlPedido = "INSERT INTO pedido (id_sesion, id_caja, precio_total, pago_total, diferencia, fecha)
                      VALUES (?, ?, ?, ?, ?, NOW())";
        $stmtPedido = $conn->prepare($sqlPedido);
        if (!$stmtPedido) {
            throw new Exception('Error al preparar el pedido.');
        }
        $stmtPedido->bind_param("iiiii", $idSesion, $idCaja, $totalPrice, $totalPayment, $change);
    } else {
        $sqlPedido = "INSERT INTO pedido (id_sesion, precio_total, pago_total, diferencia, fecha)
                      VALUES (?, ?, ?, ?, NOW())";
        $stmtPedido = $conn->prepare($sqlPedido);
        if (!$stmtPedido) {
            throw new Exception('Error al preparar el pedido.');
        }
        $stmtPedido->bind_param("iiii", $idSesion, $totalPrice, $totalPayment, $change);
    }

    if (!$stmtPedido->execute()) {
        throw new Exception('Error al insertar el pedido.');
    }

    $idPedido = $conn->insert_id;
    $stmtPedido->close();

    $sqlStock = "SELECT nombre_producto, precio_venta, cantidad FROM producto WHERE id_producto = ? AND id_user = ? FOR UPDATE";
    $stmtStock = $conn->prepare($sqlStock);
    if (!$stmtStock) {
        throw new Exception('Error al preparar la consulta de stock.');
    }

    $sqlActualizarStock = "UPDATE producto SET cantidad = ? WHERE id_producto = ? AND id_user = ?";
    $stmtActualizarStock = $conn->prepare($sqlActualizarStock);
    if (!$stmtActualizarStock) {
        throw new Exception('Error al preparar la actualización de stock.');
    }

    $sqlDetalle = "INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad_pedida, precio_total)
                   VALUES (?, ?, ?, ?)";
    $stmtDetalle = $conn->prepare($sqlDetalle);
    if (!$stmtDetalle) {
        throw new Exception('Error al preparar el detalle del pedido.');
    }

    $totalDetalle = 0;

    foreach ($cartItemsArray as $item) {
        $idProducto = isset($item['id_producto']) ? (int) $item['id_producto'] : 0;
        $cantidadPedida = isset($item['quantity']) ? (float) $item['quantity'] : 0;
        $precioTotalProducto = isset($item['price']) ? (int) round((float) $item['price']) : 0;

        if ($idProducto <= 0 || $cantidadPedida <= 0 || $precioTotalProducto < 0) {
            throw new Exception('Producto inválido en el carrito.');
        }

        $stmtStock->bind_param("ii", $idProducto, $idUser);
        if (!$stmtStock->execute()) {
            throw new Exception('Error al consultar stock del producto.');
        }

        $resultStock = $stmtStock->get_result();
        $rowStock = $resultStock->fetch_assoc();

        if (!$rowStock) {
            throw new Exception('Producto no encontrado o no pertenece al usuario.');
        }

        $cantidadActual = (float) $rowStock['cantidad'];
        $precioVenta = (int) $rowStock['precio_venta'];
        $precioEsperado = (int) round($precioVenta * $cantidadPedida);

        if (!$puedeCambiarPrecios && $precioTotalProducto !== $precioEsperado) {
            throw new Exception('No tiene permiso para cambiar precios en POS.');
        }

        if ($cantidadActual < $cantidadPedida) {
            throw new Exception('Stock insuficiente para ' . $rowStock['nombre_producto'] . '.');
        }

        $totalDetalle += $precioTotalProducto;
        $nuevaCantidad = $cantidadActual - $cantidadPedida;
        $stmtActualizarStock->bind_param("dii", $nuevaCantidad, $idProducto, $idUser);
        if (!$stmtActualizarStock->execute()) {
            throw new Exception('Error al actualizar stock del producto.');
        }

        $stmtDetalle->bind_param("iidi", $idPedido, $idProducto, $cantidadPedida, $precioTotalProducto);
        if (!$stmtDetalle->execute()) {
            throw new Exception('Error al insertar detalle del pedido.');
        }
    }

    $stmtStock->close();
    $stmtActualizarStock->close();
    $stmtDetalle->close();

    if ($totalDetalle !== $totalPrice) {
        throw new Exception('El total de la venta no coincide con el detalle.');
    }

    $sqlMetodo = "INSERT INTO metodo_de_pago (id_pedido, nombre_metodo_pago, monto)
                  VALUES (?, ?, ?)";
    $stmtMetodo = $conn->prepare($sqlMetodo);
    if (!$stmtMetodo) {
        throw new Exception('Error al preparar métodos de pago.');
    }

    $stmtMovimiento = null;
    if ($idCaja !== null) {
        $sqlMovimiento = "INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, referencia, id_pedido)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtMovimiento = $conn->prepare($sqlMovimiento);
        if (!$stmtMovimiento) {
            throw new Exception('Error al preparar movimiento de caja.');
        }
    }

    $totalPagos = 0;

    foreach ($paymentRecords as $record) {
        $nombreMetodoPago = isset($record['name']) ? trim($record['name']) : '';
        $monto = isset($record['value']) ? (int) round((float) $record['value']) : 0;

        if ($nombreMetodoPago === '' || $monto < 0) {
            throw new Exception('Método de pago inválido.');
        }

        $totalPagos += $monto;

        $stmtMetodo->bind_param("isi", $idPedido, $nombreMetodoPago, $monto);
        if (!$stmtMetodo->execute()) {
            throw new Exception('Error al insertar método de pago.');
        }

        if ($stmtMovimiento !== null) {
            $tipoMovimiento = 'VENTA';
            $concepto = 'Venta POS';
            $referencia = 'PEDIDO-' . $idPedido;
            $stmtMovimiento->bind_param("iississi", $idCaja, $idUser, $tipoMovimiento, $concepto, $monto, $nombreMetodoPago, $referencia, $idPedido);
            if (!$stmtMovimiento->execute()) {
                throw new Exception('Error al registrar movimiento de caja.');
            }
        }
    }

    $stmtMetodo->close();

    if ($totalPagos !== $totalPayment) {
        throw new Exception('El total pagado no coincide con los métodos de pago.');
    }

    if ($stmtMovimiento !== null) {
        $stmtMovimiento->close();

        $sqlActualizarCaja = "UPDATE pos_caja SET monto_actual = monto_actual + ? WHERE id_caja = ?";
        $stmtActualizarCaja = $conn->prepare($sqlActualizarCaja);
        if (!$stmtActualizarCaja) {
            throw new Exception('Error al preparar actualización de caja.');
        }
        $stmtActualizarCaja->bind_param("ii", $totalPayment, $idCaja);
        if (!$stmtActualizarCaja->execute()) {
            throw new Exception('Error al actualizar monto de caja.');
        }
        $stmtActualizarCaja->close();
    }

    $valorNuevo = json_encode(array(
        'id_pedido' => $idPedido,
        'id_sesion' => $idSesion,
        'id_caja' => $idCaja,
        'total' => $totalPrice,
        'pagado' => $totalPayment,
    ), JSON_UNESCAPED_UNICODE);
    $accion = 'CREAR';
    $entidad = 'pedido';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

    $sqlAuditoria = "INSERT INTO core_auditoria (id_user, accion, entidad, id_entidad, valor_nuevo, resultado, ip, user_agent, nivel)
                     VALUES (?, ?, ?, ?, ?, 'OK', ?, ?, 'INFO')";
    $stmtAuditoria = $conn->prepare($sqlAuditoria);
    if ($stmtAuditoria) {
        $stmtAuditoria->bind_param("ississs", $idUser, $accion, $entidad, $idPedido, $valorNuevo, $ip, $userAgent);
        $stmtAuditoria->execute();
        $stmtAuditoria->close();
    }

    $conn->commit();
    $conn->close();

    responderJson(respuestaOk('Pedido registrado correctamente.', array(
        'id_pedido' => $idPedido,
        'id_sesion' => $idSesion,
        'id_caja' => $idCaja,
    )));
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    $statusCode = $e->getMessage() === 'No tiene permiso para cambiar precios en POS.' ? 403 : 500;
    responderJson(respuestaError($e->getMessage()), $statusCode);
}
