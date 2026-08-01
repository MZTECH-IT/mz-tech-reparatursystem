[CmdletBinding()]
param(
    [switch]$ValidateOnly,
    [switch]$Deploy,
    [switch]$AuditOnly
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = 'C:\xampp\php\php.exe'
$credentialTarget = 'MZTech.Reparatursystem.ProductionFTPS.v1'
$files = @(
    'private/pdf_common.php',
    'public/pdf/angebot.php',
    'public/pdf/rechnung.php',
    'public/pdf/kostenvoranschlag.php',
    'public/portal.php',
    'public/portal_business.php'
)

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

function Get-BytesHash([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Assert-Prerequisites {
    if ($root -ne 'M:\MZ_Tech_Reparatursystem') { throw 'Falscher Projektpfad.' }
    if ($Deploy -and $AuditOnly) { throw 'Deploy und AuditOnly dürfen nicht kombiniert werden.' }
    if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw 'PHP-CLI fehlt: C:\xampp\php\php.exe' }
    foreach ($relative in $files) {
        if ($relative -eq 'private/config.php') { throw 'Sicherheitsabbruch: private/config.php.' }
        $source = Join-Path $root $relative
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "Quelldatei fehlt: $relative" }
        & $php -l $source | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "PHP-Syntaxprüfung fehlgeschlagen: $relative" }
    }
}

function Get-FtpsCredentialData {
    if (-not (Test-MzTechCredential -Target $credentialTarget)) { throw 'Reparatursystem-FTPS-Credential fehlt.' }
    $stored = Get-MzTechCredential -Target $credentialTarget
    try { $metadata = $stored.Metadata | ConvertFrom-Json }
    catch { $stored.Secret.Dispose(); throw 'FTPS-Credential-Metadaten sind ungültig.' }
    if (-not $metadata.explicit_tls -or [int]$metadata.port -ne 21 -or
        [string]$metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        $stored.Secret.Dispose()
        throw 'FTPS-Credential entspricht nicht dem freigegebenen TLS-Ziel.'
    }
    return [pscustomobject]@{ Metadata=$metadata; Secret=$stored.Secret }
}

