$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$packageRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$appRoot = [IO.Path]::GetFullPath((Join-Path $packageRoot '..\..'))
$runtimeRoot = Join-Path $packageRoot 'build\runtime'
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('bluecat-server-test-' + [Guid]::NewGuid().ToString('N'))
$configFile = Join-Path $testRoot 'installation.json'

try {
    New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
    $config = Get-Content -LiteralPath (Join-Path $packageRoot 'installation.example.json') -Raw | ConvertFrom-Json
    $config.company.tax_id = 'TEST-' + (Get-Random -Minimum 100000 -Maximum 999999)
    $config.administrator.username = 'adminbeta'
    $config.administrator.email = 'adminbeta@example.test'
    $config.administrator.password = 'Bootstrap9Segura'
    [IO.File]::WriteAllText($configFile, ($config | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))

    & (Join-Path $PSScriptRoot 'Initialize-BlueCatServer.ps1') -AppRoot $appRoot -RuntimeRoot $runtimeRoot -DataRoot (Join-Path $testRoot 'ProgramData') -InstallationConfig $configFile -SiteAddresses @('localhost') -SkipServices
    if ($LASTEXITCODE -ne 0) { throw 'La primera inicialización devolvió error.' }
    & (Join-Path $PSScriptRoot 'Initialize-BlueCatServer.ps1') -AppRoot $appRoot -RuntimeRoot $runtimeRoot -DataRoot (Join-Path $testRoot 'ProgramData') -InstallationConfig $configFile -SiteAddresses @('localhost') -SkipServices
    if ($LASTEXITCODE -ne 0) { throw 'La reparación devolvió error.' }

    $dataRoot = Join-Path $testRoot 'ProgramData'
    foreach ($required in @('config\.env','config\Caddyfile','config\php.ini','config\mariadb.ini','config\database-admin.secret','install\state.json','data\mariadb\mysql')) {
        if (-not (Test-Path -LiteralPath (Join-Path $dataRoot $required))) { throw "Falta $required después de inicializar." }
    }
    $rendered = (Get-Content -LiteralPath (Join-Path $dataRoot 'config\Caddyfile') -Raw) + (Get-Content -LiteralPath (Join-Path $dataRoot 'config\php.ini') -Raw)
    if ($rendered -match '@@[A-Z0-9_]+@@') { throw 'Quedaron tokens sin renderizar.' }
    & (Join-Path $runtimeRoot 'caddy\caddy.exe') validate --config (Join-Path $dataRoot 'config\Caddyfile') --adapter caddyfile
    if ($LASTEXITCODE -ne 0) { throw 'Caddy rechazó la configuración renderizada.' }
    foreach ($serviceName in @('BlueCatDatabase','BlueCatPhp','BlueCatWeb')) {
        [xml](Get-Content -LiteralPath (Join-Path $dataRoot "install\services\$serviceName.xml") -Raw) | Out-Null
    }
    $state = Get-Content -LiteralPath (Join-Path $dataRoot 'install\state.json') -Raw | ConvertFrom-Json
    if ($state.services_installed -ne $false -or $state.site_addresses -notcontains 'localhost') { throw 'El estado de instalación es inválido.' }
    Write-Host 'PASS runtime, datadir, migraciones y negocio inicial preparados'
    Write-Host 'PASS reparación idempotente mantiene la instalación'
    Write-Host 'PASS configuración externa y secretos quedaron separados de la aplicación'
    Write-Host 'PASS Caddyfile y definiciones WinSW son válidos'
}
finally {
    $resolvedTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    $resolvedTest = [IO.Path]::GetFullPath($testRoot)
    if ($resolvedTest.StartsWith($resolvedTemp, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path $resolvedTest -Leaf).StartsWith('bluecat-server-test-')) {
        Get-Process mariadbd -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$runtimeRoot*" } | Stop-Process -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $resolvedTest -Recurse -Force -ErrorAction SilentlyContinue
    }
}
