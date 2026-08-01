[CmdletBinding()]
param(
    [switch]$ValidateOnly,
    [switch]$PrepareTest,
    [switch]$Finalize,
    [switch]$AuditOnly,
    [string]$UploadOnly
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = 'C:\xampp\php\php.exe'
$ftpsTarget = 'MZTech.Reparatursystem.ProductionFTPS.v1'
$smtpTarget = 'MZTech.Reparatursystem.ProductionSMTP.v1'
$recipientTarget = 'MZTech.Reparatursystem.ActivationTestRecipient.v1'
$uploadFiles = @($UploadOnly -split ',' | Where-Object { $_ })
$appFiles = @(
    'private/mailer.php',
    'private/account_verification.php',
    'private/portal_security.php',
    'private/customer_auth.php',
    'private/companies.php',
    'public/companies_form.php',
    'public/portal_access.php',
    'public/settings.php'
)
$sqlFiles = @{
    preflight = 'sql/activation_email_preflight.sql'
    migration = 'sql/activation_email_migration.sql'
    postcheck = 'sql/activation_email_postcheck.sql'
}
Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

function Get-BytesHash([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Assert-ActivationEmailPrerequisites {
    if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') { throw 'Falscher Projektpfad.' }
    if ($PrepareTest -and $Finalize) { throw 'PrepareTest und Finalize dürfen nicht gleichzeitig verwendet werden.' }
    if ($UploadOnly -and ($PrepareTest -or $Finalize -or $AuditOnly -or $ValidateOnly)) {
        throw 'UploadOnly darf nicht mit einem anderen Modus kombiniert werden.'
    }
    if ($UploadOnly) {
        foreach ($relative in $uploadFiles) {
            if ($relative -notin $appFiles) { throw "Nicht freigegebene Uploaddatei: $relative" }
        }
    }
    foreach ($relative in $appFiles + @($sqlFiles.Values) + @('deployment/tools/activation_email_runner_template.php')) {
        if ($relative -eq 'private/config.php') { throw 'Sicherheitsabbruch: private/config.php.' }
        $path = Join-Path $projectRoot $relative
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Erforderliche Datei fehlt: $relative" }
        if ($relative.EndsWith('.php')) {
            & $php -l $path | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "PHP-Syntaxprüfung fehlgeschlagen: $relative" }
        }
    }
    foreach ($kind in @('preflight','postcheck')) {
        $sql = [IO.File]::ReadAllText((Join-Path $projectRoot $sqlFiles[$kind]))
        $withoutComments = [Text.RegularExpressions.Regex]::Replace($sql, '(?m)^\s*--.*$', '')
        if ($withoutComments -match '\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b') {
            throw "$kind enthält eine nicht-lesende SQL-Anweisung."
        }
    }
}

function Get-ProjectCredential([string]$Target) {
    if (-not (Test-MzTechCredential -Target $Target)) { throw "Credential fehlt: $Target" }
    $stored = Get-MzTechCredential -Target $Target
    try { $metadata = $stored.Metadata | ConvertFrom-Json }
    catch { $stored.Secret.Dispose(); throw "Credential-Metadaten sind ungültig: $Target" }
    return [pscustomobject]@{ Metadata = $metadata; Secret = $stored.Secret }
}

function Get-FtpsCredentialData {
    $credential = Get-ProjectCredential $ftpsTarget
    if (-not $credential.Metadata.explicit_tls -or [int]$credential.Metadata.port -ne 21 -or
        [string]$credential.Metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        $credential.Secret.Dispose(); throw 'FTPS-Credential entspricht nicht dem freigegebenen TLS-Ziel.'
    }
    return $credential
}

function Get-FtpsUri([object]$Credential, [string]$RelativePath) {
    $relative = $RelativePath.Replace('\','/').TrimStart('/')
    $segments = @($relative.Split('/') | Where-Object { $_ } | ForEach-Object { [uri]::EscapeDataString($_) })
    return [uri]("ftp://{0}:{1}/{2}" -f $Credential.Metadata.host, $Credential.Metadata.port, ($segments -join '/'))
}

function New-FtpsRequest([object]$Credential, [string]$RelativePath, [string]$Method) {
    $plain = ConvertFrom-MzTechSecureString -Secret $Credential.Secret
    try {
        $request = [Net.FtpWebRequest]::Create((Get-FtpsUri $Credential $RelativePath))
        $request.Method = $Method
        $request.Credentials = New-Object Net.NetworkCredential([string]$Credential.Metadata.user, $plain)
        $request.EnableSsl = $true; $request.UsePassive = $true; $request.UseBinary = $true
        $request.KeepAlive = $false; $request.Timeout = 45000; $request.ReadWriteTimeout = 45000
        return $request
    } finally { $plain = $null }
}

function Receive-FtpsBytes([object]$Credential, [string]$RelativePath) {
    $request = New-FtpsRequest $Credential $RelativePath ([Net.WebRequestMethods+Ftp]::DownloadFile)
    $response = $request.GetResponse()
    try {
        $stream = $response.GetResponseStream(); $memory = New-Object IO.MemoryStream
        try { $stream.CopyTo($memory); return $memory.ToArray() }
        finally { $memory.Dispose(); $stream.Dispose() }
    } finally { $response.Dispose() }
}

function Send-FtpsBytes([object]$Credential, [string]$RelativePath, [byte[]]$Bytes) {
    $request = New-FtpsRequest $Credential $RelativePath ([Net.WebRequestMethods+Ftp]::UploadFile)
    $request.ContentLength = $Bytes.Length; $stream = $request.GetRequestStream()
    try { $stream.Write($Bytes, 0, $Bytes.Length) } finally { $stream.Dispose() }
    $response = $request.GetResponse(); $response.Dispose()
}

function Remove-FtpsFile([object]$Credential, [string]$RelativePath) {
    $request = New-FtpsRequest $Credential $RelativePath ([Net.WebRequestMethods+Ftp]::DeleteFile)
    try { $response = $request.GetResponse(); $response.Dispose() }
    catch [Net.WebException] {
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode -eq [Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
            $_.Exception.Response.Dispose(); return
        }
        throw
    }
}

function Get-FtpsDirectoryNames([object]$Credential, [string]$RelativePath) {
    $request = New-FtpsRequest $Credential $RelativePath ([Net.WebRequestMethods+Ftp]::ListDirectory)
    $response = $request.GetResponse()
    try {
        $reader = New-Object IO.StreamReader($response.GetResponseStream())
        try { return @($reader.ReadToEnd().Split("`n") | ForEach-Object { $_.Trim() } | Where-Object { $_ }) }
        finally { $reader.Dispose() }
    } finally { $response.Dispose() }
}

function New-Runner {
    $random = New-Object byte[] 32; $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($random); $id = ([BitConverter]::ToString($random)).Replace('-','').ToLowerInvariant(); $rng.GetBytes($random); $token = [Convert]::ToBase64String($random).TrimEnd('=').Replace('+','-').Replace('/','_') }
    finally { [Array]::Clear($random,0,$random.Length); $rng.Dispose() }
    $template = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'activation_email_runner_template.php'))
    $template = $template.Replace('__TOKEN_HASH__', (Get-BytesHash ([Text.Encoding]::UTF8.GetBytes($token))))
    foreach ($kind in @('preflight','migration','postcheck')) {
        $bytes = [IO.File]::ReadAllBytes((Join-Path $projectRoot $sqlFiles[$kind]))
        try {
            $template = $template.Replace('__' + $kind.ToUpperInvariant() + '_B64__', [Convert]::ToBase64String($bytes))
            $template = $template.Replace('__' + $kind.ToUpperInvariant() + '_HASH__', (Get-BytesHash $bytes))
        } finally { [Array]::Clear($bytes,0,$bytes.Length) }
    }
    return [pscustomobject]@{
        Name = "__mzmail_$id.php"
        Token = $token
        Bytes = [Text.Encoding]::UTF8.GetBytes($template)
        Url = "https://mztech-it.de/repair_neu/public/__mzmail_$id.php"
    }
}

