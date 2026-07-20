<?php
declare(strict_types=1);

function legacyUpgradeOption(string $name): ?string
{
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if (str_starts_with($argument, $name . '=')) {
            return substr($argument, strlen($name) + 1);
        }
    }
    return null;
}

function legacyUpgradeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}

function legacyUpgradeScalar(mysqli $db, string $sql): mixed
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
function legacyUpgradeRun(array $command, string $cwd): array
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

$root = dirname(__DIR__);
$env = legacyUpgradeOption('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $env) ? $env : $root . '/' . $env;
if (!is_file($envPath)) {
    throw new RuntimeException("Falta el archivo de entorno {$envPath}.");
}

putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') {
    throw new RuntimeException('La prueba de upgrade solo puede ejecutarse con APP_ENV=test.');
}

$db = getDB();
$migration = '026_inventory_integrity.sql';
$accountId = 0;
$userId = 0;
$productId = 0;
$warehouseId = 0;
$stockId = 0;
$ledgerId = 0;
$inventoryId = 0;
$countId = 0;

$productColumns = ['stock_minimo', 'stock_maximo', 'punto_reposicion', 'stock_seguridad'];
$stockColumns = ['disponible', 'reservado', 'comprometido', 'en_transito', 'danado', 'bloqueado', 'devuelto', 'produccion'];
$ledgerColumns = ['entrada', 'salida', 'saldo'];
$requiredColumns = [
    'producto' => $productColumns,
    'stock' => $stockColumns,
    'kardex' => $ledgerColumns,
    'conteo_inventario' => ['cantidad_contada'],
];

