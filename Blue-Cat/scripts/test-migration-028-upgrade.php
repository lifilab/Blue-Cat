<?php
declare(strict_types=1);

function migration028Option(string $name): ?string
{
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if (str_starts_with($argument, $name . '=')) {
            return substr($argument, strlen($name) + 1);
        }
    }
    return null;
}

function migration028Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}

function migration028Exec(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        throw new RuntimeException('SQL invalido: ' . $db->error . "\n" . $sql);
    }
}

function migration028Scalar(mysqli $db, string $sql): mixed
{
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException('Consulta de verificacion invalida: ' . $db->error);
    }
    $row = $result->fetch_row();
    $result->free();
    return $row[0] ?? null;
}

/** @return array{0:int,1:string,2:string} */
function migration028Run(array $command, string $cwd): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar el migrador.');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim($stdout), trim($stderr)];
}

function migration028ColumnExists(mysqli $db, string $table, string $column): bool
{
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    return (int) migration028Scalar(
        $db,
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND COLUMN_NAME='{$column}'"
    ) === 1;
}

function migration028IndexExists(mysqli $db, string $table, string $index): bool
{
    $table = $db->real_escape_string($table);
    $index = $db->real_escape_string($index);
    return (int) migration028Scalar(
        $db,
        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND INDEX_NAME='{$index}'"
    ) > 0;
}

function migration028ConstraintExists(mysqli $db, string $table, string $constraint): bool
{
    $table = $db->real_escape_string($table);
    $constraint = $db->real_escape_string($constraint);
    return (int) migration028Scalar(
        $db,
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND CONSTRAINT_NAME='{$constraint}'"
    ) === 1;
}

function migration028TableExists(mysqli $db, string $table): bool
{
    $table = $db->real_escape_string($table);
    return (int) migration028Scalar(
        $db,
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND TABLE_TYPE='BASE TABLE'"
    ) === 1;
}

