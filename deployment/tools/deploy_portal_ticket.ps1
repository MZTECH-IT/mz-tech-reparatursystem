[CmdletBinding()]
param(
    [switch]$ValidateOnly
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$packageRoot = Join-Path $projectRoot 'deployment\portal_ticket_ready'
$runtimeRoot = Join-Path $projectRoot 'deployment\runtime_secure'
$php = 'C:\xampp\php\php.exe'
$sqlRunner = Join-Path $PSScriptRoot 'run_portal_sql.php'
$setupScript = Join-Path $PSScriptRoot 'setup_credentials_gui.ps1'

$targets = @{
    Database = 'MZTech.Reparatursystem.ProductionDB.v1'
    Ftps = 'MZTech.Reparatursystem.ProductionFTPS.v1'
    Foneday = 'MZTech.Reparatursystem.FONEDAY_API_TOKEN.v1'
}

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

function Assert-LocalPrerequisites {
    if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') {
        throw "Falscher Projektpfad: $projectRoot"
    }
    foreach ($path in @(
        $packageRoot,
        $php,
        $sqlRunner,
        (Join-Path $projectRoot 'sql\portal_ticket_preflight.sql'),
        (Join-Path $projectRoot 'sql\portal_ticket_migration.sql'),
        (Join-Path $projectRoot 'sql\portal_ticket_postcheck.sql')
    )) {
        if (-not (Test-Path -LiteralPath $path)) {
            throw "Erforderlicher Pfad fehlt: $path"
        }
    }
    $forbidden = @(
        Get-ChildItem -LiteralPath $packageRoot -Recurse -Force |
            Where-Object {
                $_.Name -eq 'config.php' -or
                $_.Name -eq '.git' -or
                $_.Name -match '\.(bak|diff|log)$'
            }
    )
    if ($forbidden.Count -gt 0) {
        throw 'Deployment-Paket enthält verbotene Dateien.'
    }
    & $php -l $sqlRunner | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'PHP-Syntaxprüfung des SQL-Runners fehlgeschlagen.'
    }
}

function Ensure-Credentials {
    $missing = @()
    foreach ($name in @('Database', 'Ftps')) {
        if (-not (Test-MzTechCredential -Target $targets[$name])) {
            $missing += $targets[$name]
        }
    }
    if ($missing.Count -eq 0) {
        return
    }

    Write-Host 'Erforderliche Windows-Credentials fehlen. Die sichtbare Ersteinrichtung wird geöffnet.' -ForegroundColor Yellow
    $argumentLine = "-NoProfile -STA -ExecutionPolicy Bypass -File `"$setupScript`""
    $process = Start-Process -FilePath 'powershell.exe' -ArgumentList $argumentLine `
        -WorkingDirectory $projectRoot -WindowStyle Normal -Wait -PassThru
    if ($process.ExitCode -ne 0) {
        throw 'Credential-Einrichtung wurde nicht erfolgreich abgeschlossen.'
    }
    foreach ($name in @('Database', 'Ftps')) {
        if (-not (Test-MzTechCredential -Target $targets[$name])) {
            throw "Credential fehlt weiterhin: $($targets[$name])"
        }
    }
}

function Get-CredentialData {
    param([string]$Target)
    $credential = Get-MzTechCredential -Target $Target
    if (-not $credential) {
        throw "Credential fehlt: $Target"
    }
    try {
        $metadata = $credential.Metadata | ConvertFrom-Json
    } catch {
        $credential.Secret.Dispose()
        throw "Credential-Metadaten sind ungültig: $Target"
    }
    return [pscustomobject]@{
        Metadata = $metadata
        Secret = $credential.Secret
    }
}

function Save-SanitizedResult {
    param([string]$Mode, [object]$Result)
    $resultDir = Join-Path $runtimeRoot 'results'
    New-Item -ItemType Directory -Force -Path $resultDir | Out-Null
    $path = Join-Path $resultDir ("{0}_{1}.json" -f (Get-Date -Format 'yyyyMMdd_HHmmss'), $Mode)
    $Result | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $path -Encoding UTF8
    return $path
}

