[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ($root -ne 'M:\MZ_Tech_Reparatursystem') { throw 'Falscher Projektpfad.' }

$target = Join-Path $root 'deployment\portal_document_access_ready'
$files = @(
    'private/pdf_common.php',
    'public/pdf/angebot.php',
    'public/pdf/rechnung.php',
    'public/pdf/kostenvoranschlag.php',
    'public/portal.php',
    'public/portal_business.php'
)

foreach ($relative in $files) {
    $source = Join-Path $root $relative
    $destination = Join-Path $target $relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "Quelldatei fehlt: $relative" }
    New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination -Force
}

$files -join "`n" | Set-Content -LiteralPath (Join-Path $target 'CHANGED_FILES.txt') -Encoding UTF8
Write-Output "PORTAL_DOCUMENT_PACKAGE_FILES=$(@(Get-ChildItem -LiteralPath $target -Recurse -File).Count)"
Write-Output "PORTAL_DOCUMENT_PACKAGE_PATH=$target"
