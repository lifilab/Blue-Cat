<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();

function requierePermiso($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error'=>'Permiso denegado: '.$modulo.'.'.$accion], 403);
    }
}

$conn = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id) getFactura($conn, $uid, $id);
        else listFacturas($conn, $uid);
        break;
    case 'POST':
        $input = getJsonInput();
        $accion = $input['accion'] ?? 'crear';
        if ($accion === 'crear') { requierePermiso('facturas','crear'); crearFactura($conn, $uid, $input); }
        elseif ($accion === 'pagar') { requierePermiso('facturas','editar'); registrarPago($conn, $uid, $input); }
        elseif ($accion === 'anular') { requierePermiso('facturas','eliminar'); anularFactura($conn, $uid, $input); }
        elseif ($accion === 'nota_credito') { requierePermiso('facturas','nota_credito'); crearNotaCredito($conn, $uid, $input); }
        elseif ($accion === 'nota_debito') { requierePermiso('facturas','editar'); crearNotaDebito($conn, $uid, $input); }
        else json(['error'=>'Acción no válida'], 400);
        break;
    default: json(['error'=>'Método no soportado'], 405);
}

function listFacturas($conn, $uid) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = "WHERE f.id_user = ?";
    $params = [$uid];
    $types = 'i';

    if (!empty($_GET['estado']))       { $where .= " AND f.estado = ?"; $params[] = $_GET['estado']; $types .= 's'; }
    if (!empty($_GET['tipo']))         { $where .= " AND f.tipo = ?"; $params[] = $_GET['tipo']; $types .= 's'; }
    if (!empty($_GET['q'])) {
        $q = '%' . $_GET['q'] . '%';
        $where .= " AND (f.numero LIKE ? OR f.folio LIKE ? OR c.razon_social LIKE ? OR c.rut LIKE ? OR c.nombre LIKE ?)";
        $params = array_merge($params, [$q, $q, $q, $q, $q]);
        $types .= 'sssss';
    }
    if (!empty($_GET['desde']))        { $where .= " AND f.fecha_emision >= ?"; $params[] = $_GET['desde']; $types .= 's'; }
    if (!empty($_GET['hasta']))        { $where .= " AND f.fecha_emision <= ?"; $params[] = $_GET['hasta'] . ' 23:59:59'; $types .= 's'; }
    if (!empty($_GET['cliente']))      { $where .= " AND f.id_cliente = ?"; $params[] = (int)$_GET['cliente']; $types .= 'i'; }
    if (!empty($_GET['vendedor']))     { $where .= " AND f.vendedor LIKE ?"; $params[] = '%' . $_GET['vendedor'] . '%'; $types .= 's'; }
    if (!empty($_GET['min']))          { $where .= " AND f.total >= ?"; $params[] = (int)$_GET['min']; $types .= 'i'; }
    if (!empty($_GET['max']))          { $where .= " AND f.total <= ?"; $params[] = (int)$_GET['max']; $types .= 'i'; }

    $countSql = "SELECT COUNT(*) as total FROM factura f LEFT JOIN cliente c ON f.id_cliente = c.id_cliente $where";
    $stmt = $conn->prepare($countSql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $listParams = $params;
    $listTypes = $types;
    $listParams[] = $limit;
    $listParams[] = $offset;
    $listTypes .= 'ii';

    $sql = "SELECT f.*, c.razon_social, c.rut, c.nombre as cliente_nombre
            FROM factura f
            LEFT JOIN cliente c ON f.id_cliente = c.id_cliente
            $where
            ORDER BY f.id_factura DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($listParams) $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json([
        'data' => $rows,
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]);
}