function Invoke-PortalSql {
    param(
        [ValidateSet('preflight', 'migration', 'postcheck')]
        [string]$Mode,
        [object]$DatabaseCredential
    )

    $plainSecret = ConvertFrom-MzTechSecureString -Secret $DatabaseCredential.Secret
    try {
        $env:MZTECH_DB_HOST = [string]$DatabaseCredential.Metadata.host
        $env:MZTECH_DB_PORT = [string]$DatabaseCredential.Metadata.port
        $env:MZTECH_DB_NAME = [string]$DatabaseCredential.Metadata.database
        $env:MZTECH_DB_USER = [string]$DatabaseCredential.Metadata.user
        $env:MZTECH_DB_PASSWORD = $plainSecret
        $env:MZTECH_DB_SSL_CA = [string]$DatabaseCredential.Metadata.ssl_ca
        $raw = (& $php $sqlRunner $Mode | Out-String).Trim()
        $exitCode = $LASTEXITCODE
    }
    finally {
        Remove-Item Env:MZTECH_DB_HOST -ErrorAction SilentlyContinue
        Remove-Item Env:MZTECH_DB_PORT -ErrorAction SilentlyContinue
        Remove-Item Env:MZTECH_DB_NAME -ErrorAction SilentlyContinue
        Remove-Item Env:MZTECH_DB_USER -ErrorAction SilentlyContinue
        Remove-Item Env:MZTECH_DB_PASSWORD -ErrorAction SilentlyContinue
        Remove-Item Env:MZTECH_DB_SSL_CA -ErrorAction SilentlyContinue
        $plainSecret = $null
    }
    if (-not $raw) {
        throw "SQL-$Mode lieferte keine auswertbare Antwort."
    }
    try {
        $result = $raw | ConvertFrom-Json
    } catch {
        throw "SQL-$Mode lieferte keine gültige JSON-Antwort."
    }
    $resultPath = Save-SanitizedResult -Mode $Mode -Result $result
    if ($exitCode -ne 0 -or -not $result.success) {
        $message = if ($result.error) { $result.error } else { "Exit-Code $exitCode" }
        throw "SQL-$Mode fehlgeschlagen: $message. Ergebnis: $resultPath"
    }
    return [pscustomobject]@{ Data = $result; Path = $resultPath }
}

function Get-AllResultRows {
    param([object]$SqlResult)
    $rows = @()
    foreach ($set in @($SqlResult.result_sets)) {
        foreach ($row in @($set)) {
            $rows += $row
        }
    }
    return $rows
}

function Assert-Preflight {
    param([object]$SqlResult, [string]$ExpectedSchema)
    $rows = @(Get-AllResultRows -SqlResult $SqlResult)
    $context = @($rows | Where-Object { $_.PSObject.Properties.Name -contains 'context_status' })
    if ($context.Count -ne 1 -or $context[0].context_status -ne 'OK' -or $context[0].active_schema -ne $ExpectedSchema) {
        throw 'Preflight: Datenbankkontext ist falsch oder nicht eindeutig.'
    }

    $requiredTables = @(
        'users', 'customers', 'repairs', 'settings', 'email_templates',
        'customer_portal_access', 'customer_accounts', 'portal_login_attempts'
    )
    $tableRows = @($rows | Where-Object {
        $_.PSObject.Properties.Name -contains 'status' -and
        $_.PSObject.Properties.Name -contains 'TABLE_NAME' -and
        -not ($_.PSObject.Properties.Name -contains 'COLUMN_NAME')
    })
    foreach ($table in $requiredTables) {
        $row = @($tableRows | Where-Object { $_.TABLE_NAME -eq $table })
        if ($row.Count -ne 1 -or $row[0].status -ne 'VORHANDEN') {
            throw "Preflight: erforderliche Basistabelle fehlt: $table"
        }
    }

    $prerequisiteFailures = @($rows | Where-Object {
        ($_.requirement -eq 'BASE_COLUMN' -or
         $_.requirement -eq 'UNIQUE_INDEX' -or
         $_.requirement -eq 'EXISTING_TARGET_COLUMN') -and
        $_.status -ne 'VORHANDEN'
    })
    $prerequisiteFailures = @($prerequisiteFailures | Where-Object {
        $_.status -ne 'NEUE_TABELLE'
    })
    if ($prerequisiteFailures.Count -gt 0) {
        $names = $prerequisiteFailures | ForEach-Object { "$($_.TABLE_NAME).$($_.COLUMN_NAME)" }
        throw "Preflight: Voraussetzungen fehlen: $($names -join ', ')"
    }

    $engineProblems = @($rows | Where-Object { $_.requirement -eq 'ENGINE_OR_COLLATION' })
    if ($engineProblems.Count -gt 0) {
        $names = $engineProblems | ForEach-Object { $_.TABLE_NAME }
        throw "Preflight: Engine/Collation unerwartet: $($names -join ', ')"
    }

    $marker = @($rows | Where-Object { $_.result -eq 'PREFLIGHT_READ_ONLY_COMPLETE' })
    if ($marker.Count -ne 1 -or $marker[0].checked_schema -ne $ExpectedSchema) {
        throw 'Preflight-Abschlussmarker fehlt oder verweist auf ein anderes Schema.'
    }
}

