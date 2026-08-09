<?php
declare(strict_types=1);

function argumentValue(string $name): ?string
{
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if (str_starts_with($argument, $name . '=')) {
            return substr($argument, strlen($name) + 1);
        }
    }
    return null;
}

function expectDashboard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}

function amountInCents(mixed $value): int
{
    return (int)round(((float)$value) * 100);
}

/** @return array{account:int,user:int} */
function createDashboardTenant(mysqli $db, string $label, bool $withRoles): array
{
    $name = 'Dashboard notas ' . $label . ' ' . bin2hex(random_bytes(3));
    $stmt = $db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $account = (int)$db->insert_id;
    $stmt->close();

    $login = 'dashboard-' . strtolower($label) . '-' . bin2hex(random_bytes(3));
    $email = $login . '@test.local';
    $password = password_hash('Dashboard-2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $account, $login, $email, $password);
    $stmt->execute();
    $user = (int)$db->insert_id;
    $stmt->close();

    $db->query("UPDATE cuenta SET id_usuario_propietario={$user} WHERE id_cuenta={$account}");
    if ($withRoles) {
        provisionTenantRoles($db, $account);
        $role = $db->query("SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='Administrador' LIMIT 1")->fetch_assoc();
        if (!$role) {
            throw new RuntimeException('No se pudo provisionar el rol Administrador.');
        }
        $roleId = (int)$role['id_rol'];
        $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$roleId})");
    }

    return ['account' => $account, 'user' => $user];
}

function createDashboardInvoice(
    mysqli $db,
    array $tenant,
    string $type,
    string $state,
    float $total,
    ?int $origin = null
): int {
    $folio = (string)random_int(1000000, 1900000000);
    $number = substr($type, 0, 2) . '-' . $folio;
    $user = (int)$tenant['user'];
    $account = (int)$tenant['account'];
    $zero = 0.0;
    $stmt = $db->prepare(
        'INSERT INTO factura
         (id_user,id_cuenta,id_factura_origen,folio,numero,tipo,estado,fecha_emision,fecha_vencimiento,subtotal,descuento,neto,iva,total,pagado,saldo)
         VALUES (?,?,?,?,?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),?,0,?,0,?,0,?)'
    );
    $stmt->bind_param(
        'iiissssdddd',
        $user,
        $account,
        $origin,
        $folio,
        $number,
        $type,
        $state,
        $total,
        $total,
        $total,
        $zero
    );
    $stmt->execute();
    $invoice = (int)$db->insert_id;
    $stmt->close();
    return $invoice;
}

function createDashboardDetail(
    mysqli $db,
    int $invoice,
    string $product,
    float $quantity,
    float $total,
    ?int $origin = null
): int {
    $unitPrice = $quantity === 0.0 ? 0.0 : $total / $quantity;
    $stmt = $db->prepare(
        'INSERT INTO factura_detalle
         (id_factura,id_detalle_origen,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total)
         VALUES (?,?,NULL,?,?,?,0,?,0,?)'
    );
    $stmt->bind_param('iisdddd', $invoice, $origin, $product, $quantity, $unitPrice, $total, $total);
    $stmt->execute();
    $detail = (int)$db->insert_id;
    $stmt->close();
    return $detail;
}

