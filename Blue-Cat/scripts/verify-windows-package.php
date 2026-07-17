<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$package = $root . '/packaging/windows';
$errors = [];
$lockFile = $package . '/runtime-lock.json';
$lock = is_file($lockFile) ? json_decode((string)file_get_contents($lockFile), true) : null;
if (!is_array($lock) || ($lock['schema'] ?? 0) !== 1 || ($lock['platform'] ?? '') !== 'windows-x64') {
    $errors[] = 'runtime-lock.json inválido.';
} else {
    $required = ['caddy','php','mariadb','winsw','vc-redist-x64','webview2-sdk','webview2-runtime-installer'];
    $found = [];
    foreach (($lock['components'] ?? []) as $component) {
        $id = (string)($component['id'] ?? '');
        $found[] = $id;
        $algorithm = strtoupper((string)($component['hash_algorithm'] ?? ''));
        $hash = strtolower((string)($component['hash'] ?? ''));
        $expectedLength = $algorithm === 'SHA256' ? 64 : ($algorithm === 'SHA512' ? 128 : 0);
        if ($id === '' || !preg_match('/^https:\/\//', (string)($component['url'] ?? ''))) $errors[] = "Origen inseguro o ausente para {$id}.";
        if ($expectedLength === 0 || !preg_match('/^[a-f0-9]{' . $expectedLength . '}$/', $hash)) $errors[] = "Checksum inválido para {$id}.";
        if (trim((string)($component['license'] ?? '')) === '' || !str_starts_with((string)($component['license_url'] ?? ''), 'https://')) $errors[] = "Licencia incompleta para {$id}.";
    }
    foreach ($required as $id) if (!in_array($id, $found, true)) $errors[] = "Falta el runtime {$id}.";
}

$requiredFiles = [
    'templates/Caddyfile.template'=>['@private path','php_fastcgi 127.0.0.1:9074','tls internal'],
    'templates/php.ini.template'=>['session.cookie_secure=1','display_errors=Off','extension=mysqli'],
    'templates/mariadb.ini.template'=>['bind-address=127.0.0.1','port=3307','local-infile=0'],
    'services/BlueCatDatabase.xml.template'=>['<id>BlueCatDatabase</id>','onfailure action="restart"'],
    'services/BlueCatPhp.xml.template'=>['<depend>BlueCatDatabase</depend>','127.0.0.1:9074'],
    'services/BlueCatWeb.xml.template'=>['<depend>BlueCatPhp</depend>','Blue-Cat Web'],
    'scripts/Fetch-Runtimes.ps1'=>['Get-FileHash','runtime-lock.json'],
    'scripts/Initialize-BlueCatServer.ps1'=>['Protect-MachineSecret','BlueCatDatabase','New-NetFirewallRule','--password='],
    'scripts/Test-Initialize-BlueCatServer.ps1'=>['reparación idempotente','bluecat-server-test-'],
    'scripts/New-InstallerStage.ps1'=>['artifact-manifest.json','Get-FileHash'],
    'scripts/Build-Installer.ps1'=>['RequireSignature','BlueCat-Server.spdx.json','SHA256SUMS.txt','signtool.exe'],
    'scripts/Build-DesktopLauncher.ps1'=>['BlueCatDesktop.exe','Microsoft.Web.WebView2.Wpf.dll','win32icon','Drawing.Color]::Transparent','256'],
    'scripts/Install-Prerequisites.ps1'=>['Get-AuthenticodeSignature','Microsoft Corporation','webview2-runtime-installer','3010'],
    'scripts/Invoke-BlueCatInstallation.ps1'=>['installer.log','Initialize-BlueCatServer.ps1','RedirectStandardError'],
    'scripts/Stop-BlueCatServices.ps1'=>['BlueCatWeb','Stop-Service','Dispose'],
    'scripts/Uninstall-BlueCatServices.ps1'=>['Datos conservados','BlueCatDatabase'],
    'desktop/BlueCatDesktop.cs'=>['CoreWebView2Environment','--fullscreen','EnsureServicesAsync','WaitForBackendAsync','SecurityProtocolType.Tls12','desktop.log'],
    'installer/BlueCatServer.iss'=>['bluecat-installation.json','PrivilegesRequired=admin','SignedUninstaller','Invoke-BlueCatInstallation.ps1','{autodesktop}\\Blue-Cat','BlueCatDesktop.exe','SetupIconFile'],
];
foreach ($requiredFiles as $relative=>$needles) {
    $contents = @file_get_contents($package . '/' . $relative);
    if ($contents === false) { $errors[] = "Falta {$relative}."; continue; }
    foreach ($needles as $needle) if (!str_contains($contents, $needle)) $errors[] = "Falta {$needle} en {$relative}.";
}

$example = json_decode((string)@file_get_contents($package . '/installation.example.json'), true);
$examplePassword = (string)($example['administrator']['password'] ?? '');
if ($examplePassword === '' || !str_contains($examplePassword, 'REEMPLAZAR')) $errors[] = 'El ejemplo debe exigir una contraseña ingresada por el usuario.';

if ($errors) {
    fwrite(STDERR, "Windows package baseline: FALLÓ\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo 'Windows package baseline: OK (' . count($lock['components']) . " runtimes fijados)\n";
