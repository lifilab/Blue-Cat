<?php
declare(strict_types=1);

function inventoryArg(string $name): ?string {
    foreach (array_slice($_SERVER['argv'], 1) as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function inventoryAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function inventoryQuantity($actual, string $expected, string $message): void {
    $value = number_format((float)$actual, 3, '.', '');
    inventoryAssert($value === $expected, "{$message} (esperado {$expected}, recibido {$value})");
}

function inventoryScalar(mysqli $db, string $sql) {
    $result = $db->query($sql);
    $row = $result->fetch_row();
    return $row[0] ?? null;
}

function inventoryApi(string $root, string $env, int $user, array $body): array {
    $command = [
        PHP_BINARY,
        $root . '/scripts/invoke-api-test.php',
        '--env=' . $env,
        '--endpoint=inventario.php',
        '--user=' . $user,
        '--method=POST',
        '--body=' . base64_encode((string)json_encode($body)),
    ];
    $process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo invocar inventario.php');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded)) throw new RuntimeException("Respuesta de inventario inválida ({$code}): {$stdout} {$stderr}");
    return $decoded;
}

$root = dirname(__DIR__);
$env = inventoryArg('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $env) ? $env : $root . '/' . $env;
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');

$db = getDB();
$account = $foreignAccount = $user = $foreignUser = 0;
$product = $foreignProduct = $apiProduct = $unitProduct = $origin = $destination = $foreignWarehouse = 0;
$triggerCreated = false;
$productTriggerCreated = false;

try {
    foreach (['subcategoria','lote','serie','valorizacion_inventario'] as $requiredTable) {
        $escapedTable = $db->real_escape_string($requiredTable);
        inventoryAssert((int)inventoryScalar($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$escapedTable}'") === 1, "la migracion crea la tabla operativa {$requiredTable}");
    }
    $db->query("INSERT INTO cuenta(nombre) VALUES ('Inventory Integrity A'),('Inventory Integrity B')");
    $account = (int)$db->insert_id;
    $foreignAccount = $account + 1;
    $hash = password_hash('Inventory-2026!', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $name = 'inventory-a-' . bin2hex(random_bytes(3)); $mail = $name . '@test.local';
    $stmt->bind_param('isss', $account, $name, $mail, $hash); $stmt->execute(); $user = (int)$db->insert_id;
    $name = 'inventory-b-' . bin2hex(random_bytes(3)); $mail = $name . '@test.local';
    $stmt->bind_param('isss', $foreignAccount, $name, $mail, $hash); $stmt->execute(); $foreignUser = (int)$db->insert_id;
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$user} WHERE id_cuenta={$account}");
    $db->query("UPDATE cuenta SET id_usuario_propietario={$foreignUser} WHERE id_cuenta={$foreignAccount}");
    provisionTenantRoles($db, $account);
    provisionTenantRoles($db, $foreignAccount);
    $adminA = (int)inventoryScalar($db, "SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='Administrador'");
    $adminB = (int)inventoryScalar($db, "SELECT id_rol FROM rol WHERE id_cuenta={$foreignAccount} AND nombre='Administrador'");
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$adminA}),({$foreignUser},{$adminB})");

    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,precio_costo,cantidad,tipo_venta,activo) VALUES (?,?,?,1000,500,?,'PESO',1)");
    $name = 'Harina decimal'; $quantity = 11.000;
    $stmt->bind_param('iisd', $user, $account, $name, $quantity); $stmt->execute(); $product = (int)$db->insert_id;
    $name = 'Producto ajeno'; $quantity = 4.000;
    $stmt->bind_param('iisd', $foreignUser, $foreignAccount, $name, $quantity); $stmt->execute(); $foreignProduct = (int)$db->insert_id;
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
    $code = 'ORI-' . bin2hex(random_bytes(2)); $name = 'Origen';
    $stmt->bind_param('iiss', $user, $account, $code, $name); $stmt->execute(); $origin = (int)$db->insert_id;
    $code = 'DES-' . bin2hex(random_bytes(2)); $name = 'Destino';
    $stmt->bind_param('iiss', $user, $account, $code, $name); $stmt->execute(); $destination = (int)$db->insert_id;
    $code = 'EXT-' . bin2hex(random_bytes(2)); $name = 'Ajena';
    $stmt->bind_param('iiss', $foreignUser, $foreignAccount, $code, $name); $stmt->execute(); $foreignWarehouse = (int)$db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$product},{$origin},10.000),({$product},{$destination},1.000),({$foreignProduct},{$foreignWarehouse},4.000)");
    inventoryAssert($product > 0 && $origin > 0 && $destination > 0, "crea producto y bodegas de prueba ({$product}, {$origin}, {$destination})");
    $db->begin_transaction();
    actualizarKardex($db, $user, $product, $origin, 'INICIAL', 0, 'PRUEBA', 10.000, 0, 500, 'Saldo inicial origen');
    actualizarKardex($db, $user, $product, $destination, 'INICIAL', 0, 'PRUEBA', 1.000, 0, 500, 'Saldo inicial destino');
    $db->commit();

    $dashboard = inventoryApi($root, $envPath, $user, ['accion'=>'dashboard']);
    inventoryAssert(!isset($dashboard['error']) && array_key_exists('proximos_vencer', $dashboard) && isset($dashboard['chart_stock_bodega']), 'el dashboard de inventario carga sobre una instalacion limpia');
    $lot = inventoryApi($root, $envPath, $user, ['accion'=>'lote_crear','id_producto'=>$product,'numero_lote'=>'LOT-'.bin2hex(random_bytes(4)),'cantidad'=>0.750]);
    $lotId = (int)($lot['id'] ?? 0);
    inventoryAssert($lotId > 0, 'crea un lote decimal para un producto propio');
    inventoryQuantity(inventoryScalar($db, "SELECT cantidad FROM lote WHERE id_lote={$lotId}"), '0.750', 'el lote conserva su cantidad decimal');
    $foreignLot = inventoryApi($root, $envPath, $user, ['accion'=>'lote_crear','id_producto'=>$foreignProduct,'numero_lote'=>'LOT-X-'.bin2hex(random_bytes(3)),'cantidad'=>1]);
    inventoryAssert(isset($foreignLot['error']), 'no permite crear lotes para productos de otra cuenta');
    $lots = inventoryApi($root, $envPath, $user, ['accion'=>'lotes']);
    inventoryAssert((int)($lots['total'] ?? 0) === 1, 'el listado de lotes queda limitado a la cuenta');

    $created = inventoryApi($root, $envPath, $user, [
        'accion'=>'producto_crear','nombre_producto'=>'Azucar sin catalogos','codigo_de_barras'=>'API-'.bin2hex(random_bytes(4)),
        'precio_venta'=>1500,'precio_costo'=>800,'cantidad'=>0.750,'tipo_venta'=>'PESO',
    ]);
    $apiProduct = (int)($created['id'] ?? 0);
    inventoryAssert($apiProduct > 0, 'crea un producto por peso sin categoria, marca, proveedor ni unidad');
    inventoryAssert((int)inventoryScalar($db, "SELECT COUNT(*) FROM producto WHERE id_producto={$apiProduct} AND id_categoria IS NULL AND id_marca IS NULL AND id_proveedor IS NULL AND id_unidad IS NULL") === 1, 'los catalogos opcionales se guardan como NULL');
    inventoryQuantity(inventoryScalar($db, "SELECT cantidad FROM producto WHERE id_producto={$apiProduct}"), '0.750', 'el producto nuevo consolida su stock decimal inicial');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$apiProduct} AND id_bodega={$origin}"), '0.750', 'el alta crea stock inicial en la bodega predeterminada');
    inventoryQuantity(inventoryScalar($db, "SELECT entrada FROM kardex WHERE id_producto={$apiProduct} AND tipo_movimiento='INGRESO'"), '0.750', 'el alta registra el ingreso inicial en kardex');
    $db->query("DELETE FROM kardex WHERE id_producto={$apiProduct}");
    $db->query("DELETE FROM stock WHERE id_producto={$apiProduct}");
    $db->query("DELETE FROM producto WHERE id_producto={$apiProduct}");

    $failedProductName = 'Producto rollback '.bin2hex(random_bytes(3));
    $db->query("CREATE TRIGGER sprint1_product_kardex_fail BEFORE INSERT ON kardex FOR EACH ROW BEGIN IF NEW.tipo_movimiento='INGRESO' AND NEW.id_user={$user} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced product rollback'; END IF; END");
    $productTriggerCreated = true;
    $failedProduct = inventoryApi($root, $envPath, $user, [
        'accion'=>'producto_crear','nombre_producto'=>$failedProductName,'precio_venta'=>1000,'precio_costo'=>500,'cantidad'=>1.250,'tipo_venta'=>'PESO',
    ]);
    inventoryAssert(isset($failedProduct['error']), 'un fallo de kardex rechaza el alta completa del producto');
    inventoryAssert((int)inventoryScalar($db, "SELECT COUNT(*) FROM producto WHERE nombre_producto='".$db->real_escape_string($failedProductName)."' AND id_cuenta={$account}") === 0, 'el fallo de kardex revierte producto y stock inicial');
    $db->query('DROP TRIGGER sprint1_product_kardex_fail');
    $productTriggerCreated = false;

    $clearCatalogs = inventoryApi($root, $envPath, $user, [
        'accion'=>'producto_editar','id'=>$product,'id_categoria'=>0,'id_marca'=>0,'id_proveedor'=>0,'id_unidad'=>0,
    ]);
    inventoryAssert(!empty($clearCatalogs['success']), 'editar permite limpiar catalogos opcionales sin violar claves foraneas');

    $directStockEdit = inventoryApi($root, $envPath, $user, [
        'accion'=>'producto_editar','id'=>$product,'cantidad'=>99.000,
    ]);
    inventoryAssert(isset($directStockEdit['error']), 'el catalogo no permite editar stock directamente');
    inventoryQuantity(inventoryScalar($db, "SELECT cantidad FROM producto WHERE id_producto={$product}"), '11.000', 'rechazar la edicion directa conserva el stock consolidado');

    $originStockId = (int)inventoryScalar($db, "SELECT id_stock FROM stock WHERE id_producto={$product} AND id_bodega={$origin}");
    $rawStockEdit = inventoryApi($root, $envPath, $user, [
        'accion'=>'stock_actualizar','id_stock'=>$originStockId,'campo'=>'disponible','valor'=>99.000,
    ]);
    inventoryAssert(isset($rawStockEdit['error']), 'la API rechaza mutaciones de stock sin movimiento ni kardex');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_stock={$originStockId}"), '10.000', 'rechazar la mutacion directa conserva la existencia');

    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,precio_costo,cantidad,tipo_venta,activo) VALUES (?,?,?,1000,500,5,'UNIDAD',1)");
    $unitName = 'Producto unitario';
    $stmt->bind_param('iis', $user, $account, $unitName); $stmt->execute(); $unitProduct = (int)$db->insert_id; $stmt->close();
    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$unitProduct},{$origin},5)");
    $serial = inventoryApi($root, $envPath, $user, ['accion'=>'serie_crear','id_producto'=>$unitProduct,'numero_serie'=>'SER-'.bin2hex(random_bytes(4))]);
    inventoryAssert((int)($serial['id'] ?? 0) > 0, 'crea una serie para un producto propio');
    $foreignSerial = inventoryApi($root, $envPath, $user, ['accion'=>'serie_crear','id_producto'=>$foreignProduct,'numero_serie'=>'SER-X-'.bin2hex(random_bytes(3))]);
    inventoryAssert(isset($foreignSerial['error']), 'no permite crear series para productos de otra cuenta');
    $series = inventoryApi($root, $envPath, $user, ['accion'=>'series']);
    inventoryAssert((int)($series['total'] ?? 0) === 1, 'el listado de series queda limitado a la cuenta');
    $fractionalMovement = inventoryApi($root, $envPath, $user, ['accion'=>'movimiento_crear','tipo'=>'SALIDA','id_producto'=>$unitProduct,'id_bodega'=>$origin,'cantidad'=>0.500]);
    inventoryAssert(isset($fractionalMovement['error']), 'un movimiento rechaza fracciones para productos por unidad');
    $fractionalTransfer = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_crear','id_bodega_origen'=>$origin,'id_bodega_destino'=>$destination,'productos'=>[['id_producto'=>$unitProduct,'cantidad'=>0.500]]]);
    inventoryAssert(isset($fractionalTransfer['error']), 'una transferencia rechaza fracciones para productos por unidad');
    $fractionalAdjustment = inventoryApi($root, $envPath, $user, ['accion'=>'ajuste_crear','tipo'=>'CORRECCION','id_producto'=>$unitProduct,'id_bodega'=>$origin,'cantidad_nueva'=>4.500,'motivo'=>'Prueba unitaria']);
    inventoryAssert(isset($fractionalAdjustment['error']), 'un ajuste rechaza fracciones para productos por unidad');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$unitProduct} AND id_bodega={$origin}"), '5.000', 'los rechazos unitarios no alteran stock');
    $db->query("DELETE FROM kardex WHERE id_producto={$unitProduct}");
    $db->query("DELETE FROM stock WHERE id_producto={$unitProduct}");
    $db->query("DELETE FROM producto WHERE id_producto={$unitProduct}");

    $foreign = inventoryApi($root, $envPath, $user, [
        'accion'=>'transferencia_crear','id_bodega_origen'=>$origin,'id_bodega_destino'=>$foreignWarehouse,
        'productos'=>[['id_producto'=>$product,'cantidad'=>0.500]],
    ]);
    inventoryAssert(isset($foreign['error']), 'una transferencia no puede cruzar cuentas');

    $invalidPrecision = inventoryApi($root, $envPath, $user, [
        'accion'=>'transferencia_crear','id_bodega_origen'=>$origin,'id_bodega_destino'=>$destination,
        'productos'=>[['id_producto'=>$product,'cantidad'=>0.1234]],
    ]);
    inventoryAssert(isset($invalidPrecision['error']), 'inventario rechaza cantidades con más de tres decimales');

    $pending = inventoryApi($root, $envPath, $user, [
        'accion'=>'transferencia_crear','id_bodega_origen'=>$origin,'id_bodega_destino'=>$destination,
        'productos'=>[['id_producto'=>$product,'cantidad'=>1.000],['id_producto'=>$product,'cantidad'=>1.375]],
    ]);
    $pendingId = (int)($pending['id'] ?? 0);
    inventoryAssert($pendingId > 0, 'crea una transferencia decimal agrupando líneas repetidas');
    inventoryQuantity(inventoryScalar($db, "SELECT cantidad FROM transferencia_detalle WHERE id_transferencia={$pendingId}"), '2.375', 'el detalle conserva la cantidad agrupada');
    inventoryQuantity(inventoryScalar($db, "SELECT comprometido FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '2.375', 'la transferencia pendiente reserva stock exacto');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '10.000', 'reservar no descuenta todavía el disponible');

    $overbook = inventoryApi($root, $envPath, $user, [
        'accion'=>'transferencia_crear','id_bodega_origen'=>$origin,'id_bodega_destino'=>$destination,
        'productos'=>[['id_producto'=>$product,'cantidad'=>8.000]],
    ]);
    inventoryAssert(isset($overbook['error']), 'no permite sobre-reservar el stock libre');
    inventoryQuantity(inventoryScalar($db, "SELECT comprometido FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '2.375', 'el rechazo revierte toda reserva parcial');

    $cancelPending = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_cancelar','id'=>$pendingId]);
    inventoryAssert(!empty($cancelPending['success']), 'cancela una transferencia pendiente');
    inventoryQuantity(inventoryScalar($db, "SELECT comprometido FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '0.000', 'cancelar pendiente libera lo comprometido');
    $cancelAgain = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_cancelar','id'=>$pendingId]);
    inventoryAssert(isset($cancelAgain['error']), 'una transición de transferencia solo puede aplicarse una vez');

    $transfer = inventoryApi($root, $envPath, $user, [
        'accion'=>'transferencia_crear','id_bodega_origen'=>$origin,'id_bodega_destino'=>$destination,
        'productos'=>[['id_producto'=>$product,'cantidad'=>2.375]],
    ]);
    $transferId = (int)($transfer['id'] ?? 0);
    inventoryAssert($transferId > 0, 'crea la transferencia que se enviará');

    $db->query("CREATE TRIGGER sprint1_inventory_kardex_fail BEFORE INSERT ON kardex FOR EACH ROW BEGIN IF NEW.tipo_movimiento='TRANSFERENCIA' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced inventory rollback'; END IF; END");
    $triggerCreated = true;
    $failedSend = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_enviar','id'=>$transferId]);
    inventoryAssert(isset($failedSend['error']), 'un fallo de kardex rechaza el envío completo');
    inventoryAssert(inventoryScalar($db, "SELECT estado FROM transferencia WHERE id_transferencia={$transferId}") === 'PENDIENTE', 'el fallo mantiene la transferencia pendiente');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '10.000', 'el fallo revierte el descuento de origen');
    inventoryQuantity(inventoryScalar($db, "SELECT comprometido FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '2.375', 'el fallo conserva la reserva pendiente');
    $db->query('DROP TRIGGER sprint1_inventory_kardex_fail');
    $triggerCreated = false;

    $sent = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_enviar','id'=>$transferId]);
    inventoryAssert(!empty($sent['success']), 'envía la transferencia dentro de una transacción');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '7.625', 'el envío descuenta el origen exacto');
    inventoryQuantity(inventoryScalar($db, "SELECT comprometido FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '0.000', 'el envío libera el comprometido una sola vez');
    inventoryQuantity(inventoryScalar($db, "SELECT en_transito FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '2.375', 'el envío registra stock en tránsito');
    $sentAgain = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_enviar','id'=>$transferId]);
    inventoryAssert(isset($sentAgain['error']), 'no permite enviar dos veces la misma transferencia');

    $received = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_recibir','id'=>$transferId]);
    inventoryAssert(!empty($received['success']), 'recibe la transferencia decimal');
    inventoryQuantity(inventoryScalar($db, "SELECT en_transito FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '0.000', 'recibir limpia únicamente el tránsito');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$destination}"), '3.375', 'recibir suma exactamente al destino');
    inventoryQuantity(inventoryScalar($db, "SELECT cantidad FROM producto WHERE id_producto={$product}"), '11.000', 'la transferencia conserva el stock total del producto');
    $receivedAgain = inventoryApi($root, $envPath, $user, ['accion'=>'transferencia_recibir','id'=>$transferId]);
    inventoryAssert(isset($receivedAgain['error']), 'no permite recibir dos veces la misma transferencia');

    $adjustment = inventoryApi($root, $envPath, $user, [
        'accion'=>'ajuste_crear','tipo'=>'CONTEO','id_producto'=>$product,'id_bodega'=>$origin,
        'cantidad_nueva'=>6.125,'motivo'=>'Validación decimal de ajuste',
    ]);
    inventoryAssert(!empty($adjustment['success']), 'crea un ajuste decimal transaccional');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '6.125', 'el ajuste fija el stock exacto');
    inventoryQuantity(inventoryScalar($db, "SELECT diferencia FROM ajuste_inventario WHERE id_ajuste=".(int)$adjustment['id']), '-1.500', 'el ajuste registra diferencia decimal');

    $physical = inventoryApi($root, $envPath, $user, ['accion'=>'inventario_fisico_crear','tipo'=>'BODEGA','id_bodega'=>$origin,'observaciones'=>'Conteo E2E']);
    $physicalId = (int)($physical['id'] ?? 0);
    inventoryAssert($physicalId > 0 && (int)($physical['lineas'] ?? 0) === 1, 'inicia inventario físico con snapshot de bodega');
    $countId = (int)inventoryScalar($db, "SELECT id_conteo FROM conteo_inventario WHERE id_inventario={$physicalId}");
    inventoryQuantity(inventoryScalar($db, "SELECT stock_sistema FROM conteo_inventario WHERE id_conteo={$countId}"), '6.125', 'el conteo conserva el snapshot del sistema');
    $counted = inventoryApi($root, $envPath, $user, ['accion'=>'inventario_fisico_conteo','id_conteo'=>$countId,'ronda'=>'conteo1','valor'=>5.500]);
    inventoryAssert(!empty($counted['success']), 'registra un conteo físico decimal');
    $_SESSION['user_id'] = $user;
    $db->begin_transaction();
    actualizarStock($db, $product, $origin, 'disponible', -0.500);
    actualizarKardex($db, $user, $product, $origin, 'SALIDA', 0, 'PRUEBA', 0, 0.500, 500, 'Movimiento posterior al conteo');
    sincronizarCantidadProducto($db, $product);
    $db->commit();
    unset($_SESSION['user_id']);
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '5.625', 'un movimiento posterior modifica el stock actual sin alterar el snapshot');
    $db->query("CREATE TRIGGER sprint1_inventory_kardex_fail BEFORE INSERT ON kardex FOR EACH ROW BEGIN IF NEW.tipo_movimiento='AJUSTE_FISICO' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced physical rollback'; END IF; END");
    $triggerCreated = true;
    $failedClose = inventoryApi($root, $envPath, $user, ['accion'=>'inventario_fisico_cerrar','id'=>$physicalId]);
    inventoryAssert(isset($failedClose['error']), 'un fallo de kardex revierte el cierre fisico completo');
    inventoryAssert(inventoryScalar($db, "SELECT estado FROM inventario_fisico WHERE id_inventario={$physicalId}") === 'EN_PROGRESO', 'el rollback conserva abierto el inventario fisico');
    inventoryAssert((int)inventoryScalar($db, "SELECT conciliado FROM conteo_inventario WHERE id_conteo={$countId}") === 0, 'el rollback conserva pendiente la linea de conteo');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '5.625', 'el rollback del cierre conserva el stock actual');
    $db->query('DROP TRIGGER sprint1_inventory_kardex_fail');
    $triggerCreated = false;
    $closed = inventoryApi($root, $envPath, $user, ['accion'=>'inventario_fisico_cerrar','id'=>$physicalId]);
    inventoryAssert(!empty($closed['success']), 'cierra y concilia el inventario físico');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '5.000', 'el cierre aplica la diferencia del conteo sin borrar movimientos posteriores');
    inventoryQuantity(inventoryScalar($db, "SELECT diferencia FROM conteo_inventario WHERE id_conteo={$countId}"), '-0.625', 'el cierre registra la diferencia decimal');
    inventoryAssert(inventoryScalar($db, "SELECT estado FROM inventario_fisico WHERE id_inventario={$physicalId}") === 'CERRADO', 'el inventario queda cerrado');
    $closedAgain = inventoryApi($root, $envPath, $user, ['accion'=>'inventario_fisico_cerrar','id'=>$physicalId]);
    inventoryAssert(isset($closedAgain['error']), 'el cierre físico es de una sola aplicación');
    inventoryQuantity(inventoryScalar($db, "SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$origin}"), '5.000', 'reintentar cierre no vuelve a mover stock');

    $general = inventoryApi($root, $envPath, $user, ['accion'=>'inventario_fisico_crear','tipo'=>'GENERAL','observaciones'=>'General E2E']);
    $generalId = (int)($general['id'] ?? 0);
    inventoryAssert($generalId > 0, 'inicia un inventario general sin bodega en cabecera');
    inventoryAssert(inventoryScalar($db, "SELECT id_bodega FROM inventario_fisico WHERE id_inventario={$generalId}") === null, 'la cabecera general guarda bodega NULL');
    inventoryAssert((int)inventoryScalar($db, "SELECT COUNT(*) FROM conteo_inventario WHERE id_inventario={$generalId} AND id_bodega IS NOT NULL") === 2, 'cada línea general conserva su bodega real');
    $closeWithoutCounts = inventoryApi($root, $envPath, $user, ['accion'=>'inventario_fisico_cerrar','id'=>$generalId]);
    inventoryAssert(isset($closeWithoutCounts['error']), 'no cierra un inventario con líneas sin contar');
    inventoryAssert(inventoryScalar($db, "SELECT estado FROM inventario_fisico WHERE id_inventario={$generalId}") === 'EN_PROGRESO', 'un cierre fallido conserva el inventario abierto');

    echo "OK integridad transaccional de inventario verificada.\n";
} finally {
    if ($productTriggerCreated) {
        try { $db->query('DROP TRIGGER sprint1_product_kardex_fail'); } catch (Throwable) {}
    }
    if ($triggerCreated) {
        try { $db->query('DROP TRIGGER sprint1_inventory_kardex_fail'); } catch (Throwable) {}
    }
    if ($account > 0) {
        foreach (['inventario_auditoria','pos_auditoria','core_auditoria','empleado_auditoria'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_user IN ({$user},{$foreignUser})"); } catch (Throwable) {}
        }
        $db->query("DELETE FROM conteo_inventario WHERE id_inventario IN (SELECT id_inventario FROM inventario_fisico WHERE id_user IN ({$user},{$foreignUser}))");
        $db->query("DELETE FROM inventario_fisico WHERE id_user IN ({$user},{$foreignUser})");
        $db->query("DELETE FROM ajuste_inventario WHERE id_producto IN ({$product},{$foreignProduct},{$apiProduct},{$unitProduct})");
        $db->query("DELETE FROM transferencia_detalle WHERE id_transferencia IN (SELECT id_transferencia FROM transferencia WHERE id_user IN ({$user},{$foreignUser}))");
        $db->query("DELETE FROM transferencia WHERE id_user IN ({$user},{$foreignUser})");
        $db->query("DELETE FROM kardex WHERE id_producto IN ({$product},{$foreignProduct},{$apiProduct},{$unitProduct})");
        $db->query("DELETE FROM stock WHERE id_producto IN ({$product},{$foreignProduct},{$apiProduct},{$unitProduct})");
        $db->query("DELETE FROM producto WHERE id_producto IN ({$product},{$foreignProduct},{$apiProduct},{$unitProduct})");
        $db->query("DELETE FROM bodega WHERE id_bodega IN ({$origin},{$destination},{$foreignWarehouse})");
        $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta IN ({$account},{$foreignAccount})");
        $db->query("DELETE FROM usuario WHERE id_user IN ({$user},{$foreignUser})");
        $db->query("DELETE FROM rol WHERE id_cuenta IN ({$account},{$foreignAccount})");
        $db->query("DELETE FROM cuenta WHERE id_cuenta IN ({$account},{$foreignAccount})");
    }
}