function Invoke-Runner([object]$Runner, [string]$Action, [hashtable]$Additional = @{}) {
    $body = @{ deployment_token = $Runner.Token; action = $Action }
    foreach ($key in $Additional.Keys) { $body[$key] = $Additional[$key] }
    try {
        $result = Invoke-RestMethod -Uri $Runner.Url -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -TimeoutSec 60
        if (-not $result.success) { throw "Runner-Schritt fehlgeschlagen: $Action/$($result.category)" }
        return $result
    } catch {
        throw "Runner-Schritt fehlgeschlagen: $Action"
    } finally {
        if ($body.ContainsKey('smtp_password')) { $body['smtp_password'] = $null }
        $body.Clear()
    }
}

function Open-Runner([object]$Credential) {
    $runner = New-Runner
    Send-FtpsBytes $Credential ('public/' + $runner.Name) $runner.Bytes
    $remote = Receive-FtpsBytes $Credential ('public/' + $runner.Name)
    try { if ((Get-BytesHash $remote) -ne (Get-BytesHash $runner.Bytes)) { throw 'Runner-Upload konnte nicht verifiziert werden.' } }
    finally { [Array]::Clear($remote,0,$remote.Length) }
    return $runner
}

function Close-Runner([object]$Credential, [object]$Runner) {
    if ($Runner) {
        Remove-FtpsFile $Credential ('public/' + $Runner.Name)
        $Runner.Token = ''; [Array]::Clear($Runner.Bytes,0,$Runner.Bytes.Length)
    }
}

