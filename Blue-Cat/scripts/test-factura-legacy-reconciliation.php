<?php
declare(strict_types=1);

function argument(string $name): ?string
{
    foreach (array_slice($_SERVER['argv'], 1) as $value) {
        if (str_starts_with($value, $name . '=')) return substr($value, strlen($name) + 1);
    }
    return null;
}

function checkLegacy(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function legacyScalar(mysqli $db, string $sql): mixed
{
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    $row = $result->fetch_row();
    $result->free();
    return $row[0] ?? null;
}

function callLegacyApi(
    string $root,
    string $env,
    int $user,
    string $method,
    array $query = [],
    ?array $body = null
): array {
    $command = [
        PHP_BINARY,
        $root . '/scripts/invoke-api-test.php',
        '--env=' . $env,
        '--endpoint=facturas.php',
        '--user=' . $user,
        '--method=' . $method,
    ];
    if ($query !== []) $command[] = '--query=' . base64_encode((string)json_encode($query));
    if ($body !== null) $command[] = '--body=' . base64_encode((string)json_encode($body));
    $process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo invocar la API fiscal.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Respuesta inválida de facturas (código {$code}): {$stdout} {$stderr}");
    }
    return $decoded;
}

/** @return array{account:int,user:int,viewer:int,roles:array<int,int>} */
function createLegacyTenant(mysqli $db, string $suffix): array
{
    $name = 'Legacy fiscal ' . $suffix;
    $stmt = $db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $account = (int)$db->insert_id;
    $stmt->close();

    $login = 'legacy-admin-' . strtolower($suffix) . '-' . bin2hex(random_bytes(3));
    $email = $login . '@test.local';
    $hash = password_hash('Legacy-2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $account, $login, $email, $hash);
    $stmt->execute();
    $user = (int)$db->insert_id;
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$user} WHERE id_cuenta={$account}");
    provisionTenantRoles($db, $account);
    $adminRole = (int)legacyScalar($db, "SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='Administrador'");
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$adminRole})");

    $viewerRoleName = 'Lector legacy ' . $suffix;
    $stmt = $db->prepare("INSERT INTO rol(id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla)
        VALUES (?,?,'Solo lectura fiscal',1,0,0)");
    $stmt->bind_param('is', $account, $viewerRoleName);
    $stmt->execute();
    $viewerRole = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO rol_permiso(id_rol,id_permiso)
        SELECT ?,id_permiso FROM permiso WHERE modulo='facturas' AND accion='ver'");
    $stmt->bind_param('i', $viewerRole);
    $stmt->execute();
    $stmt->close();
    $viewerName = 'legacy-viewer-' . strtolower($suffix) . '-' . bin2hex(random_bytes(3));
    $viewerEmail = $viewerName . '@test.local';
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $account, $viewerName, $viewerEmail, $hash);
    $stmt->execute();
    $viewer = (int)$db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$viewer},{$viewerRole})");
    return ['account'=>$account,'user'=>$user,'viewer'=>$viewer,'roles'=>[$adminRole,$viewerRole]];
}