function Assert-Migration {
    param([object]$SqlResult, [string]$ExpectedSchema)
    $rows = @(Get-AllResultRows -SqlResult $SqlResult)
    $marker = @($rows | Where-Object { $_.result -eq 'PORTAL_TICKET_MIGRATION_COMPLETE' })
    if ($marker.Count -ne 1 -or $marker[0].active_schema -ne $ExpectedSchema) {
        throw 'Migrations-Abschlussmarker fehlt oder verweist auf ein anderes Schema.'
    }
}

function Assert-Postcheck {
    param([object]$SqlResult, [string]$ExpectedSchema)
    $rows = @(Get-AllResultRows -SqlResult $SqlResult)
    $context = @($rows | Where-Object { $_.PSObject.Properties.Name -contains 'context_status' })
    if ($context.Count -ne 1 -or $context[0].context_status -ne 'OK' -or $context[0].active_schema -ne $ExpectedSchema) {
        throw 'Postcheck: Datenbankkontext ist falsch.'
    }
    $missing = @($rows | Where-Object {
        $_.PSObject.Properties.Name -contains 'status' -and $_.status -eq 'FEHLT'
    })
    if ($missing.Count -gt 0) {
        $names = $missing | ForEach-Object {
            if ($_.COLUMN_NAME) { "$($_.TABLE_NAME).$($_.COLUMN_NAME)" }
            elseif ($_.INDEX_NAME) { "$($_.TABLE_NAME).$($_.INDEX_NAME)" }
            else { $_.TABLE_NAME }
        }
        throw "Postcheck: Objekte fehlen: $($names -join ', ')"
    }
    $violations = @($rows | Where-Object {
        $_.PSObject.Properties.Name -contains 'violations' -and
        $_.violations -ne $null -and [int64]$_.violations -ne 0
    })
    if ($violations.Count -gt 0) {
        throw 'Postcheck: Klartexttoken-Prüfung meldet Verstöße.'
    }
    $statusRow = @($rows | Where-Object { $_.PSObject.Properties.Name -contains 'ticket_status_values' })
    foreach ($requiredStatus in @('offen', 'in_bearbeitung', 'wartet_auf_kunde', 'wartet_intern', 'geloest', 'geschlossen', 'storniert')) {
        if ($statusRow.Count -ne 1 -or $statusRow[0].ticket_status_values -notmatch [regex]::Escape("'$requiredStatus'")) {
            throw "Postcheck: Ticketstatus fehlt: $requiredStatus"
        }
    }
    $marker = @($rows | Where-Object { $_.result -eq 'POSTCHECK_READ_ONLY_COMPLETE' })
    if ($marker.Count -ne 1 -or $marker[0].checked_schema -ne $ExpectedSchema) {
        throw 'Postcheck-Abschlussmarker fehlt oder verweist auf ein anderes Schema.'
    }
}

function New-FtpsRequest {
    param(
        [uri]$Uri,
        [string]$Method,
        [object]$FtpsCredential
    )
    $password = ConvertFrom-MzTechSecureString -Secret $FtpsCredential.Secret
    try {
        $request = [Net.FtpWebRequest]::Create($Uri)
        $request.Method = $Method
        $request.Credentials = New-Object Net.NetworkCredential(
            [string]$FtpsCredential.Metadata.user,
            $password
        )
        $request.EnableSsl = $true
        $request.UsePassive = $true
        $request.UseBinary = $true
        $request.KeepAlive = $false
        $request.Timeout = 30000
        $request.ReadWriteTimeout = 30000
        return $request
    }
    finally {
        $password = $null
    }
}

