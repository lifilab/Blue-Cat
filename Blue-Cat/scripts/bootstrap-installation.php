<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';
$configFile = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) $envFile = substr($argument, 6);
    if (str_starts_with($argument, '--config=')) $configFile = substr($argument, 9);
}
if ($configFile === '' || !is_file($configFile)) {
    fwrite(STDERR, "Uso: php scripts/bootstrap-installation.php --env=<ruta> --config=<installation.json>\n");
    exit(2);
}
if (!is_file($envFile)) {
    fwrite(STDERR, "No existe el archivo de entorno indicado.\n");
    exit(2);
}

putenv('BLUECAT_ENV_FILE=' . $envFile);
require_once $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '_db.php';

function installValue(array $source, string $key, int $max, bool $required = true): string
{
    $value = trim((string)($source[$key] ?? ''));
    if ($required && $value === '') throw new InvalidArgumentException("Falta {$key}.");
    if (mb_strlen($value) > $max) throw new InvalidArgumentException("{$key} supera {$max} caracteres.");
    return $value;
}

function installCode(array $source, string $key, int $max): string
{
    $value = strtoupper(installValue($source, $key, $max));
    if (!preg_match('/^[A-Z0-9_-]{2,' . $max . '}$/', $value)) {
        throw new InvalidArgumentException("{$key} solo admite letras, números, guion y guion bajo.");
    }
    return $value;
}

/**
 * Provisiona la capacidad completa de la edición Beta local.
 *
 * El catálogo de módulos es versionado por migración. El plan y la suscripción
 * pertenecen a la instalación, por lo que se crean aquí. Una reparación solo
 * completa cuentas que nunca tuvieron una suscripción; no reactiva licencias
 * suspendidas ni vencidas.
 */
function provisionLocalBetaEntitlement(mysqli $db, int $accountId): void
{
    $moduleCodes = ['pos', 'inventario', 'crm', 'proveedores', 'facturas', 'empleados', 'ventas', 'configuracion'];
    $placeholders = implode(',', array_fill(0, count($moduleCodes), '?'));

    $planName = 'Blue-Cat Beta Completa';
    $stmt = $db->prepare('SELECT id_plan FROM plan WHERE nombre=? ORDER BY id_plan LIMIT 1');
    $stmt->bind_param('s', $planName);
    $stmt->execute();
    $planId = (int)($stmt->get_result()->fetch_assoc()['id_plan'] ?? 0);
    $stmt->close();
    if ($planId === 0) {
        $description = 'Licencia local completa para validación Beta';
        $price = 0;
        $maxCompanies = 1;
        $maxBranches = 4;
        $maxUsers = 10;
        $stmt = $db->prepare('INSERT INTO plan(nombre,descripcion,precio,max_empresas,max_sucursales,max_usuarios,activo) VALUES (?,?,?,?,?,?,1)');
        $stmt->bind_param('ssiiii', $planName, $description, $price, $maxCompanies, $maxBranches, $maxUsers);
        $stmt->execute();
        $planId = (int)$db->insert_id;
        $stmt->close();
    }

    $types = 'i' . str_repeat('s', count($moduleCodes));
    $params = array_merge([$planId], $moduleCodes);
    $stmt = $db->prepare("INSERT IGNORE INTO plan_modulo(id_plan,id_modulo)
        SELECT ?,id_modulo FROM modulo WHERE codigo IN ({$placeholders}) AND activo=1");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('SELECT COUNT(*) total
        FROM suscripcion s
        JOIN empresa e ON e.id_empresa=s.id_empresa
        WHERE e.id_cuenta=?');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $subscriptionCount = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    if ($subscriptionCount > 0) return;

    $stmt = $db->prepare('SELECT id_empresa FROM empresa WHERE id_cuenta=? AND activo=1 ORDER BY id_empresa LIMIT 1');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $companyId = (int)($stmt->get_result()->fetch_assoc()['id_empresa'] ?? 0);
    $stmt->close();
    if ($companyId === 0) {
        throw new RuntimeException('La cuenta no posee una empresa activa para asignar la licencia local.');
    }

    $stmt = $db->prepare("INSERT INTO suscripcion(id_empresa,id_plan,fecha_inicio,fecha_fin,estado)
        VALUES (?,?,CURDATE(),NULL,'activa')");
    $stmt->bind_param('ii', $companyId, $planId);
    $stmt->execute();
    $stmt->close();
}

$raw = file_get_contents($configFile);
$config = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($config)) {
    fwrite(STDERR, "El archivo de instalación no contiene JSON válido.\n");
    exit(2);
}

try {
    $company = is_array($config['company'] ?? null) ? $config['company'] : [];
    $admin = is_array($config['administrator'] ?? null) ? $config['administrator'] : [];
    $currency = is_array($config['currency'] ?? null) ? $config['currency'] : [];
    $tax = is_array($config['tax'] ?? null) ? $config['tax'] : [];
    $branch = is_array($config['branch'] ?? null) ? $config['branch'] : [];
    $warehouse = is_array($config['warehouse'] ?? null) ? $config['warehouse'] : [];
    $cash = is_array($config['cash_register'] ?? null) ? $config['cash_register'] : [];

    $legalName = installValue($company, 'legal_name', 200);
    $tradeName = installValue($company, 'trade_name', 200, false) ?: $legalName;
    $taxId = installValue($company, 'tax_id', 20);
    $activity = installValue($company, 'business_activity', 200, false);
    $address = installValue($company, 'address', 1000, false);
    $city = installValue($company, 'city', 100, false);
    $username = installValue($admin, 'username', 20);
    if (!preg_match('/^[a-zA-Z0-9._-]{3,20}$/', $username)) throw new InvalidArgumentException('Nombre de usuario inválido.');
    $fullName = installValue($admin, 'full_name', 100);
    $email = installValue($admin, 'email', 50);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Correo del administrador inválido.');
    $password = (string)($admin['password'] ?? '');
    $passwordErrors = securityPasswordErrors($password);
    if ($passwordErrors) throw new InvalidArgumentException('La contraseña requiere ' . implode(', ', $passwordErrors) . '.');

    $currencyCode = installCode($currency, 'code', 5);
    $currencyName = installValue($currency, 'name', 50);
    $currencySymbol = installValue($currency, 'symbol', 5);
    $currencyDecimals = max(0, min(4, (int)($currency['decimals'] ?? 0)));
    $taxCode = installCode($tax, 'code', 10);
    $taxName = installValue($tax, 'name', 50);
    $taxRate = (float)($tax['rate'] ?? -1);
    if ($taxRate < 0 || $taxRate > 100) throw new InvalidArgumentException('Tasa de impuesto fuera de rango.');
    $branchCode = installCode($branch, 'code', 20);
    $branchName = installValue($branch, 'name', 100);
    $warehouseCode = installCode($warehouse, 'code', 20);
    $warehouseName = installValue($warehouse, 'name', 100);
    $cashCode = installCode($cash, 'code', 40);
    $cashName = installValue($cash, 'name', 100);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, 'Configuración inválida: ' . $error->getMessage() . "\n");
    exit(2);
}

