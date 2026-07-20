[CmdletBinding()]
param(
    [string]$PackageRoot = (Join-Path $PSScriptRoot '..'),
    [string]$InnoCompiler = "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe",
    [string]$CertificateThumbprint = '',
    [string]$TimestampUrl = 'http://timestamp.digicert.com',
    [switch]$RequireSignature
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Remove-PackageDirectory([string]$Path, [string]$Root) {
    $resolved = [IO.Path]::GetFullPath($Path)
    $rootResolved = [IO.Path]::GetFullPath($Root)
    if (-not $resolved.StartsWith($rootResolved + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Se rechazo eliminar una ruta fuera del paquete: $resolved"
    }
    if (Test-Path -LiteralPath $resolved) { [IO.Directory]::Delete($resolved, $true) }
}

function Find-SignTool {
    $roots = @("${env:ProgramFiles(x86)}\Windows Kits\10\bin", "$env:ProgramFiles\Windows Kits\10\bin")
    foreach ($root in $roots) {
        if (-not (Test-Path -LiteralPath $root)) { continue }
        $candidate = Get-ChildItem -LiteralPath $root -Filter signtool.exe -Recurse -ErrorAction SilentlyContinue |
            Where-Object { $_.FullName -match '\\x64\\signtool\.exe$' } |
            Sort-Object FullName -Descending |
            Select-Object -First 1
        if ($candidate) { return $candidate.FullName }
    }
    throw 'No se encontro signtool.exe del Windows SDK.'
}

$package = [IO.Path]::GetFullPath($PackageRoot)
$runtime = Join-Path $package 'build\runtime'
$desktop = Join-Path $package 'build\desktop'
$stage = Join-Path $package 'build\stage'
$output = Join-Path $package 'output'
$lockPath = Join-Path $package 'runtime-lock.json'
$requiredRuntime = @(
    (Join-Path $runtime 'php\php.exe'),
    (Join-Path $runtime 'vc-redist-x64\vc_redist.x64.exe'),
    (Join-Path $runtime 'webview2-sdk\lib\net462\Microsoft.Web.WebView2.Wpf.dll'),
    (Join-Path $runtime 'webview2-runtime-installer\MicrosoftEdgeWebView2RuntimeInstallerX64.exe')
)

if (-not (Test-Path -LiteralPath $InnoCompiler)) { throw "No se encontro Inno Setup 6.7.3: $InnoCompiler" }
$compilerVersion = (Get-Item -LiteralPath $InnoCompiler).VersionInfo.ProductVersion
if ($compilerVersion -eq '0.0.0.0') {
    $uninstaller = Get-ChildItem -LiteralPath (Split-Path -Parent $InnoCompiler) -Filter 'unins*.exe' |
        Select-Object -First 1
    if ($uninstaller) { $compilerVersion = $uninstaller.VersionInfo.ProductVersion }
}
if ($compilerVersion -notlike '6.7.3*') { throw "Version de Inno Setup no fijada: $compilerVersion (se requiere 6.7.3)." }
if (@($requiredRuntime | Where-Object { -not (Test-Path -LiteralPath $_) }).Count -gt 0) {
    & (Join-Path $PSScriptRoot 'Fetch-Runtimes.ps1') `
        -LockFile $lockPath `
        -CachePath (Join-Path $package 'cache') `
        -OutputPath $runtime
}

& (Join-Path $PSScriptRoot 'Build-DesktopLauncher.ps1') -PackageRoot $package -RuntimeRoot $runtime -OutputRoot $desktop

Remove-PackageDirectory -Path $stage -Root $package
Remove-PackageDirectory -Path $output -Root $package
& (Join-Path $PSScriptRoot 'New-InstallerStage.ps1') -RuntimeRoot $runtime -DesktopRoot $desktop -StageRoot $stage

& $InnoCompiler /Qp (Join-Path $package 'installer\BlueCatServer.iss')
if ($LASTEXITCODE -ne 0) { throw 'La compilacion del instalador fallo.' }
$installer = Join-Path $output 'BlueCat-Server-Setup.exe'
if (-not (Test-Path -LiteralPath $installer)) { throw 'El compilador no produjo BlueCat-Server-Setup.exe.' }

if ($CertificateThumbprint.Trim() -ne '') {
    $signTool = Find-SignTool
    & $signTool sign /sha1 $CertificateThumbprint.Trim() /fd SHA256 /tr $TimestampUrl /td SHA256 $installer
    if ($LASTEXITCODE -ne 0) { throw 'Authenticode no pudo firmar el instalador.' }
}
$signature = Get-AuthenticodeSignature -LiteralPath $installer
if ($RequireSignature -and $signature.Status -ne 'Valid') {
    throw "Release bloqueado: la firma Authenticode no es valida ($($signature.Status))."
}

$installerHash = Get-FileHash -LiteralPath $installer -Algorithm SHA256
"$($installerHash.Hash.ToLowerInvariant())  $([IO.Path]::GetFileName($installer))" |
    Set-Content -LiteralPath (Join-Path $output 'SHA256SUMS.txt') -Encoding ascii

$lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
$version = (Get-Content -LiteralPath (Join-Path $package '..\..\VERSION') -Raw).Trim()
$packages = @(
    [ordered]@{
        SPDXID = 'SPDXRef-BlueCatServer'
        name = 'Blue-Cat Server'
        versionInfo = $version
        downloadLocation = 'NOASSERTION'
        filesAnalyzed = $false
        licenseConcluded = 'NOASSERTION'
        licenseDeclared = 'NOASSERTION'
        copyrightText = 'NOASSERTION'
    }
)
$relationships = @()
foreach ($component in $lock.components) {
    $spdxId = 'SPDXRef-' + ($component.id -replace '[^A-Za-z0-9.-]', '-')
    $checksumAlgorithm = if ($component.hash_algorithm -eq 'SHA512') { 'SHA512' } else { 'SHA256' }
    $packages += [ordered]@{
        SPDXID = $spdxId
        name = $component.id
        versionInfo = $component.version
        downloadLocation = $component.url
        filesAnalyzed = $false
        checksums = @([ordered]@{algorithm=$checksumAlgorithm;checksumValue=$component.hash})
        licenseConcluded = $component.license
        licenseDeclared = $component.license
        copyrightText = 'NOASSERTION'
    }
    $relationships += [ordered]@{spdxElementId='SPDXRef-BlueCatServer';relationshipType='CONTAINS';relatedSpdxElement=$spdxId}
}
$sbom = [ordered]@{
    spdxVersion = 'SPDX-2.3'
    dataLicense = 'CC0-1.0'
    SPDXID = 'SPDXRef-DOCUMENT'
    name = "Blue-Cat-Server-$version-windows-x64"
    documentNamespace = "https://github.com/lifilab/Blue-Cat/sbom/$version/$([guid]::NewGuid())"
    creationInfo = [ordered]@{created=(Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ');creators=@('Tool: Blue-Cat-Build-Installer')}
    packages = $packages
    relationships = $relationships
}
$sbom | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $output 'BlueCat-Server.spdx.json') -Encoding utf8

[ordered]@{
    version = $version
    artifact = [IO.Path]::GetFileName($installer)
    size = (Get-Item -LiteralPath $installer).Length
    sha256 = $installerHash.Hash.ToLowerInvariant()
    signature = $signature.Status.ToString()
    runtimes = @($lock.components | ForEach-Object { [ordered]@{id=$_.id;version=$_.version} })
} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $output 'build-metadata.json') -Encoding utf8

Write-Host "Instalador listo: $installer"
Write-Host "SHA-256: $($installerHash.Hash.ToLowerInvariant())"
Write-Host "Firma: $($signature.Status)"
