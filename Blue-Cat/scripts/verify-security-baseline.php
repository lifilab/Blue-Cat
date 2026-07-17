<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$requireText = static function (string $relative, string $needle) use ($root, &$errors): void {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false || !str_contains($contents, $needle)) {
        $errors[] = "Falta '{$needle}' en {$relative}";
    }
};

$requireText('assets/api/_security.php', "throw new RuntimeException('APP_KEY no configurada");
$requireText('assets/api/_security.php', 'securityRequireTransport();');
$requireText('assets/api/_db.php', 'securityValidateSession');
$requireText('assets/api/_security.php', 'securityRequireCsrf();');
$requireText('.htaccess', 'storage');
$requireText('.htaccess', 'Content-Security-Policy');
$requireText('database/migrations/020_security_foundation.sql', 'core_sesion');
$requireText('database/migrations/021_pos_action_permissions.sql', "'pos','asociar_cliente'");
$requireText('assets/js/security.js', 'renderToast');

$publicFiles = glob($root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '*.html') ?: [];
foreach ($publicFiles as $file) {
    $contents = file_get_contents($file);
    if ($contents !== false && preg_match('/<script[^>]+src=/i', $contents) && !str_contains($contents, '../assets/js/security.js')) {
        $errors[] = 'Falta security.js en public/' . basename($file);
    }
}

$unsafeToastPatterns = [
    '/(?:toast|showToast)[^{]*\{.{0,300}?\.innerHTML\s*=\s*(?:msg|message)\s*;/s',
    '/\.innerHTML\s*=\s*[\'\"]<i[^;]+\+\s*(?:msg|message)\s*;/s',
];
foreach (glob($root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . '*.js') ?: [] as $file) {
    $contents = file_get_contents($file);
    foreach ($unsafeToastPatterns as $pattern) {
        if ($contents !== false && preg_match($pattern, $contents)) {
            $errors[] = 'Toast dinámico inseguro en assets/js/' . basename($file);
            break;
        }
    }
}

if ($errors) {
    fwrite(STDERR, "Security baseline: FALLÓ\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
fwrite(STDOUT, 'Security baseline: OK (' . count($publicFiles) . " páginas verificadas)\n");
