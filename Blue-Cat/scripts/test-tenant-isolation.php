<?php
declare(strict_types=1);

function optionValue(string $name): ?string
{
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if (str_starts_with($argument, $name . '=')) return substr($argument, strlen($name) + 1);
    }
    return null;
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$projectRoot = dirname(__DIR__);
$envOption = optionValue('--env') ?? '.env.phase1-test';
$envPath = str_starts_with($envOption, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $envOption)
    ? $envOption
    : $projectRoot . DIRECTORY_SEPARATOR . $envOption;
if (!is_file($envPath)) throw new RuntimeException("No existe el entorno de prueba: {$envPath}");
putenv('BLUECAT_ENV_FILE=' . $envPath);

require_once $projectRoot . '/assets/api/_db.php';

$db = getDB();
if ((getenv('APP_ENV') ?: '') !== 'test' || DB_NAME === 'erp') {
    throw new RuntimeException('La prueba solo puede ejecutarse con APP_ENV=test y una base que no sea erp.');
}

$db->begin_transaction();
try {
    $db->query("INSERT INTO cuenta (nombre) VALUES ('Tenant A'),('Tenant B')");
    $accountA = (int)$db->insert_id;
    $accountB = $accountA + 1;

    $hash = password_hash('TenantTest-2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario (id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $name = 'tenant-a-' . bin2hex(random_bytes(3)); $email = $name . '@test.local';
    $stmt->bind_param('isss', $accountA, $name, $email, $hash); $stmt->execute(); $userA = (int)$db->insert_id;
    $name = 'tenant-b-' . bin2hex(random_bytes(3)); $email = $name . '@test.local';
    $stmt->bind_param('isss', $accountB, $name, $email, $hash); $stmt->execute(); $userB = (int)$db->insert_id;
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$userA} WHERE id_cuenta={$accountA}");
    $db->query("UPDATE cuenta SET id_usuario_propietario={$userB} WHERE id_cuenta={$accountB}");

    provisionTenantRoles($db, $accountA);
    provisionTenantRoles($db, $accountB);

    $stmt = $db->prepare('INSERT INTO producto (id_user,id_cuenta,nombre_producto,precio_venta) VALUES (?,?,?,1000)');
    $productName = 'Producto A'; $stmt->bind_param('iis', $userA, $accountA, $productName); $stmt->execute(); $productA = (int)$db->insert_id;
    $productName = 'Producto B'; $stmt->bind_param('iis', $userB, $accountB, $productName); $stmt->execute(); $productB = (int)$db->insert_id;
    $stmt->close();

    $stmt = $db->prepare('INSERT INTO bodega (id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,\'ACTIVA\')');
    $code = 'TA-' . bin2hex(random_bytes(3)); $warehouseName = 'Bodega A';
    $stmt->bind_param('iiss', $userA, $accountA, $code, $warehouseName); $stmt->execute(); $warehouseA = (int)$db->insert_id;
    $code = 'TB-' . bin2hex(random_bytes(3)); $warehouseName = 'Bodega B';
    $stmt->bind_param('iiss', $userB, $accountB, $code, $warehouseName); $stmt->execute(); $warehouseB = (int)$db->insert_id;
    $stmt->close();

    $stmt = $db->prepare('INSERT INTO cliente (id_user,id_cuenta,codigo,nombre,razon_social) VALUES (?,?,\'MISMO\',?,\'Cliente prueba\')');
    $clientName = 'Cliente A'; $stmt->bind_param('iis', $userA, $accountA, $clientName); $stmt->execute(); $clientA = (int)$db->insert_id;
    $clientName = 'Cliente B'; $stmt->bind_param('iis', $userB, $accountB, $clientName); $stmt->execute(); $clientB = (int)$db->insert_id;
    $stmt->close();

    $contextA = tenantContext($userA, true);
    $contextB = tenantContext($userB, true);
    check($contextA->accountId === $accountA && $contextB->accountId === $accountB, 'cada usuario resuelve su propia cuenta');
    check(tenantUserBelongs($db, $accountA, $userA), 'la cuenta puede leer su usuario');
    check(!tenantUserBelongs($db, $accountA, $userB), 'la cuenta no puede leer usuarios ajenos');
    check(tenantEntityBelongs($db, $contextA, 'producto', $productA), 'la cuenta puede leer su producto');
    check(!tenantEntityBelongs($db, $contextA, 'producto', $productB), 'la cuenta no puede leer productos ajenos');
    check(tenantEntityBelongs($db, $contextA, 'cliente', $clientA), 'la cuenta puede leer su cliente');
    check(!tenantEntityBelongs($db, $contextA, 'cliente', $clientB), 'la cuenta no puede leer clientes ajenos');
    check((int)$db->query("SELECT COUNT(*) FROM producto WHERE id_cuenta={$accountA}")->fetch_row()[0] === 1, 'los listados por cuenta no mezclan productos');

    $db->query("INSERT INTO producto (id_user,id_cuenta,nombre_producto) VALUES ({$userA},{$accountB},'Legacy protegido')");
    $guardedProduct = (int)$db->insert_id;
    $guardedAccount = (int)$db->query("SELECT id_cuenta FROM producto WHERE id_producto={$guardedProduct}")->fetch_row()[0];
    check($guardedAccount === $accountA, 'el trigger corrige escrituras legacy y evita falsificar id_cuenta');

    $template = $db->query("SELECT id_rol FROM rol WHERE id_cuenta IS NULL AND es_plantilla=1 LIMIT 1")->fetch_row();
    $localA = $db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND es_plantilla=0 LIMIT 1")->fetch_row();
    $localB = $db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountB} AND es_plantilla=0 LIMIT 1")->fetch_row();
    check($template && $localA && $localB, 'se aprovisionan roles locales desde plantillas globales');
    check(tenantRoleAccess($db, $contextA, (int)$template[0], false), 'una plantilla global es visible');
    check(!tenantRoleAccess($db, $contextA, (int)$template[0], true), 'una plantilla global es de solo lectura');
    check(tenantRoleAccess($db, $contextA, (int)$localA[0], true), 'un rol propio es editable');
    check(!tenantRoleAccess($db, $contextA, (int)$localB[0], false), 'un rol de otra cuenta no es visible');

    $_SESSION['user_id'] = $userA;
    check(actualizarStock($db, $productA, $warehouseA, 'disponible', 5) === 1, 'la cuenta puede modificar su stock');
    $blocked = false;
    try { actualizarStock($db, $productB, $warehouseB, 'disponible', 5); } catch (RuntimeException) { $blocked = true; }
    check($blocked, 'la cuenta no puede modificar stock ajeno');

    $fkBlocked = false;
    try { $db->query("INSERT INTO usuario (id_cuenta,nombre,correo,password) VALUES (999999,'fk-test','fk-test@test.local','x')"); }
    catch (mysqli_sql_exception) { $fkBlocked = true; }
    check($fkBlocked, 'la clave foranea impide usuarios sin cuenta valida');

    $duplicateBlocked = false;
    try {
        $stmt = $db->prepare("INSERT INTO cliente (id_user,id_cuenta,codigo,nombre) VALUES (?,?, 'MISMO','Duplicado')");
        $stmt->bind_param('ii', $userA, $accountA); $stmt->execute();
    } catch (mysqli_sql_exception) { $duplicateBlocked = true; }
    check($duplicateBlocked, 'el codigo de cliente es unico dentro de cada cuenta');

    $db->rollback();
    echo "OK aislamiento tenant verificado\n";
} catch (Throwable $error) {
    $db->rollback();
    fwrite(STDERR, "FAIL {$error->getMessage()}\n");
    exit(1);
}