function Configure-Smtp([object]$Runner, [object]$SmtpCredential) {
    $password = ConvertFrom-MzTechSecureString -Secret $SmtpCredential.Secret
    try { [void](Invoke-Runner $Runner 'configure' @{ metadata = ($SmtpCredential.Metadata | ConvertTo-Json -Compress); smtp_password = $password }) }
    finally { $password = $null }
}

function Upload-Application([object]$Credential, [string[]]$Files = $appFiles) {
    $backupRoot = Join-Path $projectRoot ('backups\production\activation_email_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    $manifest = @(); $uploaded = @()
    try {
        foreach ($relative in $Files) {
            $server = Receive-FtpsBytes $Credential $relative
            $source = [IO.File]::ReadAllBytes((Join-Path $projectRoot $relative))
            $backup = Join-Path $backupRoot $relative
            New-Item -ItemType Directory -Path (Split-Path $backup) -Force | Out-Null
            [IO.File]::WriteAllBytes($backup, $server)
            $originalHash = Get-BytesHash $server; $sourceHash = Get-BytesHash $source
            Send-FtpsBytes $Credential $relative $source
            $verify = Receive-FtpsBytes $Credential $relative
            try { if ((Get-BytesHash $verify) -ne $sourceHash) { throw "Upload-Verifikation fehlgeschlagen: $relative" } }
            finally { [Array]::Clear($verify,0,$verify.Length) }
            $uploaded += [pscustomobject]@{ Relative=$relative; Backup=$backup; OriginalHash=$originalHash }
            $manifest += [pscustomobject]@{ file=$relative; backup=$backup; original_sha256=$originalHash; deployed_sha256=$sourceHash; verified=$true }
            [Array]::Clear($server,0,$server.Length); [Array]::Clear($source,0,$source.Length)
        }
    } catch {
        foreach ($entry in $uploaded) {
            $backupBytes = [IO.File]::ReadAllBytes($entry.Backup)
            try { Send-FtpsBytes $Credential $entry.Relative $backupBytes } finally { [Array]::Clear($backupBytes,0,$backupBytes.Length) }
        }
        throw
    }
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $backupRoot 'deployment_manifest.json') -Encoding UTF8
    return [pscustomobject]@{ BackupRoot=$backupRoot; Entries=$uploaded }
}

function Restore-Application([object]$Credential, [object]$Deployment) {
    foreach ($entry in $Deployment.Entries) {
        $backupBytes = [IO.File]::ReadAllBytes($entry.Backup)
        try {
            Send-FtpsBytes $Credential $entry.Relative $backupBytes
            $verified = Receive-FtpsBytes $Credential $entry.Relative
            try {
                if ((Get-BytesHash $verified) -ne $entry.OriginalHash) { throw "Rollback-Verifikation fehlgeschlagen: $($entry.Relative)" }
            } finally { [Array]::Clear($verified,0,$verified.Length) }
        } finally { [Array]::Clear($backupBytes,0,$backupBytes.Length) }
    }
}

