[CmdletBinding()]
param(
    [string]$LockFile = (Join-Path $PSScriptRoot '..\runtime-lock.json'),
    [string]$CachePath = (Join-Path $PSScriptRoot '..\cache'),
    [string]$OutputPath = (Join-Path $PSScriptRoot '..\build\runtime'),
    [switch]$Offline
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if (-not [Environment]::Is64BitOperatingSystem) {
    throw 'Blue-Cat Server requiere Windows de 64 bits.'
}

$lock = Get-Content -LiteralPath $LockFile -Raw | ConvertFrom-Json
if ($lock.schema -ne 1 -or $lock.platform -ne 'windows-x64') {
    throw 'runtime-lock.json no es compatible con este constructor.'
}

New-Item -ItemType Directory -Path $CachePath -Force | Out-Null
New-Item -ItemType Directory -Path $OutputPath -Force | Out-Null

foreach ($component in $lock.components) {
    if ([string]::IsNullOrWhiteSpace($component.hash)) {
        throw "El componente $($component.id) no tiene checksum."
    }
    $archive = Join-Path $CachePath $component.file
    if (-not (Test-Path -LiteralPath $archive)) {
        if ($Offline) { throw "Falta $($component.file) en caché offline." }
        $partial = $archive + '.partial'
        Invoke-WebRequest -UseBasicParsing -Uri $component.url -OutFile $partial
        Move-Item -LiteralPath $partial -Destination $archive
    }

    $actual = (Get-FileHash -LiteralPath $archive -Algorithm $component.hash_algorithm).Hash.ToLowerInvariant()
    if ($actual -ne $component.hash.ToLowerInvariant()) {
        throw "Checksum inválido para $($component.id). Esperado $($component.hash); recibido $actual."
    }

    $destination = Join-Path $OutputPath $component.id
    New-Item -ItemType Directory -Path $destination -Force | Out-Null
    if ($component.file.EndsWith('.nupkg', [StringComparison]::OrdinalIgnoreCase)) {
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $extractRoot = Join-Path $OutputPath ('.extract-' + [Guid]::NewGuid().ToString('N'))
        [IO.Compression.ZipFile]::ExtractToDirectory($archive, $extractRoot)
        Copy-Item -Path (Join-Path $extractRoot '*') -Destination $destination -Recurse -Force
        if (-not ([IO.Path]::GetFullPath($extractRoot)).StartsWith([IO.Path]::GetFullPath($OutputPath), [StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta temporal fuera del build.' }
        Remove-Item -LiteralPath $extractRoot -Recurse -Force
    } elseif ($component.file.EndsWith('.zip', [StringComparison]::OrdinalIgnoreCase)) {
        if ($component.id -eq 'mariadb') {
            $extractRoot = Join-Path $OutputPath ('.extract-' + [Guid]::NewGuid().ToString('N'))
            New-Item -ItemType Directory -Path $extractRoot | Out-Null
            Expand-Archive -LiteralPath $archive -DestinationPath $extractRoot -Force
            $server = Get-ChildItem -LiteralPath $extractRoot -Filter 'mariadbd.exe' -File -Recurse | Select-Object -First 1
            if (-not $server) { throw 'El ZIP de MariaDB no contiene mariadbd.exe.' }
            $distributionRoot = Split-Path -Parent (Split-Path -Parent $server.FullName)
            Copy-Item -Path (Join-Path $distributionRoot '*') -Destination $destination -Recurse -Force
            if (-not ([IO.Path]::GetFullPath($extractRoot)).StartsWith([IO.Path]::GetFullPath($OutputPath), [StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta temporal fuera del build.' }
            Remove-Item -LiteralPath $extractRoot -Recurse -Force
            Get-ChildItem -Path (Join-Path $destination 'bin\*') -Include '*.pdb','*.lib' -File | Remove-Item -Force
        } else {
            Expand-Archive -LiteralPath $archive -DestinationPath $destination -Force
        }
    } else {
        $targetFile = if ($component.PSObject.Properties.Name -contains 'target_file') { $component.target_file } else { 'WinSW-x64.exe' }
        Copy-Item -LiteralPath $archive -Destination (Join-Path $destination $targetFile) -Force
    }
    Write-Host "OK $($component.id) $($component.version)"
}

$lock | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $OutputPath 'runtime-lock.json') -Encoding UTF8
Write-Host "Runtime verificado en $OutputPath"