function getFactura($conn, $uid, $id) {
    $stmt = $conn->prepare("SELECT f.*, c.razon_social, c.rut, c.nombre as cliente_nombre, c.direccion, c.correo, c.telefono, c.giro
        FROM factura f LEFT JOIN cliente c ON f.id_cliente = c.id_cliente WHERE f.id_factura = ? AND f.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $factura = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$factura) json(['error'=>'Factura no encontrada'], 404);

    $stmt = $conn->prepare("SELECT * FROM factura_detalle WHERE id_factura = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $factura['detalle'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM factura_pago WHERE id_factura = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $factura['pagos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT h.*, u.nombre as usuario FROM factura_historial h LEFT JOIN usuario u ON h.id_user = u.id_user WHERE h.id_factura = ? ORDER BY h.fecha DESC LIMIT 50");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $factura['historial'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json($factura);
}

function crearFactura($conn, $uid, $input) {
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    $id_pedido = (int)($input['id_pedido'] ?? 0);
    $tipo = $input['tipo'] ?? 'FACTURA';
    $metodo_pago = $input['metodo_pago'] ?? '';
    $sucursal = $input['sucursal'] ?? '';
    $vendedor = $input['vendedor'] ?? '';
    $observaciones = $input['observaciones'] ?? '';
    $orden_compra = $input['orden_compra'] ?? '';

    $conn->begin_transaction();
    try {
        // Get next folio
        $stmt = $conn->prepare("SELECT COALESCE(MAX(folio),0)+1 as next_folio FROM factura WHERE id_user = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $folio = (int)$stmt->get_result()->fetch_assoc()['next_folio'];
        $stmt->close();
        $numero = 'F-' . str_pad($folio, 8, '0', STR_PAD_LEFT);

        $items = $input['items'] ?? [];
        $subtotal = 0; $descuento = 0; $neto = 0; $iva = 0; $total = 0;

        $stmt = $conn->prepare("INSERT INTO factura (id_user, id_cliente, id_pedido, folio, numero, tipo, estado, fecha_emision, fecha_vencimiento, subtotal, descuento, neto, iva, total, metodo_pago, sucursal, vendedor, observaciones, orden_compra)
            VALUES (?,?,?,?,?,?,'EMITIDA',NOW(),DATE_ADD(NOW(),INTERVAL 30 DAY),0,0,0,0,0,?,?,?,?,?)");
        $stmt->bind_param("iiiisssssss", $uid, $id_cliente, $id_pedido, $folio, $numero, $tipo, $metodo_pago, $sucursal, $vendedor, $observaciones, $orden_compra);
        $stmt->execute();
        $id_factura = (int)$conn->insert_id;
        $stmt->close();

        foreach ($items as $item) {
            $id_producto = (int)($item['id_producto'] ?? 0);
            $nombre = $item['producto'] ?? '';
            $cantidad = (int)($item['cantidad'] ?? 1);
            $precio = (int)($item['precio'] ?? 0);
            $desc = (int)($item['descuento'] ?? 0);
            $item_neto = ($precio * $cantidad) - $desc;
            $item_iva = (int)round($item_neto * 0.19);
            $item_total = $item_neto + $item_iva;

            $subtotal += $precio * $cantidad;
            $descuento += $desc;
            $neto += $item_neto;
            $iva += $item_iva;
            $total += $item_total;

            $stmt2 = $conn->prepare("INSERT INTO factura_detalle (id_factura, id_producto, producto, cantidad, precio, descuento, neto, iva, total) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt2->bind_param("iisiiiiii", $id_factura, $id_producto, $nombre, $cantidad, $precio, $desc, $item_neto, $item_iva, $item_total);
            $stmt2->execute();
            $stmt2->close();

            // Deduct stock
            if ($id_producto) {
                $stmt_stock = $conn->prepare("UPDATE producto SET cantidad = GREATEST(cantidad - ?, 0) WHERE id_producto = ?");
                $stmt_stock->bind_param("ii", $cantidad, $id_producto);
                $stmt_stock->execute();
                $stmt_stock->close();
            }
        }

        // Update totals
        $stmt_upd = $conn->prepare("UPDATE factura SET subtotal=?, descuento=?, neto=?, iva=?, total=?, saldo=? WHERE id_factura=?");
        $stmt_upd->bind_param("iiiiiii", $subtotal, $descuento, $neto, $iva, $total, $total, $id_factura);
        $stmt_upd->execute();
        $stmt_upd->close();

        addHistorial($conn, $id_factura, $uid, 'CREAR', null, "Factura $numero creada por \$$total");

        $conn->commit();
        json(['success'=>true, 'id_factura'=>$id_factura, 'numero'=>$numero, 'folio'=>$folio, 'total'=>$total], 201);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function registrarPago($conn, $uid, $input) {
    $id_factura = (int)($input['id_factura'] ?? 0);
    $metodo = $input['metodo'] ?? '';
    $monto = (int)($input['monto'] ?? 0);
    $banco = $input['banco'] ?? '';
    $referencia = $input['referencia'] ?? '';
    $observacion = $input['observacion'] ?? '';

    if (!$id_factura || !$metodo || $monto <= 0) json(['error'=>'Datos de pago inválidos'], 400);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT total, pagado, saldo, estado FROM factura WHERE id_factura = ? AND id_user = ?");
        $stmt->bind_param("ii", $id_factura, $uid);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$f) json(['error'=>'Factura no encontrada'], 404);

        $nuevo_pagado = (int)$f['pagado'] + $monto;
        $nuevo_saldo = (int)$f['total'] - $nuevo_pagado;
        $nuevo_estado = $nuevo_saldo <= 0 ? 'PAGADA' : 'PARCIAL';

        $stmt = $conn->prepare("INSERT INTO factura_pago (id_factura, metodo, monto, banco, referencia, observacion) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("isisss", $id_factura, $metodo, $monto, $banco, $referencia, $observacion);
        $stmt->execute();
        $stmt->close();

        $stmt_upd = $conn->prepare("UPDATE factura SET pagado=?, saldo=?, estado=? WHERE id_factura=?");
        $stmt_upd->bind_param("iisi", $nuevo_pagado, $nuevo_saldo, $nuevo_estado, $id_factura);
        $stmt_upd->execute();
        $stmt_upd->close();

        addHistorial($conn, $id_factura, $uid, 'PAGO', "Pagado: \${$f['pagado']}", "Pagado: \$$nuevo_pagado, Saldo: \$$nuevo_saldo");

        $conn->commit();
        json(['success'=>true, 'pagado'=>$nuevo_pagado, 'saldo'=>$nuevo_saldo, 'estado'=>$nuevo_estado]);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function anularFactura($conn, $uid, $input) {
    $id_factura = (int)($input['id_factura'] ?? 0);
    $motivo = $input['motivo'] ?? 'Anulación manual';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id_factura, numero, total, estado FROM factura WHERE id_factura = ? AND id_user = ?");
        $stmt->bind_param("ii", $id_factura, $uid);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$f) json(['error'=>'Factura no encontrada'], 404);
        if ($f['estado'] === 'ANULADA') json(['error'=>'La factura ya está anulada'], 400);

        // Restore stock
        $stmt = $conn->prepare("SELECT id_producto, cantidad FROM factura_detalle WHERE id_factura = ?");
        $stmt->bind_param("i", $id_factura);
        $stmt->execute();
        $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($detalles as $d) {
            if ($d['id_producto']) {
                $cant = $d['cantidad'];
                $prod = $d['id_producto'];
                $stmt_rest = $conn->prepare("UPDATE producto SET cantidad = cantidad + ? WHERE id_producto = ?");
                $stmt_rest->bind_param("ii", $cant, $prod);
                $stmt_rest->execute();
                $stmt_rest->close();
            }
        }

        $estado_anterior = $f['estado'];
        $stmt_upd = $conn->prepare("UPDATE factura SET estado='ANULADA' WHERE id_factura=?");
        $stmt_upd->bind_param("i", $id_factura);
        $stmt_upd->execute();
        $stmt_upd->close();
        addHistorial($conn, $id_factura, $uid, 'ANULAR', "Estado: $estado_anterior", "Estado: ANULADA - $motivo");

        $conn->commit();
        json(['success'=>true, 'msg'=>"Factura {$f['numero']} anulada. Stock restaurado."]);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function crearNotaCredito($conn, $uid, $input) {
    $id_factura_original = (int)($input['id_factura'] ?? 0);
    $items = $input['items'] ?? [];
    $motivo = $input['motivo'] ?? 'Nota de crédito';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM factura WHERE id_factura = ? AND id_user = ?");
        $stmt->bind_param("ii", $id_factura_original, $uid);
        $stmt->execute();
        $orig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$orig) json(['error'=>'Factura original no encontrada'], 404);

        $stmt_nc = $conn->prepare("SELECT COALESCE(MAX(folio),0)+1 as next FROM factura WHERE id_user = ? AND tipo='NOTA_CREDITO'");
        $stmt_nc->bind_param("i", $uid);
        $stmt_nc->execute();
        $folio_nc = (int)$stmt_nc->get_result()->fetch_assoc()['next'];
        $stmt_nc->close();
        $numero_nc = 'NC-' . str_pad($folio_nc, 8, '0', STR_PAD_LEFT);

        $total_nc = 0;
        $id_pedido_null = null;
        $tipo_nc = 'NOTA_CREDITO';
        $estado_nc = 'EMITIDA';
        $orig_id_cliente = (int)$orig['id_cliente'];
        $stmt_nc2 = $conn->prepare("INSERT INTO factura (id_user, id_cliente, id_pedido, folio, numero, tipo, estado, fecha_emision, observaciones, subtotal, descuento, neto, iva, total, saldo) VALUES (?,?,?,?,?,?,?,NOW(),?,0,0,0,0,0,0)");
        $stmt_nc2->bind_param("iiiissss", $uid, $orig_id_cliente, $id_pedido_null, $folio_nc, $numero_nc, $tipo_nc, $estado_nc, $motivo);
        $stmt_nc2->execute();
        $id_nc = (int)$conn->insert_id;
        $stmt_nc2->close();

        foreach ($items as $item) {
            $id_producto = (int)($item['id_producto'] ?? 0);
            $nombre = $item['producto'] ?? '';
            $cantidad = (int)($item['cantidad'] ?? 1);
            $precio = (int)($item['precio'] ?? 0);
            $item_total = $precio * $cantidad;
            $item_iva = (int)round($item_total * 0.19 / 1.19);
            $item_neto = $item_total - $item_iva;
            $total_nc += $item_total;

            $stmt_det = $conn->prepare("INSERT INTO factura_detalle (id_factura, id_producto, producto, cantidad, precio, neto, iva, total) VALUES (?,?,?,?,?,?,?,?)");
            $stmt_det->bind_param("iisiiiii", $id_nc, $id_producto, $nombre, $cantidad, $precio, $item_neto, $item_iva, $item_total);
            $stmt_det->execute();
            $stmt_det->close();

            if ($id_producto) {
                $stmt_stk = $conn->prepare("UPDATE producto SET cantidad = cantidad + ? WHERE id_producto = ?");
                $stmt_stk->bind_param("ii", $cantidad, $id_producto);
                $stmt_stk->execute();
                $stmt_stk->close();
            }
        }

        $neto_calc = $total_nc - (int)round($total_nc * 0.19 / 1.19);
        $iva_calc = (int)round($total_nc * 0.19 / 1.19);
        $stmt_upd_nc = $conn->prepare("UPDATE factura SET subtotal=?, neto=?, iva=?, total=?, saldo=? WHERE id_factura=?");
        $stmt_upd_nc->bind_param("iiiiii", $total_nc, $neto_calc, $iva_calc, $total_nc, $total_nc, $id_nc);
        $stmt_upd_nc->execute();
        $stmt_upd_nc->close();

        addHistorial($conn, $id_nc, $uid, 'NOTA_CREDITO', null, "NC $numero_nc por \$$total_nc - $motivo");
        addHistorial($conn, $id_factura_original, $uid, 'NOTA_CREDITO_ASOCIADA', null, "NC $numero_nc asociada");

        // Update original factura saldo
        $stmt_upd_orig = $conn->prepare("UPDATE factura SET saldo = GREATEST(0, saldo - ?) WHERE id_factura = ?");
        $stmt_upd_orig->bind_param("ii", $total_nc, $id_factura_original);
        $stmt_upd_orig->execute();
        $stmt_upd_orig->close();
        $stmt_chk = $conn->prepare("SELECT saldo FROM factura WHERE id_factura = ?");
        $stmt_chk->bind_param("i", $id_factura_original);
        $stmt_chk->execute();
        $checkSaldo = $stmt_chk->get_result()->fetch_assoc();
        $stmt_chk->close();
        if ($checkSaldo && (int)$checkSaldo['saldo'] === 0) {
            $stmt_pag = $conn->prepare("UPDATE factura SET estado = 'PAGADA' WHERE id_factura = ?");
            $stmt_pag->bind_param("i", $id_factura_original);
            $stmt_pag->execute();
            $stmt_pag->close();
        }

        $conn->commit();
        json(['success'=>true, 'id_factura'=>$id_nc, 'numero'=>$numero_nc, 'total'=>$total_nc], 201);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function crearNotaDebito($conn, $uid, $input) {
    // Similar structure to NC but adds to total instead of subtracting
    $id_factura_original = (int)($input['id_factura'] ?? 0);
    $monto = (int)($input['monto'] ?? 0);
    $motivo = $input['motivo'] ?? 'Nota de débito';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM factura WHERE id_factura = ? AND id_user = ?");
        $stmt->bind_param("ii", $id_factura_original, $uid);
        $stmt->execute();
        $orig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$orig) json(['error'=>'Factura original no encontrada'], 404);

        $stmt_nd = $conn->prepare("SELECT COALESCE(MAX(folio),0)+1 as next FROM factura WHERE id_user = ? AND tipo='NOTA_DEBITO'");
        $stmt_nd->bind_param("i", $uid);
        $stmt_nd->execute();
        $folio_nd = (int)$stmt_nd->get_result()->fetch_assoc()['next'];
        $stmt_nd->close();
        $numero_nd = 'ND-' . str_pad($folio_nd, 8, '0', STR_PAD_LEFT);

        $tipo_nd = 'NOTA_DEBITO';
        $estado_nd = 'EMITIDA';
        $orig_id_cliente = (int)$orig['id_cliente'];
        $stmt_nd2 = $conn->prepare("INSERT INTO factura (id_user, id_cliente, folio, numero, tipo, estado, fecha_emision, observaciones, subtotal, neto, iva, total, saldo) VALUES (?,?,?,?,?,?,NOW(),?,?,?,0,?,?)");
        $stmt_nd2->bind_param("iiissssiiii", $uid, $orig_id_cliente, $folio_nd, $numero_nd, $tipo_nd, $estado_nd, $motivo, $monto, $monto, $monto, $monto);
        $stmt_nd2->execute();
        $id_nd = (int)$conn->insert_id;
        $stmt_nd2->close();

        addHistorial($conn, $id_nd, $uid, 'NOTA_DEBITO', null, "ND $numero_nd por \$$monto - $motivo");
        addHistorial($conn, $id_factura_original, $uid, 'NOTA_DEBITO_ASOCIADA', null, "ND $numero_nd asociada");

        // Update original factura saldo and total
        $stmt_upd_nd = $conn->prepare("UPDATE factura SET saldo = saldo + ?, total = total + ? WHERE id_factura = ?");
        $stmt_upd_nd->bind_param("iii", $monto, $monto, $id_factura_original);
        $stmt_upd_nd->execute();
        $stmt_upd_nd->close();

        $conn->commit();
        json(['success'=>true, 'id_factura'=>$id_nd, 'numero'=>$numero_nd, 'total'=>$monto], 201);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}
?>