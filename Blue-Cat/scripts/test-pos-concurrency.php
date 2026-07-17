<?php
declare(strict_types=1);

function argValue(string $name): ?string {
    foreach (array_slice($_SERVER['argv'], 1) as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function apiCommand(string $root, string $env, int $user, array $body): array {
    return [
        PHP_BINARY,
        $root . '/scripts/invoke-api-test.php',
        '--env=' . $env,
        '--endpoint=pos.php',
        '--user=' . $user,
        '--method=POST',
        '--body=' . base64_encode((string) json_encode($body)),
    ];
}

function invoke(array $command): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar la API de prueba.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($process);
    $result = json_decode((string) $stdout, true);
    if (!is_array($result)) throw new RuntimeException("Respuesta API inválida: {$stdout} {$stderr}");
    return $result;
}

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$root = dirname(__DIR__);
$env = argValue('--env') ?? '.env.phase2-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $env) ? $env : $root . '/' . $env;
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');

$db = getDB();
$account = $userA = $userB = $product = $warehouse = 0;
$cashIds = [];
$sessionIds = [];

try {
    $name = 'POS concurrencia ' . bin2hex(random_bytes(4));
    $stmt = $db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
    $stmt->bind_param('s', $name); $stmt->execute(); $account = (int) $db->insert_id; $stmt->close();

    $hash = password_hash('Concurrency-2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $login = 'pos-a-' . bin2hex(random_bytes(3)); $mail = $login . '@test.local';
    $stmt->bind_param('isss', $account, $login, $mail, $hash); $stmt->execute(); $userA = (int) $db->insert_id;
    $login = 'pos-b-' . bin2hex(random_bytes(3)); $mail = $login . '@test.local';
    $stmt->bind_param('isss', $account, $login, $mail, $hash); $stmt->execute(); $userB = (int) $db->insert_id;
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$userA} WHERE id_cuenta={$account}");
    provisionTenantRoles($db, $account);
    $role = (int) $db->query("SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='Administrador'")->fetch_row()[0];
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$userA},{$role}),({$userB},{$role})");

    $productName = 'Última unidad concurrente';
    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,tipo_venta) VALUES (?,?,?,1000,'UNIDAD')");
    $stmt->bind_param('iis', $userA, $account, $productName); $stmt->execute(); $product = (int) $db->insert_id; $stmt->close();
    $code = 'CON-' . bin2hex(random_bytes(3)); $warehouseName = 'Bodega concurrencia';
    $stmt = $db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
    $stmt->bind_param('iiss', $userA, $account, $code, $warehouseName); $stmt->execute(); $warehouse = (int) $db->insert_id; $stmt->close();
    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$product},{$warehouse},1)");

    foreach ([[$userA, 'A'], [$userB, 'B']] as [$user, $suffix]) {
        $boxCode='CON-' . $suffix . '-' . bin2hex(random_bytes(2));
        $open = invoke(apiCommand($root, $envPath, $user, [
            'action' => 'caja_abrir', 'codigo' => $boxCode,
            'nombre' => 'Caja ' . $suffix, 'monto_apertura' => 0,
        ]));
        expect(!empty($open['success']), "abre caja POS {$suffix}");
        $cashIds[$user] = (int) $open['caja']['id_caja'];
        $sessionIds[] = (int) $open['caja']['id_sesion'];
        if ($suffix==='A') {
            $occupied=invoke(apiCommand($root,$envPath,$userB,[
                'action'=>'caja_abrir','codigo'=>$boxCode,'nombre'=>'Caja ocupada','monto_apertura'=>0
            ]));
            expect(isset($occupied['error']),'otro cajero no puede abrir la misma caja física');
        }
    }

    $processes = [];
    foreach ([$userA, $userB] as $user) {
        $body = [
            'action' => 'venta_crear', 'id_caja' => $cashIds[$user],
            'items' => [['id_producto' => $product, 'cantidad' => 1, 'precio_unitario' => 1000]],
            'pagos' => [['metodo' => 'EFECTIVO', 'monto' => 1000]],
            'tipo_documento' => 'BOLETA',
            'idempotency_key' => 'concurrent-sale-' . bin2hex(random_bytes(12)),
        ];
        $pipes = [];
        $process = proc_open(apiCommand($root, $envPath, $user, $body), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar venta concurrente.');
        $processes[] = [$process, $pipes];
    }

    $responses = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) throw new RuntimeException("Respuesta concurrente inválida: {$stdout} {$stderr}");
        $responses[] = $decoded;
    }

    $successful = array_values(array_filter($responses, fn(array $r): bool => !empty($r['success'])));
    $failed = array_values(array_filter($responses, fn(array $r): bool => isset($r['error'])));
    expect(count($successful) === 1 && count($failed) === 1, 'solo uno de dos POS vende la última unidad');
    $stock = (float) $db->query("SELECT disponible FROM stock WHERE id_producto={$product} AND id_bodega={$warehouse}")->fetch_row()[0];
    expect(abs($stock) < 0.0001, 'el stock termina en cero y nunca negativo');
    $orders = (int) $db->query("SELECT COUNT(*) FROM pedido WHERE id_cuenta={$account}")->fetch_row()[0];
    expect($orders === 1, 'solo existe un pedido confirmado');
    echo "OK concurrencia POS verificada.\n";
} finally {
    if ($account > 0) {
        $db->query("DELETE FROM pos_movimiento_caja WHERE id_caja IN (SELECT id_caja FROM pos_caja WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_venta_idempotencia WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pos_documento_snapshot WHERE id_cuenta={$account}");
        $db->query("DELETE FROM metodo_de_pago WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM detalle_pedido WHERE id_pedido IN (SELECT id_pedido FROM pedido WHERE id_cuenta={$account})");
        $db->query("DELETE FROM pos_auditoria WHERE id_user IN ({$userA},{$userB})");
        $db->query("DELETE FROM pos_caja WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pos_caja_fisica WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pos_folio_contador WHERE id_cuenta={$account}");
        $db->query("DELETE FROM pedido WHERE id_cuenta={$account}");
        $db->query("DELETE FROM sesion WHERE id_cuenta={$account}");
        if ($product > 0) $db->query("DELETE FROM kardex WHERE id_producto={$product}");
        if ($warehouse > 0) $db->query("DELETE FROM stock WHERE id_bodega={$warehouse}");
        if ($product > 0) $db->query("DELETE FROM producto WHERE id_producto={$product}");
        if ($warehouse > 0) $db->query("DELETE FROM bodega WHERE id_bodega={$warehouse}");
        $db->query("DELETE FROM usuario_rol WHERE id_user IN ({$userA},{$userB})");
        $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta={$account}");
        $db->query("DELETE FROM usuario WHERE id_user IN ({$userA},{$userB})");
        $db->query("DELETE FROM rol WHERE id_cuenta={$account}");
        $db->query("DELETE FROM cuenta WHERE id_cuenta={$account}");
    }
}
