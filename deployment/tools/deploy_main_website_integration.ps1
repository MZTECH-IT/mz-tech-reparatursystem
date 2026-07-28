[CmdletBinding()]
param([switch]$ValidateOnly)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = 'C:\xampp\php\php.exe'
$credentialTarget = 'MZTech.MainWebsite.ProductionFTPS.v1'
$workingRoot = Join-Path $projectRoot 'deployment\main_website_ready'
$inventoryRoot = Join-Path $projectRoot 'backups\production\main_website_inventory_20260728_031309'
$inventoryManifest = Join-Path $inventoryRoot 'inventory_manifest.json'
$relativeFiles = @('index.html', 'style.css')

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

function Get-ByteHash {
    param([byte[]]$Bytes)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function Assert-Prerequisites {
    if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') {
        throw 'Falscher Projektpfad.'
    }
    if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
        throw "PHP-CLI fehlt: $php"
    }
    if (-not (Test-Path -LiteralPath $inventoryManifest -PathType Leaf)) {
        throw 'Das Manifest der lesenden Website-Bestandsaufnahme fehlt.'
    }
    foreach ($relative in $relativeFiles) {
        if ($relative -notin @('index.html', 'style.css')) {
            throw "Nicht freigegebener Websitepfad: $relative"
        }
        if (-not (Test-Path -LiteralPath (Join-Path $workingRoot $relative) -PathType Leaf)) {
            throw "Vorbereitete Website-Datei fehlt: $relative"
        }
    }
    & $php -l (Join-Path $projectRoot 'tests\main_website_integration_test.php') | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Syntaxprüfung des Website-Integrationstests fehlgeschlagen.'
    }
    & $php (Join-Path $projectRoot 'tests\main_website_integration_test.php')
    if ($LASTEXITCODE -ne 0) {
        throw 'Lokale Website-Strukturprüfung fehlgeschlagen.'
    }
}

function Get-FtpsCredentialData {
    if (-not (Test-MzTechCredential -Target $credentialTarget)) {
        throw "Der Credential-Eintrag $credentialTarget fehlt."
    }
    $credential = Get-MzTechCredential -Target $credentialTarget
    try {
        $metadata = $credential.Metadata | ConvertFrom-Json
    } catch {
        $credential.Secret.Dispose()
        throw 'Die Metadaten des Hauptwebsite-Credentials sind ungültig.'
    }
    if ([string]$metadata.host -ne 'w021c224.kasserver.com' -or
        [int]$metadata.port -ne 21 -or
        [string]$metadata.user -ne 'f0189104' -or
        [string]$metadata.base_path -ne '/' -or
        -not $metadata.explicit_tls) {
        $credential.Secret.Dispose()
        throw 'Das Credential entspricht nicht dem freigegebenen Hauptwebsite-FTPS-Ziel.'
    }
    return [pscustomobject]@{ Metadata = $metadata; Secret = $credential.Secret }
}

