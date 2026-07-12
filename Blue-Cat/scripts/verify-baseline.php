<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

function commandOk(string $command, array &$errors): void {
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) $errors[] = $command . "\n" . implode("\n", $output);
}

foreach ([$root . '/assets/api'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            commandOk(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $errors);
        }
    }
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/js'));
foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'js') {
        commandOk('node --check ' . escapeshellarg($file->getPathname()), $errors);
    }
}

foreach ([$root.'/VERSION',$root.'/BETA_SCOPE.md',$root.'/GOVERNANCE.md',$root.'/docs/adr/0001-arquitectura-local-first.md',$root.'/database/migrations',$root.'/database/demo'] as $path) {
    if (!file_exists($path)) $errors[] = 'Falta artefacto de línea base: ' . $path;
}

$version = trim((string) file_get_contents($root . '/VERSION'));
if (!preg_match('/^0\.\d+\.\d+(?:-beta\.\d+)?$/', $version)) $errors[] = 'VERSION inválida: ' . $version;

$legacyAllowlist = array_values(array_filter(array_map('trim', file($root . '/docs/legacy-api-allowlist.txt'))));
sort($legacyAllowlist);
$legacyActual = array_map('basename', glob($root . '/assets/api/compat/*.php') ?: []);
sort($legacyActual);
if ($legacyActual !== $legacyAllowlist) {
    $errors[] = "Cambió la superficie legacy sin migración explícita.\nEsperado: ".implode(', ',$legacyAllowlist)."\nActual: ".implode(', ',$legacyActual);
}

if ($errors) {
    fwrite(STDERR, "Baseline falló:\n\n" . implode("\n\n", $errors) . "\n");
    exit(1);
}
echo "Baseline válido para Blue-Cat {$version}.\n";
