[CmdletBinding()]
param(
    [string]$SourceRoot = (Join-Path $PSScriptRoot '..\..\..'),
    [string]$RuntimeRoot = (Join-Path $PSScriptRoot '..\build\runtime'),
    [string]$StageRoot = (Join-Path $PSScriptRoot '..\build\stage')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$source = [IO.Path]::GetFullPath($SourceRoot)
$runtime = [IO.Path]::GetFullPath($RuntimeRoot)
$stage = [IO.Path]::GetFullPath($StageRoot)
if (Test-Path -LiteralPath $stage) { throw "El staging ya existe: $stage. Use un directorio vacío para evitar artefactos obsoletos." }
foreach ($required in @('caddy\caddy.exe','php\php.exe','mariadb\bin\mariadbd.exe','winsw\WinSW-x64.exe','vc-redist-x64\vc_redist.x64.exe','runtime-lock.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $runtime $required))) { throw "Runtime incompleto: falta $required." }
}

$appStage = Join-Path $stage 'app'
$runtimeStage = Join-Path $stage 'runtime'
$installerStage = Join-Path $stage 'installer'
New-Item -ItemType Directory -Path $appStage,$runtimeStage,$installerStage -Force | Out-Null

$excludedDirectories = @('.git','backups','cache','node_modules','vendor','packaging','storage\backups')
$excludedFiles = @('.env','.env.local','.env.production','*.log','*.tmp','*.bak')
$arguments = @($source,$appStage,'/E','/R:2','/W:1','/NFL','/NDL','/NJH','/NJS','/NP','/XD') +
    ($excludedDirectories | ForEach-Object { Join-Path $source $_ }) + @('/XF') + $excludedFiles
& robocopy.exe @arguments | Out-Null
if ($LASTEXITCODE -ge 8) { throw "Robocopy de aplicación falló con código $LASTEXITCODE." }
Copy-Item -Path (Join-Path $runtime '*') -Destination $runtimeStage -Recurse -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot '..\templates') -Destination $installerStage -Recurse -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot '..\services') -Destination $installerStage -Recurse -Force
Copy-Item -LiteralPath $PSScriptRoot -Destination $installerStage -Recurse -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot '..\runtime-lock.json') -Destination $installerStage -Force

$manifest = Get-ChildItem -LiteralPath $stage -File -Recurse | ForEach-Object {
    [ordered]@{path=$_.FullName.Substring($stage.Length+1).Replace('\','/');size=$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $stage 'artifact-manifest.json') -Encoding UTF8
Write-Host "Staging reproducible preparado en $stage"
exit 0