function Get-FtpsUri {
    param([object]$FtpsCredential, [string]$RelativePath)
    $base = ([string]$FtpsCredential.Metadata.base_path).TrimEnd('/')
    $relative = $RelativePath.Replace('\', '/').TrimStart('/')
    $segments = @("$base/$relative".Split('/') | Where-Object { $_ -ne '' } | ForEach-Object {
        [uri]::EscapeDataString($_)
    })
    $path = '/' + ($segments -join '/')
    return [uri]("ftp://{0}:{1}{2}" -f
        $FtpsCredential.Metadata.host,
        $FtpsCredential.Metadata.port,
        $path
    )
}

function Receive-FtpsBytes {
    param(
        [object]$FtpsCredential,
        [string]$RelativePath,
        [switch]$AllowMissing
    )
    $uri = Get-FtpsUri -FtpsCredential $FtpsCredential -RelativePath $RelativePath
    $request = New-FtpsRequest -Uri $uri -Method ([Net.WebRequestMethods+Ftp]::DownloadFile) `
        -FtpsCredential $FtpsCredential
    try {
        $response = $request.GetResponse()
        try {
            $stream = $response.GetResponseStream()
            $memory = New-Object IO.MemoryStream
            try {
                $stream.CopyTo($memory)
                return $memory.ToArray()
            }
            finally {
                $memory.Dispose()
                $stream.Dispose()
            }
        }
        finally {
            $response.Dispose()
        }
    }
    catch [Net.WebException] {
        if ($AllowMissing -and $_.Exception.Response -and
            $_.Exception.Response.StatusCode -eq [Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
            $_.Exception.Response.Dispose()
            return $null
        }
        throw
    }
}

function Send-FtpsFile {
    param(
        [object]$FtpsCredential,
        [string]$RelativePath,
        [string]$LocalPath
    )
    $uri = Get-FtpsUri -FtpsCredential $FtpsCredential -RelativePath $RelativePath
    $request = New-FtpsRequest -Uri $uri -Method ([Net.WebRequestMethods+Ftp]::UploadFile) `
        -FtpsCredential $FtpsCredential
    $bytes = [IO.File]::ReadAllBytes($LocalPath)
    $request.ContentLength = $bytes.Length
    $stream = $request.GetRequestStream()
    try {
        $stream.Write($bytes, 0, $bytes.Length)
    }
    finally {
        $stream.Dispose()
    }
    $response = $request.GetResponse()
    $response.Dispose()
}

function Get-ByteHash {
    param([byte[]]$Bytes)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '')
    }
    finally {
        $sha.Dispose()
    }
}