try {
    legacyUpgradeAssert(
        (int) legacyUpgradeScalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='026_inventory_integrity.sql'") === 1,
        'la base de partida ya tiene aplicada la migracion 026'
    );

    $db->query(
        'ALTER TABLE producto '
        . implode(', ', array_map(static fn(string $column): string => "MODIFY {$column} DECIMAL(18,3) NULL DEFAULT NULL", $productColumns))
    );
    $db->query(
        'ALTER TABLE stock '
        . implode(', ', array_map(static fn(string $column): string => "MODIFY {$column} DECIMAL(18,3) NULL DEFAULT NULL", $stockColumns))
    );
    $db->query(
        'ALTER TABLE kardex '
        . implode(', ', array_map(static fn(string $column): string => "MODIFY {$column} DECIMAL(18,3) NULL DEFAULT NULL", $ledgerColumns))
    );
    $db->query('ALTER TABLE conteo_inventario MODIFY cantidad_contada DECIMAL(18,3) NULL DEFAULT NULL');
    legacyUpgradeAssert(true, 'simula el contrato nullable de una instalacion legacy');

    $suffix = bin2hex(random_bytes(5));
    $accountName = 'Legacy upgrade ' . $suffix;
    $stmt = $db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
    $stmt->bind_param('s', $accountName);
    $stmt->execute();
    $accountId = (int) $db->insert_id;
    $stmt->close();

    $username = 'legacy-' . $suffix;
    $email = $username . '@test.local';
    $password = password_hash('Legacy-Upgrade-2026!', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $stmt->bind_param('isss', $accountId, $username, $email, $password);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();

    $productName = 'Fixture legacy ' . $suffix;
    $stmt = $db->prepare(
        'INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,stock_minimo,stock_maximo,punto_reposicion,stock_seguridad) '
        . 'VALUES (?,?,?,1000,NULL,NULL,NULL,NULL)'
    );
    $stmt->bind_param('iis', $userId, $accountId, $productName);
    $stmt->execute();
    $productId = (int) $db->insert_id;
    $stmt->close();

    $warehouseCode = 'LEG-' . strtoupper(substr($suffix, 0, 8));
    $warehouseName = 'Bodega legacy ' . $suffix;
    $stmt = $db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
    $stmt->bind_param('iiss', $userId, $accountId, $warehouseCode, $warehouseName);
    $stmt->execute();
    $warehouseId = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare(
        'INSERT INTO stock(id_producto,id_bodega,disponible,reservado,comprometido,en_transito,danado,bloqueado,devuelto,produccion) '
        . 'VALUES (?,?,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)'
    );
    $stmt->bind_param('ii', $productId, $warehouseId);
    $stmt->execute();
    $stockId = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT INTO kardex(id_producto,id_bodega,tipo_movimiento,entrada,salida,saldo,id_user,observaciones) "
        . "VALUES (?,?,'LEGACY',NULL,NULL,NULL,?,'Fixture upgrade 026')"
    );
    $stmt->bind_param('iii', $productId, $warehouseId, $userId);
    $stmt->execute();
    $ledgerId = (int) $db->insert_id;
    $stmt->close();

    $inventoryCode = 'INV-LEG-' . strtoupper(substr($suffix, 0, 8));
    $stmt = $db->prepare(
        "INSERT INTO inventario_fisico(codigo,tipo,id_bodega,id_user,estado,fecha_inicio) VALUES (?,'BODEGA',?,?,'EN_PROGRESO',NOW())"
    );
    $stmt->bind_param('sii', $inventoryCode, $warehouseId, $userId);
    $stmt->execute();
    $inventoryId = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare(
        'INSERT INTO conteo_inventario(id_inventario,id_producto,id_bodega,stock_sistema,cantidad_contada) VALUES (?,?,?,0,NULL)'
    );
    $stmt->bind_param('iii', $inventoryId, $productId, $warehouseId);
    $stmt->execute();
    $countId = (int) $db->insert_id;
    $stmt->close();

    legacyUpgradeAssert(
        (int) legacyUpgradeScalar(
            $db,
            "SELECT ((stock_minimo IS NULL)+(stock_maximo IS NULL)+(punto_reposicion IS NULL)+(stock_seguridad IS NULL)) FROM producto WHERE id_producto={$productId}"
        ) === 4,
        'el fixture conserva NULL en producto antes de actualizar'
    );
    legacyUpgradeAssert(
        (int) legacyUpgradeScalar(
            $db,
            "SELECT ((disponible IS NULL)+(reservado IS NULL)+(comprometido IS NULL)+(en_transito IS NULL)+(danado IS NULL)+(bloqueado IS NULL)+(devuelto IS NULL)+(produccion IS NULL)) FROM stock WHERE id_stock={$stockId}"
        ) === 8,
        'el fixture conserva NULL en stock antes de actualizar'
    );

    $stmt = $db->prepare('DELETE FROM schema_migration WHERE version=?');
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();
    legacyUpgradeAssert($removed === 1, 'marca 026 como pendiente para simular el upgrade');

    [$exitCode, $stdout, $stderr] = legacyUpgradeRun(
        [PHP_BINARY, $root . '/scripts/migrate.php', '--env=' . $envPath],
        $root
    );
    if ($exitCode !== 0) {
        throw new RuntimeException("El upgrade 026 fallo ({$exitCode}): {$stdout} {$stderr}");
    }
    legacyUpgradeAssert(str_contains($stdout, 'APPLY 026_inventory_integrity.sql'), 'el migrador real reaplica 026');

    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $metadata = $db->query(
                "SELECT IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND COLUMN_NAME='{$column}'"
            )->fetch_assoc();
            legacyUpgradeAssert(
                ($metadata['IS_NULLABLE'] ?? '') === 'NO' && strtolower((string) ($metadata['COLUMN_TYPE'] ?? '')) === 'decimal(18,3)',
                "{$table}.{$column} queda DECIMAL(18,3) NOT NULL"
            );
        }
    }

    $fixtureChecks = [
        "SELECT COUNT(*) FROM producto WHERE id_producto={$productId} AND stock_minimo=0 AND stock_maximo=0 AND punto_reposicion=0 AND stock_seguridad=0",
        "SELECT COUNT(*) FROM stock WHERE id_stock={$stockId} AND disponible=0 AND reservado=0 AND comprometido=0 AND en_transito=0 AND danado=0 AND bloqueado=0 AND devuelto=0 AND produccion=0",
        "SELECT COUNT(*) FROM kardex WHERE id_kardex={$ledgerId} AND entrada=0 AND salida=0 AND saldo=0",
        "SELECT COUNT(*) FROM conteo_inventario WHERE id_conteo={$countId} AND cantidad_contada=0",
    ];
    foreach ($fixtureChecks as $index => $sql) {
        legacyUpgradeAssert((int) legacyUpgradeScalar($db, $sql) === 1, 'backfill legacy correcto #' . ($index + 1));
    }
    legacyUpgradeAssert(
        (int) legacyUpgradeScalar($db, "SELECT COUNT(*) FROM schema_migration WHERE version='026_inventory_integrity.sql'") === 1,
        'el upgrade registra nuevamente la migracion 026'
    );

    echo "OK upgrade legacy 025 a 026 verificado.\n";
} finally {
    if ($countId > 0) {
        $db->query("DELETE FROM conteo_inventario WHERE id_conteo={$countId}");
    }
    if ($inventoryId > 0) {
        $db->query("DELETE FROM inventario_fisico WHERE id_inventario={$inventoryId}");
    }
    if ($ledgerId > 0) {
        $db->query("DELETE FROM kardex WHERE id_kardex={$ledgerId}");
    }
    if ($stockId > 0) {
        $db->query("DELETE FROM stock WHERE id_stock={$stockId}");
    }
    if ($productId > 0) {
        $db->query("DELETE FROM producto WHERE id_producto={$productId}");
    }
    if ($warehouseId > 0) {
        $db->query("DELETE FROM bodega WHERE id_bodega={$warehouseId}");
    }
    if ($userId > 0) {
        $db->query("DELETE FROM usuario WHERE id_user={$userId}");
    }
    if ($accountId > 0) {
        $db->query("DELETE FROM cuenta WHERE id_cuenta={$accountId}");
    }
}
