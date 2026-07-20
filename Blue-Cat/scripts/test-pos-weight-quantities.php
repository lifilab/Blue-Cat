<?php
declare(strict_types=1);

function argValue(string $name): ?string {
    foreach (array_slice($_SERVER['argv'], 1) as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function expectQuantity($actual, string $expected, string $message): void {
    $normalized = number_format((float) $actual, 3, '.', '');
    expect($normalized === $expected, "{$message} (esperado {$expected}, recibido {$normalized})");
}

function invokeApi(string $root, string $env, int $user, array $body): array {
    $command = [
        PHP_BINARY,
        $root . '/scripts/invoke-api-test.php',
        '--env=' . $env,
        '--endpoint=pos.php',
        '--user=' . $user,
        '--method=POST',
        '--body=' . base64_encode((string) json_encode($body)),
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo invocar la API POS de prueba.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Respuesta POS invalida (codigo {$code}): {$stdout} {$stderr}");
    }
    return $decoded;
}

function scalar(mysqli $db, string $sql) {
    $result = $db->query($sql);
    $row = $result->fetch_row();
    return $row[0] ?? null;
}

function expectDecimalContract(mysqli $db, string $table, string $column): void {
    $stmt = $db->prepare('SELECT DATA_TYPE,NUMERIC_PRECISION,NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    expect(
        $row && $row['DATA_TYPE'] === 'decimal' && (int) $row['NUMERIC_PRECISION'] === 18 && (int) $row['NUMERIC_SCALE'] === 3,
        "{$table}.{$column} usa DECIMAL(18,3)"
    );
}

$root = dirname(__DIR__);
$env = argValue('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $env) ? $env : $root . '/' . $env;
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') {
    throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');
}

$db = getDB();
$account = $user = $weightProduct = $unitProduct = $warehouse = 0;
$triggerName = '';

try {
    foreach ([
        ['producto', 'cantidad'],
        ['stock', 'disponible'],
        ['kardex', 'entrada'],
        ['kardex', 'salida'],
        ['kardex', 'saldo'],
        ['detalle_pedido', 'cantidad_pedida'],
        ['pos_devolucion_detalle', 'cantidad'],
    ] as [$table, $column]) {
        expectDecimalContract($db, $table, $column);
    }

    $accountName = 'POS decimal ' . bin2hex(random_bytes(4));
    $stmt = $db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
    $stmt->bind_param('s', $accountName);
    $stmt->execute();
    $account = (int) $db->insert_id;
    $stmt->close();

    $hash = password_hash('Decimal-2026', PASSWORD_DEFAULT);
    $login = 'pos-decimal-' . bin2hex(random_bytes(4));
    $mail = $login . '@test.local';
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $account, $login, $mail, $hash);
    $stmt->execute();
    $user = (int) $db->insert_id;
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$user} WHERE id_cuenta={$account}");
    provisionTenantRoles($db, $account);
    $adminRole = (int) scalar($db, "SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='Administrador'");
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$adminRole})");

    $weightName = 'Producto vendido por peso';
    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,cantidad,tipo_venta,costo_promedio,activo) VALUES (?,?,?,1000,10.000,'PESO',0,1)");
    $stmt->bind_param('iis', $user, $account, $weightName);
    $stmt->execute();
    $weightProduct = (int) $db->insert_id;
    $stmt->close();

    $unitName = 'Producto solo por unidad';
    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,cantidad,tipo_venta,costo_promedio,activo) VALUES (?,?,?,1000,5.000,'UNIDAD',0,1)");
    $stmt->bind_param('iis', $user, $account, $unitName);
    $stmt->execute();
    $unitProduct = (int) $db->insert_id;
    $stmt->close();

    $warehouseCode = 'DEC-' . bin2hex(random_bytes(3));
    $warehouseName = 'Bodega decimal';
    $stmt = $db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
    $stmt->bind_param('iiss', $user, $account, $warehouseCode, $warehouseName);
    $stmt->execute();
    $warehouse = (int) $db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$weightProduct},{$warehouse},10.000),({$unitProduct},{$warehouse},5.000)");

    $db->begin_transaction();
    actualizarKardex($db, $user, $weightProduct, $warehouse, 'INICIAL', 0, 'PRUEBA', 10.000, 0, 0, 'Saldo inicial E2E decimal');
    $db->commit();

    $cashCode = 'DEC-CAJA-' . bin2hex(random_bytes(3));
    $open = invokeApi($root, $envPath, $user, [
        'action' => 'caja_abrir',
        'codigo' => $cashCode,
        'nombre' => 'Caja decimal',
        'monto_apertura' => 5000,
    ]);
    expect(!empty($open['success']), 'abre una caja para el flujo decimal');

    $saleKey = 'weight-sale-' . bin2hex(random_bytes(10));
    $salePayload = [
        'action' => 'venta_crear',
        'items' => [['id_producto' => $weightProduct, 'cantidad' => 1.275, 'precio_unitario' => 1000]],
        'pagos' => [['metodo' => 'EFECTIVO', 'monto' => 1275]],
        'tipo_documento' => 'BOLETA',
        'idempotency_key' => $saleKey,
    ];
    $sale = invokeApi($root, $envPath, $user, $salePayload);
    expect(!empty($sale['success']) && (int) ($sale['total'] ?? 0) === 1275, 'vende exactamente 1.275 de un producto por peso');
    $orderA = (int) $sale['id_pedido'];
    $detailA = (int) scalar($db, "SELECT id_detalle_pedido FROM detalle_pedido WHERE id_pedido={$orderA}");
    expectQuantity(scalar($db, "SELECT cantidad_pedida FROM detalle_pedido WHERE id_detalle_pedido={$detailA}"), '1.275', 'el detalle conserva la cantidad vendida');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '8.725', 'la venta descuenta stock decimal exacto');
    expectQuantity(scalar($db, "SELECT cantidad FROM producto WHERE id_producto={$weightProduct}"), '8.725', 'el total del producto queda sincronizado');
    $saleLedger = $db->query("SELECT entrada,salida,saldo FROM kardex WHERE id_producto={$weightProduct} AND tipo_movimiento='VENTA' AND id_documento={$orderA}")->fetch_assoc();
    expectQuantity($saleLedger['entrada'], '0.000', 'la venta no registra entrada en kardex');
    expectQuantity($saleLedger['salida'], '1.275', 'la venta registra salida decimal exacta');
    expectQuantity($saleLedger['saldo'], '8.725', 'el saldo de kardex coincide con stock tras vender');

    $replay = invokeApi($root, $envPath, $user, $salePayload);
    expect(!empty($replay['idempotent_replay']) && (int) $replay['id_pedido'] === $orderA, 'el reintento idempotente recupera la venta original');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '8.725', 'el replay no vuelve a descontar stock');
    expect((int) scalar($db, "SELECT COUNT(*) FROM kardex WHERE id_producto={$weightProduct} AND tipo_movimiento='VENTA' AND id_documento={$orderA}") === 1, 'el replay no duplica el kardex');

    $fractionalUnit = invokeApi($root, $envPath, $user, [
        'action' => 'venta_crear',
        'items' => [['id_producto' => $unitProduct, 'cantidad' => 0.125, 'precio_unitario' => 1000]],
        'pagos' => [['metodo' => 'EFECTIVO', 'monto' => 125]],
        'tipo_documento' => 'BOLETA',
        'idempotency_key' => 'unit-fraction-' . bin2hex(random_bytes(10)),
    ]);
    expect(isset($fractionalUnit['error']), 'rechaza una fraccion para un producto por unidad');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$unitProduct} AND id_bodega={$warehouse}"), '5.000', 'el rechazo por unidad no altera stock');

    $partialReturn = invokeApi($root, $envPath, $user, [
        'action' => 'devolucion_crear',
        'id_pedido' => $orderA,
        'motivo' => 'Devolucion decimal parcial',
        'items' => [['id_detalle_pedido' => $detailA, 'id_producto' => $weightProduct, 'cantidad' => 0.500]],
    ]);
    expect(!empty($partialReturn['success']) && ($partialReturn['tipo'] ?? '') === 'PARCIAL' && (int) $partialReturn['monto_devuelto'] === 500, 'devuelve 0.500 con monto calculado por servidor');
    $returnA = (int) $partialReturn['id_devolucion'];
    expectQuantity(scalar($db, "SELECT cantidad FROM pos_devolucion_detalle WHERE id_devolucion={$returnA}"), '0.500', 'la devolucion conserva su cantidad decimal');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '9.225', 'la devolucion repone exactamente 0.500');
    $returnLedger = $db->query("SELECT entrada,salida,saldo FROM kardex WHERE id_producto={$weightProduct} AND tipo_movimiento='DEVOLUCION' AND id_documento={$returnA}")->fetch_assoc();
    expectQuantity($returnLedger['entrada'], '0.500', 'la devolucion registra entrada decimal en kardex');
    expectQuantity($returnLedger['saldo'], '9.225', 'kardex y stock coinciden tras devolucion parcial');

    $voidAfterReturn = invokeApi($root, $envPath, $user, [
        'action' => 'venta_anular',
        'id_pedido' => $orderA,
        'motivo' => 'Intento tras devolución parcial',
    ]);
    expect(isset($voidAfterReturn['error']), 'impide anular completa una venta con devolucion parcial');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '9.225', 'la anulacion rechazada no duplica reposicion');

    $finalReturn = invokeApi($root, $envPath, $user, [
        'action' => 'devolucion_crear',
        'id_pedido' => $orderA,
        'motivo' => 'Completar devolucion decimal',
        'items' => [['id_detalle_pedido' => $detailA, 'id_producto' => $weightProduct, 'cantidad' => 0.775]],
    ]);
    expect(!empty($finalReturn['success']) && ($finalReturn['tipo'] ?? '') === 'TOTAL' && (int) $finalReturn['monto_devuelto'] === 775, 'completa la devolucion sin perdida decimal');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '10.000', 'las devoluciones restauran el stock inicial exacto');

    $voidSale = invokeApi($root, $envPath, $user, [
        'action' => 'venta_crear',
        'items' => [['id_producto' => $weightProduct, 'cantidad' => 0.125, 'precio_unitario' => 1000]],
        'pagos' => [['metodo' => 'EFECTIVO', 'monto' => 125]],
        'tipo_documento' => 'BOLETA',
        'idempotency_key' => 'weight-void-' . bin2hex(random_bytes(10)),
    ]);
    expect(!empty($voidSale['success']), 'crea una venta decimal de 0.125 para anular');
    $orderB = (int) $voidSale['id_pedido'];
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '9.875', 'la segunda venta descuenta 0.125 exactos');
    $voidReason = 'Error de cobro decimal';
    $db->query("UPDATE pos_caja SET monto_actual=0 WHERE id_user={$user} AND estado='ABIERTA'");
    $voidWithoutCash = invokeApi($root, $envPath, $user, ['action' => 'venta_anular', 'id_pedido' => $orderB, 'motivo' => $voidReason]);
    expect(isset($voidWithoutCash['error']), 'impide anular efectivo cuando la caja no puede cubrir el egreso');
    expect((int)scalar($db, "SELECT anulado FROM pedido WHERE id_pedido={$orderB}") === 0, 'la anulacion sin efectivo revierte el estado del pedido');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '9.875', 'la anulacion sin efectivo revierte la reposicion de stock');
    $db->query("UPDATE pos_caja SET monto_actual=5125 WHERE id_user={$user} AND estado='ABIERTA'");
    $void = invokeApi($root, $envPath, $user, ['action' => 'venta_anular', 'id_pedido' => $orderB, 'motivo' => $voidReason]);
    expect(!empty($void['success']), 'anula una venta decimal sin devoluciones');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '10.000', 'la anulacion repone exactamente 0.125');
    expectQuantity(scalar($db, "SELECT cantidad FROM producto WHERE id_producto={$weightProduct}"), '10.000', 'producto y stock terminan sincronizados');
    $voidLedger = $db->query("SELECT entrada,salida,saldo FROM kardex WHERE id_producto={$weightProduct} AND tipo_movimiento='ANULACION' AND id_documento={$orderB}")->fetch_assoc();
    expectQuantity($voidLedger['entrada'], '0.125', 'la anulacion registra 0.125 de entrada en kardex');
    expectQuantity($voidLedger['salida'], '0.000', 'la anulacion no registra salida en kardex');
    expectQuantity($voidLedger['saldo'], '10.000', 'el kardex termina con el saldo fisico exacto');
    $auditDetail = (string) scalar($db, "SELECT detalle FROM pos_auditoria WHERE accion='venta_anular' AND id_referencia={$orderB} ORDER BY id_auditoria DESC LIMIT 1");
    expect(str_contains($auditDetail, $voidReason), 'la auditoria de anulacion conserva el motivo informado');

    $ordersBeforeFailure = (int) scalar($db, "SELECT COUNT(*) FROM pedido WHERE id_cuenta={$account}");
    $triggerName = 'trg_pos_decimal_' . bin2hex(random_bytes(4));
    $db->query("CREATE TRIGGER `{$triggerName}` BEFORE INSERT ON kardex FOR EACH ROW BEGIN IF NEW.id_producto={$weightProduct} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo kardex E2E'; END IF; END");
    try {
        $failedLedgerSale = invokeApi($root, $envPath, $user, [
            'action' => 'venta_crear',
            'items' => [['id_producto' => $weightProduct, 'cantidad' => 0.500, 'precio_unitario' => 1000]],
            'pagos' => [['metodo' => 'EFECTIVO', 'monto' => 500]],
            'tipo_documento' => 'BOLETA',
            'idempotency_key' => 'weight-kardex-failure-' . bin2hex(random_bytes(10)),
        ]);
    } finally {
        $db->query("DROP TRIGGER IF EXISTS `{$triggerName}`");
        $triggerName = '';
    }
    expect(isset($failedLedgerSale['error']), 'un fallo de kardex rechaza la venta');
    expect((int) scalar($db, "SELECT COUNT(*) FROM pedido WHERE id_cuenta={$account}") === $ordersBeforeFailure, 'el fallo de kardex revierte el pedido completo');
    expectQuantity(scalar($db, "SELECT disponible FROM stock WHERE id_producto={$weightProduct} AND id_bodega={$warehouse}"), '10.000', 'el fallo de kardex revierte el descuento de stock');
    expectQuantity(scalar($db, "SELECT cantidad FROM producto WHERE id_producto={$weightProduct}"), '10.000', 'el fallo de kardex revierte la cantidad sincronizada');

    expectQuantity(scalar($db, "SELECT monto_actual FROM pos_caja WHERE id_user={$user} AND estado='ABIERTA'"), '5000.000', 'ventas, devoluciones y anulacion mantienen la caja cuadrada');
    echo "OK flujo POS decimal verificado.\n";
} finally {
    if ($triggerName !== '') $db->query("DROP TRIGGER IF EXISTS `{$triggerName}`");
    if ($account > 0) {
        $db->query("DELETE FROM pos_movimiento_caja WHERE id_caja IN (SELECT id_caja FROM pos_caja WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_devolucion_detalle WHERE id_devolucion IN (SELECT id_devolucion FROM pos_devolucion WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account}))");
        $db->query("DELETE FROM pos_devolucion WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_promocion_auditoria WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_promocion_aplicacion WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_descuento WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_venta_idempotencia WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pos_documento_snapshot WHERE id_cuenta={$account}");
        $db->query("DELETE FROM metodo_de_pago WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM detalle_pedido WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_auditoria WHERE id_user={$user}");
        $db->query("DELETE FROM pos_caja WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pos_caja_fisica WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pos_folio_contador WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pedido WHERE id_cuenta={$account}");
        $db->query("DELETE FROM sesion WHERE id_cuenta={$account}");
        if ($weightProduct > 0 || $unitProduct > 0) {
            $ids = implode(',', array_filter([$weightProduct, $unitProduct]));
            $db->query("DELETE FROM kardex WHERE id_producto IN ({$ids})");
            $db->query("DELETE FROM stock WHERE id_producto IN ({$ids})");
            $db->query("DELETE FROM producto WHERE id_producto IN ({$ids})");
        }
        if ($warehouse > 0) $db->query("DELETE FROM bodega WHERE id_bodega={$warehouse}");
        $db->query("DELETE FROM usuario_rol WHERE id_user={$user}");
        $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta={$account}");
        foreach (['core_auditoria','empleado_auditoria','inventario_auditoria','cliente_auditoria'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_user={$user}"); } catch (mysqli_sql_exception) {}
        }
        $db->query("DELETE FROM usuario WHERE id_user={$user}");
        $db->query("DELETE FROM rol WHERE id_cuenta={$account}");
        $db->query("DELETE FROM cuenta WHERE id_cuenta={$account}");
    }
}