function insertLegacyDocument(
    mysqli $db,
    array $tenant,
    string $type,
    string $number,
    float $quantity,
    float $total,
    string $product,
    ?int $origin = null,
    string $state = 'EMITIDA'
): array {
    $net = round($total / 1.19, 2);
    $tax = round($total - $net, 2);
    $subtotal = $total;
    $zero = 0.0;
    $user = (int)$tenant['user'];
    $account = (int)$tenant['account'];
    $stmt = $db->prepare("INSERT INTO factura
        (id_cuenta,id_user,id_factura_origen,folio,numero,tipo,estado,fecha_emision,
         subtotal,descuento,neto,iva,total,pagado,saldo)
        VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?,?,?,0,?)");
    $folio = substr($number, 0, 20);
    $stmt->bind_param(
        'iiissssdddddd',
        $account,
        $user,
        $origin,
        $folio,
        $number,
        $type,
        $state,
        $subtotal,
        $zero,
        $net,
        $tax,
        $total,
        $total
    );
    $stmt->execute();
    $invoice = (int)$db->insert_id;
    $stmt->close();
    $unitPrice = round($total / $quantity, 2);
    $stmt = $db->prepare('INSERT INTO factura_detalle
        (id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total)
        VALUES (?,NULL,?,?,?,?,?,?,?)');
    $stmt->bind_param('isdddddd', $invoice, $product, $quantity, $unitPrice, $zero, $net, $tax, $total);
    $stmt->execute();
    $detail = (int)$db->insert_id;
    $stmt->close();
    return ['invoice'=>$invoice,'detail'=>$detail];
}

function addLegacyPending(mysqli $db, array $tenant, int $note, string $state = 'SIN_CANDIDATO'): void
{
    $account = (int)$tenant['account'];
    $detail = 'Fixture legacy pendiente';
    $stmt = $db->prepare('INSERT INTO factura_legacy_reconciliacion
        (id_cuenta,id_factura_nota,estado,bloquea_nueva_nc,resuelta_manual,detalle)
        VALUES (?,?,?,1,0,?)');
    $stmt->bind_param('iiss', $account, $note, $state, $detail);
    $stmt->execute();
    $stmt->close();
}

$root = dirname(__DIR__);
$envPath = argument('--env') ?? 'C:/laragon/tmp/bluecat-sprint1-test.env';
if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $envPath)) {
    $envPath = $root . DIRECTORY_SEPARATOR . $envPath;
}
if (!is_file($envPath)) throw new RuntimeException("No existe el entorno aislado: {$envPath}");
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim($value));
}
require_once $root . '/assets/api/_db.php';

