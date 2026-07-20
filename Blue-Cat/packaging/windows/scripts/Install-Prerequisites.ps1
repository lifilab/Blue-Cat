[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$PrerequisiteRoot,
    [Parameter(Mandatory=$true)][string]$LockFile,
    [switch]$VerifyOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$prerequisites = [IO.Path]::GetFullPath($PrerequisiteRoot)
$lock = Get-Content -LiteralPath $LockFile -Raw | ConvertFrom-Json

function Assert-MicrosoftPrerequisite([string]$ComponentId, [string]$FileName) {
    $component = $lock.components | Where-Object id -eq $ComponentId | Select-Object -First 1
    if (-not $component) { throw "Falta $ComponentId en runtime-lock.json." }
    $installer = Join-Path $prerequisites $FileName
    if (-not (Test-Path -LiteralPath $installer)) { throw "Falta $FileName en el paquete." }
    $actual = (Get-FileHash -LiteralPath $installer -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $component.hash.ToLowerInvariant()) { throw "Checksum invalido para $ComponentId." }
    $signature = Get-AuthenticodeSignature -LiteralPath $installer
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notlike '*Microsoft Corporation*') {
        throw "Firma Microsoft invalida para ${ComponentId}: $($signature.Status)."
    }
    return $installer
}

$vcInstaller = Assert-MicrosoftPrerequisite 'vc-redist-x64' 'vc_redist.x64.exe'
$webViewInstaller = Assert-MicrosoftPrerequisite 'webview2-runtime-installer' 'MicrosoftEdgeWebView2RuntimeInstallerX64.exe'
if ($VerifyOnly) {
    Write-Host 'Prerrequisitos Microsoft verificados.'
    exit 0
}

$process = Start-Process -FilePath $vcInstaller -ArgumentList '/install','/quiet','/norestart' -Wait -PassThru
if ($process.ExitCode -notin @(0, 1638, 3010)) {
    throw "Microsoft Visual C++ Runtime fallo con codigo $($process.ExitCode)."
}
$restartRequired = $process.ExitCode -eq 3010

$process = Start-Process -FilePath $webViewInstaller -ArgumentList '/silent','/install' -Wait -PassThru
if ($process.ExitCode -notin @(0, 1638, 3010)) {
    throw "Microsoft WebView2 Runtime fallo con codigo $($process.ExitCode)."
}
if ($restartRequired -or $process.ExitCode -eq 3010) { exit 3010 }
exit 0
