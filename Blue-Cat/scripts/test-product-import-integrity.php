<?php
declare(strict_types=1);

function importArg(string $name): ?string {
    foreach (array_slice($_SERVER['argv'], 1) as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function importAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

function importScalar(mysqli $db, string $sql) {
    $result = $db->query($sql);
    $row = $result->fetch_row();
    return $row[0] ?? null;
}

function importQuantity($actual, string $expected, string $message): void {
    $formatted = number_format((float)$actual, 3, '.', '');
    importAssert($formatted === $expected, "{$message} (esperado {$expected}, recibido {$formatted})");
}

function importCsv(array $rows, ?array $headers = null): string {
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, $headers ?? ['Nombre','Precio','Codigo','Cantidad','Categoria','SKU','Costo','Tipo venta','Unidad','Activo']);
    foreach ($rows as $row) fputcsv($stream, $row);
    rewind($stream);
    $csv = (string)stream_get_contents($stream);
    fclose($stream);
    return $csv;
}

function importLegacyXls(array $rows): string {
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html><body><table><thead><tr>';
    foreach (['Nombre','Precio','Codigo','Cantidad','Categoria','SKU','Costo','Tipo venta','Unidad','Activo'] as $header) {
        $html .= '<th>' . $escape($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) $html .= '<td>' . $escape($cell) . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table></body></html>';
}

function importXlsx(array $rows): string {
    return bluecatXlsxBuildWorkbook(
        ['Nombre','Precio','Codigo','Cantidad','Categoria','SKU','Costo','Tipo venta','Unidad','Activo'],
        $rows,
        'Productos'
    );
}

function importFormulaXlsx(): string {
    $temporary = tempnam(sys_get_temp_dir(), 'bluecat-formula-xlsx-');
    if ($temporary === false) throw new RuntimeException('No se pudo crear XLSX de fórmula');
    try {
        bluecatXlsxWriteWorkbook($temporary, ['Nombre','Precio','Codigo','Cantidad'], [['Formula',10,'FORMULA',1]], 'Productos');
        $zip = new ZipArchive();
        if ($zip->open($temporary) !== true) throw new RuntimeException('No se pudo abrir XLSX de fórmula');
        $sheet = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet = preg_replace('~<c r="A2"[^>]*>.*?</c>~', '<c r="A2"><f>1+1</f><v>2</v></c>', $sheet, 1);
        if (!is_string($sheet) || !$zip->addFromString('xl/worksheets/sheet1.xml', $sheet) || !$zip->close()) throw new RuntimeException('No se pudo modificar XLSX de fórmula');
        return (string)file_get_contents($temporary);
    } finally {
        @unlink($temporary);
    }
}

function importRequest(int $port, string $filename, string $mime, string $contents, ?int $warehouseId = null, ?string $reference = 'TEST-GUIA-001', ?int $actingUserId = null): array {
    $boundary = '----BlueCatImportTest' . bin2hex(random_bytes(12));
    $body = '';
    if ($warehouseId !== null) {
        $body .= "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"id_bodega\"\r\n\r\n"
            . $warehouseId . "\r\n";
    }
    if ($reference !== null) {
        $body .= "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"referencia_documento\"\r\n\r\n"
            . $reference . "\r\n";
    }
    $body .= "--{$boundary}\r\n"
        . 'Content-Disposition: form-data; name="file"; filename="' . addcslashes($filename, "\"\\") . "\"\r\n"
        . "Content-Type: {$mime}\r\n\r\n"
        . $contents . "\r\n--{$boundary}--\r\n";
    $request = "POST / HTTP/1.1\r\n"
        . "Host: 127.0.0.1:{$port}\r\n"
        . "X-Requested-With: XMLHttpRequest\r\n"
        . ($actingUserId !== null ? "X-Bluecat-Test-User: {$actingUserId}\r\n" : '')
        . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $body;

    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 5);
    if (!$socket) throw new RuntimeException("No se pudo conectar al servidor de carga ({$errno}: {$error})");
    stream_set_timeout($socket, 15);
    $offset = 0;
    while ($offset < strlen($request)) {
        $written = fwrite($socket, substr($request, $offset));
        if ($written === false || $written === 0) {
            fclose($socket);
            throw new RuntimeException('No se pudo enviar el archivo de prueba');
        }
        $offset += $written;
    }
    $response = (string)stream_get_contents($socket);
    fclose($socket);
    $separator = strpos($response, "\r\n\r\n");
    $payload = $separator === false ? $response : substr($response, $separator + 4);
    $decoded = json_decode(trim($payload), true);
    if (!is_array($decoded)) throw new RuntimeException("Respuesta de importacion invalida: {$response}");
    if (preg_match('~^HTTP/\S+\s+(\d{3})~', $response, $statusMatch)) $decoded['_http_status'] = (int)$statusMatch[1];
    return $decoded;
}

function importDownloadRequest(int $port, string $path, ?int $actingUserId = null): array {
    $request = "GET {$path} HTTP/1.1\r\n"
        . "Host: 127.0.0.1:{$port}\r\n"
        . "X-Requested-With: XMLHttpRequest\r\n"
        . ($actingUserId !== null ? "X-Bluecat-Test-User: {$actingUserId}\r\n" : '')
        . "Connection: close\r\n\r\n";
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 5);
    if (!$socket) throw new RuntimeException("No se pudo conectar al endpoint XLSX ({$errno}: {$error})");
    fwrite($socket, $request);
    $response = (string)stream_get_contents($socket);
    fclose($socket);
    $separator = strpos($response, "\r\n\r\n");
    if ($separator === false) throw new RuntimeException('Respuesta HTTP XLSX inválida');
    $head = substr($response, 0, $separator);
    $body = substr($response, $separator + 4);
    preg_match('~^HTTP/\S+\s+(\d{3})~', $head, $statusMatch);
    $headers = [];
    foreach (array_slice(explode("\r\n", $head), 1) as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) $headers[strtolower(trim(substr($line,0,$colon)))] = trim(substr($line,$colon+1));
    }
    return ['status'=>(int)($statusMatch[1]??0),'headers'=>$headers,'body'=>$body];
}