$db = getDB();
$tenants = [];
try {
    $tenantA = createLegacyTenant($db, 'A-' . bin2hex(random_bytes(2)));
    $tenants[] = $tenantA;
    $tenantB = createLegacyTenant($db, 'B-' . bin2hex(random_bytes(2)));
    $tenants[] = $tenantB;

    $originGood = insertLegacyDocument($db, $tenantA, 'FACTURA', 'F-LEGACY-GOOD', 5.0, 500.0, 'Producto inequívoco');
    $legacyGood = insertLegacyDocument($db, $tenantA, 'NOTA_CREDITO', 'NC-LEGACY-GOOD', 1.0, 100.0, 'Producto inequívoco');
    addLegacyPending($db, $tenantA, $legacyGood['invoice']);

    $originOver = insertLegacyDocument($db, $tenantA, 'FACTURA', 'F-LEGACY-LIMIT', 2.0, 200.0, 'Producto limitado');
    $existingCredit = insertLegacyDocument(
        $db,
        $tenantA,
        'NOTA_CREDITO',
        'NC-LEGACY-ACTIVE',
        1.5,
        150.0,
        'Producto limitado',
        $originOver['invoice']
    );
    $db->query("UPDATE factura_detalle SET id_detalle_origen={$originOver['detail']} WHERE id_factura={$existingCredit['invoice']}");
    $legacyOver = insertLegacyDocument($db, $tenantA, 'NOTA_CREDITO', 'NC-LEGACY-OVER', 1.0, 100.0, 'Producto limitado');
    addLegacyPending($db, $tenantA, $legacyOver['invoice']);

    $originAmbiguous = insertLegacyDocument($db, $tenantA, 'FACTURA', 'F-LEGACY-AMB', 2.0, 200.0, 'Producto repetido');
    $zero = 0.0;
    $ambiguousQuantity = 2.0;
    $ambiguousUnit = 100.0;
    $ambiguousNet = 168.07;
    $ambiguousTax = 31.93;
    $ambiguousTotal = 200.0;
    $ambiguousProduct = 'Producto repetido';
    $stmt = $db->prepare('INSERT INTO factura_detalle
        (id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total)
        VALUES (?,NULL,?,?,?,?,?,?,?)');
    $stmt->bind_param(
        'isdddddd',
        $originAmbiguous['invoice'],
        $ambiguousProduct,
        $ambiguousQuantity,
        $ambiguousUnit,
        $zero,
        $ambiguousNet,
        $ambiguousTax,
        $ambiguousTotal
    );
    $stmt->execute();
    $stmt->close();
    $legacyAmbiguous = insertLegacyDocument($db, $tenantA, 'NOTA_CREDITO', 'NC-LEGACY-AMB', 1.0, 100.0, 'Producto repetido');
    addLegacyPending($db, $tenantA, $legacyAmbiguous['invoice']);

    $legacyDebit = insertLegacyDocument($db, $tenantA, 'NOTA_DEBITO', 'ND-LEGACY-ORPHAN', 1.0, 25.0, 'Ajuste legacy');
    addLegacyPending($db, $tenantA, $legacyDebit['invoice']);
    $foreignNote = insertLegacyDocument($db, $tenantB, 'NOTA_CREDITO', 'NC-FOREIGN', 1.0, 10.0, 'Producto externo');
    addLegacyPending($db, $tenantB, $foreignNote['invoice']);

    $pending = callLegacyApi(
        $root,
        $envPath,
        $tenantA['user'],
        'GET',
        ['accion'=>'legacy_pendientes']
    );
    $pendingIds = array_map('intval', array_column($pending['data'] ?? [], 'id_factura_nota'));
    checkLegacy(in_array($legacyGood['invoice'], $pendingIds, true), 'el administrador lista sus notas legacy pendientes');
    checkLegacy(!in_array($foreignNote['invoice'], $pendingIds, true), 'el listado no expone pendientes de otro tenant');

    $denied = callLegacyApi(
        $root,
        $envPath,
        $tenantA['viewer'],
        'GET',
        ['accion'=>'legacy_pendientes']
    );
    checkLegacy(
        str_contains(strtolower((string)($denied['message'] ?? $denied['error'] ?? '')), 'permiso'),
        'facturas.ver no permite acceder a la conciliación administrativa'
    );

    $foreignDenied = callLegacyApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'legacy_conciliar',
        'id_factura_nota'=>$foreignNote['invoice'],
        'decision'=>'sin_origen',
        'motivo'=>'Intento cruzado',
    ]);
    checkLegacy(empty($foreignDenied['success']), 'la conciliación rechaza una nota de otro tenant');

    $unrelatedCredit = callLegacyApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'nota_credito',
        'id_factura'=>$originGood['invoice'],
        'idempotency_key'=>'legacy-unrelated-' . bin2hex(random_bytes(8)),
        'motivo'=>'Prueba de bloqueo acotado',
        'items'=>[['id_factura_detalle'=>$originGood['detail'],'cantidad'=>'0.500']],
    ]);
    checkLegacy(
        !empty($unrelatedCredit['success']),
        'un SIN_CANDIDATO ajeno no bloquea notas de crédito sobre todas las facturas'
    );

    $linked = callLegacyApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'legacy_conciliar',
        'id_factura_nota'=>$legacyGood['invoice'],
        'decision'=>'vincular',
        'id_factura_origen'=>$originGood['invoice'],
        'motivo'=>'Se verificó el libro fiscal y el producto coincide de forma inequívoca',
    ]);
    checkLegacy(!empty($linked['success']), 'una nota legacy inequívoca se vincula manualmente');
    checkLegacy(
        (int)legacyScalar($db, "SELECT id_factura_origen FROM factura WHERE id_factura={$legacyGood['invoice']}")
            === $originGood['invoice'],
        'la nota conserva la FACTURA de origen del mismo tenant'
    );
    checkLegacy(
        (int)legacyScalar($db, "SELECT id_detalle_origen FROM factura_detalle WHERE id_factura={$legacyGood['invoice']}")
            === $originGood['detail'],
        'el detalle se mapea solo al candidato inequívoco'
    );
    checkLegacy(
        (int)legacyScalar($db, "SELECT COUNT(*) FROM factura_historial
            WHERE id_factura={$legacyGood['invoice']} AND accion='CONCILIACION_LEGACY_VINCULADA'") === 1,
        'la decisión de vinculación queda en historial'
    );

    $overRejected = callLegacyApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'legacy_conciliar',
        'id_factura_nota'=>$legacyOver['invoice'],
        'decision'=>'vincular',
        'id_factura_origen'=>$originOver['invoice'],
        'motivo'=>'Intento que supera el saldo acreditable',
    ]);
    checkLegacy(empty($overRejected['success']), 'se rechaza una NC que excede cantidades o importes ya acreditados');
    checkLegacy(
        legacyScalar($db, "SELECT id_factura_origen FROM factura WHERE id_factura={$legacyOver['invoice']}") === null,
        'el rechazo revierte íntegramente la vinculación'
    );

    $ambiguousRejected = callLegacyApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'legacy_conciliar',
        'id_factura_nota'=>$legacyAmbiguous['invoice'],
        'decision'=>'vincular',
        'id_factura_origen'=>$originAmbiguous['invoice'],
        'motivo'=>'Prueba con dos líneas originales idénticas',
    ]);
    checkLegacy(
        empty($ambiguousRejected['success'])
            && str_contains(strtolower((string)($ambiguousRejected['error'] ?? '')), 'inequívoco'),
        'una línea con más de un candidato nunca se mapea automáticamente'
    );

    $withoutOrigin = callLegacyApi($root, $envPath, $tenantA['user'], 'POST', [], [
        'accion'=>'legacy_conciliar',
        'id_factura_nota'=>$legacyDebit['invoice'],
        'decision'=>'sin_origen',
        'motivo'=>'Documento histórico revisado; no existe comprobante original recuperable',
    ]);
    checkLegacy(!empty($withoutOrigin['success']), 'el administrador confirma explícitamente una nota sin origen');
    checkLegacy(
        (int)legacyScalar($db, "SELECT resuelta_manual FROM factura_legacy_reconciliacion
            WHERE id_cuenta={$tenantA['account']} AND id_factura_nota={$legacyDebit['invoice']}") === 1,
        'la confirmación sin origen cierra la conciliación y elimina el bloqueo'
    );

    echo "OK conciliación fiscal legacy\n";
} finally {
    foreach (array_reverse($tenants) as $tenant) {
        $account = (int)$tenant['account'];
        try {
            $db->query("UPDATE factura_detalle d JOIN factura f ON f.id_factura=d.id_factura
                SET d.id_detalle_origen=NULL WHERE f.id_cuenta={$account}");
            $db->query("UPDATE factura SET id_factura_origen=NULL WHERE id_cuenta={$account}");
            $db->query("DELETE FROM factura WHERE id_cuenta={$account}");
            $db->query("DELETE ur FROM usuario_rol ur JOIN usuario u ON u.id_user=ur.id_user WHERE u.id_cuenta={$account}");
            $db->query("DELETE rp FROM rol_permiso rp JOIN rol r ON r.id_rol=rp.id_rol WHERE r.id_cuenta={$account}");
            $db->query("DELETE FROM rol WHERE id_cuenta={$account}");
            $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta={$account}");
            $db->query("DELETE FROM usuario WHERE id_cuenta={$account}");
            $db->query("DELETE FROM pos_folio_contador WHERE id_cuenta={$account}");
            $db->query("DELETE FROM cuenta WHERE id_cuenta={$account}");
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, "WARN cleanup tenant {$account}: {$cleanupError->getMessage()}\n");
        }
    }
    $db->close();
}
