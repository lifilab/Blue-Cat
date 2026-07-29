<?php
declare(strict_types=1);

function migration029Option(string $name): ?string
{
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if (str_starts_with($argument, $name . '=')) return substr($argument, strlen($name) + 1);
    }
    return null;
}

function migration029Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function migration029Scalar(mysqli $db, string $sql): mixed
{
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error . "\n" . $sql);
    $row = $result->fetch_row();
    $result->free();
    return $row[0] ?? null;
}

/** @return array{0:int,1:string,2:string} */
function migration029Run(array $command, string $cwd): array
{
    $process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes, $cwd);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el migrador');
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim($stdout), trim($stderr)];
}

function migration029WriteEnv(string $path, string $database): void
{
    $values = [
        'APP_ENV'=>'test',
        'DB_HOST'=>DB_HOST,
        'DB_PORT'=>(string)DB_PORT,
        'DB_NAME'=>$database,
        'DB_USER'=>DB_USER,
        'DB_PASSWORD'=>DB_PASS,
    ];
    $lines = [];
    foreach ($values as $key=>$value) {
        if (str_contains((string)$value, "\r") || str_contains((string)$value, "\n")) {
            throw new RuntimeException("Variable {$key} invalida");
        }
        $lines[] = $key . '=' . $value;
    }
    if (file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo crear el entorno scratch');
    }
}

$root = dirname(__DIR__);
$envOption = migration029Option('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $envOption) ? $envOption : $root . '/' . $envOption;
if (!is_file($envPath)) throw new RuntimeException("Falta {$envPath}");
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') {
    throw new RuntimeException('La prueba 029 solo admite APP_ENV=test y una base distinta de erp');
}

$database = 'bluecat_u029_' . bin2hex(random_bytes(6));
$scratchEnv = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $database . '.env';
$admin = null;
$db = null;
$created = false;
$failure = null;

