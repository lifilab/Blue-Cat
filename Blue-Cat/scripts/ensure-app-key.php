<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $candidate = substr($arg, 6);
        $envPath = str_starts_with($candidate, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $candidate)
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    }
}

if (!is_file($envPath)) {
    fwrite(STDERR, "No existe el archivo de entorno: {$envPath}\n");
    exit(1);
}
if (!is_readable($envPath) || !is_writable($envPath)) {
    fwrite(STDERR, "El archivo de entorno no permite lectura y escritura.\n");
    exit(1);
}

$contents = file_get_contents($envPath);
if ($contents === false) {
    fwrite(STDERR, "No fue posible leer el archivo de entorno.\n");
    exit(1);
}
if (preg_match('/^APP_KEY=(.+)$/m', $contents, $match) && trim($match[1], " \t\r\n\"'") !== '') {
    fwrite(STDOUT, "APP_KEY ya está configurada; no se realizaron cambios.\n");
    exit(0);
}

$key = 'base64:' . base64_encode(random_bytes(32));
if (preg_match('/^APP_KEY=.*$/m', $contents)) {
    $updated = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents, 1);
} else {
    $updated = rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
}
if (!is_string($updated)) {
    fwrite(STDERR, "No fue posible preparar APP_KEY.\n");
    exit(1);
}

$temp = $envPath . '.tmp.' . bin2hex(random_bytes(6));
if (file_put_contents($temp, $updated, LOCK_EX) === false || !rename($temp, $envPath)) {
    @unlink($temp);
    fwrite(STDERR, "No fue posible guardar APP_KEY de forma atómica.\n");
    exit(1);
}
fwrite(STDOUT, "APP_KEY única generada correctamente.\n");
