<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) $envFile = substr($argument, 6);
}
putenv('BLUECAT_ENV_FILE='.$envFile);
putenv('BLUECAT_ENFORCE_ENTITLEMENTS=1');
require_once $root . '/assets/api/_db.php';

if (getenv('APP_ENV') !== 'test') {
    fwrite(STDERR, "Esta prueba exige APP_ENV=test.\n");
    exit(2);
}

$db = getDB();
$token = bin2hex(random_bytes(4));
$accountIds = [];
$userIds = [];
$companyIds = [];
$planId = 0;
$subscriptionId = 0;

function entitlementAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

try {
    foreach (['Con licencia '.$token, 'Sin licencia '.$token] as $name) {
        $stmt = $db->prepare("INSERT INTO cuenta(nombre,estado) VALUES (?,'ACTIVA')");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $accountIds[] = (int)$db->insert_id;
        $stmt->close();
    }

    foreach ($accountIds as $index=>$accountId) {
        $username = "license_{$token}_{$index}";
        $email = "{$username}@example.test";
        $hash = password_hash('License9Segura', PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,0)');
        $stmt->bind_param('isss', $accountId, $username, $email, $hash);
        $stmt->execute();
        $userIds[] = (int)$db->insert_id;
        $stmt->close();

        $companyName = "Empresa licencia {$token} {$index}";
        $rut = '99.' . substr($token, 0, 3) . '.' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '-K';
        $stmt = $db->prepare('INSERT INTO empresa(id_cuenta,razon_social,nombre_comercial,rut,activo) VALUES (?,?,?,?,1)');
        $stmt->bind_param('isss', $accountId, $companyName, $companyName, $rut);
        $stmt->execute();
        $companyIds[] = (int)$db->insert_id;
        $stmt->close();
    }

    $planName = "Plan prueba {$token}";
    $stmt = $db->prepare("INSERT INTO plan(nombre,descripcion,precio,max_empresas,max_sucursales,max_usuarios,activo) VALUES (?,'Prueba',0,1,1,2,1)");
    $stmt->bind_param('s', $planName);
    $stmt->execute();
    $planId = (int)$db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO plan_modulo(id_plan,id_modulo)
        SELECT {$planId},id_modulo FROM modulo WHERE codigo='pos'");
    $stmt = $db->prepare("INSERT INTO suscripcion(id_empresa,id_plan,fecha_inicio,estado) VALUES (?,?,CURDATE(),'activa')");
    $stmt->bind_param('ii', $companyIds[0], $planId);
    $stmt->execute();
    $subscriptionId = (int)$db->insert_id;
    $stmt->close();

    $_SESSION['user_id'] = $userIds[0];
    $GLOBALS['_moduleEntitlementCache'] = [];
    entitlementAssert(hasModuleEntitlement('pos'), 'una licencia activa habilita únicamente su módulo contratado');
    entitlementAssert(!hasModuleEntitlement('inventario'), 'un permiso RBAC no reemplaza un módulo fuera del plan');

    $_SESSION['user_id'] = $userIds[1];
    $GLOBALS['_moduleEntitlementCache'] = [];
    entitlementAssert(!hasModuleEntitlement('pos'), 'una cuenta sin suscripción no accede al módulo');

    $db->query("UPDATE suscripcion SET estado='suspendida' WHERE id_suscripcion={$subscriptionId}");
    $_SESSION['user_id'] = $userIds[0];
    $GLOBALS['_moduleEntitlementCache'] = [];
    entitlementAssert(!hasModuleEntitlement('pos'), 'una suscripción suspendida revoca el módulo en backend');
} finally {
    unset($_SESSION['user_id']);
    if ($subscriptionId) $db->query("DELETE FROM suscripcion WHERE id_suscripcion={$subscriptionId}");
    if ($planId) {
        $db->query("DELETE FROM plan_modulo WHERE id_plan={$planId}");
        $db->query("DELETE FROM plan WHERE id_plan={$planId}");
    }
    foreach (array_reverse($companyIds) as $id) $db->query("DELETE FROM empresa WHERE id_empresa={$id}");
    foreach (array_reverse($userIds) as $id) $db->query("DELETE FROM usuario WHERE id_user={$id}");
    foreach (array_reverse($accountIds) as $id) $db->query("DELETE FROM cuenta WHERE id_cuenta={$id}");
}
