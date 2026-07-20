<?php
declare(strict_types=1);

function employeeArg(string $name): ?string {
    foreach (array_slice($_SERVER['argv'], 1) as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function employeeAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function employeeApi(string $root, string $env, string $endpoint, int $user, string $method, array $query = [], ?array $body = null): array {
    $parts = [PHP_BINARY, $root . '/scripts/invoke-api-test.php', '--env=' . $env, '--endpoint=' . $endpoint, '--user=' . $user, '--method=' . $method];
    if ($query) $parts[] = '--query=' . base64_encode((string)json_encode($query));
    if ($body !== null) $parts[] = '--body=' . base64_encode((string)json_encode($body));
    exec(implode(' ', array_map('escapeshellarg', $parts)), $lines, $code);
    $raw = implode("\n", $lines);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new RuntimeException("Respuesta API inválida ({$endpoint}, {$code}): {$raw}");
    return $decoded;
}

function grantEmployeePermission(mysqli $db, int $roleId, string $module, string $action): void {
    $stmt = $db->prepare('INSERT IGNORE INTO rol_permiso(id_rol,id_permiso) SELECT ?,id_permiso FROM permiso WHERE modulo=? AND accion=?');
    $stmt->bind_param('iss', $roleId, $module, $action);
    $stmt->execute();
    $stmt->close();
}

$root = dirname(__DIR__);
$env = employeeArg('--env') ?? '.env.phase1-test';
$envPath = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $env) ? $env : $root . '/' . $env;
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');

$db = getDB();
$accounts = [];
$users = [];
$employees = [];

try {
    $db->query("INSERT INTO cuenta(nombre) VALUES ('Employee Auth A')");
    $accountA = (int)$db->insert_id;
    $db->query("INSERT INTO cuenta(nombre) VALUES ('Employee Auth B')");
    $accountB = (int)$db->insert_id;
    $accounts = [$accountA, $accountB];
    provisionTenantRoles($db, $accountA);
    provisionTenantRoles($db, $accountB);

    $hash = securityHashPassword('EmployeeAuth-2026!');
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $createUser = static function(string $prefix, int $account) use ($stmt, $hash, &$users): int {
        $name = $prefix . '-' . bin2hex(random_bytes(3));
        $mail = $name . '@test.local';
        $stmt->bind_param('isss', $account, $name, $mail, $hash);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $users[] = $id;
        return $id;
    };
    $adminA = $createUser('employee-admin-a', $accountA);
    $viewerA = $createUser('employee-viewer-a', $accountA);
    $editorA = $createUser('employee-editor-a', $accountA);
    $managerA = $createUser('employee-manager-a', $accountA);
    $combinedA = $createUser('employee-combined-a', $accountA);
    $linkedA = $createUser('employee-linked-a', $accountA);
    $adminB = $createUser('employee-admin-b', $accountB);
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$adminA} WHERE id_cuenta={$accountA}");
    $db->query("UPDATE cuenta SET id_usuario_propietario={$adminB} WHERE id_cuenta={$accountB}");

    $adminRoleA = (int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND nombre='Administrador'")->fetch_row()[0];
    $adminRoleB = (int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountB} AND nombre='Administrador'")->fetch_row()[0];
    $db->query("INSERT INTO rol(id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla) VALUES
        ({$accountA},'Employee Viewer','Test',1,0,0),
        ({$accountA},'Employee Editor','Test',1,0,0),
        ({$accountA},'Account Manager','Test',1,0,0),
        ({$accountA},'Employee Combined','Test',1,0,0)");
    $viewerRole = (int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND nombre='Employee Viewer'")->fetch_row()[0];
    $editorRole = (int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND nombre='Employee Editor'")->fetch_row()[0];
    $managerRole = (int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND nombre='Account Manager'")->fetch_row()[0];
    $combinedRole = (int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND nombre='Employee Combined'")->fetch_row()[0];
    grantEmployeePermission($db, $viewerRole, 'empleados', 'ver');
    grantEmployeePermission($db, $editorRole, 'empleados', 'ver');
    grantEmployeePermission($db, $editorRole, 'empleados', 'editar');
    grantEmployeePermission($db, $managerRole, 'usuarios', 'editar_cuentas');
    foreach ([['empleados','ver'],['empleados','crear'],['empleados','editar'],['empleados','eliminar'],['usuarios','editar_cuentas']] as [$module, $action]) {
        grantEmployeePermission($db, $combinedRole, $module, $action);
    }
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES
        ({$adminA},{$adminRoleA}),({$viewerA},{$viewerRole}),({$editorA},{$editorRole}),
        ({$managerA},{$managerRole}),({$combinedA},{$combinedRole}),({$adminB},{$adminRoleB})");

    $stmt = $db->prepare('INSERT INTO empleado(id_user,id_cuenta,codigo,nombres,apellidos,estado) VALUES (?,?,?,?,?,\'ACTIVO\')');
    $code = 'AUTH-A-' . bin2hex(random_bytes(2)); $first = 'Ana'; $last = 'Cuenta A';
    $stmt->bind_param('iisss', $adminA, $accountA, $code, $first, $last); $stmt->execute(); $employeeA = (int)$stmt->insert_id;
    $code = 'AUTH-B-' . bin2hex(random_bytes(2)); $first = 'Berta'; $last = 'Cuenta B';
    $stmt->bind_param('iisss', $adminB, $accountB, $code, $first, $last); $stmt->execute(); $employeeB = (int)$stmt->insert_id;
    $stmt->close();
    $employees = [$employeeA, $employeeB];

    $deniedList = employeeApi($root, $envPath, 'empleados.php', $managerA, 'GET');
    employeeAssert(isset($deniedList['error']), 'sin empleados.ver no se puede listar empleados');
    $deniedProfile = employeeApi($root, $envPath, 'empleados.php', $managerA, 'GET', ['id' => $employeeA]);
    employeeAssert(isset($deniedProfile['error']), 'sin empleados.ver no se puede abrir un perfil');

    $viewerList = employeeApi($root, $envPath, 'empleados.php', $viewerA, 'GET');
    $ids = array_map(static fn(array $row): int => (int)$row['id_empleado'], $viewerList['items'] ?? []);
    employeeAssert(in_array($employeeA, $ids, true) && !in_array($employeeB, $ids, true), 'empleados.ver queda limitado a la cuenta');
    $foreignProfile = employeeApi($root, $envPath, 'empleados.php', $viewerA, 'GET', ['id' => $employeeB]);
    employeeAssert(isset($foreignProfile['error']), 'un perfil de otra cuenta queda oculto');

    $viewerMutation = employeeApi($root, $envPath, 'empleados.php', $viewerA, 'POST', [], [
        'accion' => 'historial_crear', 'id_empleado' => $employeeA, 'tipo' => 'INTRUSION',
    ]);
    employeeAssert(isset($viewerMutation['error']), 'empleados.ver no concede mutaciones');
    $editorLink = employeeApi($root, $envPath, 'empleados.php', $editorA, 'POST', [], [
        'accion' => 'vincular_usuario', 'id_empleado' => $employeeA, 'id_user' => $linkedA,
    ]);
    employeeAssert(isset($editorLink['error']), 'vincular exige también usuarios.editar_cuentas');
    $managerLink = employeeApi($root, $envPath, 'empleados.php', $managerA, 'POST', [], [
        'accion' => 'vincular_usuario', 'id_empleado' => $employeeA, 'id_user' => $linkedA,
    ]);
    employeeAssert(isset($managerLink['error']), 'gestionar cuentas no sustituye empleados.editar');

    $linked = employeeApi($root, $envPath, 'empleados.php', $combinedA, 'POST', [], [
        'accion' => 'vincular_usuario', 'id_empleado' => $employeeA, 'id_user' => $linkedA,
    ]);
    employeeAssert(!empty($linked['success']), 'ambos permisos permiten vincular una identidad del tenant');

    $contract = employeeApi($root, $envPath, 'empleados.php', $combinedA, 'POST', [], [
        'accion' => 'contrato_crear', 'id_empleado' => $employeeA, 'tipo' => 'INDEFINIDO',
        'fecha_inicio' => '2026-01-01', 'sueldo_base' => 500000,
    ]);
    $contractId = (int)($contract['id_contrato'] ?? 0);
    employeeAssert($contractId > 0, 'el esquema fresco permite crear contratos');
    $deletedContract = employeeApi($root, $envPath, 'empleados.php', $adminA, 'POST', [], [
        'accion' => 'contrato_eliminar', 'id_contrato' => $contractId,
    ]);
    employeeAssert(!empty($deletedContract['success']), 'el administrador conserva alcance después de vincular al empleado');

    $foreignMutation = employeeApi($root, $envPath, 'empleados.php', $combinedA, 'POST', [], [
        'accion' => 'historial_crear', 'id_empleado' => $employeeB, 'tipo' => 'INTRUSION',
    ]);
    employeeAssert(isset($foreignMutation['error']), 'ninguna mutación cruza cuentas');
    $unknown = employeeApi($root, $envPath, 'empleados.php', $combinedA, 'POST', [], ['accion' => 'accion_inexistente']);
    employeeAssert(isset($unknown['error']), 'acciones desconocidas se rechazan antes del acceso a datos');

    $created = employeeApi($root, $envPath, 'empleados.php', $adminA, 'POST', [], [
        'accion' => 'crear', 'nombres' => 'Fresh', 'apellidos' => 'Install', 'correo' => 'fresh@test.local',
    ]);
    $createdId = (int)($created['id_empleado'] ?? 0);
    if ($createdId > 0) $employees[] = $createdId;
    employeeAssert($createdId > 0, 'una base migrada desde cero permite crear empleados');
    $profile = employeeApi($root, $envPath, 'empleados.php', $adminA, 'GET', ['id' => $createdId]);
    employeeAssert((int)($profile['id_empleado'] ?? 0) === $createdId && isset($profile['documentos'], $profile['remuneraciones'], $profile['auditoria']), 'el perfil fresco carga todas sus secciones');

    echo "OK autorización y esquema de empleados verificados\n";
} finally {
    foreach ($employees as $employeeId) {
        foreach (['empleado_auditoria'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_empleado=" . (int)$employeeId); } catch (mysqli_sql_exception) {}
        }
        try { $db->query('DELETE FROM empleado WHERE id_empleado=' . (int)$employeeId); } catch (mysqli_sql_exception) {}
    }
    foreach ($accounts as $accountId) $db->query('UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta=' . (int)$accountId);
    foreach ($users as $userId) {
        foreach (['core_sesion','core_auditoria','empleado_auditoria'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_user=" . (int)$userId); } catch (mysqli_sql_exception) {}
        }
        try { $db->query('DELETE FROM usuario WHERE id_user=' . (int)$userId); } catch (mysqli_sql_exception) {}
    }
    foreach ($accounts as $accountId) $db->query('DELETE FROM cuenta WHERE id_cuenta=' . (int)$accountId);
}