function importWorkbookRows(string $contents): array {
    $temporary = tempnam(sys_get_temp_dir(), 'bluecat-read-xlsx-');
    if ($temporary === false) throw new RuntimeException('No se pudo crear XLSX temporal');
    try {
        file_put_contents($temporary, $contents);
        return bluecatXlsxReadRows($temporary, BLUECAT_XLSX_MAX_ROWS, 64);
    } finally {
        @unlink($temporary);
    }
}

function importStartServer(string $root, string $envPath, int $userId, int &$port, string &$router, string &$log) {
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$probe) throw new RuntimeException("No se pudo reservar puerto de prueba ({$errno}: {$error})");
    $address = (string)stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int)substr($address, (int)strrpos($address, ':') + 1);
    $router = tempnam(sys_get_temp_dir(), 'bluecat-import-router-');
    $log = tempnam(sys_get_temp_dir(), 'bluecat-import-log-');
    if ($router === false || $log === false) throw new RuntimeException('No se pudo crear el harness temporal');
    $routerSource = <<<'PHP'
<?php
putenv('BLUECAT_ENV_FILE=' . (string)getenv('BLUECAT_IMPORT_TEST_ENV'));
putenv('BLUECAT_TEST_SESSION=1');
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$root = (string)getenv('BLUECAT_IMPORT_TEST_ROOT');
require_once $root . '/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') {
    http_response_code(500);
    echo json_encode(['error'=>true,'message'=>'Harness fuera de APP_ENV=test']);
    exit;
}
$_SESSION['user_id'] = (int)($_SERVER['HTTP_X_BLUECAT_TEST_USER'] ?? getenv('BLUECAT_IMPORT_TEST_USER'));
$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/inventario') require $root . '/assets/api/inventario.php';
else require $root . '/assets/api/importar_productos.php';
PHP;
    file_put_contents($router, $routerSource);
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['BLUECAT_IMPORT_TEST_ROOT'] = $root;
    $environment['BLUECAT_IMPORT_TEST_ENV'] = $envPath;
    $environment['BLUECAT_IMPORT_TEST_USER'] = (string)$userId;
    $process = proc_open(
        [PHP_BINARY, '-d', 'extension=zip', '-d', 'display_errors=0', '-S', "127.0.0.1:{$port}", $router],
        [0=>['pipe','r'], 1=>['file',$log,'a'], 2=>['file',$log,'a']],
        $pipes,
        $root,
        $environment
    );
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el servidor HTTP de prueba');
    if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($socket) {
            fclose($socket);
            return $process;
        }
        usleep(100000);
    }
    $details = is_file($log) ? (string)file_get_contents($log) : '';
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException("El servidor HTTP de prueba no inicio: {$details}");
}

$root = dirname(__DIR__);
$env = importArg('--env') ?? '.env.sprint1-test';
$envPath = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $env) ? $env : $root . '/' . $env;
putenv('BLUECAT_ENV_FILE=' . $envPath);
require_once $root . '/assets/api/_db.php';
require_once $root . '/assets/api/_xlsx.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');

$db = getDB();
$account = $foreignAccount = $user = $foreignUser = $limitedUser = $limitedRole = $warehouse = $secondaryWarehouse = $foreignWarehouse = $unitId = $pieceUnitId = 0;
$server = null;
$serverPort = 0;
$router = $serverLog = '';
$token = strtoupper(bin2hex(random_bytes(4)));