function Get-FtpsUri([object]$Credential, [string]$RelativePath) {
    $segments = @($RelativePath.Replace('\','/').TrimStart('/').Split('/') |
        Where-Object { $_ } | ForEach-Object { [uri]::EscapeDataString($_) })
    return [uri]("ftp://{0}:{1}/{2}" -f $Credential.Metadata.host, $Credential.Metadata.port, ($segments -join '/'))
}

function New-FtpsRequest([object]$Credential, [string]$RelativePath, [string]$Method) {
    $plain = ConvertFrom-MzTechSecureString -Secret $Credential.Secret
    try {
        $request = [Net.FtpWebRequest]::Create((Get-FtpsUri $Credential $RelativePath))
        $request.Method = $Method
        $request.Credentials = New-Object Net.NetworkCredential([string]$Credential.Metadata.user, $plain)
        $request.EnableSsl = $true
        $request.UsePassive = $true
        $request.UseBinary = $true
        $request.KeepAlive = $false
        $request.Timeout = 45000
        $request.ReadWriteTimeout = 45000
        return $request
    } finally { $plain = $null }
}

function Receive-FtpsBytes([object]$Credential, [string]$RelativePath) {
    $request = New-FtpsRequest $Credential $RelativePath ([Net.WebRequestMethods+Ftp]::DownloadFile)
    $response = $request.GetResponse()
    try {
        $stream = $response.GetResponseStream()
        $memory = New-Object IO.MemoryStream
        try { $stream.CopyTo($memory); return $memory.ToArray() }
        finally { $memory.Dispose(); $stream.Dispose() }
    } finally { $response.Dispose() }
}

function Send-FtpsBytes([object]$Credential, [string]$RelativePath, [byte[]]$Bytes) {
    $request = New-FtpsRequest $Credential $RelativePath ([Net.WebRequestMethods+Ftp]::UploadFile)
    $request.ContentLength = $Bytes.Length
    $stream = $request.GetRequestStream()
    try { $stream.Write($Bytes, 0, $Bytes.Length) } finally { $stream.Dispose() }
    $response = $request.GetResponse()
    $response.Dispose()
}

Assert-Prerequisites
if ($ValidateOnly -or (-not $Deploy -and -not $AuditOnly)) {
    Write-Output 'PORTAL_DOCUMENT_LOCAL_VALIDATION=OK'
    exit 0
}

$credential = $null
try {
    $credential = Get-FtpsCredentialData
    if ($AuditOnly) {
        $identical = 0
        foreach ($relative in $files) {
            $remote = Receive-FtpsBytes $credential $relative
            $auditFile = Join-Path ([IO.Path]::GetTempPath()) ('mztech_portal_audit_' + [guid]::NewGuid().ToString('N'))
            try {
                [IO.File]::WriteAllBytes($auditFile, $remote)
                $remoteBlobHash = (& git hash-object ('--path=' + $relative) -- $auditFile).Trim()
                $headBlobHash = (& git rev-parse ('HEAD:' + $relative)).Trim()
                if ($LASTEXITCODE -ne 0 -or $remoteBlobHash -ne $headBlobHash) {
                    throw "Produktivdatei weicht inhaltlich ab: $relative"
                }
                $identical++
            } finally {
                [Array]::Clear($remote,0,$remote.Length)
                if (Test-Path -LiteralPath $auditFile) { Remove-Item -LiteralPath $auditFile -Force }
            }
        }
        Write-Output "PORTAL_DOCUMENT_SERVER_FILES_IDENTICAL=$identical"
        exit 0
    }

    $backupRoot = Join-Path $root ('backups\production\portal_document_access_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    $inventory = @()
    $knownDeployedHashes = @{}
    Get-ChildItem -LiteralPath (Join-Path $root 'backups\production') -Directory -Filter 'portal_document_access_*' -ErrorAction SilentlyContinue |
        ForEach-Object {
            $priorManifest = Join-Path $_.FullName 'deployment_manifest.json'
            if (-not (Test-Path -LiteralPath $priorManifest -PathType Leaf)) { return }
            $priorEntries = Get-Content -LiteralPath $priorManifest -Raw | ConvertFrom-Json
            foreach ($prior in $priorEntries) {
                $key = [string]$prior.file
                if (-not $knownDeployedHashes.ContainsKey($key)) { $knownDeployedHashes[$key] = @() }
                $knownDeployedHashes[$key] += [string]$prior.deployed_sha256
            }
        }

    # Zuerst alle Serverdateien sichern und gegen den bekannten HEAD-Stand
    # prüfen. Vor Abschluss dieser Phase wird keine Produktivdatei verändert.
    foreach ($relative in $files) {
        $remote = Receive-FtpsBytes $credential $relative
        $backup = Join-Path $backupRoot $relative
        New-Item -ItemType Directory -Path (Split-Path $backup) -Force | Out-Null
        [IO.File]::WriteAllBytes($backup, $remote)
        $remoteHash = Get-BytesHash $remote
        $local = [IO.File]::ReadAllBytes((Join-Path $root $relative))
        $localHash = Get-BytesHash $local
        # Produktivdateien können durch den Windows-Upload CRLF besitzen,
        # obwohl der Git-Blob LF verwendet. Mit --path werden exakt dieselben
        # Clean-/EOL-Regeln wie für die jeweilige Projektdatei angewendet.
        $remoteBlobHash = (& git hash-object ('--path=' + $relative) -- $backup).Trim()
        $headBlobHash = (& git rev-parse ('HEAD:' + $relative)).Trim()
        $knownPriorDeployment = $knownDeployedHashes.ContainsKey($relative) -and
            $knownDeployedHashes[$relative] -contains $remoteHash
        if ($LASTEXITCODE -ne 0 -or ($remoteBlobHash -ne $headBlobHash -and -not $knownPriorDeployment)) {
            throw "Produktivdatei besitzt unbekannte Fremdänderungen: $relative"
        }
        if ($remoteHash -ne $localHash) {
            $inventory += [pscustomobject]@{ Relative=$relative; Backup=$backup; OriginalHash=$remoteHash }
        }
        [Array]::Clear($remote,0,$remote.Length)
        [Array]::Clear($local,0,$local.Length)
    }

    $manifest = @()
    foreach ($entry in $inventory) {
        $relative = $entry.Relative
        $source = [IO.File]::ReadAllBytes((Join-Path $root $relative))
        $sourceHash = Get-BytesHash $source
        try {
            try {
                Send-FtpsBytes $credential $relative $source
                $verify = Receive-FtpsBytes $credential $relative
                try {
                    if ((Get-BytesHash $verify) -ne $sourceHash) { throw "Upload-Verifikation fehlgeschlagen: $relative" }
                } finally { [Array]::Clear($verify,0,$verify.Length) }
            } catch {
                $original = [IO.File]::ReadAllBytes($entry.Backup)
                try {
                    Send-FtpsBytes $credential $relative $original
                    $rollback = Receive-FtpsBytes $credential $relative
                    try {
                        if ((Get-BytesHash $rollback) -ne $entry.OriginalHash) { throw "Rollback-Verifikation fehlgeschlagen: $relative" }
                    } finally { [Array]::Clear($rollback,0,$rollback.Length) }
                } finally { [Array]::Clear($original,0,$original.Length) }
                throw
            }
            $manifest += [pscustomobject]@{
                file=$relative
                backup=$entry.Backup
                original_sha256=$entry.OriginalHash
                deployed_sha256=$sourceHash
                verified=$true
            }
        } finally { [Array]::Clear($source,0,$source.Length) }
    }
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $backupRoot 'deployment_manifest.json') -Encoding UTF8
    Write-Output 'PORTAL_DOCUMENT_DEPLOYMENT=OK'
    Write-Output "PORTAL_DOCUMENT_DEPLOYED_FILES=$($manifest.Count)"
    Write-Output "BACKUP_ROOT=$backupRoot"
} finally {
    if ($credential) { $credential.Secret.Dispose() }
}
