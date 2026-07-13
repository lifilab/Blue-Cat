<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$envFile = $root . '/.env';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $candidate = substr($argument, 6);
        $envFile = preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\\/)/', $candidate) ? $candidate : $root . '/' . $candidate;
    }
}
if (!is_file($envFile)) {
    fwrite(STDERR, "Falta {$envFile}. Configure el entorno antes de inventariar.\n");
    exit(1);
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    $_ENV[$key] = trim($value, "\"'");
}

$schema = $_ENV['DB_NAME'] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
    throw new RuntimeException('Nombre de esquema invalido.');
}
$db = new mysqli(
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    $schema,
    (int)($_ENV['DB_PORT'] ?? 3306)
);
$db->set_charset('utf8mb4');

$scopeColumns = [
    'id_cuenta', 'id_user', 'id_empresa', 'id_sucursal', 'id_bodega',
    'id_caja', 'id_sesion', 'id_pedido', 'id_producto', 'id_proveedor', 'id_cliente',
];
$stmt = $db->prepare(
    "SELECT t.TABLE_NAME, c.COLUMN_NAME, c.ORDINAL_POSITION
       FROM information_schema.TABLES t
       JOIN information_schema.COLUMNS c
         ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
      WHERE t.TABLE_SCHEMA=? AND t.TABLE_TYPE='BASE TABLE'
      ORDER BY t.TABLE_NAME, c.ORDINAL_POSITION"
);
$stmt->bind_param('s', $schema);
$stmt->execute();
$result = $stmt->get_result();
$tables = [];
while ($row = $result->fetch_assoc()) {
    $table = $row['TABLE_NAME'];
    $tables[$table] ??= ['columns' => [], 'scope' => [], 'foreign_keys' => 0, 'indexes' => 0];
    $tables[$table]['columns'][] = $row['COLUMN_NAME'];
    if (in_array($row['COLUMN_NAME'], $scopeColumns, true)) {
        $tables[$table]['scope'][] = $row['COLUMN_NAME'];
    }
}
$stmt->close();

$stmt = $db->prepare(
    "SELECT TABLE_NAME, COUNT(*) total
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL
      GROUP BY TABLE_NAME"
);
$stmt->bind_param('s', $schema);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $tables[$row['TABLE_NAME']]['foreign_keys'] = (int)$row['total'];
}
$stmt->close();

$stmt = $db->prepare(
    "SELECT TABLE_NAME, COUNT(DISTINCT INDEX_NAME) total
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=?
      GROUP BY TABLE_NAME"
);
$stmt->bind_param('s', $schema);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $tables[$row['TABLE_NAME']]['indexes'] = (int)$row['total'];
}
$stmt->close();

$report = ['schema' => $schema, 'table_count' => count($tables), 'tables' => []];
foreach ($tables as $name => $data) {
    $report['tables'][] = [
        'name' => $name,
        'scope' => $data['scope'],
        'foreign_keys' => $data['foreign_keys'],
        'indexes' => $data['indexes'],
        'column_count' => count($data['columns']),
    ];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