try {
    $db->query("INSERT INTO cuenta(nombre) VALUES ('Import Integrity {$token} A'),('Import Integrity {$token} B')");
    $account = (int)$db->insert_id;
    $foreignAccount = $account + 1;
    $hash = password_hash('Import-2026!', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $name = "import-a-{$token}"; $mail = strtolower($name) . '@test.local';
    $stmt->bind_param('isss', $account, $name, $mail, $hash); $stmt->execute(); $user = (int)$db->insert_id;
    $name = "import-b-{$token}"; $mail = strtolower($name) . '@test.local';
    $stmt->bind_param('isss', $foreignAccount, $name, $mail, $hash); $stmt->execute(); $foreignUser = (int)$db->insert_id;
    $name = "import-limited-{$token}"; $mail = strtolower($name) . '@test.local';
    $stmt->bind_param('isss', $account, $name, $mail, $hash); $stmt->execute(); $limitedUser = (int)$db->insert_id;
    $stmt->close();
    $db->query("UPDATE cuenta SET id_usuario_propietario={$user} WHERE id_cuenta={$account}");
    $db->query("UPDATE cuenta SET id_usuario_propietario={$foreignUser} WHERE id_cuenta={$foreignAccount}");
    provisionTenantRoles($db, $account);
    provisionTenantRoles($db, $foreignAccount);
    $admin = (int)importScalar($db, "SELECT id_rol FROM rol WHERE id_cuenta={$account} AND nombre='Administrador'");
    $foreignAdmin = (int)importScalar($db, "SELECT id_rol FROM rol WHERE id_cuenta={$foreignAccount} AND nombre='Administrador'");
    importAssert($admin > 0 && $foreignAdmin > 0, 'aprovisiona roles administrativos de prueba');
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$user},{$admin}),({$foreignUser},{$foreignAdmin})");
    $limitedRoleName = "Importador {$token}";
    $stmt = $db->prepare("INSERT INTO rol(id_cuenta,nombre,descripcion,es_sistema,es_plantilla,activo) VALUES (?,?,?,0,0,1)");
    $description = 'Rol de prueba sin acceso a costos';
    $stmt->bind_param('iss',$account,$limitedRoleName,$description);$stmt->execute();$limitedRole=(int)$db->insert_id;$stmt->close();
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$limitedUser},{$limitedRole})");
    $db->query("INSERT INTO rol_permiso(id_rol,id_permiso) SELECT {$limitedRole},id_permiso FROM permiso WHERE modulo='inventario' AND accion IN ('importar','exportar','precios')");

    $unitName = "Kilogramo {$token}";
    $unitAbbreviation = 'K' . substr($token, 0, 7);
    $pieceUnitName = "Unidad {$token}";
    $pieceUnitAbbreviation = 'U' . substr($token, 0, 7);
    $stmt = $db->prepare('INSERT INTO unidad_medida(nombre,abreviatura,tipo) VALUES (?,?,?)');
    $unitType = 'PESO';
    $stmt->bind_param('sss', $unitName, $unitAbbreviation, $unitType); $stmt->execute(); $unitId = (int)$db->insert_id;
    $unitType = 'UNIDAD';
    $stmt->bind_param('sss', $pieceUnitName, $pieceUnitAbbreviation, $unitType); $stmt->execute(); $pieceUnitId = (int)$db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
    $code = 'IA-' . substr($token, 0, 8); $name = "Import A {$token}";
    $stmt->bind_param('iiss', $user, $account, $code, $name); $stmt->execute(); $warehouse = (int)$db->insert_id;
    $code = 'IA2-' . substr($token, 0, 7); $name = "Import A Secondary {$token}";
    $stmt->bind_param('iiss', $user, $account, $code, $name); $stmt->execute(); $secondaryWarehouse = (int)$db->insert_id;
    $code = 'IB-' . substr($token, 0, 8); $name = "Import B {$token}";
    $stmt->bind_param('iiss', $foreignUser, $foreignAccount, $code, $name); $stmt->execute(); $foreignWarehouse = (int)$db->insert_id;
    $stmt->close();

    $legacyCode = 'LEG-' . $token;
    $protectedCode = 'PRO-' . $token;
    $unitCode = 'UNI-' . $token;
    $tenantCode = 'TEN-' . $token;
    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,codigo_de_barras,cantidad,categoria,sku,precio_costo,costo_promedio,ultimo_costo,tipo_venta,id_unidad,activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $insertProduct = static function (int $owner, int $tenant, string $name, float $price, string $barcode, float $quantity, string $category, string $sku, float $cost, string $type, ?int $unit, int $active) use ($stmt): int {
        $stmt->bind_param('iisdsdssdddsii', $owner, $tenant, $name, $price, $barcode, $quantity, $category, $sku, $cost, $cost, $cost, $type, $unit, $active);
        $stmt->execute();
        return (int)$stmt->insert_id;
    };
    $legacyProduct = $insertProduct($user, $account, 'Legacy original', 900, $legacyCode, 5, 'Legacy cat', 'SKU-KEEP', 125, 'PESO', $unitId, 0);
    $protectedProduct = $insertProduct($user, $account, 'Protected original', 1000, $protectedCode, 5, 'Protected cat', 'SKU-PROTECTED', 300, 'PESO', $unitId, 1);
    $unitProduct = $insertProduct($user, $account, 'Unit original', 500, $unitCode, 2, 'Unit cat', 'SKU-UNIT', 200, 'UNIDAD', null, 1);
    $foreignProduct = $insertProduct($foreignUser, $foreignAccount, 'Foreign untouched', 777, $tenantCode, 9, 'Foreign cat', 'SKU-FOREIGN', 333, 'PESO', $unitId, 1);
    $stmt->close();
    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$legacyProduct},{$warehouse},5),({$legacyProduct},{$secondaryWarehouse},4),({$protectedProduct},{$warehouse},5),({$unitProduct},{$warehouse},2),({$foreignProduct},{$foreignWarehouse},9)");

    $server = importStartServer($root, $envPath, $user, $serverPort, $router, $serverLog);

    $missingExportWarehouse = importDownloadRequest($serverPort, '/inventario?accion=exportar_productos');
    importAssert($missingExportWarehouse['status'] === 400, 'la exportación exige seleccionar una bodega');
    $foreignExportWarehouse = importDownloadRequest($serverPort, '/inventario?accion=exportar_productos&id_bodega=' . $foreignWarehouse);
    importAssert($foreignExportWarehouse['status'] === 400, 'la exportación rechaza una bodega de otro tenant');

    $limitedExport = importDownloadRequest($serverPort, '/inventario?accion=exportar_productos&id_bodega=' . $warehouse, $limitedUser);
    importAssert($limitedExport['status'] === 403 && !str_starts_with($limitedExport['body'], "PK\x03\x04"), 'un rol sin ver_costos no obtiene el catálogo con costos');
    $limitedBefore = $db->query("SELECT nombre_producto,precio_venta,precio_costo,cantidad FROM producto WHERE id_producto={$unitProduct}")->fetch_assoc();
    $limitedImport = importRequest($serverPort, 'sin-permiso-costos.csv', 'text/csv', importCsv([
        ['Cambio no autorizado', 9999, $unitCode, 8, 'Privado', 'SKU-UNIT', 8888, 'UNIDAD', '', 1],
    ]), $warehouse, 'DOC-SIN-COSTOS', $limitedUser);
    $limitedAfter = $db->query("SELECT nombre_producto,precio_venta,precio_costo,cantidad FROM producto WHERE id_producto={$unitProduct}")->fetch_assoc();
    importAssert((int)($limitedImport['_http_status'] ?? 0) === 403 && $limitedAfter === $limitedBefore, 'un importador sin ver_costos no modifica costos, precios ni stock');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM inventario_auditoria WHERE id_user={$limitedUser} AND accion='IMPORTACION_PRODUCTOS'") === 0, 'el intento 403 no registra una importación inexistente');

    $db->query("INSERT INTO rol_permiso(id_rol,id_permiso) SELECT {$limitedRole},id_permiso FROM permiso WHERE modulo='inventario' AND accion='ver_costos'");
    $db->query("DELETE rp FROM rol_permiso rp JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE rp.id_rol={$limitedRole} AND p.modulo='inventario' AND p.accion='precios'");
    $limitedPriceImport = importRequest($serverPort, 'sin-permiso-precios.csv', 'text/csv', importCsv([
        ['Cambio no autorizado', 9999, $unitCode, 8, 'Privado', 'SKU-UNIT', 8888, 'UNIDAD', '', 1],
    ]), $warehouse, 'DOC-SIN-PRECIOS', $limitedUser);
    $limitedPriceAfter = $db->query("SELECT nombre_producto,precio_venta,precio_costo,cantidad FROM producto WHERE id_producto={$unitProduct}")->fetch_assoc();
    importAssert((int)($limitedPriceImport['_http_status'] ?? 0) === 403 && $limitedPriceAfter === $limitedBefore, 'un importador sin permiso de precios no modifica la fila');

    $export = importDownloadRequest($serverPort, '/inventario?accion=exportar_productos&id_bodega=' . $warehouse);
    importAssert($export['status'] === 200 && str_starts_with((string)($export['headers']['content-type'] ?? ''), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') && str_starts_with($export['body'], "PK\x03\x04"), 'exporta un XLSX OOXML real');
    importAssert(str_contains((string)($export['headers']['content-disposition'] ?? ''), 'productos_' . date('Y-m-d') . '.xlsx'), 'la exportación usa nombre XLSX fechado');
    $exportRows = importWorkbookRows($export['body']);
    importAssert(($exportRows[0] ?? []) === ['Nombre','Precio','Codigo','Cantidad','Categoria','SKU','Costo','Tipo venta','Unidad','Activo'], 'la exportación usa las 10 columnas canónicas importables');
    $exportByCode = [];
    foreach (array_slice($exportRows,1) as $row) $exportByCode[(string)($row[2]??'')] = $row;
    importQuantity($exportByCode[$legacyCode][3] ?? null, '5.000', 'la exportación usa stock solo de la bodega seleccionada');
    importAssert(!isset($exportByCode[$tenantCode]), 'la exportación no filtra productos de otro tenant');
    $secondaryExport = importDownloadRequest($serverPort, '/inventario?accion=exportar_productos&id_bodega=' . $secondaryWarehouse);
    $secondaryRows = importWorkbookRows($secondaryExport['body']);
    $secondaryByCode = [];
    foreach (array_slice($secondaryRows,1) as $row) $secondaryByCode[(string)($row[2]??'')] = $row;
    importQuantity($secondaryByCode[$legacyCode][3] ?? null, '4.000', 'cada exportación refleja su propia bodega');

    $roundTrip = importRequest($serverPort, 'productos-roundtrip.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $export['body'], $warehouse, 'ROUNDTRIP-' . $token);
    importAssert((int)($roundTrip['_http_status'] ?? 0) === 200 && (int)$roundTrip['actualizados'] === 3 && (int)$roundTrip['errores'] === 0, 'el XLSX exportado se puede importar de vuelta sin pérdidas');
    $auditDetail = (string)importScalar($db, "SELECT detalle FROM inventario_auditoria WHERE id_user={$user} AND accion='IMPORTACION_PRODUCTOS' ORDER BY id_auditoria DESC LIMIT 1");
    $auditDecoded = json_decode($auditDetail,true);
    importAssert(is_array($auditDecoded) && ($auditDecoded['referencia_documento']??'') === 'ROUNDTRIP-' . $token && ($auditDecoded['actualizados']??0) === 3, 'la importación registra una auditoría resumida con documento y totales');

    $template = importDownloadRequest($serverPort, '/inventario?accion=plantilla_productos');
    $templateRows = importWorkbookRows($template['body']);
    importAssert($template['status'] === 200 && count($templateRows) === 6, 'la plantilla XLSX contiene cabecera y cinco ejemplos válidos');
    importAssert(count(array_filter(array_slice($templateRows,1), static fn(array $row): bool => (float)($row[3]??-1) !== 0.0)) === 0, 'la plantilla usa cantidad cero para no alterar stock accidentalmente');
    importAssert(count(array_filter(array_slice($templateRows,1), static fn(array $row): bool => (int)($row[9]??1) !== 0)) === 0, 'la plantilla mantiene inactivos sus productos de ejemplo');
    $templateImport = importRequest($serverPort, 'plantilla_productos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $template['body'], $warehouse, 'PLANTILLA-' . $token);
    importAssert((int)($templateImport['_http_status'] ?? 0) === 200 && (int)$templateImport['insertados'] === 5 && (int)$templateImport['errores'] === 0, 'las cinco filas de la plantilla completan el round-trip de importación');

    $missingWarehouseCode = 'NOW-' . $token;
    $missingWarehouse = importRequest($serverPort, 'sin-bodega.csv', 'text/csv', importCsv([
        ['Sin bodega', 100, $missingWarehouseCode, 1, 'Prueba', 'SKU-NOW', 20, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]));
    importAssert(empty($missingWarehouse['success']), 'rechaza una importación sin bodega seleccionada');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$missingWarehouseCode}'") === 0, 'el rechazo sin bodega no crea productos');

    $missingReferenceCode = 'NODOC-' . $token;
    $missingReference = importRequest($serverPort, 'sin-documento.csv', 'text/csv', importCsv([
        ['Sin documento', 100, $missingReferenceCode, 1, 'Prueba', 'SKU-NODOC', 20, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]), $warehouse, null);
    importAssert((int)($missingReference['_http_status'] ?? 0) === 400, 'rechaza una importación sin documento de respaldo');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$missingReferenceCode}'") === 0, 'el rechazo sin documento no altera el catálogo');

    $foreignWarehouseCode = 'XWH-' . $token;
    $crossTenantWarehouse = importRequest($serverPort, 'bodega-ajena.csv', 'text/csv', importCsv([
        ['Bodega ajena', 100, $foreignWarehouseCode, 1, 'Prueba', 'SKU-XWH', 20, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]), $foreignWarehouse);
    importAssert(empty($crossTenantWarehouse['success']), 'rechaza una bodega perteneciente a otro tenant');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$foreignWarehouseCode}'") === 0, 'el rechazo de bodega ajena no crea productos');

    $invalidHeaderWorkbook = bluecatXlsxBuildWorkbook(['Nombre','Precio incorrecto','Codigo','Cantidad'], [['Cabecera inválida',10,'BAD-HEADER',1]], 'Productos');
    $invalidHeader = importRequest($serverPort, 'cabecera-invalida.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $invalidHeaderWorkbook, $warehouse);
    importAssert((int)($invalidHeader['_http_status'] ?? 0) === 400 && str_contains((string)($invalidHeader['msg'] ?? ''),'cabecera'), 'rechaza una cabecera fuera del orden canónico');

    $duplicateFileCode = 'DUP-FILE-' . $token;
    $duplicateRows = importRequest($serverPort, 'filas-duplicadas.csv', 'text/csv', importCsv([
        ['Duplicado primero', 100, $duplicateFileCode, 1, 'Prueba', 'SKU-DUP-FILE-' . $token, 20, 'UNIDAD', $pieceUnitAbbreviation, 1],
        ['Duplicado segundo', 999, $duplicateFileCode, 3, 'Otra', 'SKU-DUP-FILE-OTRO-' . $token, 50, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]), $warehouse, 'DOC-DUPLICADOS');
    importAssert(
        empty($duplicateRows['success'])
        && (int)($duplicateRows['_http_status'] ?? 0) === 422
        && (int)($duplicateRows['errores'] ?? 0) === 2
        && (int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$duplicateFileCode}'") === 0,
        'rechaza todas las filas duplicadas del mismo archivo en vez de aplicar la última silenciosamente'
    );

    $formula = importRequest($serverPort, 'formula.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', importFormulaXlsx(), $warehouse);
    importAssert((int)($formula['_http_status'] ?? 0) === 400 && str_contains(mb_strtolower((string)($formula['msg'] ?? '')),'fórmula'), 'rechaza celdas XLSX con fórmula aunque tengan valor cacheado');
    $longCellRejected = false;
    try { bluecatXlsxBuildWorkbook(['Nombre'], [[str_repeat('á',32768)]], 'Productos'); }
    catch (InvalidArgumentException $error) { $longCellRejected = str_contains($error->getMessage(),'32.767'); }
    importAssert($longCellRejected, 'aplica el límite Excel de 32.767 caracteres Unicode por celda');

    $nonNumericBefore = $db->query("SELECT nombre_producto,precio_venta,precio_costo,cantidad FROM producto WHERE id_producto={$unitProduct}")->fetch_assoc();
    $nonNumericCost = importRequest($serverPort, 'costo-no-numerico.csv', 'text/csv', importCsv([
        ['No debe cambiar', 7777, $unitCode, 9, 'Prueba', 'SKU-UNIT', 'costo-inválido', 'UNIDAD', '', 1],
    ]), $warehouse, 'DOC-COSTO-INVALIDO');
    $nonNumericAfter = $db->query("SELECT nombre_producto,precio_venta,precio_costo,cantidad FROM producto WHERE id_producto={$unitProduct}")->fetch_assoc();
    importAssert((int)($nonNumericCost['_http_status'] ?? 0) === 422 && empty($nonNumericCost['success']), 'un costo no numérico produce 422 cuando ninguna fila se aplica');
    importAssert($nonNumericAfter === $nonNumericBefore, 'el costo no numérico conserva costo, precio, nombre y stock previos');

    $csvCode = $tenantCode;
    $csv = importRequest($serverPort, 'productos-actuales.csv', 'text/csv', importCsv([
        ['CSV actual', 1500, $csvCode, '1.250', 'Alimentos', 'SKU-CSV', 800, 'PESO', $unitAbbreviation, 1],
    ]), $warehouse);
    importAssert(!empty($csv['success']) && (int)$csv['insertados'] === 1 && (int)$csv['errores'] === 0, 'importa CSV actual de 10 columnas');
    $csvProduct = (int)importScalar($db, "SELECT id_producto FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$csvCode}'");
    importAssert($csvProduct > 0 && $csvProduct !== $foreignProduct, 'la importacion crea el producto dentro de la cuenta autenticada');
    importQuantity(importScalar($db, "SELECT cantidad FROM producto WHERE id_producto={$csvProduct}"), '1.250', 'el CSV acepta cantidad decimal para PESO');
    importAssert((int)importScalar($db, "SELECT id_unidad FROM producto WHERE id_producto={$csvProduct}") === $unitId, 'una unidad conocida se aplica desde CSV');
    importAssert((string)importScalar($db, "SELECT nombre_producto FROM producto WHERE id_producto={$foreignProduct}") === 'Foreign untouched', 'la coincidencia de codigo no modifica otro tenant');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$foreignProduct}"), '9.000', 'la importacion no altera stock de otro tenant');

    $skuLookupCode = 'SKU-MATCH-' . $token;
    $skuLookup = importRequest($serverPort, 'buscar-por-sku.csv', 'text/csv', importCsv([
        ['CSV encontrado por SKU', 1550, $skuLookupCode, '1.250', 'Alimentos', 'SKU-CSV', 805, 'PESO', $unitAbbreviation, 1],
    ]), $warehouse);
    importAssert((int)($skuLookup['_http_status'] ?? 0) === 200 && (int)$skuLookup['actualizados'] === 1, 'busca por SKU cuando el código no coincide');
    importAssert((int)importScalar($db, "SELECT id_producto FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$skuLookupCode}'") === $csvProduct, 'la búsqueda por SKU actualiza el producto existente sin duplicarlo');

    $ambiguousName = 'Ambiguo ' . $token;
    $stmt = $db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,codigo_de_barras,categoria,sku,precio_costo,tipo_venta,activo) VALUES (?,?,?,?,?,?,?,?,?,1)");
    $ambiguousPrice=100.0;$emptyCode=null;$ambiguousCategory='Prueba';$emptySku=null;$ambiguousCost=20.0;$ambiguousType='UNIDAD';
    $stmt->bind_param('iisdsssds',$user,$account,$ambiguousName,$ambiguousPrice,$emptyCode,$ambiguousCategory,$emptySku,$ambiguousCost,$ambiguousType);$stmt->execute();
    $stmt->bind_param('iisdsssds',$user,$account,$ambiguousName,$ambiguousPrice,$emptyCode,$ambiguousCategory,$emptySku,$ambiguousCost,$ambiguousType);$stmt->execute();$stmt->close();
    $ambiguous = importRequest($serverPort, 'nombre-ambiguo.csv', 'text/csv', importCsv([
        [$ambiguousName, 200, '', 0, 'Prueba', '', 30, 'UNIDAD', '', 1],
    ]), $warehouse);
    importAssert((int)($ambiguous['_http_status'] ?? 0) === 422 && str_contains((string)(($ambiguous['detalle_errores'][0]['motivo'] ?? '')),'más de un producto'), 'rechaza nombres ambiguos en vez de elegir LIMIT 1');

    $priceOverflowCode = 'PRICE-' . $token;
    $costOverflowCode = 'COST-' . $token;
    $decimalOverflow = importRequest($serverPort, 'precios-fuera-de-rango.csv', 'text/csv', importCsv([
        ['Precio fuera de rango', 100000000, $priceOverflowCode, 1, 'Prueba', 'SKU-PRICE', 10, 'UNIDAD', $pieceUnitAbbreviation, 1],
        ['Costo fuera de rango', 100, $costOverflowCode, 1, 'Prueba', 'SKU-COST', 100000000, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]), $warehouse);
    $overflowDetails = $decimalOverflow['detalle_errores'] ?? [];
    importAssert(empty($decimalOverflow['success']) && (int)$decimalOverflow['_http_status'] === 422 && (int)$decimalOverflow['errores'] === 2, 'rechaza precio y costo que exceden DECIMAL(10,2)');
    importAssert(count($overflowDetails) === 2 && (int)$overflowDetails[0]['fila'] === 2 && (int)$overflowDetails[1]['fila'] === 3, 'informa fila y motivo para corregir hasta 20 errores');
    importAssert(str_contains((string)$overflowDetails[0]['motivo'], 'precio de venta') && str_contains((string)$overflowDetails[1]['motivo'], 'costo'), 'los motivos de rango son comprensibles y específicos');
    importAssert(!str_contains(json_encode($overflowDetails), 'SQLSTATE'), 'los errores por fila no exponen detalles internos de base de datos');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras IN ('{$priceOverflowCode}','{$costOverflowCode}')") === 0, 'los desbordes no crean productos parciales');
    $manyInvalidRows = array_fill(0, 21, ['Fila incompleta', 10, '']);
    $manyInvalid = importRequest($serverPort, 'muchos-errores.csv', 'text/csv', importCsv($manyInvalidRows), $warehouse);
    importAssert((int)($manyInvalid['_http_status'] ?? 0) === 422 && (int)($manyInvalid['errores'] ?? 0) === 21 && count($manyInvalid['detalle_errores'] ?? []) === 20, 'limita a 20 los detalles aunque contabiliza todos los errores');

    $partialCode = 'PART-' . $token;
    $partial = importRequest($serverPort, 'importacion-parcial.csv', 'text/csv', importCsv([
        ['Producto parcial válido', 300, $partialCode, 0, 'Prueba', 'SKU-PART', 100, 'UNIDAD', '', 1],
        ['Producto parcial inválido', 100000000, 'PART-BAD-' . $token, 0, 'Prueba', 'SKU-PART-BAD', 100, 'UNIDAD', '', 1],
    ]), $warehouse);
    importAssert((int)($partial['_http_status'] ?? 0) === 207 && !empty($partial['success']) && !empty($partial['parcial']) && (int)$partial['insertados'] === 1 && (int)$partial['errores'] === 1, 'una importación parcial responde 207 con resumen y detalle');

    $xlsxCode = 'XLSX-' . $token;
    $xlsxContents = importXlsx([
        ['XLSX actual', 1600, $xlsxCode, 3, 'Abarrotes', 'SKU-XLSX', 700, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]);
    importAssert(str_starts_with($xlsxContents, "PK\x03\x04"), 'genera un contenedor ZIP OOXML real para la prueba XLSX');
    $xlsx = importRequest($serverPort, 'productos-actuales.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsxContents, $warehouse);
    importAssert(!empty($xlsx['success']) && (int)$xlsx['insertados'] === 1 && (int)$xlsx['errores'] === 0, 'importa XLSX real de 10 columnas');
    $xlsxProduct = (int)importScalar($db, "SELECT id_producto FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$xlsxCode}'");
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$xlsxProduct} AND id_bodega={$warehouse}"), '3.000', 'el XLSX crea el stock objetivo en la bodega seleccionada');

    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$xlsxProduct},{$secondaryWarehouse},6)");
    $xlsxUpdate = importRequest($serverPort, 'productos-actualizados.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', importXlsx([
        ['XLSX actualizado', 1700, $xlsxCode, 5, 'Abarrotes', 'SKU-XLSX', 710, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]), $warehouse);
    importAssert(!empty($xlsxUpdate['success']) && (int)$xlsxUpdate['actualizados'] === 1 && (int)$xlsxUpdate['errores'] === 0, 'actualiza un producto desde XLSX real');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$xlsxProduct} AND id_bodega={$warehouse}"), '5.000', 'el XLSX ajusta solo el objetivo de la bodega seleccionada');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$xlsxProduct} AND id_bodega={$secondaryWarehouse}"), '6.000', 'el XLSX mantiene intacto el stock de otra bodega');
    importQuantity(importScalar($db, "SELECT cantidad FROM producto WHERE id_producto={$xlsxProduct}"), '11.000', 'el XLSX sincroniza el espejo global con ambas bodegas');
    importQuantity(importScalar($db, "SELECT SUM(entrada) FROM kardex WHERE id_producto={$xlsxProduct} AND id_bodega={$warehouse} AND tipo_movimiento='IMPORTACION'"), '5.000', 'el XLSX registra las diferencias en Kardex para la bodega seleccionada');

    $legacyXlsCode = 'HTML-' . $token;
    $legacyXls = importRequest($serverPort, 'productos-compatibles.xls', 'application/vnd.ms-excel', importLegacyXls([
        ['XLS compatible', 450, $legacyXlsCode, 2, 'Legacy', 'SKU-HTML', 100, 'UNIDAD', $pieceUnitAbbreviation, 1],
    ]), $warehouse);
    importAssert(!empty($legacyXls['success']) && (int)$legacyXls['insertados'] === 1, 'mantiene compatibilidad con la tabla HTML XLS generada por versiones anteriores');

    $binaryXlsCode = 'BIFF-' . $token;
    $binaryXls = importRequest($serverPort, 'productos-binarios.xls', 'application/vnd.ms-excel', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64), $warehouse);
    importAssert(empty($binaryXls['success']) && str_contains((string)($binaryXls['msg'] ?? ''), 'XLSX o CSV'), 'rechaza XLS binario BIFF sin fingir soporte');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$binaryXlsCode}'") === 0, 'el rechazo BIFF no altera productos');

    $db->query("UPDATE stock SET disponible=4.500 WHERE id_producto={$legacyProduct} AND id_bodega={$secondaryWarehouse}");
    $db->query("UPDATE producto SET cantidad=9.500 WHERE id_producto={$legacyProduct}");
    $fractionalOtherWarehouse = importRequest($serverPort, 'cambio-unidad-bodega-fraccionaria.csv', 'text/csv', importCsv([
        ['Legacy no debe cambiar', 1100, $legacyCode, 5, 'Legacy cat', 'SKU-KEEP', 125, 'UNIDAD', $pieceUnitAbbreviation, 0],
    ]), $warehouse, 'DOC-CAMBIO-UNIDAD');
    importAssert(
        empty($fractionalOtherWarehouse['success'])
        && (int)($fractionalOtherWarehouse['_http_status'] ?? 0) === 422
        && (string)importScalar($db, "SELECT tipo_venta FROM producto WHERE id_producto={$legacyProduct}") === 'PESO',
        'no cambia un producto a UNIDAD cuando otra bodega conserva existencias fraccionarias'
    );
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$legacyProduct} AND id_bodega={$warehouse}"), '5.000', 'el rechazo mantiene la bodega objetivo');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$legacyProduct} AND id_bodega={$secondaryWarehouse}"), '4.500', 'el rechazo mantiene la fracción de la otra bodega');
    $db->query("UPDATE stock SET disponible=4.000 WHERE id_producto={$legacyProduct} AND id_bodega={$secondaryWarehouse}");
    $db->query("UPDATE producto SET cantidad=9.000 WHERE id_producto={$legacyProduct}");

    $legacy = importRequest($serverPort, 'productos-legacy.csv', 'text/csv', importCsv([
        ['Legacy actualizado', 1100, $legacyCode, '7.500'],
    ], ['Nombre','Precio','Codigo','Cantidad']), $warehouse);
    importAssert(!empty($legacy['success']) && (int)$legacy['actualizados'] === 1 && (int)$legacy['errores'] === 0, 'importa archivo legacy de 4 columnas');
    $legacyRow = $db->query("SELECT sku,precio_costo,tipo_venta,id_unidad,activo FROM producto WHERE id_producto={$legacyProduct}")->fetch_assoc();
    importAssert($legacyRow['sku'] === 'SKU-KEEP' && (float)$legacyRow['precio_costo'] === 125.0 && $legacyRow['tipo_venta'] === 'PESO' && (int)$legacyRow['id_unidad'] === $unitId && (int)$legacyRow['activo'] === 0, 'el formato legacy preserva SKU, costo, tipo, unidad y estado');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$legacyProduct} AND id_bodega={$warehouse}"), '7.500', 'el target legacy ajusta solo la bodega seleccionada');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$legacyProduct} AND id_bodega={$secondaryWarehouse}"), '4.000', 'la importación conserva intacta otra bodega del mismo tenant');
    importQuantity(importScalar($db, "SELECT cantidad FROM producto WHERE id_producto={$legacyProduct}"), '11.500', 'el espejo del producto conserva la suma de todas sus bodegas');
    importQuantity(importScalar($db, "SELECT entrada FROM kardex WHERE id_producto={$legacyProduct} AND id_bodega={$warehouse} AND tipo_movimiento='IMPORTACION'"), '2.500', 'el target legacy genera Kardex por la diferencia en la bodega seleccionada');

    $db->query("UPDATE stock SET reservado=2,comprometido=2 WHERE id_producto={$protectedProduct}");
    $protected = importRequest($serverPort, 'stock-protegido.csv', 'text/csv', importCsv([
        ['Protected changed', 9999, $protectedCode, 3],
    ]), $warehouse);
    importAssert(empty($protected['success']) && (int)$protected['_http_status'] === 422 && (int)$protected['errores'] === 1 && (int)$protected['actualizados'] === 0, 'rechaza target menor que stock reservado o comprometido');
    importAssert((string)importScalar($db, "SELECT nombre_producto FROM producto WHERE id_producto={$protectedProduct}") === 'Protected original' && (float)importScalar($db, "SELECT precio_venta FROM producto WHERE id_producto={$protectedProduct}") === 1000.0, 'el rechazo revierte los cambios del producto');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$protectedProduct} AND id_bodega={$warehouse}"), '5.000', 'el rechazo conserva stock completo');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM kardex WHERE id_producto={$protectedProduct}") === 0, 'el rechazo no deja movimientos Kardex parciales');

    $fractional = importRequest($serverPort, 'unidad-fraccionaria.csv', 'text/csv', importCsv([
        ['Unit changed', 600, $unitCode, '1.500'],
    ]), $warehouse);
    importAssert(empty($fractional['success']) && (int)$fractional['_http_status'] === 422 && (int)$fractional['errores'] === 1, 'rechaza cantidad fraccionaria para UNIDAD');
    importQuantity(importScalar($db, "SELECT disponible FROM stock WHERE id_producto={$unitProduct} AND id_bodega={$warehouse}"), '2.000', 'el rechazo unitario conserva stock');

    $unknownCode = 'BAD-' . $token;
    $unknown = importRequest($serverPort, 'unidad-desconocida.csv', 'text/csv', importCsv([
        ['Unidad desconocida', 100, $unknownCode, 1, 'Prueba', 'SKU-BAD', 20, 'UNIDAD', 'NO-' . $token, 1],
    ]), $warehouse);
    importAssert(empty($unknown['success']) && (int)$unknown['_http_status'] === 422 && (int)$unknown['errores'] === 1, 'rechaza unidad de medida desconocida');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$unknownCode}'") === 0, 'la unidad desconocida revierte el alta completa');

    $incompatibleCode = 'MIX-' . $token;
    $incompatible = importRequest($serverPort, 'unidad-incompatible.csv', 'text/csv', importCsv([
        ['Unidad incompatible', 100, $incompatibleCode, 1, 'Prueba', 'SKU-MIX', 20, 'UNIDAD', $unitAbbreviation, 1],
    ]), $warehouse);
    importAssert(empty($incompatible['success']) && (int)$incompatible['_http_status'] === 422 && (int)$incompatible['errores'] === 1, 'rechaza una unidad PESO para un producto tipo UNIDAD');
    importAssert((int)importScalar($db, "SELECT COUNT(*) FROM producto WHERE id_cuenta={$account} AND codigo_de_barras='{$incompatibleCode}'") === 0, 'la incompatibilidad tipo/unidad revierte el alta completa');

    echo "OK integridad de importacion de productos verificada.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ([$router, $serverLog] as $temporary) {
        if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
    }
    if ($account > 0) {
        $productIds = [];
        try {
            $result = $db->query("SELECT id_producto FROM producto WHERE id_cuenta IN ({$account},{$foreignAccount})");
            while ($row = $result->fetch_assoc()) $productIds[] = (int)$row['id_producto'];
        } catch (Throwable) {}
        if ($productIds) {
            $ids = implode(',', $productIds);
            foreach (['kardex','stock'] as $table) {
                try { $db->query("DELETE FROM {$table} WHERE id_producto IN ({$ids})"); } catch (Throwable) {}
            }
            try { $db->query("DELETE FROM producto WHERE id_producto IN ({$ids})"); } catch (Throwable) {}
        }
        foreach (['inventario_auditoria','pos_auditoria','core_auditoria','empleado_auditoria'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_user IN ({$user},{$foreignUser},{$limitedUser})"); } catch (Throwable) {}
        }
        try { $db->query("DELETE FROM categoria WHERE id_cuenta IN ({$account},{$foreignAccount})"); } catch (Throwable) {}
        try { $db->query("DELETE FROM bodega WHERE id_bodega IN ({$warehouse},{$secondaryWarehouse},{$foreignWarehouse})"); } catch (Throwable) {}
        try { $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta IN ({$account},{$foreignAccount})"); } catch (Throwable) {}
        try { $db->query("DELETE FROM usuario_rol WHERE id_user IN ({$user},{$foreignUser},{$limitedUser})"); } catch (Throwable) {}
        try { $db->query("DELETE FROM usuario WHERE id_user IN ({$user},{$foreignUser},{$limitedUser})"); } catch (Throwable) {}
        try { $db->query("DELETE FROM rol WHERE id_cuenta IN ({$account},{$foreignAccount})"); } catch (Throwable) {}
        try { $db->query("DELETE FROM cuenta WHERE id_cuenta IN ({$account},{$foreignAccount})"); } catch (Throwable) {}
    }
    if ($unitId > 0 || $pieceUnitId > 0) {
        $units = implode(',', array_filter([$unitId, $pieceUnitId], static fn(int $id): bool => $id > 0));
        try { $db->query("DELETE FROM unidad_medida WHERE id_unidad IN ({$units})"); } catch (Throwable) {}
    }
}