function Get-FtpsUri {
    param([object]$Credential, [string]$RelativePath)
    if ($RelativePath.Contains('..') -or $RelativePath.Contains('\') -or
        $RelativePath.TrimStart('/') -notin @('index.html', 'style.css')) {
        throw "Unzulässiger Serverpfad: $RelativePath"
    }
    $relative = [uri]::EscapeDataString($RelativePath.TrimStart('/'))
    return [uri]("ftp://{0}:{1}/{2}" -f
        $Credential.Metadata.host, $Credential.Metadata.port, $relative)
}

function New-FtpsRequest {
    param([object]$Credential, [string]$RelativePath, [string]$Method)
    $plain = ConvertFrom-MzTechSecureString -Secret $Credential.Secret
    try {
        $request = [Net.FtpWebRequest]::Create(
            (Get-FtpsUri -Credential $Credential -RelativePath $RelativePath)
        )
        $request.Method = $Method
        $request.Credentials = New-Object Net.NetworkCredential(
            [string]$Credential.Metadata.user,
            $plain
        )
        $request.EnableSsl = $true
        $request.UsePassive = $true
        $request.UseBinary = $true
        $request.KeepAlive = $false
        $request.Timeout = 45000
        $request.ReadWriteTimeout = 45000
        return $request
    } finally {
        $plain = $null
    }
}

function Receive-FtpsBytes {
    param([object]$Credential, [string]$RelativePath)
    $request = New-FtpsRequest -Credential $Credential -RelativePath $RelativePath `
        -Method ([Net.WebRequestMethods+Ftp]::DownloadFile)
    $response = $request.GetResponse()
    try {
        $stream = $response.GetResponseStream()
        $memory = New-Object IO.MemoryStream
        try {
            $stream.CopyTo($memory)
            return $memory.ToArray()
        } finally {
            $memory.Dispose()
            $stream.Dispose()
        }
    } finally {
        $response.Dispose()
    }
}

function Send-FtpsBytes {
    param([object]$Credential, [string]$RelativePath, [byte[]]$Bytes)
    $request = New-FtpsRequest -Credential $Credential -RelativePath $RelativePath `
        -Method ([Net.WebRequestMethods+Ftp]::UploadFile)
    $request.ContentLength = $Bytes.Length
    $stream = $request.GetRequestStream()
    try {
        $stream.Write($Bytes, 0, $Bytes.Length)
    } finally {
        $stream.Dispose()
    }
    $response = $request.GetResponse()
    $response.Dispose()
}

Assert-Prerequisites
if ($ValidateOnly) {
    Write-Host 'Website-Deployment-Prüfung erfolgreich; keine Credentials gelesen und keine Verbindung hergestellt.' -ForegroundColor Green
    exit 0
}

$credential = $null
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$backupRoot = Join-Path $projectRoot "backups\production\main_website_integration_$timestamp"
$inventory = @((Get-Content -LiteralPath $inventoryManifest -Raw | ConvertFrom-Json))
$originals = @{}
$deployed = New-Object Collections.Generic.List[string]
$manifest = @()

try {
    $credential = Get-FtpsCredentialData

    # Alle aktuellen Serverdateien werden vor der ersten Änderung gelesen,
    # gegen die Bestandsaufnahme geprüft und einzeln gesichert.
    foreach ($relative in $relativeFiles) {
        $serverBytes = Receive-FtpsBytes -Credential $credential -RelativePath $relative
        $serverHash = Get-ByteHash -Bytes $serverBytes
        $expected = $inventory | Where-Object { $_.file -eq $relative } | Select-Object -First 1
        if (-not $expected -or $serverHash -ne [string]$expected.sha256) {
            throw "Serverdatei hat sich seit der Bestandsaufnahme geändert; kein Upload: $relative"
        }
        $originals[$relative] = $serverBytes
    }

    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    foreach ($relative in $relativeFiles) {
        $backupPath = Join-Path $backupRoot $relative
        [IO.File]::WriteAllBytes($backupPath, [byte[]]$originals[$relative])
    }

    try {
        foreach ($relative in $relativeFiles) {
            $localBytes = [IO.File]::ReadAllBytes((Join-Path $workingRoot $relative))
            $localHash = Get-ByteHash -Bytes $localBytes
            $originalHash = Get-ByteHash -Bytes ([byte[]]$originals[$relative])

            Send-FtpsBytes -Credential $credential -RelativePath $relative -Bytes $localBytes
            $deployed.Add($relative)
            $verifiedBytes = Receive-FtpsBytes -Credential $credential -RelativePath $relative
            $verifiedHash = Get-ByteHash -Bytes $verifiedBytes
            if ($verifiedHash -ne $localHash) {
                throw "Upload-Verifikation fehlgeschlagen: $relative"
            }

            $manifest += [pscustomobject]@{
                file = '/' + $relative
                backup = (Join-Path $backupRoot $relative)
                original_sha256 = $originalHash
                deployed_sha256 = $localHash
                verified_sha256 = $verifiedHash
                verified = $true
            }
            Write-Host "Gesichert, hochgeladen und verifiziert: /$relative" -ForegroundColor Green
        }
    } catch {
        $uploadError = $_
        foreach ($relative in @($deployed)) {
            Send-FtpsBytes -Credential $credential -RelativePath $relative `
                -Bytes ([byte[]]$originals[$relative])
            $rollbackBytes = Receive-FtpsBytes -Credential $credential -RelativePath $relative
            if ((Get-ByteHash -Bytes $rollbackBytes) -ne
                (Get-ByteHash -Bytes ([byte[]]$originals[$relative]))) {
                throw "Uploadfehler und Rollback-Verifikation fehlgeschlagen: $relative"
            }
        }
        throw $uploadError
    }

    $manifest | ConvertTo-Json -Depth 4 |
        Set-Content -LiteralPath (Join-Path $backupRoot 'deployment_manifest.json') -Encoding UTF8
    Write-Host "MAIN_WEBSITE_BACKUP_ROOT=$backupRoot"
} finally {
    if ($credential) {
        $credential.Secret.Dispose()
    }
}
