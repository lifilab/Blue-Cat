<?php
declare(strict_types=1);

function argValue(string $name): ?string
{
    foreach (array_slice($_SERVER['argv'], 1) as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function scalar(mysqli $db, string $sql): mixed
{
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    $row = $result->fetch_row();
    $result->free();
    return $row[0] ?? null;
}

function cents(mixed $value): int
{
    $text = trim((string)$value);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $parts)) {
        throw new RuntimeException('Importe invalido en fixture: ' . $text);
    }
    $result = ((int)$parts[2]) * 100 + (int)str_pad($parts[3] ?? '', 2, '0');
    return ($parts[1] ?? '') === '-' ? -$result : $result;
}

function invokeApi(
    string $root,
    string $env,
    int $user,
    string $method,
    array $query = [],
    ?array $body = null,
    string $endpoint = 'facturas.php'
): array {
    $command = [
        PHP_BINARY,
        $root . '/scripts/invoke-api-test.php',
        '--env=' . $env,
        '--endpoint=' . $endpoint,
        '--user=' . $user,
        '--method=' . $method,
    ];
    if ($query !== []) $command[] = '--query=' . base64_encode((string)json_encode($query));
    if ($body !== null) $command[] = '--body=' . base64_encode((string)json_encode($body));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo invocar la API de facturas.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Respuesta de facturas invalida (codigo {$code}): {$stdout} {$stderr}");
    }
    return $decoded;
}

/** @return array{account:int,user:int,client:int,product:int,product_name:string,warehouse:int,session:int} */
function createTenantFixture(mysqli $db, string $suffix, array &$fixture): array
{
    $accountName = 'Factura tenant ' . $suffix;
    $stmt = $db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
    $stmt->bind_param('s', $accountName);
    $stmt->execute();
    $account = (int)$db->insert_id;
    $fixture['account'] = $account;
    $stmt->close();

    $hash = password_hash('Factura-2026', PASSWORD_DEFAULT);
    $login = 'factura-' . strtolower($suffix) . '-' . bin2hex(random_bytes(3));
    $email = $login . '@test.local';
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $account, $login, $email, $hash);
    $stmt->execute();
    $user = (int)$db->insert_id;
    $fixture['user'] = $user;
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$user} WHERE id_cuenta={$account}");
    provisionTenantRoles($db, $account);
    $adminRole = (int)scalar($db, "SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='Administrador'");
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$adminRole})");

    $clientCode = 'FAC-' . $suffix . '-' . bin2hex(random_bytes(2));
    $clientName = 'Cliente factura ' . $suffix;
    $businessName = 'Cliente factura ' . $suffix . ' SpA';
    $rut = '12.345.678-5';
    $stmt = $db->prepare('INSERT INTO cliente(id_user,id_cuenta,codigo,nombre,razon_social,rut) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('iissss', $user, $account, $clientCode, $clientName, $businessName, $rut);
    $stmt->execute();
    $client = (int)$db->insert_id;
    $fixture['client'] = $client;
    $stmt->close();

    $productName = 'Producto facturable por peso ' . $suffix;
    $sku = 'FAC-SKU-' . $suffix . '-' . bin2hex(random_bytes(2));
    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,cantidad,tipo_venta,sku,activo) VALUES (?,?,?,1190,8.000,'PESO',?,1)");
    $stmt->bind_param('iiss', $user, $account, $productName, $sku);
    $stmt->execute();
    $product = (int)$db->insert_id;
    $fixture['product'] = $product;
    $fixture['product_name'] = $productName;
    $stmt->close();

    $warehouseCode = 'FAC-' . $suffix . '-' . bin2hex(random_bytes(2));
    $warehouseName = 'Bodega factura ' . $suffix;
    $stmt = $db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
    $stmt->bind_param('iiss', $user, $account, $warehouseCode, $warehouseName);
    $stmt->execute();
    $warehouse = (int)$db->insert_id;
    $fixture['warehouse'] = $warehouse;
    $stmt->close();
    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$product},{$warehouse},8.000)");

    $fechaIngreso = date('Y-m-d H:i:s');
    $employee = 'Facturador ' . $suffix;
    $stmt = $db->prepare('INSERT INTO sesion(id_cuenta,id_user,fecha_ingreso,monto_apertura,empleado) VALUES (?,?,?,0,?)');
    $stmt->bind_param('iiss', $account, $user, $fechaIngreso, $employee);
    $stmt->execute();
    $session = (int)$db->insert_id;
    $fixture['session'] = $session;
    $stmt->close();
    $cashCode = 'FAC-CASH-' . $suffix;
    $stmt = $db->prepare("INSERT INTO pos_caja_fisica(id_cuenta,codigo,nombre,sucursal,id_bodega,activo) VALUES (?,?,?,'Principal',?,1)");
    $cashName = 'Caja factura ' . $suffix;
    $stmt->bind_param('issi', $account, $cashCode, $cashName, $warehouse);
    $stmt->execute();
    $physicalCash = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO pos_caja(id_user,id_cuenta,id_caja_fisica,codigo,nombre,estado,monto_apertura,monto_actual,fecha_apertura,id_sesion) VALUES (?,?,?,?,?,'ABIERTA',10000,10000,NOW(),?)");
    $stmt->bind_param('iiissi', $user, $account, $physicalCash, $cashCode, $cashName, $session);
    $stmt->execute();
    $fixture['cash'] = (int)$db->insert_id;
    $fixture['physical_cash'] = $physicalCash;
    $stmt->close();

    // La configuración vigente es 19%; cada snapshot de prueba conserva 7%.
    $stmt = $db->prepare("INSERT INTO config_boleta(id_user,id_cuenta,nombre_empresa,iva_porcentaje,activo) VALUES (?,?,'Empresa factura',19,1)");
    $stmt->bind_param('ii', $user, $account);
    $stmt->execute();
    $stmt->close();
    return [
        'account'=>$account,
        'user'=>$user,
        'client'=>$client,
        'product'=>$product,
        'product_name'=>$productName,
        'warehouse'=>$warehouse,
        'session'=>$session,
    ];
}