Assert-ActivationEmailPrerequisites
if ($ValidateOnly -or (-not $PrepareTest -and -not $Finalize -and -not $AuditOnly -and -not $UploadOnly)) {
    Write-Output 'ACTIVATION_EMAIL_LOCAL_VALIDATION=OK'
    exit 0
}

$ftps = $null; $smtp = $null; $recipient = $null; $runner = $null
try {
    $ftps = Get-FtpsCredentialData
    if ($UploadOnly) {
        $deployment = Upload-Application -Credential $ftps -Files $uploadFiles
        Write-Output 'ACTIVATION_EMAIL_PARTIAL_UPLOAD=OK'
        Write-Output "BACKUP_ROOT=$($deployment.BackupRoot)"
        return
    }
    $smtp = Get-ProjectCredential $smtpTarget
    $runner = Open-Runner $ftps
    if ($AuditOnly) {
        $status = Invoke-Runner $runner 'status'
        $identical = 0
        foreach ($relative in $appFiles) {
            $remote = Receive-FtpsBytes $ftps $relative
            $local = [IO.File]::ReadAllBytes((Join-Path $projectRoot $relative))
            try { if ((Get-BytesHash $remote) -ne (Get-BytesHash $local)) { throw "Produktivdatei weicht ab: $relative" }; $identical++ }
            finally { [Array]::Clear($remote,0,$remote.Length); [Array]::Clear($local,0,$local.Length) }
        }
        Close-Runner $ftps $runner
        $runner = $null
        $leftovers = @(Get-FtpsDirectoryNames $ftps 'public/' | Where-Object { $_ -like '__mzmail_*.php' })
        if ($leftovers.Count -ne 0) { throw 'Temporäre SMTP-Runnerdateien sind noch vorhanden.' }
        Write-Output "SERVER_FILES_IDENTICAL=$identical"
        Write-Output "MIGRATION_COMPLETE=$($status.migration_complete)"
        Write-Output "SMTP_CONFIGURED=$($status.smtp_configured)"
        Write-Output "DELIVERY_ENABLED=$($status.delivery_enabled)"
        Write-Output "PLAINTEXT_TOKENS=$($status.plaintext_tokens)"
        Write-Output 'TEMP_RUNNERS_REMOVED=YES'
        return
    }
    Configure-Smtp $runner $smtp
    if ($PrepareTest) {
        $recipient = Get-ProjectCredential $recipientTarget
        if (-not $recipient.Metadata.confirmed -or -not [Net.Mail.MailAddress]::new([string]$recipient.Metadata.recipient)) { throw 'TEST-Empfänger ist nicht bestätigt.' }
        [void](Invoke-Runner $runner 'test' @{ test_recipient = [string]$recipient.Metadata.recipient })
        Write-Output 'SMTP_TEST_SENT=YES'
        Write-Output 'PORTAL_EMAIL_DELIVERY_ENABLED=NO'
    } else {
        foreach ($step in @('preflight','migration','postcheck')) { [void](Invoke-Runner $runner $step) }
        $deployment = Upload-Application $ftps
        try {
            [void](Invoke-Runner $runner 'enable' @{ test_confirmed = '1' })
            $health = Invoke-WebRequest -Uri 'https://mztech-it.de/repair_neu/public/portal_access.php' -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 30
            if ($health.StatusCode -ne 200) { throw 'HTTP-Prüfung der Portalzugangsverwaltung fehlgeschlagen.' }
        } catch {
            try { [void](Invoke-Runner $runner 'disable') } catch { }
            Restore-Application $ftps $deployment
            throw
        }
        Write-Output 'ACTIVATION_EMAIL_DEPLOYMENT=OK'
        Write-Output "BACKUP_ROOT=$($deployment.BackupRoot)"
    }
} finally {
    if ($ftps -and $runner) { Close-Runner $ftps $runner }
    foreach ($item in @($recipient,$smtp,$ftps)) { if ($item) { $item.Secret.Dispose() } }
}
