[CmdletBinding()]
param(
    [string]$RuntimeRoot = "$env:ProgramFiles\Blue-Cat\runtime",
    [string]$DataRoot = "$env:ProgramData\Blue-Cat",
    [switch]$PurgeData
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$runtime = [IO.Path]::GetFullPath($RuntimeRoot)
$data = [IO.Path]::GetFullPath($DataRoot)
foreach ($serviceName in @('BlueCatWeb','BlueCatPhp','BlueCatDatabase')) {
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if ($service) {
        if ($service.Status -ne 'Stopped') { Stop-Service -Name $serviceName -Force; $service.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(45)) }
        $wrapper = Join-Path $runtime "services\$serviceName.exe"
        if (Test-Path -LiteralPath $wrapper) { & $wrapper uninstall }
    }
}
Get-NetFirewallRule -DisplayName 'Blue-Cat Server HTTPS' -ErrorAction SilentlyContinue | Remove-NetFirewallRule

if ($PurgeData) {
    $expected = [IO.Path]::GetFullPath("$env:ProgramData\Blue-Cat")
    if (-not $data.Equals($expected,[StringComparison]::OrdinalIgnoreCase)) { throw 'La eliminación de datos solo se permite en ProgramData\Blue-Cat.' }
    if (Test-Path -LiteralPath $data) { Remove-Item -LiteralPath $data -Recurse -Force }
} else {
    Write-Host "Datos conservados en $data"
}
