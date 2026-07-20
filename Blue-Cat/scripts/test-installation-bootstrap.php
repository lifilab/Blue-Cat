<?php
declare(strict_types=1);

if (!in_array('--allow-production', $argv, true)) {
    fwrite(STDERR, "Esta prueba crea y elimina una base temporal. Use --allow-production para confirmar.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/assets/api/env_loader.php';
loadEnv($root . '/.env');
$host = (string)(getenv('DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('DB_PORT') ?: 3306);
$user = (string)(getenv('DB_USER') ?: 'root');
$password = (string)(getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '');
$database = 'bluecat_installer_test_' . bin2hex(random_bytes(4));
if (!preg_match('/^bluecat_installer_test_[a-f0-9]{8}$/', $database)) throw new RuntimeException('Nombre temporal inválido.');

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $database;
$envFile = $temp . DIRECTORY_SEPARATOR . '.env';
$configFile = $temp . DIRECTORY_SEPARATOR . 'installation.json';
$admin = new mysqli($host, $user, $password, '', $port);
$admin->set_charset('utf8mb4');

function runInstallCommand(array $command, string $cwd): array
{
    $descriptor = [1=>['pipe','w'],2=>['pipe','w']];
    $process = proc_open($command, $descriptor, $pipes, $cwd);
    if (!is_resource($process)) throw new RuntimeException('No fue posible iniciar el proceso de prueba.');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), trim((string)$stdout), trim((string)$stderr)];
}

try {
    if (!mkdir($temp, 0700, true) && !is_dir($temp)) throw new RuntimeException('No fue posible crear el directorio temporal.');
    $admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $env = "APP_ENV=test\nAPP_KEY=bluecat-installer-test-key\nDB_HOST={$host}\nDB_PORT={$port}\nDB_NAME={$database}\nDB_USER={$user}\nDB_PASSWORD={$password}\n";
    file_put_contents($envFile, $env, LOCK_EX);
    $config = [
        'company'=>['legal_name'=>'Blue-Cat Instalador SpA','trade_name'=>'Tienda Bootstrap','tax_id'=>'76.999.999-9','business_activity'=>'Pruebas','address'=>'Calle Uno 123','city'=>'Santiago'],
        'administrator'=>['username'=>'adminbeta','full_name'=>'Administrador Beta','email'=>'adminbeta@example.test','password'=>'Bootstrap9Segura'],
        'currency'=>['code'=>'CLP','name'=>'Peso chileno','symbol'=>'$','decimals'=>0],
        'tax'=>['code'=>'IVA','name'=>'IVA','rate'=>19],
        'branch'=>['code'=>'SUC-001','name'=>'Principal'],
        'warehouse'=>['code'=>'BOD-001','name'=>'Bodega Principal'],
        'cash_register'=>['code'=>'CAJA-01','name'=>'Caja Principal'],
    ];
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    [$code,$out,$err] = runInstallCommand([PHP_BINARY,$root.'/scripts/migrate.php','--env='.$envFile], $root);
    if ($code !== 0) throw new RuntimeException("Migración falló: {$err} {$out}");
    [$code,$out,$err] = runInstallCommand([PHP_BINARY,$root.'/scripts/bootstrap-installation.php','--env='.$envFile,'--config='.$configFile], $root);
    if ($code !== 0) throw new RuntimeException("Bootstrap falló: {$err} {$out}");
    $result = json_decode($out, true);
    if (($result['status'] ?? '') !== 'configured') throw new RuntimeException('El primer bootstrap no configuró la instalación.');

    $db = new mysqli($host, $user, $password, $database, $port);
    $checks = [
        'cuenta'=>1,'usuario'=>1,'empresa'=>1,'sucursal'=>1,'bodega'=>1,'pos_caja_fisica'=>1,'core_installation'=>1,
        'modulo'=>8,'plan'=>1,'plan_modulo'=>8,'suscripcion'=>1,
    ];
    foreach ($checks as $table=>$expected) {
        $count = (int)$db->query("SELECT COUNT(*) total FROM `{$table}`")->fetch_assoc()['total'];
        if ($count !== $expected) throw new RuntimeException("{$table}: se esperaba {$expected}, se obtuvo {$count}.");
    }
    $row = $db->query("SELECT password FROM usuario WHERE nombre='adminbeta'")->fetch_assoc();
    if (!$row || !password_verify('Bootstrap9Segura', $row['password'])) throw new RuntimeException('La contraseña inicial no quedó hasheada correctamente.');
    $adminRole = (int)$db->query("SELECT COUNT(*) total FROM usuario_rol ur JOIN rol r ON r.id_rol=ur.id_rol WHERE r.nombre='Administrador' AND r.id_cuenta IS NOT NULL")->fetch_assoc()['total'];
    if ($adminRole !== 1) throw new RuntimeException('El administrador no recibió su rol local.');
    $permissionCounts = $db->query("SELECT COUNT(DISTINCT rp.id_permiso) assigned,(SELECT COUNT(*) FROM permiso) available FROM usuario_rol ur JOIN rol r ON r.id_rol=ur.id_rol JOIN rol_permiso rp ON rp.id_rol=r.id_rol WHERE ur.id_user=(SELECT id_user FROM usuario WHERE nombre='adminbeta') AND r.nombre='Administrador'")->fetch_assoc();
    if ((int)$permissionCounts['assigned'] !== (int)$permissionCounts['available']) throw new RuntimeException('El superadministrador no recibió todos los permisos disponibles.');
    $db->close();
    $db = null;

    [$code,$out,$err] = runInstallCommand([PHP_BINARY,$root.'/scripts/bootstrap-installation.php','--env='.$envFile,'--config='.$configFile], $root);
    $result = json_decode($out, true);
    if ($code !== 0 || ($result['status'] ?? '') !== 'already-configured') throw new RuntimeException("La reparación idempotente falló: {$err} {$out}");
    echo "PASS instalación inicial crea cuenta, administrador, empresa, sucursal, bodega y caja\n";
    echo "PASS contraseña elegida se almacena con hash fuerte\n";
    echo "PASS catálogo, plan, suscripción y módulos quedan habilitados\n";
    echo "PASS superadministrador recibe todos los permisos\n";
    echo "PASS segundo bootstrap es idempotente\n";
} finally {
    if (isset($db) && $db instanceof mysqli) $db->close();
    $admin->query("DROP DATABASE IF EXISTS `{$database}`");
    $admin->close();
    foreach ([$configFile,$envFile] as $file) if (is_file($file)) unlink($file);
    if (is_dir($temp)) rmdir($temp);
}
