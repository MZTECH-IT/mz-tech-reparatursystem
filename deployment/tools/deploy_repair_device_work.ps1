[CmdletBinding()]
param(
    [switch]$ValidateOnly,
    [switch]$AuditOnly,
    [string]$SingleFile,
    [switch]$BusinessDocuments,
    [switch]$DiagnosticOnly
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$packageRoot = Join-Path $projectRoot $(if ($BusinessDocuments) { 'deployment\business_documents_ready' } else { 'deployment\repair_device_work_ready' })
$runtimeRoot = Join-Path $projectRoot $(if ($BusinessDocuments) { 'deployment\runtime_secure\business_documents' } else { 'deployment\runtime_secure\repair_device_work' })
$php = 'C:\xampp\php\php.exe'
$credentialTarget = 'MZTech.Reparatursystem.ProductionFTPS.v1'

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

function Assert-DeploymentPrerequisites {
    if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') {
        throw 'Falscher Projektpfad.'
    }
    $sqlPrerequisites = if ($BusinessDocuments) {
        @('business_documents_preflight.sql','business_documents_migration.sql','business_documents_postcheck.sql')
    } else {
        @('repair_device_work_preflight.sql','repair_device_work_migration.sql','repair_device_work_postcheck.sql','account_verification_preflight.sql','account_verification_migration.sql','account_verification_postcheck.sql')
    }
    $requiredPaths = @(
        $packageRoot,
        $php,
        (Join-Path $PSScriptRoot 'repair_account_runner_template.php')
    ) + @($sqlPrerequisites | ForEach-Object { Join-Path $projectRoot ('sql\' + $_) })
    foreach ($path in $requiredPaths) {
        if (-not (Test-Path -LiteralPath $path)) {
            throw "Erforderlicher Pfad fehlt: $path"
        }
    }
    $forbidden = @(Get-ChildItem -LiteralPath $packageRoot -Recurse -Force | Where-Object {
        $_.Name -eq 'config.php' -or $_.Name -eq '.git' -or
        $_.Name -match '\.(bak|diff|log|sql\.gz|zip)$'
    })
    if ($forbidden.Count -gt 0) {
        throw 'Das Deployment-Paket enthält verbotene Dateien.'
    }
    $phpFiles = @(Get-ChildItem -LiteralPath (Join-Path $packageRoot 'private'),
        (Join-Path $packageRoot 'public') -Recurse -File -Filter '*.php')
    foreach ($file in $phpFiles) {
        & $php -l $file.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "PHP-Syntaxprüfung fehlgeschlagen: $($file.FullName)"
        }
    }
}

function Get-FtpsCredentialData {
    if (-not (Test-MzTechCredential -Target $credentialTarget)) {
        Start-Process -FilePath 'powershell.exe' -ArgumentList @(
            '-NoProfile', '-STA', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot 'setup_credentials_gui.ps1')
        ) -WindowStyle Normal
        throw 'FTPS-Credential fehlt. Das unabhängige Einrichtungsfenster wurde geöffnet.'
    }
    $credential = Get-MzTechCredential -Target $credentialTarget
    try {
        $metadata = $credential.Metadata | ConvertFrom-Json
    } catch {
        $credential.Secret.Dispose()
        throw 'Die FTPS-Credential-Metadaten sind ungültig.'
    }
    if (-not $metadata.explicit_tls -or [int] $metadata.port -ne 21 -or
        [string] $metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        $credential.Secret.Dispose()
        throw 'FTPS-Credential entspricht nicht dem freigegebenen TLS-Ziel.'
    }
    # Dieses Konto ist serverseitig direkt auf /mztech-it.de/repair_neu/
    # gechrootet. Innerhalb der FTPS-Sitzung entspricht daher "/" exakt dem
    # freigegebenen Produktivstamm; der Hostingpfad darf nicht doppelt
    # vorangestellt werden.
    $metadata | Add-Member -NotePropertyName effective_base_path -NotePropertyValue ''
    return [pscustomobject]@{ Metadata = $metadata; Secret = $credential.Secret }
}

function Get-FtpsUri {
    param([object]$Credential, [string]$RelativePath)
    $base = ([string] $Credential.Metadata.effective_base_path).TrimEnd('/')
    $relative = $RelativePath.Replace('\', '/').TrimStart('/')
    $segments = @("$base/$relative".Split('/') | Where-Object { $_ } | ForEach-Object {
        [uri]::EscapeDataString($_)
    })
    return [uri]("ftp://{0}:{1}/{2}" -f
        $Credential.Metadata.host, $Credential.Metadata.port, ($segments -join '/'))
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
            [string] $Credential.Metadata.user,
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
    param([object]$Credential, [string]$RelativePath, [switch]$AllowMissing)
    $request = New-FtpsRequest -Credential $Credential -RelativePath $RelativePath `
        -Method ([Net.WebRequestMethods+Ftp]::DownloadFile)
    try {
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
    } catch [Net.WebException] {
        if ($AllowMissing -and $_.Exception.Response -and
            $_.Exception.Response.StatusCode -eq [Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
            $_.Exception.Response.Dispose()
            return $null
        }
        throw
    }
}

function Receive-FtpsBytesWithRetry {
    param([object]$Credential, [string]$RelativePath, [switch]$AllowMissing)
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            return Receive-FtpsBytes -Credential $Credential -RelativePath $RelativePath -AllowMissing:$AllowMissing
        } catch [Net.WebException] {
            if ($attempt -ge 3) { throw }
            Start-Sleep -Milliseconds 750
        }
    }
}

function Send-FtpsFileOnce {
    param([object]$Credential, [string]$RelativePath, [string]$LocalPath)
    $request = New-FtpsRequest -Credential $Credential -RelativePath $RelativePath `
        -Method ([Net.WebRequestMethods+Ftp]::UploadFile)
    $bytes = [IO.File]::ReadAllBytes($LocalPath)
    try {
        $request.ContentLength = $bytes.Length
        $stream = $request.GetRequestStream()
        try {
            $stream.Write($bytes, 0, $bytes.Length)
        } finally {
            $stream.Dispose()
        }
        $response = $request.GetResponse()
        $response.Dispose()
    } finally {
        [Array]::Clear($bytes, 0, $bytes.Length)
    }
}

function Send-FtpsFile {
    param([object]$Credential, [string]$RelativePath, [string]$LocalPath)
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            Send-FtpsFileOnce -Credential $Credential -RelativePath $RelativePath -LocalPath $LocalPath
            return
        } catch {
            $uploadError = $_
            try {
                $remoteBytes = Receive-FtpsBytesWithRetry -Credential $Credential -RelativePath $RelativePath
                $localBytes = [IO.File]::ReadAllBytes($LocalPath)
                try {
                    if ((Get-ByteHash $remoteBytes) -eq (Get-ByteHash $localBytes)) {
                        return
                    }
                } finally {
                    [Array]::Clear($localBytes, 0, $localBytes.Length)
                    [Array]::Clear($remoteBytes, 0, $remoteBytes.Length)
                }
            } catch {
                # Der read-only Kontrollabruf ist nur eine Absicherung nach
                # unklarer FTPS-Antwort; der begrenzte Upload-Retry bleibt aktiv.
            }
            if ($attempt -ge 3) { throw $uploadError }
            Start-Sleep -Milliseconds (1000 * $attempt)
        }
    }
}

