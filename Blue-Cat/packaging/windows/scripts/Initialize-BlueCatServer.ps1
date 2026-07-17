[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$AppRoot,
    [Parameter(Mandatory=$true)][string]$RuntimeRoot,
    [Parameter(Mandatory=$true)][string]$DataRoot,
    [Parameter(Mandatory=$true)][string]$InstallationConfig,
    [string[]]$SiteAddresses = @(),
    [switch]$SkipServices
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.Security

function New-RandomSecret([int]$Bytes = 32) {
    $buffer = New-Object byte[] $Bytes
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($buffer)
}
function Write-Utf8NoBom([string]$Path, [string]$Value) {
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
}
function Convert-ToForwardSlash([string]$Path) { return ([IO.Path]::GetFullPath($Path) -replace '\\','/') }
function Render-Template([string]$Source, [string]$Destination, [hashtable]$Tokens) {
    $content = Get-Content -LiteralPath $Source -Raw
    foreach ($key in $Tokens.Keys) { $content = $content.Replace("@@$key@@", [string]$Tokens[$key]) }
    if ($content -match '@@[A-Z0-9_]+@@') { throw "Quedaron tokens sin resolver en $Source." }
    Write-Utf8NoBom $Destination $content
}
function Wait-TcpPort([string]$HostName, [int]$Port, [int]$TimeoutSeconds = 60) {
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        try {
            $client = [Net.Sockets.TcpClient]::new()
            $task = $client.ConnectAsync($HostName, $Port)
            if ($task.Wait(500) -and $client.Connected) { $client.Dispose(); return }
            $client.Dispose()
        } catch {}
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "El puerto $HostName`:$Port no quedó disponible."
}
function Protect-MachineSecret([string]$Secret, [string]$Destination) {
    $bytes = [Text.Encoding]::UTF8.GetBytes($Secret)
    $protected = [Security.Cryptography.ProtectedData]::Protect($bytes, $null, [Security.Cryptography.DataProtectionScope]::LocalMachine)
    Set-Content -LiteralPath $Destination -Value ([Convert]::ToBase64String($protected)) -Encoding ASCII
}
function Unprotect-MachineSecret([string]$Source) {
    $protected = [Convert]::FromBase64String((Get-Content -LiteralPath $Source -Raw).Trim())
    $bytes = [Security.Cryptography.ProtectedData]::Unprotect($protected, $null, [Security.Cryptography.DataProtectionScope]::LocalMachine)
    return [Text.Encoding]::UTF8.GetString($bytes)
}
function Assert-Executable([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Falta el ejecutable $Path." }
}
function Wait-ServiceAbsent([string]$Name, [int]$TimeoutSeconds = 30) {
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
        if (-not $service) { return }
        $service.Dispose()
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "El servicio anterior $Name quedó pendiente de eliminación. Reinicie Windows y ejecute Reparar."
}

$packageRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$app = [IO.Path]::GetFullPath($AppRoot)
$runtime = [IO.Path]::GetFullPath($RuntimeRoot)
$data = [IO.Path]::GetFullPath($DataRoot)
$installConfig = [IO.Path]::GetFullPath($InstallationConfig)
if (-not (Test-Path -LiteralPath $installConfig -PathType Leaf)) { throw 'Falta installation.json.' }
if (-not (Test-Path -LiteralPath (Join-Path $app 'VERSION') -PathType Leaf)) { throw 'AppRoot no contiene una aplicación Blue-Cat válida.' }

$paths = @{
    Config = Join-Path $data 'config'; Database = Join-Path $data 'data\mariadb'; Caddy = Join-Path $data 'data\caddy'
    Logs = Join-Path $data 'logs'; Backups = Join-Path $data 'backups'; Install = Join-Path $data 'install'
    Temp = Join-Path $data 'temp'; Sessions = Join-Path $data 'data\sessions'; Uploads = Join-Path $data 'data\uploads'
    Services = if ($SkipServices) { Join-Path $data 'install\services' } else { Join-Path $runtime 'services' }
}
foreach ($path in $paths.Values) { New-Item -ItemType Directory -Path $path -Force | Out-Null }

$caddyExe = Join-Path $runtime 'caddy\caddy.exe'
$phpExe = Join-Path $runtime 'php\php.exe'
$phpCgiExe = Join-Path $runtime 'php\php-cgi.exe'
$mariaBin = Join-Path $runtime 'mariadb\bin'
$mariadbdExe = Join-Path $mariaBin 'mariadbd.exe'
$installDbExe = Join-Path $mariaBin 'mariadb-install-db.exe'
$mariaClientExe = Join-Path $mariaBin 'mariadb.exe'
$mariaAdminExe = Join-Path $mariaBin 'mariadb-admin.exe'
$winswExe = Join-Path $runtime 'winsw\WinSW-x64.exe'
foreach ($executable in @($caddyExe,$phpExe,$phpCgiExe,$mariadbdExe,$installDbExe,$mariaClientExe,$mariaAdminExe,$winswExe)) { Assert-Executable $executable }

if (-not $SkipServices) {
    $conflicts = @()
    Get-NetTCPConnection -State Listen -LocalPort 443 -ErrorAction SilentlyContinue | ForEach-Object {
        $process = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue
        if ($process -and $process.ProcessName -ne 'caddy') {
            $conflicts += "$($process.ProcessName) (PID $($process.Id))"
        }
    }
    if ($conflicts.Count -gt 0) {
        throw "El puerto HTTPS 443 esta ocupado por $($conflicts -join ', '). Cierre Laragon/Apache u otro servidor y ejecute Reparar."
    }
}

if ($SiteAddresses.Count -eq 0) {
    $SiteAddresses = @('localhost', [Net.Dns]::GetHostName())
    Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -match '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' } |
        ForEach-Object { $SiteAddresses += $_.IPAddress }
}
$SiteAddresses = $SiteAddresses | Where-Object { $_ -match '^[a-zA-Z0-9.-]+$' } | Select-Object -Unique
if ($SiteAddresses.Count -eq 0) { throw 'No se encontró una dirección válida para HTTPS.' }
$siteLabel = ($SiteAddresses | ForEach-Object { 'https://' + $_ }) -join ', '

$envFile = Join-Path $paths.Config '.env'
$phpIni = Join-Path $paths.Config 'php.ini'
$mariaIni = Join-Path $paths.Config 'mariadb.ini'
$caddyFile = Join-Path $paths.Config 'Caddyfile'
Render-Template (Join-Path $packageRoot 'templates\php.ini.template') $phpIni @{
    PHP_ERROR_LOG=(Convert-ToForwardSlash (Join-Path $paths.Logs 'php-error.log')); PHP_EXT_DIR=(Convert-ToForwardSlash (Join-Path $runtime 'php\ext'))
    TEMP_DIR=(Convert-ToForwardSlash $paths.Temp); UPLOAD_TEMP=(Convert-ToForwardSlash $paths.Temp); SESSION_DIR=(Convert-ToForwardSlash $paths.Sessions)
}
Render-Template (Join-Path $packageRoot 'templates\mariadb.ini.template') $mariaIni @{
    MARIADB_ROOT=(Convert-ToForwardSlash (Join-Path $runtime 'mariadb')); MARIADB_DATA=(Convert-ToForwardSlash $paths.Database)
    MARIADB_ERROR_LOG=(Convert-ToForwardSlash (Join-Path $paths.Logs 'mariadb-error.log')); MARIADB_SLOW_LOG=(Convert-ToForwardSlash (Join-Path $paths.Logs 'mariadb-slow.log'))
}
Render-Template (Join-Path $packageRoot 'templates\Caddyfile.template') $caddyFile @{
    CADDY_DATA=(Convert-ToForwardSlash $paths.Caddy); SITE_ADDRESSES=$siteLabel; APP_ROOT=(Convert-ToForwardSlash $app)
    ACCESS_LOG=(Convert-ToForwardSlash (Join-Path $paths.Logs 'caddy-access.log'))
}

$databaseInitialized = Test-Path -LiteralPath (Join-Path $paths.Database 'mysql') -PathType Container
if ($databaseInitialized -xor (Test-Path -LiteralPath $envFile -PathType Leaf)) {
    throw 'Estado inconsistente: el datadir y .env deben existir juntos. Restaure el backup o complete una instalación limpia.'
}

$temporaryDatabase = $null
$rootClientConfig = Join-Path $paths.Temp 'database-root.cnf'
if (-not $databaseInitialized) {
    $rootPassword = New-RandomSecret 36; $appPassword = New-RandomSecret 36; $appKey = 'base64:' + (New-RandomSecret 32)
    & $installDbExe "--datadir=$($paths.Database)" "--password=$rootPassword" '--port=3307' "--config=$mariaIni" '--silent'
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB no pudo inicializar su datadir.' }
    $temporaryDatabase = Start-Process -FilePath $mariadbdExe -ArgumentList @("--defaults-file=$mariaIni",'--console') -WindowStyle Hidden -PassThru
    Wait-TcpPort '127.0.0.1' 3307 60
    $sqlFile = Join-Path $paths.Temp 'provision-database.sql'
    Write-Utf8NoBom $rootClientConfig "[client]`nhost=127.0.0.1`nport=3307`nuser=root`npassword=$rootPassword`n"
    $sql = @"
CREATE DATABASE IF NOT EXISTS bluecat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'bluecat_app'@'127.0.0.1' IDENTIFIED BY '$appPassword';
ALTER USER 'bluecat_app'@'127.0.0.1' IDENTIFIED BY '$appPassword';
CREATE USER IF NOT EXISTS 'bluecat_app'@'localhost' IDENTIFIED BY '$appPassword';
ALTER USER 'bluecat_app'@'localhost' IDENTIFIED BY '$appPassword';
GRANT ALL PRIVILEGES ON bluecat.* TO 'bluecat_app'@'127.0.0.1';
GRANT ALL PRIVILEGES ON bluecat.* TO 'bluecat_app'@'localhost';
FLUSH PRIVILEGES;
"@
    Write-Utf8NoBom $sqlFile $sql
    $client = Start-Process -FilePath $mariaClientExe -ArgumentList @("--defaults-extra-file=$rootClientConfig") -RedirectStandardInput $sqlFile -Wait -PassThru -WindowStyle Hidden
    if ($client.ExitCode -ne 0) { throw 'No fue posible crear la base y el usuario de aplicación.' }
    $envContent = @"
APP_NAME=Blue-Cat Server
APP_ENV=production
APP_DEBUG=false
APP_URL=https://$($SiteAddresses[0])
APP_TIMEZONE=America/Santiago
FORCE_HTTPS=true
APP_KEY=$appKey
SESSION_LIFETIME=120
BCRYPT_ROUNDS=12
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=bluecat
DB_USER=bluecat_app
DB_PASSWORD=$appPassword
"@
    Write-Utf8NoBom $envFile $envContent
    Protect-MachineSecret $rootPassword (Join-Path $paths.Config 'database-admin.secret')
    Remove-Item -LiteralPath $sqlFile -Force
}
else {
    $databaseService = Get-Service -Name 'BlueCatDatabase' -ErrorAction SilentlyContinue
    $mustStartTemporaryDatabase = $SkipServices -or -not $databaseService -or $databaseService.Status -ne 'Running'
    if ($databaseService) { $databaseService.Dispose() }
}
if ($databaseInitialized -and $mustStartTemporaryDatabase) {
    $rootPassword = Unprotect-MachineSecret (Join-Path $paths.Config 'database-admin.secret')
    Write-Utf8NoBom $rootClientConfig "[client]`nhost=127.0.0.1`nport=3307`nuser=root`npassword=$rootPassword`n"
    $temporaryDatabase = Start-Process -FilePath $mariadbdExe -ArgumentList @("--defaults-file=$mariaIni",'--console') -WindowStyle Hidden -PassThru
    Wait-TcpPort '127.0.0.1' 3307 60
}

try {
    & $phpExe '-c' $phpIni (Join-Path $app 'scripts\migrate.php') "--env=$envFile"
    if ($LASTEXITCODE -ne 0) { throw 'Las migraciones de Blue-Cat fallaron.' }
    & $phpExe '-c' $phpIni (Join-Path $app 'scripts\bootstrap-installation.php') "--env=$envFile" "--config=$installConfig"
    if ($LASTEXITCODE -ne 0) { throw 'El bootstrap inicial de Blue-Cat falló.' }
} finally {
    if ($temporaryDatabase -and -not $temporaryDatabase.HasExited) {
        $shutdown = Start-Process -FilePath $mariaAdminExe -ArgumentList @("--defaults-extra-file=$rootClientConfig",'shutdown') -Wait -PassThru -WindowStyle Hidden
        if ($shutdown.ExitCode -ne 0) { Write-Warning 'MariaDB no respondió al apagado administrativo; se cerrará el proceso temporal.' }
        if (-not $temporaryDatabase.WaitForExit(15000)) { Stop-Process -Id $temporaryDatabase.Id -Force }
    }
    if (Test-Path -LiteralPath $rootClientConfig) { Remove-Item -LiteralPath $rootClientConfig -Force }
}

$serviceTokens = @{
    MARIADBD_EXE=$mariadbdExe; MARIADB_INI=$mariaIni; PHP_CGI_EXE=$phpCgiExe; PHP_INI=$phpIni; CADDY_EXE=$caddyExe
    CADDYFILE=$caddyFile; ENV_FILE=$envFile; SERVICE_LOG_DIR=$paths.Logs
}
foreach ($serviceName in @('BlueCatDatabase','BlueCatPhp','BlueCatWeb')) {
    $wrapper = Join-Path $paths.Services ($serviceName + '.exe'); Copy-Item -LiteralPath $winswExe -Destination $wrapper -Force
    Render-Template (Join-Path $packageRoot "services\$serviceName.xml.template") (Join-Path $paths.Services ($serviceName + '.xml')) $serviceTokens
}

if (-not $SkipServices) {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent(); $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'La instalación de servicios requiere elevación de administrador.' }
    & icacls.exe $data '/inheritance:r' '/grant:r' '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-19:(OI)(CI)M' | Out-Null
    $serviceNames = @('BlueCatDatabase','BlueCatPhp','BlueCatWeb')
    foreach ($serviceName in @('BlueCatWeb','BlueCatPhp','BlueCatDatabase')) {
        $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
        if ($service -and $service.Status -ne 'Stopped') {
            Stop-Service -Name $serviceName -Force
            $service.WaitForStatus([ServiceProcess.ServiceControllerStatus]::Stopped,[TimeSpan]::FromSeconds(30))
        }
        if ($service) { $service.Dispose() }
    }
    foreach ($serviceName in $serviceNames) {
        $wrapper = Join-Path $paths.Services ($serviceName + '.exe')
        $existingService = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
        if ($existingService) {
            $existingService.Dispose()
            & $wrapper uninstall
            if ($LASTEXITCODE -ne 0) { throw "No fue posible retirar el servicio anterior $serviceName." }
            Wait-ServiceAbsent $serviceName 30
        }
        & $wrapper install
        if ($LASTEXITCODE -ne 0) { throw "No fue posible registrar el servicio $serviceName." }
    }
    foreach ($serviceName in $serviceNames) {
        Start-Service -Name $serviceName
        (Get-Service -Name $serviceName).WaitForStatus([ServiceProcess.ServiceControllerStatus]::Running,[TimeSpan]::FromSeconds(45))
    }
    $rootCertificate = Join-Path $paths.Caddy 'pki\authorities\local\root.crt'
    $certificateDeadline = [DateTime]::UtcNow.AddSeconds(45)
    while (-not (Test-Path -LiteralPath $rootCertificate) -and [DateTime]::UtcNow -lt $certificateDeadline) { Start-Sleep -Milliseconds 500 }
    if (-not (Test-Path -LiteralPath $rootCertificate)) { throw 'Caddy no generó su certificado raíz local.' }
    & certutil.exe -addstore -f Root $rootCertificate | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'No fue posible confiar en la CA local de Blue-Cat.' }
    if (-not (Get-NetFirewallRule -DisplayName 'Blue-Cat Server HTTPS' -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -DisplayName 'Blue-Cat Server HTTPS' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 443 -Profile Private | Out-Null
    }
    $healthDeadline = [DateTime]::UtcNow.AddSeconds(45)
    $healthy = $false
    do {
        try {
            $health = Invoke-RestMethod -UseBasicParsing -Uri 'https://localhost/assets/api/health.php' -TimeoutSec 5
            $healthy = $health.status -eq 'ok' -and $health.database -eq $true -and $health.setup -eq $true
        } catch { Start-Sleep -Milliseconds 750 }
    } while (-not $healthy -and [DateTime]::UtcNow -lt $healthDeadline)
    if (-not $healthy) { throw 'Los servicios iniciaron, pero el health check de Blue-Cat no quedo operativo.' }
}

$state = [ordered]@{schema=1;configured_at=[DateTime]::UtcNow.ToString('o');app_root=$app;data_root=$data;site_addresses=$SiteAddresses;services_installed=(-not $SkipServices)}
$state | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $paths.Install 'state.json') -Encoding UTF8
Write-Host 'Blue-Cat Server preparado correctamente.'