$db = getDB();
try {
    $migrationFiles = glob($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    $expectedMigrations = array_map('basename', $migrationFiles);
    sort($expectedMigrations, SORT_STRING);
    $appliedMigrations = [];
    $migrationResult = $db->query('SELECT version FROM schema_migration');
    while ($migrationRow = $migrationResult->fetch_assoc()) $appliedMigrations[] = (string)$migrationRow['version'];
    $missingMigrations = array_values(array_diff($expectedMigrations, $appliedMigrations));
    if ($missingMigrations) {
        throw new RuntimeException('Ejecute todas las migraciones antes del bootstrap. Faltan: '.implode(', ', $missingMigrations));
    }

    $existing = $db->query('SELECT installation_id,id_cuenta,id_user_admin,setup_completed FROM core_installation WHERE id_installation=1')->fetch_assoc();
    if ($existing) {
        $db->begin_transaction();
        provisionLocalBetaEntitlement($db, (int)$existing['id_cuenta']);
        $db->commit();
        echo json_encode(['ok'=>true,'status'=>'already-configured','installation_id'=>$existing['installation_id'],'id_cuenta'=>(int)$existing['id_cuenta']], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    $users = (int)$db->query('SELECT COUNT(*) total FROM usuario')->fetch_assoc()['total'];
    if ($users > 0) throw new RuntimeException('La base ya contiene usuarios y no posee marcador de instalación; use el proceso de migración, no el bootstrap inicial.');

    $db->begin_transaction();
    $stmt = $db->prepare("INSERT INTO cuenta(nombre,estado) VALUES (?,'ACTIVA')");
    $stmt->bind_param('s', $tradeName); $stmt->execute(); $accountId = (int)$db->insert_id; $stmt->close();

    $passwordHash = securityHashPassword($password);
    $stmt = $db->prepare("INSERT INTO usuario(id_cuenta,nombre,nombre_completo,correo,password,cargo,validar_sesion,requiere_cambio_password,activo) VALUES (?,?,?,?,?,'Administrador',0,0,1)");
    $stmt->bind_param('issss', $accountId, $username, $fullName, $email, $passwordHash); $stmt->execute(); $adminId = (int)$db->insert_id; $stmt->close();
    $stmt = $db->prepare('UPDATE cuenta SET id_usuario_propietario=? WHERE id_cuenta=?');
    $stmt->bind_param('ii', $adminId, $accountId); $stmt->execute(); $stmt->close();

    provisionTenantRoles($db, $accountId);
    $role = 'Administrador';
    $stmt = $db->prepare("INSERT INTO usuario_rol(id_user,id_rol) SELECT ?,id_rol FROM rol WHERE id_cuenta=? AND nombre=? AND es_plantilla=0 LIMIT 1");
    $stmt->bind_param('iis', $adminId, $accountId, $role); $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new RuntimeException('No fue posible asignar el rol Administrador.');
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO empresa(id_cuenta,razon_social,nombre_comercial,rut,giro,direccion,ciudad,moneda_base,activo) VALUES (?,?,?,?,?,?,?,?,1)");
    $stmt->bind_param('isssssss', $accountId, $legalName, $tradeName, $taxId, $activity, $address, $city, $currencyCode); $stmt->execute(); $companyId = (int)$db->insert_id; $stmt->close();
    provisionLocalBetaEntitlement($db, $accountId);
    $stmt = $db->prepare("INSERT INTO sucursal(id_cuenta,id_empresa,codigo,nombre,direccion,responsable,activo) VALUES (?,?,?,?,?,?,1)");
    $stmt->bind_param('iissss', $accountId, $companyId, $branchCode, $branchName, $address, $fullName); $stmt->execute(); $branchId = (int)$db->insert_id; $stmt->close();
    $stmt = $db->prepare('UPDATE usuario SET id_sucursal=? WHERE id_user=?');
    $stmt->bind_param('ii', $branchId, $adminId); $stmt->execute(); $stmt->close();

    $stmt = $db->prepare("INSERT INTO bodega(id_cuenta,id_user,codigo,nombre,responsable,direccion,estado) VALUES (?,?,?,?,?,?,'ACTIVA')");
    $stmt->bind_param('iissss', $accountId, $adminId, $warehouseCode, $warehouseName, $fullName, $address); $stmt->execute(); $warehouseId = (int)$db->insert_id; $stmt->close();
    $stmt = $db->prepare("INSERT INTO pos_caja_fisica(id_cuenta,codigo,nombre,sucursal,id_bodega,activo) VALUES (?,?,?,?,?,1)");
    $stmt->bind_param('isssi', $accountId, $cashCode, $cashName, $branchName, $warehouseId); $stmt->execute(); $cashId = (int)$db->insert_id; $stmt->close();

    $stmt = $db->prepare('INSERT INTO moneda(codigo,nombre,simbolo,decimales,activo) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),simbolo=VALUES(simbolo),decimales=VALUES(decimales),activo=1');
    $stmt->bind_param('sssi', $currencyCode, $currencyName, $currencySymbol, $currencyDecimals); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare("INSERT INTO impuesto(codigo,nombre,tasa,tipo,activo) VALUES (?,?,?,'IVA',1) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),tasa=VALUES(tasa),activo=1");
    $stmt->bind_param('ssd', $taxCode, $taxName, $taxRate); $stmt->execute(); $stmt->close();

    $installationId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0,65535), random_int(0,65535), random_int(0,65535), random_int(0,4095)|0x4000, random_int(0,16383)|0x8000, random_int(0,65535), random_int(0,65535), random_int(0,65535));
    $version = trim((string)@file_get_contents($root . DIRECTORY_SEPARATOR . 'VERSION')) ?: 'development';
    $stmt = $db->prepare('INSERT INTO core_installation(id_installation,id_cuenta,id_user_admin,installation_id,installed_version,setup_completed) VALUES (1,?,?,?,?,1)');
    $stmt->bind_param('iiss', $accountId, $adminId, $installationId, $version); $stmt->execute(); $stmt->close();
    $db->commit();

    echo json_encode(['ok'=>true,'status'=>'configured','installation_id'=>$installationId,'id_cuenta'=>$accountId,'id_empresa'=>$companyId,'id_sucursal'=>$branchId,'id_bodega'=>$warehouseId,'id_caja_fisica'=>$cashId], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    try { $db->rollback(); } catch (Throwable) {}
    fwrite(STDERR, 'Bootstrap cancelado: ' . $error->getMessage() . "\n");
    exit(1);
}