function Invoke-FtpsDeployment {
    param([object]$FtpsCredential)
    if (-not $FtpsCredential.Metadata.explicit_tls) {
        throw 'FTPS-Credential verlangt nicht ausdrücklich TLS.'
    }
    if ([int]$FtpsCredential.Metadata.port -ne 21) {
        throw 'Für dieses Deployment ist ausschließlich explizites FTPS auf Port 21 zulässig.'
    }
    if ([string]$FtpsCredential.Metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        throw 'FTPS-Zielpfad weicht vom freigegebenen Produktivverzeichnis ab.'
    }

    $files = @(
        Get-ChildItem -LiteralPath (Join-Path $packageRoot 'private'), (Join-Path $packageRoot 'public') `
            -Recurse -File | Sort-Object FullName
    )
    $packagePrefix = $packageRoot.TrimEnd('\') + '\'
    $relativePaths = @($files | ForEach-Object { $_.FullName.Substring($packagePrefix.Length) })
    if ($relativePaths -contains 'private\config.php') {
        throw 'Sicherheitsabbruch: private/config.php befindet sich in der Uploadliste.'
    }

    $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
    $backupRoot = Join-Path $projectRoot "backups\production\portal_ticket_$timestamp"
    $manifest = @()
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($packagePrefix.Length)
        Write-Host "Sicherung: $relative"
        $serverBytes = Receive-FtpsBytes -FtpsCredential $FtpsCredential `
            -RelativePath $relative -AllowMissing
        if ($serverBytes -ne $null) {
            $backupPath = Join-Path $backupRoot $relative
            New-Item -ItemType Directory -Force -Path (Split-Path $backupPath) | Out-Null
            [IO.File]::WriteAllBytes($backupPath, $serverBytes)
            $manifest += [pscustomobject]@{
                file = $relative.Replace('\', '/')
                previous_state = 'backed_up'
                backup_sha256 = Get-ByteHash -Bytes $serverBytes
                verified = $false
            }
        } else {
            $manifest += [pscustomobject]@{
                file = $relative.Replace('\', '/')
                previous_state = 'new_file'
                backup_sha256 = $null
                verified = $false
            }
        }
    }

    foreach ($file in $files) {
        $relative = $file.FullName.Substring($packagePrefix.Length)
        Write-Host "Upload und Verifikation: $relative"
        Send-FtpsFile -FtpsCredential $FtpsCredential -RelativePath $relative -LocalPath $file.FullName
        $verifiedBytes = Receive-FtpsBytes -FtpsCredential $FtpsCredential -RelativePath $relative
        $localBytes = [IO.File]::ReadAllBytes($file.FullName)
        if ((Get-ByteHash -Bytes $verifiedBytes) -ne (Get-ByteHash -Bytes $localBytes)) {
            throw "Hash-Verifikation fehlgeschlagen: $relative"
        }
        ($manifest | Where-Object { $_.file -eq $relative.Replace('\', '/') }).verified = $true
    }

    New-Item -ItemType Directory -Force -Path $backupRoot | Out-Null
    $manifestPath = Join-Path $backupRoot 'deployment_manifest.json'
    $manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    return [pscustomobject]@{
        BackupRoot = $backupRoot
        ManifestPath = $manifestPath
        Uploaded = $relativePaths
    }
}

function Invoke-HttpSmokeTests {
    $urls = @(
        'https://mztech-it.de/repair_neu/public/portal.php',
        'https://mztech-it.de/repair_neu/public/portal_business.php',
        'https://mztech-it.de/repair_neu/public/portal_tickets.php'
    )
    $results = @()
    foreach ($url in $urls) {
        $request = [Net.HttpWebRequest]::Create($url)
        $request.Method = 'GET'
        $request.AllowAutoRedirect = $false
        $request.Timeout = 20000
        try {
            $response = $request.GetResponse()
            try {
                $code = [int]$response.StatusCode
            } finally {
                $response.Dispose()
            }
        } catch [Net.WebException] {
            if ($_.Exception.Response) {
                $code = [int]$_.Exception.Response.StatusCode
                $_.Exception.Response.Dispose()
            } else {
                throw
            }
        }
        if ($code -notin @(200, 301, 302, 303)) {
            throw "HTTP-Smoke-Test fehlgeschlagen: $url (Status $code)"
        }
        $results += [pscustomobject]@{ url = $url; status = $code }
    }
    return $results
}

Assert-LocalPrerequisites
if ($ValidateOnly) {
    Write-Host 'Lokale Deployment-Prüfung erfolgreich. Es wurden keine Credentials gelesen und keine Verbindung hergestellt.' -ForegroundColor Green
    exit 0
}

Ensure-Credentials
$databaseCredential = Get-CredentialData -Target $targets.Database
$ftpsCredential = Get-CredentialData -Target $targets.Ftps

try {
    Write-Host '1/6 Datenbank-Preflight (rein lesend)'
    $preflight = Invoke-PortalSql -Mode preflight -DatabaseCredential $databaseCredential
    Assert-Preflight -SqlResult $preflight.Data -ExpectedSchema $databaseCredential.Metadata.database

    Write-Host '2/6 Migration'
    $migration = Invoke-PortalSql -Mode migration -DatabaseCredential $databaseCredential
    Assert-Migration -SqlResult $migration.Data -ExpectedSchema $databaseCredential.Metadata.database

    Write-Host '3/6 Postcheck (rein lesend)'
    $postcheck = Invoke-PortalSql -Mode postcheck -DatabaseCredential $databaseCredential
    Assert-Postcheck -SqlResult $postcheck.Data -ExpectedSchema $databaseCredential.Metadata.database

    Write-Host '4/6 FTPS-Sicherungen'
    Write-Host '5/6 FTPS-Upload und SHA-256-Verifikation'
    $deployment = Invoke-FtpsDeployment -FtpsCredential $ftpsCredential

    Write-Host '6/6 HTTP-Smoke-Tests'
    $smokeTests = Invoke-HttpSmokeTests

    Write-Host ''
    Write-Host 'Deployment technisch erfolgreich abgeschlossen.' -ForegroundColor Green
    Write-Host "Lokale Sicherung: $($deployment.BackupRoot)"
    $smokeTests | Format-Table -AutoSize
}
catch {
    Write-Host ''
    Write-Host 'KRITISCHER ABBRUCH – keine weiteren Schritte werden ausgeführt.' -ForegroundColor Red
    Write-Host $_.Exception.Message
    Write-Host "Rollback-Datei vorbereitet: $projectRoot\sql\portal_ticket_rollback.sql"
    exit 1
}
finally {
    if ($databaseCredential) {
        $databaseCredential.Secret.Dispose()
    }
    if ($ftpsCredential) {
        $ftpsCredential.Secret.Dispose()
    }
}