function createRoleUser(mysqli $db, array &$tenant, string $roleName, string $label): int
{
    $account = (int)$tenant['account'];
    $role = (int)scalar(
        $db,
        "SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='"
        . $db->real_escape_string($roleName) . "'"
    );
    if ($role <= 0) throw new RuntimeException("No existe el rol {$roleName}");
    $name = $label . '-' . bin2hex(random_bytes(3));
    $mail = strtolower($name) . '@test.local';
    $hash = password_hash('Factura-Role-2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $account, $name, $mail, $hash);
    $stmt->execute();
    $user = (int)$db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$role})");
    $tenant['extra_users'][] = $user;
    return $user;
}

function createReadOnlyInvoiceUser(mysqli $db, array &$tenant): int
{
    $account = (int)$tenant['account'];
    $name = 'Lector Facturas ' . bin2hex(random_bytes(3));
    $stmt = $db->prepare("INSERT INTO rol(id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla) VALUES (?,?,'Solo lectura de facturas',1,0,0)");
    $stmt->bind_param('is', $account, $name);
    $stmt->execute();
    $role = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO rol_permiso(id_rol,id_permiso) SELECT ?,id_permiso FROM permiso WHERE modulo='facturas' AND accion='ver'");
    $stmt->bind_param('i', $role);
    $stmt->execute();
    $stmt->close();

    $userName = 'lector-factura-' . bin2hex(random_bytes(3));
    $mail = $userName . '@test.local';
    $hash = password_hash('Factura-Read-2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $account, $userName, $mail, $hash);
    $stmt->execute();
    $user = (int)$db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$role})");
    $tenant['extra_users'][] = $user;
    return $user;
}

