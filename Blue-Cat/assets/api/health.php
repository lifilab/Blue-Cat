<?php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';
$configuredEnv = getenv('BLUECAT_ENV_FILE');
loadEnv($configuredEnv !== false && $configuredEnv !== '' ? $configuredEnv : dirname(__DIR__, 2) . '/.env');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$version = trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: 'development';
$response = [
    'service' => 'bluecat-server',
    'version' => $version,
    'status' => 'unavailable',
    'database' => false,
    'setup' => false,
    'time' => gmdate('c'),
];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(
        (string)(getenv('DB_HOST') ?: '127.0.0.1'),
        (string)(getenv('DB_USER') ?: ''),
        (string)(getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ''),
        (string)(getenv('DB_NAME') ?: ''),
        (int)(getenv('DB_PORT') ?: 3306)
    );
    $db->set_charset('utf8mb4');
    $db->query('SELECT 1');
    $response['database'] = true;
    $table = $db->query("SELECT COUNT(*) total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='core_installation'")->fetch_assoc();
    if ((int)($table['total'] ?? 0) === 1) {
        $installation = $db->query('SELECT setup_completed FROM core_installation WHERE id_installation=1')->fetch_assoc();
        $response['setup'] = (int)($installation['setup_completed'] ?? 0) === 1;
    }
    $response['status'] = $response['setup'] ? 'ok' : 'setup_required';
    $db->close();
    http_response_code(200);
} catch (Throwable $error) {
    error_log('health database unavailable: ' . $error->getMessage());
    http_response_code(503);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