function migration028CreateScratchEnv(string $targetPath, string $database): void
{
    $values = [
        'APP_ENV' => 'test',
        'DB_HOST' => DB_HOST,
        'DB_PORT' => (string)DB_PORT,
        'DB_NAME' => $database,
        'DB_USER' => DB_USER,
        'DB_PASSWORD' => DB_PASS,
    ];
    $lines = [];
    foreach ($values as $key => $value) {
        if (str_contains((string)$value, "\n") || str_contains((string)$value, "\r")) {
            throw new RuntimeException("La variable {$key} no puede copiarse al entorno scratch.");
        }
        $lines[] = $key . '=' . $value;
    }
    if (file_put_contents($targetPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo crear el entorno aislado de la prueba.');
    }
    @chmod($targetPath, 0600);
}

$root = dirname(__DIR__);
$env = migration028Option('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $env) ? $env : $root . '/' . $env;
if (!is_file($envPath)) {
    throw new RuntimeException("Falta el archivo de entorno {$envPath}.");
}

putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') {
    throw new RuntimeException('La prueba de upgrade 028 solo puede ejecutarse con APP_ENV=test y una base que no sea erp.');
}

$migration = '028_operational_consistency.sql';
$scratchDatabase = 'bluecat_u028_' . bin2hex(random_bytes(6));
$scratchEnvPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $scratchDatabase . '.env';
$admin = null;
$db = null;
$scratchCreated = false;
$fixture = [
    'accounts' => [],
    'users' => [],
    'warehouses' => [],
    'registers' => [],
    'transfer' => 0,
    'invoice' => 0,
    'exempt_invoice' => 0,
    'partial_invoice' => 0,
    'config' => 0,
];
$primaryFailure = null;

try {
    $admin = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    $admin->set_charset('utf8mb4');
    migration028Exec($admin, "CREATE DATABASE `{$scratchDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $scratchCreated = true;
    migration028CreateScratchEnv($scratchEnvPath, $scratchDatabase);

    [$initialExitCode, $initialStdout, $initialStderr] = migration028Run(
        [PHP_BINARY, $root . '/scripts/migrate.php', '--env=' . $scratchEnvPath, '--to=027_sales_immutability_permissions.sql'],
        $root
    );
    if ($initialExitCode !== 0) {
        throw new RuntimeException("No se pudo preparar la base scratch ({$initialExitCode}): {$initialStdout} {$initialStderr}");
    }
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, $scratchDatabase, DB_PORT);
    $db->set_charset('utf8mb4');

    migration028Assert(
        str_contains($initialStdout, 'APPLY 027_sales_immutability_permissions.sql')
        && (int)migration028Scalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='027_sales_immutability_permissions.sql'") === 1
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='{$migration}'") === 0,
        'la base scratch se construye realmente hasta la migracion 027'
    );
    migration028Assert(
        !migration028ColumnExists($db, 'transferencia', 'id_cuenta')
        && !migration028ColumnExists($db, 'pos_caja_fisica', 'id_bodega')
        && !migration028ColumnExists($db, 'factura', 'pagado')
        && !migration028ColumnExists($db, 'factura', 'id_factura_origen')
        && !migration028ColumnExists($db, 'factura_detalle', 'id_detalle_origen')
        && !migration028ColumnExists($db, 'factura_pago', 'id_metodo_de_pago')
        && (string)migration028Scalar($db, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND COLUMN_NAME='precio_unitario'") === 'YES',
        'el esquema 027 conserva el contrato legacy nullable'
    );

    $suffix = strtoupper(bin2hex(random_bytes(5)));
    foreach (['MULTI', 'SINGLE'] as $label) {
        $accountName = "Upgrade 028 {$label} {$suffix}";
        $stmt = $db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
        $stmt->bind_param('s', $accountName);
        $stmt->execute();
        $accountId = (int) $db->insert_id;
        $stmt->close();
        $fixture['accounts'][] = $accountId;

        $username = strtolower("upgrade028-{$label}-{$suffix}");
        $email = $username . '@test.local';
        $password = password_hash('Upgrade-028-2026!', PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
        $stmt->bind_param('isss', $accountId, $username, $email, $password);
        $stmt->execute();
        $userId = (int) $db->insert_id;
        $stmt->close();
        $fixture['users'][] = $userId;

        $warehouseCount = $label === 'MULTI' ? 2 : 1;
        $labelCode = $label === 'MULTI' ? 'M' : 'S';
        for ($index = 1; $index <= $warehouseCount; $index++) {
            $code = "U28-{$labelCode}-{$index}-{$suffix}";
            $name = "Bodega {$label} {$index} {$suffix}";
            $stmt = $db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
            $stmt->bind_param('iiss', $userId, $accountId, $code, $name);
            $stmt->execute();
            $fixture['warehouses'][] = (int) $db->insert_id;
            $stmt->close();
        }

        $registerCode = "REG-{$label}-{$suffix}";
        $registerName = "Caja {$label} {$suffix}";
        $stmt = $db->prepare("INSERT INTO pos_caja_fisica(id_cuenta,codigo,nombre,sucursal,activo) VALUES (?,?,?,'Principal',1)");
        $stmt->bind_param('iss', $accountId, $registerCode, $registerName);
        $stmt->execute();
        $fixture['registers'][] = (int) $db->insert_id;
        $stmt->close();
    }

    [$multiAccount, $singleAccount] = $fixture['accounts'];
    [$multiUser, $singleUser] = $fixture['users'];
    [$multiOrigin, $multiDestination, $singleWarehouse] = $fixture['warehouses'];
    [$multiRegister, $singleRegister] = $fixture['registers'];

    $transferNumber = 'TR-U28-' . $suffix;
    $stmt = $db->prepare(
        "INSERT INTO transferencia(numero,id_bodega_origen,id_bodega_destino,estado,id_user,observaciones) VALUES (?,?,?,'PENDIENTE',?,'Fixture legacy 028')"
    );
    $stmt->bind_param('siii', $transferNumber, $multiOrigin, $multiDestination, $multiUser);
    $stmt->execute();
    $fixture['transfer'] = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO config_boleta(id_user,id_cuenta,nombre_empresa,iva_porcentaje,activo) VALUES (?,?,'Upgrade 028',7,1)");
    $stmt->bind_param('ii', $singleUser, $singleAccount);
    $stmt->execute();
    $fixture['config'] = (int) $db->insert_id;
    $stmt->close();

    $folio = 'U28-' . $suffix;
    $stmt = $db->prepare(
        "INSERT INTO factura(id_user,id_cuenta,folio,tipo,estado,fecha_emision,subtotal,descuento,neto,iva,total,saldo) "
        . "VALUES (?,?,?,'FACTURA','EMITIDA',CURDATE(),900,119,900,171,1071,471)"
    );
    $stmt->bind_param('iis', $singleUser, $singleAccount, $folio);
    $stmt->execute();
    $fixture['invoice'] = (int) $db->insert_id;
    $stmt->close();

    $invoiceId = (int) $fixture['invoice'];
    migration028Exec(
        $db,
        "INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,total) "
        . "VALUES ({$invoiceId},NULL,'Producto historico',1,1190,1071)"
    );
    migration028Exec(
        $db,
        "INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,total) "
        . "VALUES ({$invoiceId},NULL,'Precio nulo legacy',1,NULL,0)"
    );
    migration028Exec(
        $db,
        "INSERT INTO factura_pago(id_factura,metodo,monto,fecha,referencia) "
        . "VALUES ({$invoiceId},'EFECTIVO',1071,CURDATE(),'PAGO-U28')"
    );
    $exemptFolio = 'EX-' . $suffix;
    $stmt = $db->prepare(
        "INSERT INTO factura(id_user,id_cuenta,folio,tipo,estado,fecha_emision,subtotal,descuento,neto,iva,total,saldo) "
        . "VALUES (?,?,?,'FACTURA','EMITIDA',CURDATE(),500,0,500,0,500,500)"
    );
    $stmt->bind_param('iis', $singleUser, $singleAccount, $exemptFolio);
    $stmt->execute();
    $fixture['exempt_invoice'] = (int)$db->insert_id;
    $stmt->close();
    migration028Exec(
        $db,
        "INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,total) "
        . "VALUES ({$fixture['exempt_invoice']},NULL,'Servicio exento historico',1,500,500)"
    );
    $partialFolio = 'PA-' . $suffix;
    $stmt = $db->prepare(
        "INSERT INTO factura(id_user,id_cuenta,folio,tipo,estado,fecha_emision,subtotal,descuento,neto,iva,total,saldo) "
        . "VALUES (?,?,?,'FACTURA','EMITIDA',CURDATE(),100,0,84,16,100,100)"
    );
    $stmt->bind_param('iis', $singleUser, $singleAccount, $partialFolio);
    $stmt->execute();
    $fixture['partial_invoice'] = (int)$db->insert_id;
    $stmt->close();
    migration028Exec(
        $db,
        "INSERT INTO factura_pago(id_factura,metodo,monto,fecha,referencia) "
        . "VALUES ({$fixture['partial_invoice']},'EFECTIVO',40,CURDATE(),'PAGO-PARCIAL-U28')"
    );

    [$exitCode, $stdout, $stderr] = migration028Run(
        [PHP_BINARY, $root . '/scripts/migrate.php', '--env=' . $scratchEnvPath],
        $root
    );
    if ($exitCode !== 0) {
        throw new RuntimeException("El upgrade 028 fallo ({$exitCode}): {$stdout} {$stderr}");
    }
    migration028Assert(str_contains($stdout, "APPLY {$migration}"), 'el migrador real aplica 028 sobre el fixture legacy');

    migration028Assert(
        (int) migration028Scalar($db, "SELECT COUNT(*) FROM transferencia WHERE id_transferencia={$fixture['transfer']} AND id_cuenta={$multiAccount} AND clave_idempotencia IS NULL AND solicitud_hash IS NULL") === 1,
        'la transferencia legacy hereda la cuenta de su bodega de origen'
    );
    migration028Assert(
        migration028ColumnExists($db, 'transferencia', 'id_cuenta')
        && (string) migration028Scalar($db, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND COLUMN_NAME='id_cuenta'") === 'NO'
        && migration028IndexExists($db, 'bodega', 'uq_bodega_cuenta_id')
        && migration028IndexExists($db, 'transferencia', 'uq_transferencia_idempotencia')
        && migration028ConstraintExists($db, 'transferencia', 'fk_transferencia_cuenta')
        && migration028ConstraintExists($db, 'transferencia', 'fk_transferencia_origen_cuenta')
        && migration028ConstraintExists($db, 'transferencia', 'fk_transferencia_destino_cuenta'),
        'transferencia queda aislada por cuenta, bodegas compuestas e idempotencia unica'
    );
    $crossTenantTransferRejected = false;
    $crossNumber = 'XTR-U28-' . $suffix;
    $stmt = $db->prepare("INSERT INTO transferencia(id_cuenta,numero,id_bodega_origen,id_bodega_destino,estado,id_user) VALUES (?,?,?,?,'PENDIENTE',?)");
    $stmt->bind_param('isiii', $multiAccount, $crossNumber, $multiOrigin, $singleWarehouse, $multiUser);
    try {
        $crossTenantTransferRejected = !$stmt->execute();
    } catch (mysqli_sql_exception) {
        $crossTenantTransferRejected = true;
    }
    $stmt->close();
    migration028Assert($crossTenantTransferRejected, 'rechaza transferencias entre bodegas de cuentas distintas');
    migration028Assert(
        migration028TableExists($db, 'transferencia_asignacion')
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia_asignacion' AND COLUMN_NAME IN ('id_transferencia','id_producto','id_stock','cantidad')") === 4
        && migration028IndexExists($db, 'transferencia_asignacion', 'uq_transferencia_asignacion_stock')
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='transferencia_asignacion'") === 3,
        'crea el contrato de asignaciones de stock con integridad referencial'
    );

    migration028Assert(
        migration028ColumnExists($db, 'pos_caja_fisica', 'id_bodega')
        && migration028IndexExists($db, 'pos_caja_fisica', 'idx_pos_caja_fisica_bodega')
        && migration028ConstraintExists($db, 'pos_caja_fisica', 'fk_pos_caja_fisica_bodega')
        && migration028ConstraintExists($db, 'pos_caja_fisica', 'fk_pos_caja_fisica_bodega_cuenta'),
        'la caja fisica incorpora su bodega con indice y aislamiento por cuenta'
    );
    migration028Assert(
        migration028Scalar($db, "SELECT id_bodega FROM pos_caja_fisica WHERE id_caja_fisica={$multiRegister}") === null,
        'una caja de cuenta multi-bodega queda sin asignacion ambigua'
    );
    migration028Assert(
        (int) migration028Scalar($db, "SELECT id_bodega FROM pos_caja_fisica WHERE id_caja_fisica={$singleRegister}") === $singleWarehouse,
        'una caja de cuenta con una sola bodega se asigna automaticamente'
    );
    $crossTenantRegisterRejected = false;
    $crossRegisterCode = 'XREG-' . $suffix;
    $stmt = $db->prepare("INSERT INTO pos_caja_fisica(id_cuenta,codigo,nombre,sucursal,id_bodega,activo) VALUES (?,?,'Caja cruzada','Principal',?,1)");
    $stmt->bind_param('isi', $singleAccount, $crossRegisterCode, $multiOrigin);
    try {
        $crossTenantRegisterRejected = !$stmt->execute();
    } catch (mysqli_sql_exception) {
        $crossTenantRegisterRejected = true;
    }
    $stmt->close();
    migration028Assert($crossTenantRegisterRejected, 'rechaza una caja asociada a la bodega de otra cuenta');

    migration028Assert(
        (int) migration028Scalar(
            $db,
            "SELECT COUNT(*) FROM factura WHERE id_factura={$invoiceId} AND pagado=1071.00 AND saldo=0 AND estado='PAGADA'"
        ) === 1,
        'factura historica pagada recupera pagado, saldo y estado'
    );
    migration028Assert(
        (int) migration028Scalar(
            $db,
            "SELECT COUNT(*) FROM factura_detalle WHERE id_factura={$invoiceId} AND descuento=119.00 AND neto=900.00 AND iva=171.00"
        ) === 1,
        'detalle historico recupera el IVA de su factura aunque la configuracion actual sea distinta'
    );
    migration028Assert(
        (int) migration028Scalar(
            $db,
            "SELECT COUNT(*) FROM factura_detalle WHERE id_factura={$invoiceId} AND producto='Precio nulo legacy' AND precio_unitario=0"
        ) === 1,
        'precio unitario NULL se normaliza antes de convertir la columna a NOT NULL'
    );
    migration028Assert(
        (int) migration028Scalar(
            $db,
            "SELECT COUNT(*) FROM factura_detalle WHERE id_factura={$fixture['exempt_invoice']} AND neto=500 AND iva=0"
        ) === 1,
        'una factura historica exenta conserva IVA cero'
    );
    migration028Assert(
        (int)migration028Scalar(
            $db,
            "SELECT COUNT(*) FROM factura WHERE id_factura={$fixture['partial_invoice']} AND pagado=40 AND saldo=60 AND estado='PARCIAL'"
        ) === 1,
        'una factura historica parcialmente pagada recalcula saldo y estado'
    );
    migration028Assert(
        (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME IN ('metodo_pago','sucursal','vendedor','orden_compra','pagado')") === 5
        && migration028IndexExists($db, 'factura', 'idx_factura_cuenta_pedido_tipo')
        && migration028IndexExists($db, 'factura', 'uq_factura_cuenta_id')
        && migration028ConstraintExists($db, 'factura', 'fk_factura_origen_cuenta')
        && migration028ColumnExists($db, 'factura', 'id_factura_origen')
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME IN ('subtotal','descuento','neto','iva','total','pagado','saldo') AND COLUMN_TYPE='decimal(18,2)' AND IS_NULLABLE='NO'") === 7
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND COLUMN_NAME IN ('descuento','neto','iva','id_detalle_origen')") === 4
        && migration028ConstraintExists($db, 'factura_detalle', 'fk_factura_detalle_origen')
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND COLUMN_NAME IN ('precio_unitario','total') AND IS_NULLABLE='NO' AND COLUMN_TYPE='decimal(18,2)'") === 2
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME IN ('banco','observacion','id_metodo_de_pago')") === 3
        && (int) migration028Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME='monto' AND COLUMN_TYPE='decimal(18,2)'") === 1
        && migration028IndexExists($db, 'factura_pago', 'uq_factura_pago_metodo_pos')
        && migration028ConstraintExists($db, 'factura_pago', 'fk_factura_pago_metodo_pos'),
        'restaura el esquema operativo de factura, detalle y pagos'
    );
    migration028Assert(
        (int)migration028Scalar($db, "SELECT COUNT(*) FROM permiso WHERE modulo='facturas' AND accion='nota_debito'") === 1
        && (int)migration028Scalar($db, "SELECT COUNT(*) FROM rol r JOIN rol_permiso rp ON rp.id_rol=r.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE r.activo=1 AND r.nombre IN ('Administrador','Supervisor') AND p.modulo='facturas' AND p.accion='nota_debito'")
            === (int)migration028Scalar($db, "SELECT COUNT(*) FROM rol WHERE activo=1 AND nombre IN ('Administrador','Supervisor')"),
        'nota de debito existe y queda asignada a Administrador y Supervisor'
    );
    migration028Assert(
        (int)migration028Scalar($db, "SELECT COUNT(*) FROM permiso WHERE modulo='inventario' AND accion='precios'") === 1
        && (int)migration028Scalar($db, "SELECT COUNT(*) FROM rol r JOIN rol_permiso rp ON rp.id_rol=r.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE r.activo=1 AND r.nombre IN ('Administrador','Supervisor') AND p.modulo='inventario' AND p.accion='precios'")
            === (int)migration028Scalar($db, "SELECT COUNT(*) FROM rol WHERE activo=1 AND nombre IN ('Administrador','Supervisor')")
        && (int)migration028Scalar($db, "SELECT COUNT(*) FROM rol r JOIN rol_permiso rp ON rp.id_rol=r.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE r.activo=1 AND r.nombre NOT IN ('Administrador','Supervisor') AND p.modulo='inventario' AND p.accion='precios'") === 0,
        'actualizar precios queda reservado a Administrador y Supervisor'
    );

    $crossTenantOriginRejected = false;
    $crossOriginFolio = 'XO-' . $suffix;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,tipo,estado,fecha_emision,total,saldo) VALUES (?,?,?,'FACTURA','EMITIDA',CURDATE(),10,10)");
    $stmt->bind_param('iis', $multiUser, $multiAccount, $crossOriginFolio);
    $stmt->execute();
    $foreignOriginInvoice = (int)$db->insert_id;
    $stmt->close();
    try {
        migration028Exec($db, "UPDATE factura SET id_factura_origen={$foreignOriginInvoice} WHERE id_factura={$invoiceId}");
    } catch (RuntimeException) {
        $crossTenantOriginRejected = true;
    }
    migration028Assert($crossTenantOriginRejected, 'la FK compuesta impide ligar notas con facturas de otra cuenta');
    migration028Assert(
        (int) migration028Scalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='{$migration}'") === 1,
        'el upgrade registra la migracion 028'
    );

    echo "OK upgrade real 027 a 028 verificado.\n";
} catch (Throwable $error) {
    $primaryFailure = $error;
} finally {
    $cleanupErrors = [];
    try {
        if ($db instanceof mysqli) $db->close();
    } catch (Throwable $error) {
        $cleanupErrors[] = 'cierre DB scratch: ' . $error->getMessage();
    }
    try {
        if (!$admin instanceof mysqli) {
            $admin = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
        }
        if ($scratchCreated && !$admin->query("DROP DATABASE `{$scratchDatabase}`")) {
            throw new RuntimeException($admin->error);
        }
        $admin->close();
    } catch (Throwable $error) {
        $cleanupErrors[] = 'DROP DATABASE scratch: ' . $error->getMessage();
    }
    if (is_file($scratchEnvPath) && !unlink($scratchEnvPath)) {
        $cleanupErrors[] = 'no se pudo eliminar el entorno scratch';
    }
    if ($cleanupErrors) {
        $cleanupFailure = new RuntimeException(implode('; ', $cleanupErrors));
    }
}

if ($primaryFailure !== null) {
    if (isset($cleanupFailure)) {
        throw new RuntimeException(
            $primaryFailure->getMessage() . ' Ademas fallo la limpieza: ' . $cleanupFailure->getMessage(),
            0,
            $primaryFailure
        );
    }
    throw $primaryFailure;
}
if (isset($cleanupFailure)) {
    throw $cleanupFailure;
}