function Remove-FtpsFile {
    param([object]$Credential, [string]$RelativePath, [switch]$AllowMissing)
    $request = New-FtpsRequest -Credential $Credential -RelativePath $RelativePath `
        -Method ([Net.WebRequestMethods+Ftp]::DeleteFile)
    try {
        $response = $request.GetResponse()
        $response.Dispose()
    } catch [Net.WebException] {
        if ($AllowMissing -and $_.Exception.Response -and
            $_.Exception.Response.StatusCode -eq [Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
            $_.Exception.Response.Dispose()
            return
        }
        throw
    }
}

function Get-ByteHash {
    param([byte[]]$Bytes)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function New-ServerRunner {
    $random = New-Object byte[] 24
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($random)
        $runnerId = ([BitConverter]::ToString($random)).Replace('-', '').ToLowerInvariant()
        $rng.GetBytes($random)
        $token = [Convert]::ToBase64String($random).TrimEnd('=').Replace('+', '-').Replace('/', '_')
        [Array]::Clear($random, 0, $random.Length)
    } finally {
        $rng.Dispose()
    }
    $runnerName = "__mzdeploy_$runnerId.php"
    $statusName = "__mzdeploy_$runnerId.json"

    $template = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'repair_account_runner_template.php'))
    $template = $template.Replace('__RUNNER_ID__', $runnerId)
    $template = $template.Replace('__DEPLOYMENT_SCOPE__', $(if ($DiagnosticOnly) { 'preflight' } elseif ($BusinessDocuments) { 'portal' } else { 'complete' }))
    $template = $template.Replace('__TOKEN_HASH__', (
        Get-ByteHash -Bytes ([Text.Encoding]::UTF8.GetBytes($token))
    ))
    $template = $template.Replace('__STATUS_FILE__', $statusName)
    $sqlMap = if ($BusinessDocuments -and $DiagnosticOnly) { @{
        PORTAL_PREFLIGHT = 'business_documents_postcheck.sql'; PORTAL_MIGRATION = 'business_documents_migration.sql'; PORTAL_POSTCHECK = 'business_documents_postcheck.sql'
        FONEDAY_PREFLIGHT = 'business_documents_preflight.sql'; FONEDAY_MIGRATION = 'business_documents_preflight.sql'; FONEDAY_POSTCHECK = 'business_documents_postcheck.sql'
    } } elseif ($BusinessDocuments) { @{
        PORTAL_PREFLIGHT = 'business_documents_preflight.sql'; PORTAL_MIGRATION = 'business_documents_migration.sql'; PORTAL_POSTCHECK = 'business_documents_postcheck.sql'
        FONEDAY_PREFLIGHT = 'business_documents_preflight.sql'; FONEDAY_MIGRATION = 'business_documents_preflight.sql'; FONEDAY_POSTCHECK = 'business_documents_postcheck.sql'
    } } else { @{
        PORTAL_PREFLIGHT = 'repair_device_work_preflight.sql'; PORTAL_MIGRATION = 'repair_device_work_migration.sql'; PORTAL_POSTCHECK = 'repair_device_work_postcheck.sql'
        FONEDAY_PREFLIGHT = 'account_verification_preflight.sql'; FONEDAY_MIGRATION = 'account_verification_migration.sql'; FONEDAY_POSTCHECK = 'account_verification_postcheck.sql'
    } }
    foreach ($key in $sqlMap.Keys) {
        $bytes = [IO.File]::ReadAllBytes((Join-Path $projectRoot ('sql\' + $sqlMap[$key])))
        $template = $template.Replace("__${key}_B64__", [Convert]::ToBase64String($bytes))
        $template = $template.Replace("__${key}_HASH__", (Get-ByteHash -Bytes $bytes))
    }
    New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
    $localPath = Join-Path $runtimeRoot $runnerName
    [IO.File]::WriteAllText($localPath, $template, (New-Object Text.UTF8Encoding($false)))
    & $php -l $localPath | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Der generierte Server-Runner ist syntaktisch ungültig.'
    }
    return [pscustomobject]@{
        Id = $runnerId
        Token = $token
        RunnerName = $runnerName
        StatusName = $statusName
        LocalPath = $localPath
    }
}

function Invoke-ServerSqlSequence {
    param([object]$Credential, [object]$Runner)
    $runnerRelative = 'public\' + $Runner.RunnerName
    $statusRelative = 'private\' + $Runner.StatusName
    Send-FtpsFile -Credential $Credential -RelativePath $runnerRelative -LocalPath $Runner.LocalPath
    $remoteRunner = Receive-FtpsBytes -Credential $Credential -RelativePath $runnerRelative
    $localRunner = [IO.File]::ReadAllBytes($Runner.LocalPath)
    if ((Get-ByteHash $remoteRunner) -ne (Get-ByteHash $localRunner)) {
        throw 'Hashprüfung des temporären Runners fehlgeschlagen.'
    }

    Add-Type -AssemblyName System.Windows.Forms
    Start-Process 'https://mztech-it.de/repair_neu/public/index.php'
    [Windows.Forms.MessageBox]::Show(
        'Bitte melden Sie sich im geöffneten Browser als interner Administrator an. Klicken Sie erst danach auf OK.',
        'MZ Tech – Administratoranmeldung',
        [Windows.Forms.MessageBoxButtons]::OKCancel,
        [Windows.Forms.MessageBoxIcon]::Information
    ) | ForEach-Object {
        if ($_ -ne [Windows.Forms.DialogResult]::OK) {
            throw 'Administratoranmeldung wurde abgebrochen.'
        }
    }
    $url = "https://mztech-it.de/repair_neu/public/$($Runner.RunnerName)"
    Start-Process $url
    Write-Host 'Der geschützte Runner wurde mit serverseitigem Sitzungs-Nonce geöffnet.'
    Write-Host $(if ($BusinessDocuments) { 'Die drei Dokument-/Abrechnungsschritte müssen dort einzeln bestätigt werden. Geheimnisse werden nicht ausgegeben.' } else { 'Die sechs Reparatur-/Konto-Schritte müssen dort einzeln bestätigt werden. Geheimnisse werden nicht ausgegeben.' })

    $deadline = (Get-Date).AddMinutes(30)
    $state = ''
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 5
        $statusBytes = Receive-FtpsBytes -Credential $Credential -RelativePath $statusRelative -AllowMissing
        if ($null -eq $statusBytes) {
            continue
        }
        try {
            $status = [Text.Encoding]::UTF8.GetString($statusBytes) | ConvertFrom-Json
        } catch {
            throw 'Der bereinigte Runnerstatus ist ungültig.'
        }
        if ($status.runner_id -ne $Runner.Id) {
            throw 'Runnerstatus gehört nicht zum aktuellen Lauf.'
        }
        if ($status.state -ne $state) {
            $state = [string] $status.state
            Write-Host "Runnerstatus: $state"
        }
        if ($state -eq 'failed') {
            $diagnostic = [string] $status.summary.diagnostic
            if ($diagnostic) {
                throw "Der serverseitige SQL-Ablauf wurde sicher gestoppt: $diagnostic"
            }
            throw 'Der serverseitige SQL-Ablauf wurde sicher gestoppt.'
        }
        if ($state -eq 'complete') {
            break
        }
    }
    if ($state -ne 'complete') {
        throw 'Zeitlimit für die manuell zu bestätigenden SQL-Schritte erreicht.'
    }

    [Windows.Forms.Clipboard]::Clear()
    Remove-FtpsFile -Credential $Credential -RelativePath $runnerRelative
    Remove-FtpsFile -Credential $Credential -RelativePath $statusRelative
    if ($null -ne (Receive-FtpsBytes -Credential $Credential -RelativePath $runnerRelative -AllowMissing) -or
        $null -ne (Receive-FtpsBytes -Credential $Credential -RelativePath $statusRelative -AllowMissing)) {
        throw 'Die Entfernung des temporären Runners wurde nicht bestätigt.'
    }
}

function Invoke-ApplicationUpload {
    param([object]$Credential)
    $roots = @((Join-Path $packageRoot 'private'), (Join-Path $packageRoot 'public'))
    $files = @(Get-ChildItem -LiteralPath $roots -Recurse -File | Sort-Object FullName)
    $prefix = $packageRoot.TrimEnd('\') + '\'
    $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
    $backupRoot = Join-Path $projectRoot $(if ($BusinessDocuments) { "backups\production\business_documents_$timestamp" } else { "backups\production\repair_device_work_$timestamp" })
    $manifest = @()

    foreach ($file in $files) {
        $relative = $file.FullName.Substring($prefix.Length)
        if ($relative -ieq 'private\config.php') {
            throw 'Sicherheitsabbruch: private/config.php in Uploadliste.'
        }
        $serverBytes = Receive-FtpsBytesWithRetry -Credential $Credential -RelativePath $relative -AllowMissing
        if ($null -ne $serverBytes) {
            $backupPath = Join-Path $backupRoot $relative
            New-Item -ItemType Directory -Path (Split-Path $backupPath) -Force | Out-Null
            [IO.File]::WriteAllBytes($backupPath, $serverBytes)
            $manifest += [pscustomobject]@{
                file = $relative.Replace('\', '/')
                prior = 'backup'
                original_sha256 = Get-ByteHash $serverBytes
                verified = $false
            }
        } else {
            $manifest += [pscustomobject]@{
                file = $relative.Replace('\', '/')
                prior = 'new'
                original_sha256 = $null
                verified = $false
            }
        }
    }

    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    $manifestPath = Join-Path $backupRoot 'deployment_manifest.json'
    $manifest | ConvertTo-Json -Depth 5 |
        Set-Content -LiteralPath $manifestPath -Encoding UTF8

    foreach ($file in $files) {
        $relative = $file.FullName.Substring($prefix.Length)
        Send-FtpsFile -Credential $Credential -RelativePath $relative -LocalPath $file.FullName
        $verified = Receive-FtpsBytes -Credential $Credential -RelativePath $relative
        $local = [IO.File]::ReadAllBytes($file.FullName)
        if ((Get-ByteHash $verified) -ne (Get-ByteHash $local)) {
            throw "Upload-Verifikation fehlgeschlagen: $relative"
        }
        ($manifest | Where-Object file -eq $relative.Replace('\', '/')).verified = $true
        $manifest | ConvertTo-Json -Depth 5 |
            Set-Content -LiteralPath $manifestPath -Encoding UTF8
        Write-Host "Verifiziert: $relative"
    }
    return [pscustomobject]@{ BackupRoot = $backupRoot; Manifest = $manifest }
}

function Invoke-ServerAudit {
    param([object]$Credential)
    $roots = @((Join-Path $packageRoot 'private'), (Join-Path $packageRoot 'public'))
    $files = @(Get-ChildItem -LiteralPath $roots -Recurse -File | Sort-Object FullName)
    $prefix = $packageRoot.TrimEnd('\') + '\'
    $summary = [ordered]@{ total = $files.Count; identical = 0; different = 0; missing = 0 }
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($prefix.Length)
        if ($relative -ieq 'private\config.php') {
            throw 'Sicherheitsabbruch: private/config.php in Auditliste.'
        }
        $serverBytes = Receive-FtpsBytesWithRetry -Credential $Credential -RelativePath $relative -AllowMissing
        if ($null -eq $serverBytes) {
            $summary.missing++
            continue
        }
        $localBytes = [IO.File]::ReadAllBytes($file.FullName)
        if ((Get-ByteHash $serverBytes) -eq (Get-ByteHash $localBytes)) {
            $summary.identical++
        } else {
            $summary.different++
        }
    }
    return [pscustomobject]$summary
}

function Test-CoreHttpHealth {
    $checks = @(
        @{ Url = 'https://mztech-it.de/repair_neu/public/index.php'; Expected = 200; Html = $true },
        @{ Url = 'https://mztech-it.de/repair_neu/public/dashboard.php'; Expected = 302; Html = $false },
        @{ Url = 'https://mztech-it.de/repair_neu/public/repairs.php'; Expected = 302; Html = $false }
    )
    foreach ($check in $checks) {
        $response = $null
        try {
            $request = [Net.HttpWebRequest]::Create([uri]$check.Url)
            $request.AllowAutoRedirect = $false
            $request.Timeout = 20000
            try {
                $response = $request.GetResponse()
            } catch [Net.WebException] {
                if ($_.Exception.Response) { $response = $_.Exception.Response } else { return $false }
            }
            if ([int]$response.StatusCode -ne [int]$check.Expected) { return $false }
            $reader = New-Object IO.StreamReader($response.GetResponseStream())
            try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
            if ($body -match '"success"\s*:\s*false.*Datenbankfehler|Etwas ist schiefgelaufen') {
                return $false
            }
            if ($check.Html -and $body -notmatch '<html|<!DOCTYPE|<form') { return $false }
        } finally {
            if ($response) { $response.Dispose() }
        }
    }
    return $true
}

function Invoke-SingleFileUpload {
    param([object]$Credential, [string]$RelativePath)
    $relative = $RelativePath.Replace('/', '\').TrimStart('\')
    if ($relative -notmatch '^(private|public)\\' -or $relative -ieq 'private\config.php') {
        throw 'Die Einzeldatei liegt außerhalb der erlaubten Anwendungsbereiche.'
    }
    $source = Join-Path $packageRoot $relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw 'Die Einzeldatei ist nicht Bestandteil des geprüften Deployment-Pakets.'
    }
    $serverBytes = Receive-FtpsBytesWithRetry -Credential $Credential -RelativePath $relative -AllowMissing
    if ($null -eq $serverBytes) {
        throw 'Der sichere Einzeldateimodus überschreibt nur vorhandene Dateien.'
    }
    $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
    $backupRoot = Join-Path $projectRoot "backups\production\single_file_$timestamp"
    $backupPath = Join-Path $backupRoot $relative
    New-Item -ItemType Directory -Path (Split-Path $backupPath) -Force | Out-Null
    [IO.File]::WriteAllBytes($backupPath, $serverBytes)
    $localBytes = [IO.File]::ReadAllBytes($source)
    try {
        $manifest = [pscustomobject]@{
            file = $relative.Replace('\', '/')
            original_sha256 = Get-ByteHash $serverBytes
            replacement_sha256 = Get-ByteHash $localBytes
            upload_verified = $false
            health_verified = $false
            rolled_back = $false
        }
        $manifestPath = Join-Path $backupRoot 'single_file_manifest.json'
        $manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8
        if ($manifest.original_sha256 -ne $manifest.replacement_sha256) {
            Send-FtpsFile -Credential $Credential -RelativePath $relative -LocalPath $source
        }
        $verified = Receive-FtpsBytesWithRetry -Credential $Credential -RelativePath $relative
        try {
            if ((Get-ByteHash $verified) -ne $manifest.replacement_sha256) {
                throw 'Upload-Hashprüfung der Einzeldatei fehlgeschlagen.'
            }
        } finally { [Array]::Clear($verified, 0, $verified.Length) }
        $manifest.upload_verified = $true
        $manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8

        if (-not (Test-CoreHttpHealth)) {
            Send-FtpsFile -Credential $Credential -RelativePath $relative -LocalPath $backupPath
            $restored = Receive-FtpsBytesWithRetry -Credential $Credential -RelativePath $relative
            try {
                if ((Get-ByteHash $restored) -ne $manifest.original_sha256) {
                    throw 'Automatischer Einzeldatei-Rollback konnte nicht verifiziert werden.'
                }
            } finally { [Array]::Clear($restored, 0, $restored.Length) }
            $manifest.rolled_back = $true
            $manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8
            throw "HTTP-Smoke-Test fehlgeschlagen; Einzeldatei wurde zurückgerollt: $relative"
        }
        $manifest.health_verified = $true
        $manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8
        Write-Host "SINGLE_FILE_VERIFIED=$relative"
        Write-Host "SINGLE_FILE_BACKUP=$backupRoot"
    } finally {
        [Array]::Clear($serverBytes, 0, $serverBytes.Length)
        [Array]::Clear($localBytes, 0, $localBytes.Length)
    }
}

Assert-DeploymentPrerequisites
if ($ValidateOnly) {
    Write-Host 'Lokale Deployment-Prüfung erfolgreich; keine Credentials oder Netzwerke verwendet.'
    exit 0
}

$credential = $null
$runner = $null
try {
    $credential = Get-FtpsCredentialData
    if ($AuditOnly) {
        $audit = Invoke-ServerAudit -Credential $credential
        Write-Host "SERVER_AUDIT_TOTAL=$($audit.total)"
        Write-Host "SERVER_AUDIT_IDENTICAL=$($audit.identical)"
        Write-Host "SERVER_AUDIT_DIFFERENT=$($audit.different)"
        Write-Host "SERVER_AUDIT_MISSING=$($audit.missing)"
        return
    }
    if ($SingleFile) {
        Invoke-SingleFileUpload -Credential $credential -RelativePath $SingleFile
        return
    }
    $runner = New-ServerRunner
    Invoke-ServerSqlSequence -Credential $credential -Runner $runner
    if ($DiagnosticOnly) {
        Write-Host 'Rein lesende Produktivdiagnose abgeschlossen; keine Migration und kein Anwendungsupload ausgeführt.'
        return
    }
    $upload = Invoke-ApplicationUpload -Credential $credential
    Write-Host 'Produktivbereitstellung mit Sicherungen und Hashprüfung abgeschlossen.' -ForegroundColor Green
    Write-Host "Sicherungsverzeichnis: $($upload.BackupRoot)"
} catch {
    Write-Host 'KRITISCHER ABBRUCH: Es werden keine weiteren Schritte ausgeführt.' -ForegroundColor Red
    Write-Host $_.Exception.Message
    if ($credential -and $runner) {
        try {
            Add-Type -AssemblyName System.Windows.Forms
            [Windows.Forms.Clipboard]::Clear()
            Remove-FtpsFile -Credential $credential -RelativePath ('public\' + $runner.RunnerName) -AllowMissing
            Remove-FtpsFile -Credential $credential -RelativePath ('private\' + $runner.StatusName) -AllowMissing
        } catch {
            Write-Host ('Der temporäre Runner muss manuell entfernt und geprüft werden: ' +
                $_.Exception.Message) -ForegroundColor Red
        }
    }
    exit 1
} finally {
    if ($runner) {
        $runner.Token = ''
        Remove-Item -LiteralPath $runner.LocalPath -Force -ErrorAction SilentlyContinue
    }
    if ($credential) {
        $credential.Secret.Dispose()
    }
}
