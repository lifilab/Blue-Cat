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
    fwrite(STDERR, "Falta {$envFile}. Configure el entorno antes de migrar.\n");
    exit(1);
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    $_ENV[$key] = trim($value, "\"'");
}
$db = new mysqli($_ENV['DB_HOST'] ?? '127.0.0.1', $_ENV['DB_USER'] ?? '', $_ENV['DB_PASSWORD'] ?? '', $_ENV['DB_NAME'] ?? '', (int)($_ENV['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
$db->query("CREATE TABLE IF NOT EXISTS schema_migration (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files, SORT_NATURAL);
if (in_array('--with-demo', $argv, true)) {
    $demo = glob($root . '/database/demo/*.sql') ?: [];
    sort($demo, SORT_NATURAL);
    $files = array_merge($files, $demo);
}
foreach ($files as $file) {
    $version = basename($file);
    $stmt = $db->prepare('SELECT 1 FROM schema_migration WHERE version=?');
    $stmt->bind_param('s', $version);
    $stmt->execute();
    $applied = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($applied) { echo "SKIP {$version}\n"; continue; }
    echo "APPLY {$version}\n";
    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/^\\s*DELIMITER\\s+.*$/mi', '', $sql);
    $sql = str_replace('$$', ';', $sql);
    if (!$db->multi_query($sql)) throw new RuntimeException("{$version}: {$db->error}");
    do {
        if ($result = $db->store_result()) $result->free();
        if (!$db->more_results()) break;
    } while ($db->next_result());
    if ($db->errno) throw new RuntimeException("{$version}: {$db->error}");
    $stmt = $db->prepare('INSERT INTO schema_migration (version) VALUES (?)');
    $stmt->bind_param('s', $version);
    $stmt->execute();
    $stmt->close();
}
echo "Migraciones completas.\n";
