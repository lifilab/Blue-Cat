[CmdletBinding()]
param(
    [string]$PackageRoot = (Join-Path $PSScriptRoot '..'),
    [string]$RuntimeRoot = (Join-Path $PSScriptRoot '..\build\runtime'),
    [string]$OutputRoot = (Join-Path $PSScriptRoot '..\build\desktop')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$package = [IO.Path]::GetFullPath($PackageRoot)
$runtime = [IO.Path]::GetFullPath($RuntimeRoot)
$output = [IO.Path]::GetFullPath($OutputRoot)
$sdk = Join-Path $runtime 'webview2-sdk'
$source = Join-Path $package 'desktop\BlueCatDesktop.cs'
$core = Join-Path $sdk 'lib\net462\Microsoft.Web.WebView2.Core.dll'
$wpf = Join-Path $sdk 'lib\net462\Microsoft.Web.WebView2.Wpf.dll'
$loader = Join-Path $sdk 'runtimes\win-x64\native\WebView2Loader.dll'
$logo = Join-Path $package '..\..\assets\img\Blue-Cat_logo-removebg.png'
foreach ($required in @($source,$core,$wpf,$loader,$logo)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw "Falta $required para construir el launcher." }
}

if (Test-Path -LiteralPath $output) {
    $resolved = [IO.Path]::GetFullPath($output)
    if (-not $resolved.StartsWith($package + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Se rechazo limpiar una ruta fuera del paquete: $resolved"
    }
    [IO.Directory]::Delete($resolved, $true)
}
New-Item -ItemType Directory -Path $output | Out-Null

Add-Type -AssemblyName System.Drawing
$iconPath = Join-Path $output 'BlueCat.ico'
$bitmap = [Drawing.Bitmap]::FromFile($logo)
try {
    $iconSizes = @(16,24,32,48,64,128,256)
    $pngImages = @()
    foreach ($size in $iconSizes) {
        $canvas = [Drawing.Bitmap]::new($size,$size,[Drawing.Imaging.PixelFormat]::Format32bppArgb)
        try {
            $graphics = [Drawing.Graphics]::FromImage($canvas)
            try {
                $graphics.Clear([Drawing.Color]::Transparent)
                $graphics.CompositingMode = [Drawing.Drawing2D.CompositingMode]::SourceCopy
                $graphics.CompositingQuality = [Drawing.Drawing2D.CompositingQuality]::HighQuality
                $graphics.InterpolationMode = [Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
                $graphics.PixelOffsetMode = [Drawing.Drawing2D.PixelOffsetMode]::HighQuality
                $padding = [Math]::Max(1,[int][Math]::Round($size * 0.025))
                $graphics.DrawImage($bitmap,$padding,$padding,$size-(2*$padding),$size-(2*$padding))
            } finally { $graphics.Dispose() }
            $memory = [IO.MemoryStream]::new()
            try {
                $canvas.Save($memory,[Drawing.Imaging.ImageFormat]::Png)
                $pngImages += ,$memory.ToArray()
            } finally { $memory.Dispose() }
        } finally { $canvas.Dispose() }
    }

    $stream = [IO.File]::Create($iconPath)
    $writer = [IO.BinaryWriter]::new($stream)
    try {
        $writer.Write([UInt16]0); $writer.Write([UInt16]1); $writer.Write([UInt16]$iconSizes.Count)
        $offset = 6 + (16 * $iconSizes.Count)
        for ($index=0; $index -lt $iconSizes.Count; $index++) {
            $size = $iconSizes[$index]
            $writer.Write([byte]$(if ($size -eq 256) { 0 } else { $size }))
            $writer.Write([byte]$(if ($size -eq 256) { 0 } else { $size }))
            $writer.Write([byte]0); $writer.Write([byte]0)
            $writer.Write([UInt16]1); $writer.Write([UInt16]32)
            $writer.Write([UInt32]$pngImages[$index].Length); $writer.Write([UInt32]$offset)
            $offset += $pngImages[$index].Length
        }
        foreach ($imageBytes in $pngImages) { $writer.Write($imageBytes) }
    } finally { $writer.Dispose(); $stream.Dispose() }
} finally { $bitmap.Dispose() }

$assembly = Join-Path $output 'BlueCatDesktop.exe'
Add-Type -AssemblyName System.Core,System.Drawing,System.ServiceProcess,System.Xaml,WindowsBase,PresentationCore,PresentationFramework
$references = @(
    [Uri].Assembly.Location,
    [Linq.Enumerable].Assembly.Location,
    [Drawing.Icon].Assembly.Location,
    [System.ServiceProcess.ServiceController].Assembly.Location,
    [System.Xaml.XamlReader].Assembly.Location,
    [System.Windows.DependencyObject].Assembly.Location,
    [System.Windows.Media.Brush].Assembly.Location,
    [System.Windows.Window].Assembly.Location,
    $core,$wpf
)
$compiler = [Microsoft.CSharp.CSharpCodeProvider]::new()
$parameters = [CodeDom.Compiler.CompilerParameters]::new()
$parameters.GenerateExecutable = $true
$parameters.GenerateInMemory = $false
$parameters.OutputAssembly = $assembly
$parameters.CompilerOptions = "/target:winexe /optimize /win32icon:`"$iconPath`""
foreach ($reference in $references) { [void]$parameters.ReferencedAssemblies.Add($reference) }
$result = $compiler.CompileAssemblyFromSource($parameters, (Get-Content -LiteralPath $source -Raw -Encoding UTF8))
$compiler.Dispose()
if ($result.Errors.HasErrors) {
    $messages = @($result.Errors | ForEach-Object { "$($_.FileName):$($_.Line): $($_.ErrorText)" })
    throw "El launcher no compiló:`n$($messages -join "`n")"
}

Copy-Item -LiteralPath $core,$wpf,$loader -Destination $output -Force
Copy-Item -LiteralPath (Join-Path $sdk 'LICENSE.txt'),(Join-Path $sdk 'NOTICE.txt') -Destination $output -Force
if (-not (Test-Path -LiteralPath $assembly)) { throw 'No se genero BlueCatDesktop.exe.' }
Write-Host "Launcher WebView2 preparado en $output"
