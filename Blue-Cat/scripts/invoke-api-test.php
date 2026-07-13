<?php
declare(strict_types=1);

function cliOption(string $name): ?string {
    foreach (array_slice($_SERVER['argv'],1) as $arg) {
        if (str_starts_with($arg,$name.'=')) return substr($arg,strlen($name)+1);
    }
    return null;
}

$root = dirname(__DIR__);
$env = cliOption('--env');
$endpoint = basename(cliOption('--endpoint') ?? '');
$userId = (int)(cliOption('--user') ?? 0);
if (!$env || !preg_match('/^[a-z0-9_-]+\.php$/i',$endpoint) || $userId <= 0) exit(2);
$envPath = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/',$env) ? $env : $root.'/'.$env;
putenv('BLUECAT_ENV_FILE='.$envPath);
require_once $root.'/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') exit(3);

$_SESSION['user_id'] = $userId;
$_SERVER['REQUEST_METHOD'] = strtoupper(cliOption('--method') ?? 'GET');
$query = cliOption('--query');
$_GET = $query ? (json_decode(base64_decode($query),true) ?: []) : [];
$body = cliOption('--body');
if ($body !== null) putenv('BLUECAT_TEST_JSON='.base64_decode($body));
require $root.'/assets/api/'.$endpoint;