function invokeDashboard(string $root, string $env, int $user): array
{
    $command = [
        PHP_BINARY,
        $root . '/scripts/invoke-api-test.php',
        '--env=' . $env,
        '--endpoint=dashboard.php',
        '--user=' . $user,
        '--method=GET',
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo invocar dashboard.php.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $response = json_decode((string)$stdout, true);
    if (!is_array($response) || isset($response['error'])) {
        throw new RuntimeException("Respuesta dashboard invalida ({$exitCode}): {$stdout} {$stderr}");
    }
    return $response;
}

function cleanupDashboardTenant(mysqli $db, array $tenant): void
{
    $account = (int)($tenant['account'] ?? 0);
    $user = (int)($tenant['user'] ?? 0);
    if ($account <= 0) {
        return;
    }
    $db->query("DELETE d FROM factura_detalle d JOIN factura f ON f.id_factura=d.id_factura WHERE f.id_cuenta={$account} AND d.id_detalle_origen IS NOT NULL");
    $db->query("DELETE d FROM factura_detalle d JOIN factura f ON f.id_factura=d.id_factura WHERE f.id_cuenta={$account}");
    $db->query("DELETE FROM factura WHERE id_cuenta={$account} AND id_factura_origen IS NOT NULL");
    $db->query("DELETE FROM factura WHERE id_cuenta={$account}");
    if ($user > 0) {
        $db->query("DELETE FROM usuario_rol WHERE id_user={$user}");
    }
    $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta={$account}");
    if ($user > 0) {
        $db->query("DELETE FROM usuario WHERE id_user={$user}");
    }
    $db->query("DELETE FROM rol WHERE id_cuenta={$account}");
    $db->query("DELETE FROM cuenta WHERE id_cuenta={$account}");
}

$root = dirname(__DIR__);
$env = argumentValue('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $env) ? $env : $root . '/' . $env;
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') {
    throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');
}

$db = getDB();
$tenant = $foreignTenant = [];
try {
    $databaseToday = (string)($db->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d') AS today")->fetch_assoc()['today'] ?? '');
    expectDashboard($databaseToday === date('Y-m-d'), 'PHP y MySQL comparten el mismo dia operativo');
    $tenant = createDashboardTenant($db, 'A', true);
    $foreignTenant = createDashboardTenant($db, 'B', false);
    $product = 'Producto dashboard firmado ' . bin2hex(random_bytes(3));

    $invoice = createDashboardInvoice($db, $tenant, 'FACTURA', 'EMITIDA', 100.25);
    $invoiceDetail = createDashboardDetail($db, $invoice, $product, 3.500, 100.25);

    $credit = createDashboardInvoice($db, $tenant, 'NOTA_CREDITO', 'EMITIDA', 30.10, $invoice);
    createDashboardDetail($db, $credit, $product, 1.250, 30.10, $invoiceDetail);

    $debit = createDashboardInvoice($db, $tenant, 'NOTA_DEBITO', 'EMITIDA', 10.05, $invoice);
    createDashboardDetail($db, $debit, $product, 0.500, 10.05);

    $annulledCredit = createDashboardInvoice($db, $tenant, 'NOTA_CREDITO', 'ANULADA', 90.00, $invoice);
    createDashboardDetail($db, $annulledCredit, $product, 3.000, 90.00, $invoiceDetail);

    $rejectedDebit = createDashboardInvoice($db, $tenant, 'NOTA_DEBITO', 'RECHAZADA', 50.00, $invoice);
    createDashboardDetail($db, $rejectedDebit, $product, 1.000, 50.00);

    $foreignInvoice = createDashboardInvoice($db, $foreignTenant, 'FACTURA', 'EMITIDA', 999.99);
    createDashboardDetail($db, $foreignInvoice, $product, 99.000, 999.99);

    $dashboard = invokeDashboard($root, $envPath, (int)$tenant['user']);
    expectDashboard((int)$dashboard['hoy_cantidad'] === 3, 'cuenta los tres documentos vigentes sin incluir anulados ni rechazados');
    expectDashboard(
        amountInCents($dashboard['hoy_monto']) === 8020
        && amountInCents($dashboard['mes_monto']) === 8020,
        'ingreso de hoy y del mes suma FACTURA y ND, y resta NC con centavos'
    );

    $daily = array_values(array_filter(
        (array)$dashboard['diario'],
        static fn(array $row): bool => ($row['fecha'] ?? '') === date('Y-m-d')
    ));
    expectDashboard(
        count($daily) === 1
        && (int)$daily[0]['cantidad'] === 3
        && amountInCents($daily[0]['monto']) === 8020,
        'serie diaria aplica el signo fiscal y conserva el conteo documental'
    );

    $monthly = array_values(array_filter(
        (array)$dashboard['mensual'],
        static fn(array $row): bool => ($row['mes'] ?? '') === date('Y-m')
    ));
    expectDashboard(
        count($monthly) === 1
        && (int)$monthly[0]['cantidad'] === 3
        && amountInCents($monthly[0]['monto']) === 8020,
        'serie mensual aplica el signo fiscal'
    );

    $products = array_values(array_filter(
        (array)$dashboard['top_productos'],
        static fn(array $row): bool => ($row['producto'] ?? '') === $product
    ));
    expectDashboard(
        count($products) === 1
        && abs((float)$products[0]['cantidad'] - 2.750) < 0.0001
        && amountInCents($products[0]['monto']) === 8020,
        'detalle vendido resta unidades e importe de NC y suma los de ND'
    );

    $emitted = array_values(array_filter(
        (array)$dashboard['por_estado'],
        static fn(array $row): bool => ($row['estado'] ?? '') === 'EMITIDA'
    ));
    expectDashboard(
        count($emitted) === 1
        && (int)$emitted[0]['cantidad'] === 3
        && amountInCents($emitted[0]['monto']) === 8020,
        'resumen por estado presenta el importe contable neto'
    );
    expectDashboard(
        (int)$dashboard['total_facturas'] === 5,
        'dashboard mantiene aislamiento por cuenta'
    );

    echo "OK dashboard con notas fiscales firmadas verificado.\n";
} finally {
    if ($tenant !== []) {
        cleanupDashboardTenant($db, $tenant);
    }
    if ($foreignTenant !== []) {
        cleanupDashboardTenant($db, $foreignTenant);
    }
}
