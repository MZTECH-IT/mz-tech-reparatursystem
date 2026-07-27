[CmdletBinding()]
param(
    [switch]$ConnectionTestOnly
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = 'C:\xampp\php\php.exe'
$fetcher = Join-Path $PSScriptRoot 'foneday_fetch_catalog.php'
$runtimeDirectory = Join-Path $projectRoot 'deployment\runtime_secure\foneday'
$outputPath = Join-Path $runtimeDirectory (
    'foneday_catalog_{0}.json' -f (Get-Date -Format 'yyyyMMdd_HHmmss')
)

if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') {
    throw 'Falscher Projektpfad.'
}
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw 'PHP-CLI fehlt unter C:\xampp\php\php.exe.'
}

Import-Module (Join-Path $PSScriptRoot 'MzTechFonedayDpapi.psm1') -Force
if (-not (Test-MzTechFonedayToken)) {
    throw 'Der DPAPI-geschützte Foneday-Token fehlt.'
}

$secureToken = Get-MzTechFonedayToken
$bstr = [IntPtr]::Zero
try {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
    $env:MZTECH_FONEDAY_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
    & $php $fetcher $outputPath
    if ($LASTEXITCODE -ne 0) {
        throw 'Der lesende Foneday-Abruf ist fehlgeschlagen.'
    }
    if (-not (Test-Path -LiteralPath $outputPath -PathType Leaf)) {
        throw 'Der erwartete lokale Katalog wurde nicht erzeugt.'
    }
    if ($ConnectionTestOnly) {
        Remove-Item -LiteralPath $outputPath -Force
        Write-Host 'Foneday-Verbindungstest: erfolgreich (ausschließlich GET).' -ForegroundColor Green
    } else {
        Write-Host ('Katalog gespeichert: {0}' -f $outputPath) -ForegroundColor Green
    }
}
finally {
    Remove-Item Env:\MZTECH_FONEDAY_TOKEN -ErrorAction SilentlyContinue
    if ($bstr -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
    if ($secureToken) {
        $secureToken.Dispose()
    }
}
