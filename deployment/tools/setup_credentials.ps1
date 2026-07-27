[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$module = Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1'
Import-Module $module -Force

$targets = @{
    Database = 'MZTech.Reparatursystem.ProductionDB.v1'
    Ftps = 'MZTech.Reparatursystem.ProductionFTPS.v1'
    Foneday = 'MZTech.Reparatursystem.FONEDAY_API_TOKEN.v1'
}

function Read-RequiredValue {
    param([string]$Prompt, [string]$Default = '')
    $suffix = if ($Default) { " [$Default]" } else { '' }
    $value = (Read-Host "$Prompt$suffix").Trim()
    if (-not $value) {
        $value = $Default
    }
    if (-not $value) {
        throw "$Prompt darf nicht leer sein."
    }
    return $value
}

Clear-Host
Write-Host 'MZ Tech – sichere lokale Zugangsdaten-Einrichtung' -ForegroundColor Cyan
Write-Host 'Die Geheimnisse werden im Windows Credential Manager des aktuellen Benutzers gespeichert.'
Write-Host 'Sie werden weder angezeigt noch in Projektdateien oder Protokolle geschrieben.'
Write-Host ''

$dbHost = Read-RequiredValue 'Produktivdatenbank: Server'
$dbPort = Read-RequiredValue 'Produktivdatenbank: Port' '3306'
$dbName = Read-RequiredValue 'Produktivdatenbank: Datenbankname'
$dbUser = Read-RequiredValue 'Produktivdatenbank: Benutzer'
$dbCaPath = (Read-Host 'Produktivdatenbank: TLS-CA-Zertifikatspfad (optional)').Trim()
if ($dbCaPath -and -not (Test-Path -LiteralPath $dbCaPath -PathType Leaf)) {
    throw "TLS-CA-Zertifikat nicht gefunden: $dbCaPath"
}
$dbSecret = Read-Host 'Produktivdatenbank: Passwort (verdeckt)' -AsSecureString
if ($dbSecret.Length -eq 0) {
    throw 'Das Datenbankpasswort darf nicht leer sein.'
}
$dbMetadata = @{
    host = $dbHost
    port = [int]$dbPort
    database = $dbName
    user = $dbUser
    ssl_ca = $dbCaPath
} | ConvertTo-Json -Compress
Set-MzTechCredential -Target $targets.Database -Metadata $dbMetadata -Secret $dbSecret

Write-Host ''
$ftpsHost = Read-RequiredValue 'FTPS: Server' 'w021c224.kasserver.com'
$ftpsPort = Read-RequiredValue 'FTPS: Port' '21'
$ftpsUser = Read-RequiredValue 'FTPS: Benutzer' 'f01890e0'
$ftpsPath = Read-RequiredValue 'FTPS: Zielverzeichnis' '/mztech-it.de/repair_neu/'
$ftpsSecret = Read-Host 'FTPS: Passwort (verdeckt)' -AsSecureString
if ($ftpsSecret.Length -eq 0) {
    throw 'Das FTPS-Passwort darf nicht leer sein.'
}
$ftpsMetadata = @{
    host = $ftpsHost
    port = [int]$ftpsPort
    user = $ftpsUser
    base_path = $ftpsPath
    explicit_tls = $true
} | ConvertTo-Json -Compress
Set-MzTechCredential -Target $targets.Ftps -Metadata $ftpsMetadata -Secret $ftpsSecret

Write-Host ''
$configureFoneday = (Read-Host 'FONEDAY_API_TOKEN jetzt hinterlegen? [j/N]').Trim()
if ($configureFoneday -match '^(j|ja|y|yes)$') {
    $fonedaySecret = Read-Host 'FONEDAY_API_TOKEN (verdeckt)' -AsSecureString
    if ($fonedaySecret.Length -eq 0) {
        throw 'Der FONEDAY_API_TOKEN darf nicht leer sein.'
    }
    Set-MzTechCredential -Target $targets.Foneday -Metadata '{"service":"foneday"}' -Secret $fonedaySecret
}

$dbSecret.Dispose()
$ftpsSecret.Dispose()
if ($fonedaySecret) {
    $fonedaySecret.Dispose()
}

Write-Host ''
Write-Host 'Gespeicherte projektspezifische Credential-Namen:' -ForegroundColor Green
Write-Host "  $($targets.Database)"
Write-Host "  $($targets.Ftps)"
if (Test-MzTechCredential -Target $targets.Foneday) {
    Write-Host "  $($targets.Foneday)"
} else {
    Write-Host "  $($targets.Foneday) (noch nicht eingerichtet)"
}
Write-Host ''
Write-Host 'Einrichtung abgeschlossen. Geheimnisse wurden nicht ausgegeben.'
