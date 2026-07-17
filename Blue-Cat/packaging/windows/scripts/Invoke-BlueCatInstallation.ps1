[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$AppRoot,
    [Parameter(Mandatory=$true)][string]$RuntimeRoot,
    [Parameter(Mandatory=$true)][string]$DataRoot,
    [Parameter(Mandatory=$true)][string]$InstallationConfig,
    [Parameter(Mandatory=$true)][string]$PrerequisiteRoot,
    [Parameter(Mandatory=$true)][string]$LockFile
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$logDirectory = Join-Path ([IO.Path]::GetFullPath($DataRoot)) 'logs'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
$logFile = Join-Path $logDirectory 'installer.log'
$restartRequired = $false
$exitCode = 0

Start-Transcript -LiteralPath $logFile -Force | Out-Null
try {
    $powerShell = Join-Path $PSHOME 'powershell.exe'
    $prerequisiteOutput = Join-Path $logDirectory 'prerequisites-output.log'
    $prerequisiteError = Join-Path $logDirectory 'prerequisites-error.log'
    $prerequisiteProcess = Start-Process -FilePath $powerShell -WindowStyle Hidden -Wait -PassThru -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + (Join-Path $PSScriptRoot 'Install-Prerequisites.ps1') + '"'),
        '-PrerequisiteRoot', ('"' + $PrerequisiteRoot + '"'), '-LockFile', ('"' + $LockFile + '"')
    ) -RedirectStandardOutput $prerequisiteOutput -RedirectStandardError $prerequisiteError
    Get-Content -LiteralPath $prerequisiteOutput -ErrorAction SilentlyContinue | Write-Host
    Get-Content -LiteralPath $prerequisiteError -ErrorAction SilentlyContinue | Write-Host
    if ($prerequisiteProcess.ExitCode -notin @(0, 3010)) { throw "La instalación de prerrequisitos falló con código $($prerequisiteProcess.ExitCode)." }
    $restartRequired = $prerequisiteProcess.ExitCode -eq 3010

    $initializeOutput = Join-Path $logDirectory 'initialize-output.log'
    $initializeError = Join-Path $logDirectory 'initialize-error.log'
    $initializeProcess = Start-Process -FilePath $powerShell -WindowStyle Hidden -Wait -PassThru -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + (Join-Path $PSScriptRoot 'Initialize-BlueCatServer.ps1') + '"'),
        '-AppRoot', ('"' + $AppRoot + '"'), '-RuntimeRoot', ('"' + $RuntimeRoot + '"'),
        '-DataRoot', ('"' + $DataRoot + '"'), '-InstallationConfig', ('"' + $InstallationConfig + '"')
    ) -RedirectStandardOutput $initializeOutput -RedirectStandardError $initializeError
    Get-Content -LiteralPath $initializeOutput -ErrorAction SilentlyContinue | Write-Host
    Get-Content -LiteralPath $initializeError -ErrorAction SilentlyContinue | Write-Host
    if ($initializeProcess.ExitCode -ne 0) { throw "La configuración de Blue-Cat falló con código $($initializeProcess.ExitCode)." }

    Write-Host 'Instalación de Blue-Cat completada y verificada.'
    if ($restartRequired) { $exitCode = 3010 }
} catch {
    $exitCode = 1
    Write-Error ("Instalación de Blue-Cat interrumpida: " + $_.Exception.Message)
} finally {
    Stop-Transcript | Out-Null
}

exit $exitCode