try {
    $admin = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    $admin->set_charset('utf8mb4');
    if (!$admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException($admin->error);
    }
    $created = true;
    migration029WriteEnv($scratchEnv, $database);

    [$baseCode,$baseOut,$baseErr] = migration029Run(
        [PHP_BINARY,$root.'/scripts/migrate.php','--env='.$scratchEnv,'--to=028_operational_consistency.sql'],
        $root
    );
    if ($baseCode !== 0) throw new RuntimeException("No se pudo preparar 028: {$baseOut} {$baseErr}");
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, $database, DB_PORT);
    $db->set_charset('utf8mb4');
    migration029Assert(
        (int)migration029Scalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='028_operational_consistency.sql'") === 1
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='029_invoice_reconciliation.sql'") === 0,
        'prepara una instalacion detenida exactamente en 028'
    );

    $suffix = strtoupper(bin2hex(random_bytes(5)));
    $accountName = 'Upgrade 029 ' . $suffix;
    $stmt = $db->prepare("INSERT INTO cuenta(nombre,estado) VALUES (?,'ACTIVA')");
    $stmt->bind_param('s',$accountName);
    $stmt->execute();
    $account = (int)$db->insert_id;
    $stmt->close();
    $userName = 'upgrade029-' . strtolower($suffix);
    $mail = $userName . '@test.local';
    $password = password_hash('Upgrade-029!', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss',$account,$userName,$mail,$password);
    $stmt->execute();
    $user = (int)$db->insert_id;
    $stmt->close();

    $foreignAccountName = 'Upgrade 029 foreign ' . $suffix;
    $stmt = $db->prepare("INSERT INTO cuenta(nombre,estado) VALUES (?,'ACTIVA')");
    $stmt->bind_param('s',$foreignAccountName);
    $stmt->execute();
    $foreignAccount = (int)$db->insert_id;
    $stmt->close();
    $foreignUserName = 'upgrade029-foreign-' . strtolower($suffix);
    $foreignMail = $foreignUserName . '@test.local';
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss',$foreignAccount,$foreignUserName,$foreignMail,$password);
    $stmt->execute();
    $foreignUser = (int)$db->insert_id;
    $stmt->close();
    $foreignCode = 'U029-F-' . $suffix;
    $foreignClientName = 'Cliente foraneo U029';
    $stmt = $db->prepare('INSERT INTO cliente(id_user,id_cuenta,codigo,nombre) VALUES (?,?,?,?)');
    $stmt->bind_param('iiss',$foreignUser,$foreignAccount,$foreignCode,$foreignClientName);
    $stmt->execute();
    $foreignClient = (int)$db->insert_id;
    $stmt->close();

    $productName = 'Producto U029 ' . $suffix;
    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,precio_costo,cantidad,tipo_venta,activo) VALUES (?,?,?,1190,700,10,'PESO',1)");
    $stmt->bind_param('iis',$user,$account,$productName);
    $stmt->execute();
    $product = (int)$db->insert_id;
    $stmt->close();

    $folio = 'U029-' . $suffix;
    // 028 solo validaba el id global del cliente, por lo que esta referencia
    // cruzada era legal antes de 029.
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,id_cliente,id_pedido,folio,numero,tipo,estado,fecha_emision,subtotal,descuento,neto,iva,total,pagado,saldo) VALUES (?,?,?,9001,?,?,'FACTURA','EMITIDA',CURDATE(),1190,0,1000,190,1190,0,1)");
    $numero = 'F-' . $folio;
    $stmt->bind_param('iiiss',$user,$account,$foreignClient,$folio,$numero);
    $stmt->execute();
    $original = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,1.500,1190,0,1500,285,1785)');
    $stmt->bind_param('iis',$original,$product,$productName);
    $stmt->execute();
    $originalDetail = (int)$db->insert_id;
    $stmt->close();

    $duplicateFolio = 'DUP-' . $suffix;
    $duplicateNumber = 'F-' . $duplicateFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,id_pedido,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo) VALUES (?,?,9001,?,?,'FACTURA','EMITIDA',CURDATE(),1190,0,1190)");
    $stmt->bind_param('iiss',$user,$account,$duplicateFolio,$duplicateNumber);
    $stmt->execute();
    $duplicate = (int)$db->insert_id;
    $stmt->close();

    // El duplicado mas antiguo esta anulado. La migracion debe conservar el
    // vinculo del documento vigente aunque tenga un id_factura posterior.
    $annulledFolio = 'ANN-DUP-' . $suffix;
    $annulledNumber = 'F-' . $annulledFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,id_pedido,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo) VALUES (?,?,9002,?,?,'FACTURA','ANULADA',CURDATE(),850,0,0)");
    $stmt->bind_param('iiss',$user,$account,$annulledFolio,$annulledNumber);
    $stmt->execute();
    $annulledDuplicate = (int)$db->insert_id;
    $stmt->close();

    $activeFolio = 'ACT-DUP-' . $suffix;
    $activeNumber = 'F-' . $activeFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,id_pedido,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo) VALUES (?,?,9002,?,?,'FACTURA','EMITIDA',CURDATE(),850,0,850)");
    $stmt->bind_param('iiss',$user,$account,$activeFolio,$activeNumber);
    $stmt->execute();
    $activeDuplicate = (int)$db->insert_id;
    $stmt->close();

    $partialFolio = 'PAR-' . $suffix;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo) VALUES (?,?,?,?,'FACTURA','EMITIDA',CURDATE(),100,99,1)");
    $partialNumber = 'F-' . $partialFolio;
    $stmt->bind_param('iiss',$user,$account,$partialFolio,$partialNumber);
    $stmt->execute();
    $partial = (int)$db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO factura_pago(id_factura,metodo,monto,fecha,referencia) VALUES ({$partial},'EFECTIVO',40,CURDATE(),'LEGACY-PARTIAL')");

    $noteFolio = 'NC-' . $suffix;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,subtotal,neto,iva,total,pagado,saldo) VALUES (?,?,?,?,'NOTA_CREDITO','EMITIDA',CURDATE(),595,500,95,595,0,0)");
    $noteNumber = 'NC-' . $noteFolio;
    $stmt->bind_param('iiss',$user,$account,$noteFolio,$noteNumber);
    $stmt->execute();
    $note = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,0.500,1190,0,500,95,595)');
    $stmt->bind_param('iis',$note,$product,$productName);
    $stmt->execute();
    $noteDetail = (int)$db->insert_id;
    $stmt->close();
    $historyText = "NC legacy vinculada a factura #{$original} por prueba";
    $action = 'NOTA_CREDITO';
    $stmt = $db->prepare('INSERT INTO factura_historial(id_factura,id_user,accion,valor_nuevo) VALUES (?,?,?,?)');
    $stmt->bind_param('iiss',$note,$user,$action,$historyText);
    $stmt->execute();
    $stmt->close();

    // El historial legacy real registraba el numero de la NC en la factura
    // original, sin guardar el id_factura_origen.
    $numberOriginalFolio = 'NUML-' . $suffix;
    $numberOriginalNumber = 'F-' . $numberOriginalFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,subtotal,neto,iva,total,pagado,saldo) VALUES (?,?,?,?,'FACTURA','EMITIDA',CURDATE(),500,420,80,500,0,500)");
    $stmt->bind_param('iiss',$user,$account,$numberOriginalFolio,$numberOriginalNumber);
    $stmt->execute();
    $numberOriginal = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,1.000,500,0,420,80,500)');
    $stmt->bind_param('iis',$numberOriginal,$product,$productName);
    $stmt->execute();
    $numberOriginalDetail = (int)$db->insert_id;
    $stmt->close();

    $numberNoteFolio = 'NCL-' . $suffix;
    $numberNoteNumber = 'NC-' . $numberNoteFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,subtotal,neto,iva,total,pagado,saldo) VALUES (?,?,?,?,'NOTA_CREDITO','EMITIDA',CURDATE(),500,420,80,500,0,0)");
    $stmt->bind_param('iiss',$user,$account,$numberNoteFolio,$numberNoteNumber);
    $stmt->execute();
    $numberNote = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,1.000,500,0,420,80,500)');
    $stmt->bind_param('iis',$numberNote,$product,$productName);
    $stmt->execute();
    $numberNoteDetail = (int)$db->insert_id;
    $stmt->close();
    $numberHistory = "NC {$numberNoteNumber} asociada";
    $action = 'NOTA_CREDITO_ASOCIADA';
    $stmt = $db->prepare('INSERT INTO factura_historial(id_factura,id_user,accion,valor_nuevo) VALUES (?,?,?,?)');
    $stmt->bind_param('iiss',$numberOriginal,$user,$action,$numberHistory);
    $stmt->execute();
    $stmt->close();

    // Algunas ND conservaban el numero completo de la factura en su propio
    // historial. La relacion inversa tambien debe ser 1:1 y exacta.
    $debitOriginalFolio = 'NDOL-' . $suffix;
    $debitOriginalNumber = 'F-' . $debitOriginalFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,subtotal,neto,iva,total,pagado,saldo) VALUES (?,?,?,?,'FACTURA','EMITIDA',CURDATE(),300,252,48,300,0,300)");
    $stmt->bind_param('iiss',$user,$account,$debitOriginalFolio,$debitOriginalNumber);
    $stmt->execute();
    $debitOriginal = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,1.000,300,0,252,48,300)');
    $stmt->bind_param('iis',$debitOriginal,$product,$productName);
    $stmt->execute();
    $debitOriginalDetail = (int)$db->insert_id;
    $stmt->close();

    $legacyDebitFolio = 'NDL-' . $suffix;
    $legacyDebitNumber = 'ND-' . $legacyDebitFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,subtotal,neto,iva,total,pagado,saldo) VALUES (?,?,?,?,'NOTA_DEBITO','EMITIDA',CURDATE(),30,25.20,4.80,30,0,30)");
    $stmt->bind_param('iiss',$user,$account,$legacyDebitFolio,$legacyDebitNumber);
    $stmt->execute();
    $legacyDebit = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO factura_detalle(id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,0.100,300,0,25.20,4.80,30)');
    $stmt->bind_param('iis',$legacyDebit,$product,$productName);
    $stmt->execute();
    $legacyDebitDetail = (int)$db->insert_id;
    $stmt->close();
    $debitHistory = "ND legacy vinculada a factura {$debitOriginalNumber}";
    $action = 'NOTA_DEBITO';
    $stmt = $db->prepare('INSERT INTO factura_historial(id_factura,id_user,accion,valor_nuevo) VALUES (?,?,?,?)');
    $stmt->bind_param('iiss',$legacyDebit,$user,$action,$debitHistory);
    $stmt->execute();
    $stmt->close();

    // Dos historiales que mencionan el mismo numero no autorizan una
    // conciliacion automatica.
    $ambiguousNoteFolio = 'NCA-' . $suffix;
    $ambiguousNoteNumber = 'NC-' . $ambiguousNoteFolio;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo) VALUES (?,?,?,?,'NOTA_CREDITO','EMITIDA',CURDATE(),10,0,0)");
    $stmt->bind_param('iiss',$user,$account,$ambiguousNoteFolio,$ambiguousNoteNumber);
    $stmt->execute();
    $ambiguousNote = (int)$db->insert_id;
    $stmt->close();
    foreach ([$numberOriginal, $debitOriginal] as $ambiguousOriginal) {
        $ambiguousHistory = "NC {$ambiguousNoteNumber} asociada";
        $action = 'NOTA_CREDITO_ASOCIADA';
        $stmt = $db->prepare('INSERT INTO factura_historial(id_factura,id_user,accion,valor_nuevo) VALUES (?,?,?,?)');
        $stmt->bind_param('iiss',$ambiguousOriginal,$user,$action,$ambiguousHistory);
        $stmt->execute();
        $stmt->close();
    }

    $orphanFolio = 'ORPH-' . $suffix;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo) VALUES (?,?,?,?,'NOTA_CREDITO','EMITIDA',CURDATE(),10,0,0)");
    $orphanNumber = 'NC-' . $orphanFolio;
    $stmt->bind_param('iiss',$user,$account,$orphanFolio,$orphanNumber);
    $stmt->execute();
    $orphan = (int)$db->insert_id;
    $stmt->close();

    [$upgradeCode,$upgradeOut,$upgradeErr] = migration029Run(
        [PHP_BINARY,$root.'/scripts/migrate.php','--env='.$scratchEnv],
        $root
    );
    if ($upgradeCode !== 0) throw new RuntimeException("Upgrade 029 fallo: {$upgradeOut} {$upgradeErr}");
    migration029Assert(str_contains($upgradeOut,'APPLY 029_invoice_reconciliation.sql'), 'el migrador aplica 029');

    migration029Assert(
        (int)migration029Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME IN ('idempotency_key','solicitud_hash')") === 2
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME IN ('idempotency_key','solicitud_hash')") === 2
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND INDEX_NAME='uq_factura_pago_idempotencia'") > 0
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND INDEX_NAME='uq_factura_nota_idempotencia'") > 0,
        'crea el contrato de idempotencia de pagos, NC y ND'
    );
    migration029Assert(
        (int)migration029Scalar($db, "SELECT COUNT(*) FROM permiso WHERE modulo='facturas' AND accion='exportar'") === 1,
        'registra el permiso explicito para exportar facturas'
    );
    migration029Assert(
        migration029Scalar($db, "SELECT id_cliente FROM factura WHERE id_factura={$original}") === null
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM factura_historial WHERE id_factura={$original} AND accion='CONCILIAR_CLIENTE_TENANT'") === 1
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND CONSTRAINT_NAME='fk_factura_cliente_cuenta'") === 1,
        'separa referencias de cliente cruzadas y las impide mediante FK compuesta'
    );
    migration029Assert(
        (int)migration029Scalar($db, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND INDEX_NAME='uq_factura_cuenta_pedido_tipo'") > 0
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM factura WHERE id_factura={$original} AND id_pedido=9001") === 1
        && migration029Scalar($db, "SELECT id_pedido FROM factura WHERE id_factura={$duplicate}") === null
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM factura_historial WHERE id_factura={$duplicate} AND accion='CONCILIAR_DUPLICADO'") === 1,
        'preserva duplicados historicos pero deja un solo documento canonico por pedido'
    );
    migration029Assert(
        migration029Scalar($db, "SELECT id_pedido FROM factura WHERE id_factura={$annulledDuplicate}") === null
        && (int)migration029Scalar($db, "SELECT id_pedido FROM factura WHERE id_factura={$activeDuplicate}") === 9002
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM factura_historial WHERE id_factura={$annulledDuplicate} AND accion='CONCILIAR_DUPLICADO'") === 1,
        'prefiere como canonica la factura vigente aunque el duplicado anulado sea mas antiguo'
    );
    migration029Assert(
        (int)migration029Scalar($db, "SELECT id_factura_origen FROM factura WHERE id_factura={$note}") === $original
        && (int)migration029Scalar($db, "SELECT id_detalle_origen FROM factura_detalle WHERE id_factura_detalle={$noteDetail}") === $originalDetail
        && (string)migration029Scalar($db, "SELECT estado FROM factura_legacy_reconciliacion WHERE id_cuenta={$account} AND id_factura_nota={$note}") === 'VINCULADA'
        && (int)migration029Scalar($db, "SELECT bloquea_nueva_nc FROM factura_legacy_reconciliacion WHERE id_cuenta={$account} AND id_factura_nota={$note}") === 0,
        'vincula automaticamente solo la nota legacy con evidencia inequivoca'
    );
    migration029Assert(
        (int)migration029Scalar($db, "SELECT id_factura_origen FROM factura WHERE id_factura={$numberNote}") === $numberOriginal
        && (int)migration029Scalar($db, "SELECT id_detalle_origen FROM factura_detalle WHERE id_factura_detalle={$numberNoteDetail}") === $numberOriginalDetail
        && (string)migration029Scalar($db, "SELECT estado FROM factura_legacy_reconciliacion WHERE id_factura_nota={$numberNote}") === 'VINCULADA',
        'reconcilia una NC por su numero completo registrado 1:1 en la factura origen'
    );
    migration029Assert(
        (int)migration029Scalar($db, "SELECT id_factura_origen FROM factura WHERE id_factura={$legacyDebit}") === $debitOriginal
        && (int)migration029Scalar($db, "SELECT id_detalle_origen FROM factura_detalle WHERE id_factura_detalle={$legacyDebitDetail}") === $debitOriginalDetail
        && (string)migration029Scalar($db, "SELECT estado FROM factura_legacy_reconciliacion WHERE id_factura_nota={$legacyDebit}") === 'VINCULADA',
        'reconcilia una ND cuando su historial menciona exactamente el numero de factura'
    );
    migration029Assert(
        migration029Scalar($db, "SELECT id_factura_origen FROM factura WHERE id_factura={$ambiguousNote}") === null
        && (string)migration029Scalar($db, "SELECT estado FROM factura_legacy_reconciliacion WHERE id_factura_nota={$ambiguousNote}") === 'ORIGEN_AMBIGUO'
        && (int)migration029Scalar($db, "SELECT bloquea_nueva_nc FROM factura_legacy_reconciliacion WHERE id_factura_nota={$ambiguousNote}") === 1,
        'dos coincidencias por numero se conservan como ambiguedad bloqueante'
    );
    migration029Assert(
        (string)migration029Scalar($db, "SELECT estado FROM factura_legacy_reconciliacion WHERE id_cuenta={$account} AND id_factura_nota={$orphan}") === 'SIN_CANDIDATO'
        && (int)migration029Scalar($db, "SELECT bloquea_nueva_nc FROM factura_legacy_reconciliacion WHERE id_cuenta={$account} AND id_factura_nota={$orphan}") === 1,
        'una nota sin evidencia queda bloqueada para conciliacion manual'
    );
    migration029Assert(
        (int)migration029Scalar($db, "SELECT COUNT(*) FROM factura WHERE id_factura={$original} AND pagado=0 AND saldo=1190 AND estado='EMITIDA'") === 1
        && (int)migration029Scalar($db, "SELECT COUNT(*) FROM factura WHERE id_factura={$partial} AND pagado=40 AND saldo=60 AND estado='PARCIAL'") === 1,
        'reconstruye pagado y saldo solo desde el ledger, nunca desde el saldo legacy'
    );

    $key = 'payment-u029-' . bin2hex(random_bytes(8));
    $hash = hash('sha256','request-a');
    $stmt = $db->prepare("INSERT INTO factura_pago(id_factura,metodo,monto,fecha,idempotency_key,solicitud_hash) VALUES (?,'EFECTIVO',1,CURDATE(),?,?)");
    $stmt->bind_param('iss',$partial,$key,$hash);
    $stmt->execute();
    $stmt->close();
    $duplicatePaymentRejected = false;
    $stmt = $db->prepare("INSERT INTO factura_pago(id_factura,metodo,monto,fecha,idempotency_key,solicitud_hash) VALUES (?,'EFECTIVO',1,CURDATE(),?,?)");
    $stmt->bind_param('iss',$partial,$key,$hash);
    try {
        $duplicatePaymentRejected = !$stmt->execute();
    } catch (mysqli_sql_exception) {
        $duplicatePaymentRejected = true;
    }
    $stmt->close();
    migration029Assert($duplicatePaymentRejected, 'la base impide duplicar una clave de pago en la misma factura');

    $noteKey = 'note-u029-' . bin2hex(random_bytes(8));
    $noteHash = hash('sha256','note-request-a');
    $noteKeyFolio1 = 'NDK1-' . $suffix;
    $noteKeyNumber1 = 'ND-' . $noteKeyFolio1;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo,idempotency_key,solicitud_hash) VALUES (?,?,?,?,'NOTA_DEBITO','EMITIDA',CURDATE(),1,0,1,?,?)");
    $stmt->bind_param('iissss',$user,$account,$noteKeyFolio1,$noteKeyNumber1,$noteKey,$noteHash);
    $stmt->execute();
    $stmt->close();
    $duplicateNoteRejected = false;
    $noteKeyFolio2 = 'NDK2-' . $suffix;
    $noteKeyNumber2 = 'ND-' . $noteKeyFolio2;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo,idempotency_key,solicitud_hash) VALUES (?,?,?,?,'NOTA_DEBITO','EMITIDA',CURDATE(),1,0,1,?,?)");
    $stmt->bind_param('iissss',$user,$account,$noteKeyFolio2,$noteKeyNumber2,$noteKey,$noteHash);
    try {
        $duplicateNoteRejected = !$stmt->execute();
    } catch (mysqli_sql_exception) {
        $duplicateNoteRejected = true;
    }
    $stmt->close();
    migration029Assert($duplicateNoteRejected, 'la base impide duplicar una clave de idempotencia de NC o ND');

    $duplicateInvoiceRejected = false;
    $folioAfter = 'AFTER-' . $suffix;
    $numberAfter = 'F-' . $folioAfter;
    $stmt = $db->prepare("INSERT INTO factura(id_user,id_cuenta,id_pedido,folio,numero,tipo,estado,fecha_emision,total,pagado,saldo) VALUES (?,?,9001,?,?,'FACTURA','EMITIDA',CURDATE(),1,0,1)");
    $stmt->bind_param('iiss',$user,$account,$folioAfter,$numberAfter);
    try {
        $duplicateInvoiceRejected = !$stmt->execute();
    } catch (mysqli_sql_exception) {
        $duplicateInvoiceRejected = true;
    }
    $stmt->close();
    migration029Assert($duplicateInvoiceRejected, 'la base impide materializar dos FACTURA para el mismo pedido');

    migration029Assert(
        (int)migration029Scalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='029_invoice_reconciliation.sql'") === 1,
        'registra la migracion 029'
    );
    echo "OK upgrade real 028 a 029 verificado.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $cleanupErrors = [];
    try {
        if ($db instanceof mysqli) $db->close();
    } catch (Throwable $error) {
        $cleanupErrors[] = $error->getMessage();
    }
    try {
        if (!$admin instanceof mysqli) $admin = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
        if ($created && !$admin->query("DROP DATABASE `{$database}`")) throw new RuntimeException($admin->error);
        $admin->close();
    } catch (Throwable $error) {
        $cleanupErrors[] = $error->getMessage();
    }
    if (is_file($scratchEnv) && !unlink($scratchEnv)) $cleanupErrors[] = 'no se pudo eliminar el env scratch';
    if ($cleanupErrors && $failure === null) $failure = new RuntimeException(implode('; ', $cleanupErrors));
}

if ($failure !== null) throw $failure;
