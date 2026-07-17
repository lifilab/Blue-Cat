[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$RuntimeRoot,
    [Parameter(Mandatory=$true)][string]$LockFile,
    [switch]$VerifyOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$runtime = [IO.Path]::GetFullPath($RuntimeRoot)
$lock = Get-Content -LiteralPath $LockFile -Raw | ConvertFrom-Json
$component = $lock.components | Where-Object id -eq 'vc-redist-x64' | Select-Object -First 1
if (-not $component) { throw 'Falta vc-redist-x64 en runtime-lock.json.' }
$installer = Join-Path $runtime 'vc-redist-x64\vc_redist.x64.exe'
if (-not (Test-Path -LiteralPath $installer)) { throw 'Falta Microsoft Visual C++ Runtime en el paquete.' }
$actual = (Get-FileHash -LiteralPath $installer -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actual -ne $component.hash.ToLowerInvariant()) { throw 'Checksum invalido para Microsoft Visual C++ Runtime.' }
$signature = Get-AuthenticodeSignature -LiteralPath $installer
if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notlike '*Microsoft Corporation*') {
    throw "Firma Microsoft invalida para el prerrequisito: $($signature.Status)."
}
if ($VerifyOnly) {
    Write-Host 'Microsoft Visual C++ Runtime verificado.'
    exit 0
}

$process = Start-Process -FilePath $installer -ArgumentList '/install','/quiet','/norestart' -Wait -PassThru
if ($process.ExitCode -notin @(0, 1638, 3010)) {
    throw "Microsoft Visual C++ Runtime fallo con codigo $($process.ExitCode)."
}
if ($process.ExitCode -eq 3010) { exit 3010 }
exit 0