/** @return array{id:int,folio:int,numero:string,pago:int} */
function createOrder(mysqli $db, array $tenant, string $type = 'FACTURA', bool $voided = false, bool $withClient = true): array
{
    $type = strtoupper($type);
    $total = 1785; // 1.500 x $1.190, bruto con IVA incluido.
    $anulado = $voided ? 1 : 0;
    $account = (int)$tenant['account'];
    $session = (int)$tenant['session'];
    $client = $withClient ? (int)$tenant['client'] : null;
    $warehouse = (int)$tenant['warehouse'];
    $product = (int)$tenant['product'];
    $folio = random_int(1000000, 1900000000);
    $numero = ($type === 'FACTURA' ? 'F-' : 'B-') . str_pad((string)$folio, 8, '0', STR_PAD_LEFT);
    $stmt = $db->prepare("INSERT INTO pedido(id_cuenta,id_sesion,id_cliente,id_bodega,tipo_documento,folio,numero_documento,anulado,devuelto,precio_total,pago_total,monto_recibido,vuelto,diferencia,fecha)
        VALUES (?,?,?,?,?,?,?, ?,0,?,?,?,0,0,NOW())");
    $stmt->bind_param('iiiisisiiii', $account, $session, $client, $warehouse, $type, $folio, $numero, $anulado, $total, $total, $total);
    $stmt->execute();
    $order = (int)$db->insert_id;
    $stmt->close();

    $quantity = 1.500;
    $unitPrice = 1190;
    $discount = 0;
    $stmt = $db->prepare('INSERT INTO detalle_pedido(id_pedido,id_producto,cantidad_pedida,precio_unitario_original,descuento,precio_unitario_final,precio_total) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('iiddidi', $order, $product, $quantity, $unitPrice, $discount, $unitPrice, $total);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO metodo_de_pago(id_pedido,nombre_metodo_pago,monto) VALUES (?,'EFECTIVO',?)");
    $stmt->bind_param('ii', $order, $total);
    $stmt->execute();
    $payment = (int)$db->insert_id;
    $stmt->close();

    $snapshot = json_encode([
        'version'=>1,
        'id_pedido'=>$order,
        'folio'=>$folio,
        'numero_documento'=>$numero,
        'tipo_documento'=>$type,
        'total'=>$total,
        'items'=>[[
            'id_producto'=>$product,
            'nombre'=>(string)$tenant['product_name'],
            'cantidad'=>$quantity,
            'precio_original'=>$unitPrice,
            'descuento'=>$discount,
            'subtotal_original'=>$total,
            'subtotal'=>$total,
        ]],
        'pagos'=>[['metodo'=>'EFECTIVO','monto'=>$total,'referencia'=>'']],
        'config'=>['iva_porcentaje'=>7],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO pos_documento_snapshot(id_cuenta,id_pedido,contenido_json) VALUES (?,?,?)');
    $stmt->bind_param('iis', $account, $order, $snapshot);
    $stmt->execute();
    $stmt->close();
    return ['id'=>$order, 'folio'=>$folio, 'numero'=>$numero, 'pago'=>$payment];
}

function cleanupTenant(mysqli $db, array $tenant): void
{
    $account = (int)($tenant['account'] ?? 0);
    if ($account <= 0) return;
    $user = (int)($tenant['user'] ?? 0);
    $users = array_values(array_unique(array_filter(array_merge(
        [$user],
        array_map('intval', (array)($tenant['extra_users'] ?? []))
    ))));
    $product = (int)($tenant['product'] ?? 0);
    $warehouse = (int)($tenant['warehouse'] ?? 0);
    $db->query("DELETE dd FROM pos_devolucion_detalle dd JOIN pos_devolucion d ON d.id_devolucion=dd.id_devolucion JOIN pedido p ON p.id_pedido=d.id_pedido WHERE p.id_cuenta={$account}");
    $db->query("DELETE d FROM pos_devolucion d JOIN pedido p ON p.id_pedido=d.id_pedido WHERE p.id_cuenta={$account}");
    $db->query("DELETE FROM factura_pago WHERE id_factura IN (SELECT id_factura FROM factura WHERE id_cuenta={$account})");
    $db->query("DELETE FROM factura_historial WHERE id_factura IN (SELECT id_factura FROM factura WHERE id_cuenta={$account})");
    $db->query("DELETE d FROM factura_detalle d JOIN factura f ON f.id_factura=d.id_factura WHERE f.id_cuenta={$account} AND f.id_factura_origen IS NOT NULL");
    $db->query("DELETE d FROM factura_detalle d JOIN factura f ON f.id_factura=d.id_factura WHERE f.id_cuenta={$account}");
    $db->query("DELETE FROM factura WHERE id_cuenta={$account} AND id_factura_origen IS NOT NULL");
    $db->query("DELETE FROM factura WHERE id_cuenta={$account}");
    $db->query("DELETE FROM pos_documento_snapshot WHERE id_cuenta={$account}");
    $db->query("DELETE FROM pos_movimiento_caja WHERE id_caja IN (SELECT id_caja FROM pos_caja WHERE id_cuenta={$account})");
    $db->query("DELETE FROM metodo_de_pago WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
    $db->query("DELETE FROM detalle_pedido WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
    $db->query("DELETE FROM pedido WHERE id_cuenta={$account}");
    $db->query("DELETE FROM pos_folio_contador WHERE id_cuenta={$account}");
    $db->query("DELETE FROM config_boleta WHERE id_cuenta={$account}");
    $db->query("DELETE FROM pos_caja WHERE id_cuenta={$account}");
    $db->query("DELETE FROM pos_caja_fisica WHERE id_cuenta={$account}");
    $db->query("DELETE FROM sesion WHERE id_cuenta={$account}");
    $db->query("DELETE FROM kardex WHERE id_producto={$product}");
    $db->query("DELETE FROM stock WHERE id_producto={$product}");
    $db->query("DELETE FROM producto WHERE id_producto={$product}");
    $db->query("DELETE FROM bodega WHERE id_bodega={$warehouse}");
    $db->query("DELETE FROM cliente WHERE id_cuenta={$account}");
    if ($users) {
        $userList = implode(',', $users);
        $db->query("DELETE FROM usuario_rol WHERE id_user IN ({$userList})");
        foreach (['core_auditoria', 'empleado_auditoria', 'inventario_auditoria', 'cliente_auditoria', 'pos_auditoria'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_user IN ({$userList})"); } catch (mysqli_sql_exception) {}
        }
    }
    $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta={$account}");
    if ($users) $db->query("DELETE FROM usuario WHERE id_user IN (".implode(',', $users).")");
    $db->query("DELETE FROM rol WHERE id_cuenta={$account}");
    $db->query("DELETE FROM cuenta WHERE id_cuenta={$account}");
}

$root = dirname(__DIR__);
$env = argValue('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $env) ? $env : $root . '/' . $env;
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');

$db = getDB();
$tenantA = $tenantB = [];
try {
    $invoiceJs = (string)file_get_contents($root . '/assets/js/facturas.js');
    $invoiceHtml = (string)file_get_contents($root . '/public/facturas.html');
    $exportApi = (string)file_get_contents($root . '/assets/api/exportar.php');
    expect(
        str_contains($invoiceJs, 'response.items || []')
        && str_contains($invoiceJs, "hasFacturaPermission('nota_credito')")
        && str_contains($invoiceJs, "hasFacturaPermission('nota_debito')")
        && str_contains($invoiceJs, "mutationIdempotencyKey('pay')")
        && str_contains($invoiceHtml, 'id="btn-create-factura"')
        && str_contains($invoiceHtml, 'id="btn-export-facturas"'),
        'la interfaz consume clientes paginados y aplica permisos reales a facturas, pagos, notas y exportacion'
    );
    expect(
        str_contains($exportApi, "\$d['precio_unitario']")
        && !str_contains($exportApi, "\$d['precio']"),
        'la exportacion usa la columna canonica precio_unitario'
    );
    foreach (['metodo_pago','sucursal','vendedor','orden_compra','pagado','id_factura_origen'] as $column) {
        expect((int)scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='".$db->real_escape_string($column)."'") === 1, "migration 028 expone factura.{$column}");
    }
    expect((int)scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME='id_metodo_de_pago'") === 1, 'migration 028 vincula factura_pago con el pago POS');
    expect(
        (int)scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME IN ('idempotency_key','solicitud_hash')") === 2
        && (int)scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME IN ('idempotency_key','solicitud_hash')") === 2,
        'migration 029 expone idempotencia para pagos y notas fiscales'
    );
    expect(
        (int)scalar($db, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND CONSTRAINT_NAME='fk_factura_cliente_cuenta'") === 1,
        'migration 029 impone la relacion cliente-factura dentro de la misma cuenta'
    );

    createTenantFixture($db, 'A', $tenantA);
    createTenantFixture($db, 'B', $tenantB);
    $viewerA = createReadOnlyInvoiceUser($db, $tenantA);
    $supervisorA = createRoleUser($db, $tenantA, 'Supervisor', 'supervisor-factura');
    expect(
        (int)scalar($db, "SELECT COUNT(*) FROM permiso p JOIN rol_permiso rp ON rp.id_permiso=p.id_permiso JOIN usuario_rol ur ON ur.id_rol=rp.id_rol WHERE ur.id_user={$supervisorA} AND p.modulo='facturas' AND p.accion IN ('ver','nota_credito','nota_debito','exportar')") === 4,
        'Supervisor puede ver y operar las notas que autoriza sin recibir permisos de pago o anulacion'
    );
    $orderA = createOrder($db, $tenantA);
    $voidedOrderA = createOrder($db, $tenantA, 'FACTURA', true);
    $boletaA = createOrder($db, $tenantA, 'BOLETA');
    $anonymousA = createOrder($db, $tenantA, 'FACTURA', false, false);
    $returnedOrderA = createOrder($db, $tenantA);
    $partiallyReturnedOrderA = createOrder($db, $tenantA);
    $db->query("UPDATE pedido SET devuelto=1 WHERE id_pedido={$returnedOrderA['id']}");
    $returnReason = 'Devolución legacy anterior a la materialización';
    $stmt = $db->prepare("INSERT INTO pos_devolucion(id_cuenta,id_user,id_pedido,tipo,motivo,monto_devuelto) VALUES (?,?,?,'PARCIAL',?,100)");
    $stmt->bind_param('iiis', $tenantA['account'], $tenantA['user'], $partiallyReturnedOrderA['id'], $returnReason);
    $stmt->execute();
    $stmt->close();
    $orderB = createOrder($db, $tenantB);

    $stockBefore = (float)scalar($db, "SELECT disponible FROM stock WHERE id_producto={$tenantA['product']} AND id_bodega={$tenantA['warehouse']}");
    $ledgerBefore = (int)scalar($db, "SELECT COUNT(*) FROM kardex WHERE id_producto={$tenantA['product']}");
    $created = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'crear', 'id_pedido'=>$orderA['id'], 'id_cliente'=>$tenantA['client'], 'tipo'=>'FACTURA',
        'metodo_pago'=>'TRANSFERENCIA', 'items'=>[['id_producto'=>999999,'producto'=>'Manipulado','cantidad'=>99,'precio'=>1]],
        'observaciones'=>'Factura POS canónica',
    ]);
    expect(!empty($created['success']) && (int)($created['id_factura'] ?? 0) > 0, 'materializa una FACTURA desde una venta FACTURA válida');
    $invoiceA = (int)$created['id_factura'];
    expect((string)$created['folio'] === (string)$orderA['folio'] && $created['numero'] === $orderA['numero'], 'reutiliza exactamente el folio y número canónicos del POS');

    $stored = $db->query("SELECT id_cuenta,id_cliente,id_pedido,folio,numero,estado,total,pagado,saldo,neto,iva,metodo_pago FROM factura WHERE id_factura={$invoiceA}")->fetch_assoc();
    expect($stored && (int)$stored['id_cuenta'] === $tenantA['account'] && (int)$stored['id_pedido'] === $orderA['id'], 'vincula factura, pedido, cliente y cuenta correctos');
    expect($stored['estado'] === 'PAGADA' && (int)$stored['pagado'] === 1785 && (int)$stored['saldo'] === 0, 'la factura POS pagada nace PAGADA y saldada');
    expect($stored['metodo_pago'] === 'EFECTIVO', 'ignora el método HTTP y deriva el método de pago POS');
    expect(abs((float)$stored['iva'] - 116.78) < 0.001 && abs((float)$stored['neto'] - 1668.22) < 0.001, 'calcula IVA con el 7% inmutable del snapshot, no con el 19% vigente');

    $detail = $db->query("SELECT id_factura_detalle,id_producto,producto,cantidad,precio_unitario,neto,iva,total FROM factura_detalle WHERE id_factura={$invoiceA}")->fetch_assoc();
    expect($detail && number_format((float)$detail['cantidad'], 3, '.', '') === '1.500', 'conserva la cantidad decimal del pedido');
    expect((int)$detail['id_producto'] === $tenantA['product'] && (int)$detail['precio_unitario'] === 1190 && (int)$detail['total'] === 1785, 'usa producto, nombre, precio y total canónicos e ignora items manipulados');
    $detailId = (int)$detail['id_factura_detalle'];
    $orderDetailId = (int)scalar($db, "SELECT id_detalle_pedido FROM detalle_pedido WHERE id_pedido={$orderA['id']}");

    $payment = $db->query("SELECT id_metodo_de_pago,metodo,monto,referencia FROM factura_pago WHERE id_factura={$invoiceA}")->fetch_assoc();
    expect($payment && (int)$payment['id_metodo_de_pago'] === $orderA['pago'] && (int)$payment['monto'] === 1785 && $payment['metodo'] === 'EFECTIVO', 'copia el pago POS 1:1 con FK e importe canónico');
    $loaded = invokeApi($root, $envPath, $tenantA['user'], 'GET', ['id'=>$invoiceA]);
    expect(count($loaded['pagos'] ?? []) === 1 && (int)$loaded['pagos'][0]['id_metodo_de_pago'] === $orderA['pago'], 'GET expone el ledger de pagos POS vinculado');
    $viewerRead = invokeApi($root, $envPath, $viewerA, 'GET', ['id'=>$invoiceA]);
    expect((int)($viewerRead['id_factura'] ?? 0) === $invoiceA, 'un usuario con facturas.ver puede consultar documentos de su cuenta');
    $supervisorRead = invokeApi($root, $envPath, $supervisorA, 'GET', ['id'=>$invoiceA]);
    expect((int)($supervisorRead['id_factura'] ?? 0) === $invoiceA, 'Supervisor ve el documento fiscal que debe autorizar');
    $supervisorExport = invokeApi(
        $root,
        $envPath,
        $supervisorA,
        'GET',
        ['id'=>$invoiceA,'formato'=>'json'],
        null,
        'exportar.php'
    );
    expect(
        (int)($supervisorExport['id_factura'] ?? 0) === $invoiceA
        && isset($supervisorExport['detalle'][0]['precio_unitario']),
        'Supervisor exporta su factura con el precio unitario canonico'
    );
    $viewerExportDenied = invokeApi(
        $root,
        $envPath,
        $viewerA,
        'GET',
        ['id'=>$invoiceA,'formato'=>'json'],
        null,
        'exportar.php'
    );
    expect(!empty($viewerExportDenied['error']), 'facturas.ver no concede implicitamente el permiso de exportar');
    $viewerCreateDenied = invokeApi($root, $envPath, $viewerA, 'POST', [], [
        'accion'=>'crear',
        'id_pedido'=>$orderA['id'],
        'tipo'=>'FACTURA',
    ]);
    expect(!empty($viewerCreateDenied['error']), 'facturas.ver no permite crear o materializar facturas');

    $replay = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$orderA['id'],'tipo'=>'FACTURA']);
    expect(!empty($replay['success']) && !empty($replay['idempotent_replay']) && (int)$replay['id_factura'] === $invoiceA, 'un reintento reutiliza el mismo documento fiscal');
    expect((int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_cuenta={$tenantA['account']} AND id_pedido={$orderA['id']} AND tipo='FACTURA'") === 1 && (int)scalar($db, "SELECT COUNT(*) FROM factura_pago WHERE id_factura={$invoiceA}") === 1, 'el reintento no duplica factura ni pagos');

    $boletaRejected = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$boletaA['id'],'tipo'=>'FACTURA']);
    expect(isset($boletaRejected['error']) && (int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_pedido={$boletaA['id']}") === 0, 'rechaza convertir una BOLETA POS en FACTURA');
    $anonymousRejected = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$anonymousA['id'],'tipo'=>'FACTURA']);
    expect(isset($anonymousRejected['error']) && (int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_pedido={$anonymousA['id']}") === 0, 'rechaza FACTURA anónima sin convertir NULL en una FK cero');
    $voidedRejected = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$voidedOrderA['id'],'tipo'=>'FACTURA']);
    expect(isset($voidedRejected['error']), 'rechaza facturar una venta POS anulada');
    $returnedRejected = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$returnedOrderA['id'],'tipo'=>'FACTURA']);
    expect(isset($returnedRejected['error']), 'rechaza materializar una FACTURA de una venta totalmente devuelta');
    $partialReturnRejected = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$partiallyReturnedOrderA['id'],'tipo'=>'FACTURA']);
    expect(
        isset($partialReturnRejected['error'])
        && (int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_pedido={$partiallyReturnedOrderA['id']}") === 0,
        'rechaza materializar una FACTURA después de cualquier devolución parcial'
    );

    $originalBeforeNotes = $db->query("SELECT total,pagado,saldo,estado FROM factura WHERE id_factura={$invoiceA}")->fetch_assoc();
    $missingCreditKey = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'nota_credito','id_factura'=>$invoiceA,'motivo'=>'Sin clave',
        'items'=>[['id_factura_detalle'=>$detailId,'cantidad'=>'0.500']],
    ]);
    expect(!empty($missingCreditKey['error']), 'una NC exige clave de idempotencia antes de emitir un folio');
    $returnWithoutCredit = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'action'=>'devolucion_crear',
        'id_pedido'=>$orderA['id'],
        'motivo'=>'Intento sin nota fiscal',
        'items'=>[[
            'id_detalle_pedido'=>$orderDetailId,
            'id_producto'=>$tenantA['product'],
            'cantidad'=>0.500,
        ]],
        'idempotency_key'=>'invoice-return-no-credit-'.bin2hex(random_bytes(8)),
    ], 'pos.php');
    expect(!empty($returnWithoutCredit['error']), 'una FACTURA no repone stock ni dinero antes de emitir su nota de crédito');

    $creditKey1 = 'nc-first-' . bin2hex(random_bytes(8));
    $creditRequest1 = [
        'accion'=>'nota_credito','id_factura'=>$invoiceA,'motivo'=>'Devolución parcial',
        'idempotency_key'=>$creditKey1,
        'items'=>[['id_factura_detalle'=>$detailId,'cantidad'=>'0.500','id_producto'=>$tenantB['product'],'producto'=>'Falso','precio'=>1]],
    ];
    $credit1 = invokeApi($root, $envPath, $supervisorA, 'POST', [], $creditRequest1);
    expect(!empty($credit1['success']) && (int)$credit1['total'] === 595, 'NC parcial calcula el beneficio desde la línea original, no desde el cliente HTTP');
    $creditId1 = (int)$credit1['id_factura'];
    $creditLine1 = $db->query("SELECT id_detalle_origen,id_producto,producto,cantidad,precio_unitario,neto,iva,total FROM factura_detalle WHERE id_factura={$creditId1}")->fetch_assoc();
    expect($creditLine1 && (int)$creditLine1['id_detalle_origen'] === $detailId && (int)$creditLine1['id_producto'] === $tenantA['product'] && (int)$creditLine1['precio_unitario'] === 1190, 'NC queda ligada a la línea origen y conserva sus datos canónicos');
    $creditReplay = invokeApi($root, $envPath, $supervisorA, 'POST', [], $creditRequest1);
    expect(
        !empty($creditReplay['success'])
        && !empty($creditReplay['idempotent_replay'])
        && (int)$creditReplay['id_factura'] === $creditId1
        && (int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_cuenta={$tenantA['account']} AND tipo='NOTA_CREDITO' AND idempotency_key='".$db->real_escape_string($creditKey1)."'") === 1,
        'reintentar la misma NC devuelve el mismo documento sin consumir más cantidad'
    );
    $returnKey1 = 'invoice-return-first-' . bin2hex(random_bytes(8));
    $returnRequest1 = [
        'action'=>'devolucion_crear',
        'id_pedido'=>$orderA['id'],
        'motivo'=>'Recepción física de NC parcial',
        'id_nota_credito'=>$creditId1,
        'items'=>[[
            'id_detalle_pedido'=>$orderDetailId,
            'id_producto'=>$tenantA['product'],
            'cantidad'=>0.500,
        ]],
        'idempotency_key'=>$returnKey1,
    ];
    $physicalReturn1 = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $returnRequest1, 'pos.php');
    expect(
        !empty($physicalReturn1['success'])
        && (int)$physicalReturn1['id_nota_credito'] === $creditId1
        && (int)$physicalReturn1['monto_devuelto'] === 595,
        'la devolución física parcial queda ligada 1:1 a su NC y usa el monto canónico'
    );
    $physicalReplay1 = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $returnRequest1, 'pos.php');
    expect(
        !empty($physicalReplay1['idempotent_replay'])
        && (int)$physicalReplay1['id_devolucion'] === (int)$physicalReturn1['id_devolucion'],
        'reintentar la recepción física devuelve la misma operación sin duplicar stock ni caja'
    );
    expect(
        abs((float)scalar($db, "SELECT disponible FROM stock WHERE id_producto={$tenantA['product']} AND id_bodega={$tenantA['warehouse']}") - ($stockBefore + 0.500)) < 0.000001,
        'la primera NC repone exactamente la cantidad acreditada'
    );
    $creditConflictRequest = $creditRequest1;
    $creditConflictRequest['motivo'] = 'Otra devolución con la misma clave';
    $creditConflict = invokeApi($root, $envPath, $supervisorA, 'POST', [], $creditConflictRequest);
    expect(!empty($creditConflict['error']), 'una clave de NC no puede reutilizarse con otra solicitud');
    $annulBlocked = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'anular','id_factura'=>$invoiceA,'motivo'=>'No debe anular con NC activa',
    ]);
    expect(!empty($annulBlocked['error']), 'una factura con NC activa no puede anularse y perder su relación fiscal');
    $viewerNoteDenied = invokeApi($root, $envPath, $viewerA, 'POST', [], [
        'accion'=>'nota_credito','id_factura'=>$invoiceA,'motivo'=>'No autorizado',
        'idempotency_key'=>'nc-viewer-denied-' . bin2hex(random_bytes(6)),
        'items'=>[['id_factura_detalle'=>$detailId,'cantidad'=>'0.100']],
    ]);
    expect(!empty($viewerNoteDenied['error']), 'facturas.ver no concede permiso para emitir notas de crédito');

    $creditKey2 = 'nc-close-' . bin2hex(random_bytes(8));
    $creditRequest2 = [
        'accion'=>'nota_credito','id_factura'=>$invoiceA,'motivo'=>'Completar devolución',
        'idempotency_key'=>$creditKey2,
        'items'=>[
            ['id_factura_detalle'=>$detailId,'cantidad'=>'0.250','precio'=>999999],
            ['id_factura_detalle'=>$detailId,'cantidad'=>'0.750','precio'=>1],
        ],
    ];
    $credit2 = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $creditRequest2);
    expect(!empty($credit2['success']) && (int)$credit2['total'] === 1190, 'agrega líneas repetidas y permite completar exactamente la cantidad restante');
    $creditId2 = (int)$credit2['id_factura'];
    expect((float)scalar($db, "SELECT SUM(d.cantidad) FROM factura n JOIN factura_detalle d ON d.id_factura=n.id_factura WHERE n.id_factura_origen={$invoiceA} AND n.tipo='NOTA_CREDITO' AND n.estado<>'ANULADA'") === 1.5, 'el acumulado acreditado coincide con la cantidad original');
    expect((int)scalar($db, "SELECT SUM(d.total) FROM factura n JOIN factura_detalle d ON d.id_factura=n.id_factura WHERE n.id_factura_origen={$invoiceA} AND n.tipo='NOTA_CREDITO' AND n.estado<>'ANULADA'") === 1785, 'el cierre de redondeo acredita exactamente el importe original');
    $creditReplay2 = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $creditRequest2);
    expect(!empty($creditReplay2['idempotent_replay']) && (int)$creditReplay2['id_factura'] === $creditId2, 'la NC que cerró una cantidad fraccional también es reintentable');
    $physicalReturn2 = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'action'=>'devolucion_crear',
        'id_pedido'=>$orderA['id'],
        'motivo'=>'Recepción física final de NC',
        'id_nota_credito'=>$creditId2,
        'items'=>[[
            'id_detalle_pedido'=>$orderDetailId,
            'id_producto'=>$tenantA['product'],
            'cantidad'=>1.000,
        ]],
        'idempotency_key'=>'invoice-return-final-'.bin2hex(random_bytes(8)),
    ], 'pos.php');
    expect(
        !empty($physicalReturn2['success'])
        && !empty($physicalReturn2['pedido_devuelto'])
        && (int)$physicalReturn2['id_nota_credito'] === $creditId2,
        'la segunda NC completa la devolución física y marca la venta como devuelta'
    );
    expect(
        abs((float)scalar($db, "SELECT disponible FROM stock WHERE id_producto={$tenantA['product']} AND id_bodega={$tenantA['warehouse']}") - ($stockBefore + 1.500)) < 0.000001,
        'las devoluciones de FACTURA restauran exactamente toda la cantidad acreditada'
    );

    $overCredit = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'nota_credito','id_factura'=>$invoiceA,
        'idempotency_key'=>'nc-over-' . bin2hex(random_bytes(8)),
        'items'=>[['id_factura_detalle'=>$detailId,'cantidad'=>'0.001']],
    ]);
    expect(isset($overCredit['error']) && (int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_factura_origen={$invoiceA} AND tipo='NOTA_CREDITO'") === 2, 'rechaza sobredevolución acumulada sin crear un documento parcial');

    $negativeDebit = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'nota_debito','id_factura'=>$invoiceA,'monto'=>'-1.00',
        'idempotency_key'=>'nd-negative-' . bin2hex(random_bytes(8)),
    ]);
    expect(isset($negativeDebit['error']) && (int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_factura_origen={$invoiceA} AND tipo='NOTA_DEBITO'") === 0, 'rechaza nota de débito negativa');
    $debitKey = 'nd-charge-' . bin2hex(random_bytes(8));
    $debitRequest = [
        'accion'=>'nota_debito','id_factura'=>$invoiceA,'monto'=>'214.37',
        'motivo'=>'Cargo documentado','idempotency_key'=>$debitKey,
    ];
    $debit = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $debitRequest);
    expect(!empty($debit['success']) && cents($debit['total']) === 21437, 'crea una ND decimal positiva como documento separado');
    $debitId = (int)$debit['id_factura'];
    $debitStored = $db->query("SELECT id_factura_origen,total,neto,iva,pagado,saldo,estado FROM factura WHERE id_factura={$debitId}")->fetch_assoc();
    expect(
        $debitStored
        && (int)$debitStored['id_factura_origen'] === $invoiceA
        && cents($debitStored['total']) === 21437
        && cents($debitStored['neto']) + cents($debitStored['iva']) === 21437
        && cents($debitStored['saldo']) === 21437,
        'ND conserva vínculo, centavos, desglose tributario y saldo propios consistentes'
    );
    $debitReplay = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $debitRequest);
    expect(!empty($debitReplay['idempotent_replay']) && (int)$debitReplay['id_factura'] === $debitId, 'reintentar la misma ND no crea otro folio');
    $debitConflictRequest = $debitRequest;
    $debitConflictRequest['monto'] = '214.36';
    $debitConflict = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $debitConflictRequest);
    expect(!empty($debitConflict['error']), 'una clave de ND no puede reutilizarse con otro monto');

    $missingPaymentKey = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'pagar','id_factura'=>$debitId,'metodo'=>'EFECTIVO','monto'=>'1.00',
    ]);
    expect(!empty($missingPaymentKey['error']), 'un pago manual exige clave de idempotencia');
    $viewerPaymentDenied = invokeApi($root, $envPath, $viewerA, 'POST', [], [
        'accion'=>'pagar','id_factura'=>$debitId,'metodo'=>'EFECTIVO','monto'=>'1.00',
        'idempotency_key'=>'pay-viewer-denied-' . bin2hex(random_bytes(6)),
    ]);
    expect(!empty($viewerPaymentDenied['error']), 'facturas.ver no concede permiso para registrar pagos');
    $overPayment = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'pagar','id_factura'=>$debitId,'metodo'=>'EFECTIVO','monto'=>'214.38',
        'idempotency_key'=>'pay-over-' . bin2hex(random_bytes(8)),
    ]);
    expect(
        isset($overPayment['error'])
        && (int)scalar($db, "SELECT COUNT(*) FROM factura_pago WHERE id_factura={$debitId}") === 0
        && cents(scalar($db, "SELECT saldo FROM factura WHERE id_factura={$debitId}")) === 21437,
        'un pago excedente revierte por completo y conserva saldo y ledger'
    );
    $paymentKey = 'pay-valid-' . bin2hex(random_bytes(8));
    $paymentRequest = [
        'accion'=>'pagar','id_factura'=>$debitId,'metodo'=>'TARJETA','monto'=>'100.25',
        'referencia'=>'TEST-ND','idempotency_key'=>$paymentKey,
    ];
    $validPayment = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $paymentRequest);
    expect(
        !empty($validPayment['success'])
        && empty($validPayment['idempotent_replay'])
        && cents($validPayment['pagado']) === 10025
        && cents($validPayment['saldo']) === 11412
        && $validPayment['estado'] === 'PARCIAL',
        'un pago válido conserva centavos exactos y actualiza el saldo'
    );
    $validPaymentId = (int)$validPayment['id_factura_pago'];
    $paymentReplay = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $paymentRequest);
    expect(
        !empty($paymentReplay['idempotent_replay'])
        && (int)$paymentReplay['id_factura_pago'] === $validPaymentId
        && cents($paymentReplay['saldo']) === 11412
        && (int)scalar($db, "SELECT COUNT(*) FROM factura_pago WHERE id_factura={$debitId}") === 1,
        'reintentar un pago devuelve el mismo asiento sin duplicar saldo ni ledger'
    );
    $paymentConflictRequest = $paymentRequest;
    $paymentConflictRequest['referencia'] = 'OTRA-REFERENCIA';
    $paymentConflict = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $paymentConflictRequest);
    expect(
        !empty($paymentConflict['error'])
        && cents(scalar($db, "SELECT saldo FROM factura WHERE id_factura={$debitId}")) === 11412,
        'una clave de pago reutilizada con otro contenido se rechaza sin mutar saldo'
    );
    $annulledDebit = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'anular','id_factura'=>$debitId,'motivo'=>'Prueba de pago anulado']);
    expect(!empty($annulledDebit['success']), 'puede anular la ND sin tocar el documento origen');
    $paymentReplayAfterAnnul = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], $paymentRequest);
    expect(
        !empty($paymentReplayAfterAnnul['idempotent_replay'])
        && (int)$paymentReplayAfterAnnul['id_factura_pago'] === $validPaymentId,
        'un reintento confirmado sigue siendo reconocible aunque el documento se anule después'
    );
    $paymentOnAnnulled = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'pagar','id_factura'=>$debitId,'metodo'=>'EFECTIVO','monto'=>'1.00',
        'idempotency_key'=>'pay-annulled-' . bin2hex(random_bytes(8)),
    ]);
    expect(isset($paymentOnAnnulled['error']) && (int)scalar($db, "SELECT COUNT(*) FROM factura_pago WHERE id_factura={$debitId}") === 1, 'rechaza pagos sobre documentos anulados sin insertar filas huérfanas');

    $originalAfterNotes = $db->query("SELECT total,pagado,saldo,estado FROM factura WHERE id_factura={$invoiceA}")->fetch_assoc();
    expect($originalAfterNotes === $originalBeforeNotes, 'NC y ND no mutan total, pago, saldo ni estado del documento origen');
    $stockAfterNotes = (float)scalar($db, "SELECT disponible FROM stock WHERE id_producto={$tenantA['product']} AND id_bodega={$tenantA['warehouse']}");
    $ledgerAfterNotes = (int)scalar($db, "SELECT COUNT(*) FROM kardex WHERE id_producto={$tenantA['product']}");
    expect(
        abs($stockAfterNotes - ($stockBefore + 1.500)) < 0.0001
        && $ledgerAfterNotes === $ledgerBefore + 2,
        'los documentos fiscales no mueven stock; solo sus dos recepciones físicas auditadas reponen 1.500'
    );

    $paymentRejected = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'pagar','id_factura'=>$invoiceA,'metodo'=>'EFECTIVO','monto'=>'1.00',
        'idempotency_key'=>'pay-settled-' . bin2hex(random_bytes(8)),
    ]);
    expect(isset($paymentRejected['error']) && (int)scalar($db, "SELECT COUNT(*) FROM factura_pago WHERE id_factura={$invoiceA}") === 1, 'rechaza cobrar nuevamente una factura POS ya saldada');

    $foreignCreate = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$orderB['id'],'tipo'=>'FACTURA']);
    expect(isset($foreignCreate['error']), 'un tenant no puede facturar el pedido de otra cuenta');
    $createdB = invokeApi($root, $envPath, $tenantB['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$orderB['id'],'tipo'=>'FACTURA']);
    expect(!empty($createdB['success']), 'el segundo tenant materializa su propia factura');
    $invoiceB = (int)$createdB['id_factura'];
    $foreignRead = invokeApi($root, $envPath, $tenantA['user'], 'GET', ['id'=>$invoiceB]);
    expect(isset($foreignRead['error']), 'GET no expone documentos de otra cuenta');
    $foreignPayment = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'pagar','id_factura'=>$invoiceB,'metodo'=>'EFECTIVO','monto'=>'1.00',
        'idempotency_key'=>'pay-foreign-' . bin2hex(random_bytes(8)),
    ]);
    expect(!empty($foreignPayment['error']), 'un tenant no puede pagar documentos de otra cuenta');
    $foreignDebit = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'nota_debito','id_factura'=>$invoiceB,'monto'=>'1.00',
        'idempotency_key'=>'nd-foreign-' . bin2hex(random_bytes(8)),
    ]);
    expect(!empty($foreignDebit['error']), 'un tenant no puede emitir notas sobre documentos de otra cuenta');

    // Simula una fila legacy corrupta saltando deliberadamente la FK solo en
    // esta conexion de prueba. El JOIN de lectura no debe filtrar PII ajena.
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $db->query("UPDATE factura SET id_cliente={$tenantB['client']} WHERE id_factura={$invoiceA}");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    $corruptRead = invokeApi($root, $envPath, $tenantA['user'], 'GET', ['id'=>$invoiceA]);
    expect(
        array_key_exists('razon_social', $corruptRead)
        && $corruptRead['razon_social'] === null
        && $corruptRead['rut'] === null
        && $corruptRead['cliente_nombre'] === null,
        'una referencia legacy corrupta no expone datos de cliente de otro tenant'
    );
    $db->query("UPDATE factura SET id_cliente={$tenantA['client']} WHERE id_factura={$invoiceA}");

    $viewerAnnulDenied = invokeApi($root, $envPath, $viewerA, 'POST', [], [
        'accion'=>'anular','id_factura'=>$creditId1,'motivo'=>'Sin permiso',
    ]);
    expect(!empty($viewerAnnulDenied['error']), 'facturas.ver no concede permiso para anular documentos');
    $annulCredit1 = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'anular','id_factura'=>$creditId1,'motivo'=>'Cerrar prueba NC 1',
    ]);
    $annulCredit2 = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'anular','id_factura'=>$creditId2,'motivo'=>'Cerrar prueba NC 2',
    ]);
    expect(!empty($annulCredit1['success']) && !empty($annulCredit2['success']), 'al anular las notas activas se libera de forma explícita la factura origen');
    $annulled = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'anular','id_factura'=>$invoiceA,'motivo'=>'Prueba fiscal']);
    expect(
        !empty($annulled['success'])
        && abs((float)scalar($db, "SELECT disponible FROM stock WHERE id_producto={$tenantA['product']} AND id_bodega={$tenantA['warehouse']}") - $stockAfterNotes) < 0.0001,
        'anular la factura no altera el stock ya repuesto por devoluciones físicas'
    );
    $reissueRejected = invokeApi($root, $envPath, $tenantA['user'], 'POST', [], ['accion'=>'crear','id_pedido'=>$orderA['id'],'tipo'=>'FACTURA']);
    expect(isset($reissueRejected['error']) && (int)scalar($db, "SELECT COUNT(*) FROM factura WHERE id_pedido={$orderA['id']} AND tipo='FACTURA'") === 1, 'una factura anulada no puede reemitirse con el mismo folio fuera de un flujo explícito');

    echo "OK integridad documental de facturación verificada.\n";
} finally {
    if ($tenantA !== []) cleanupTenant($db, $tenantA);
    if ($tenantB !== []) cleanupTenant($db, $tenantB);
}
