[CmdletBinding()]
param([switch]$ValidateOnly)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$localPath = Join-Path $projectRoot 'deployment\runtime_secure\legacy_repair\.htaccess'
$remotePath = 'repair/public/.htaccess'
$target = 'MZTech.MainWebsite.ProductionFTPS.v1'

if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') { throw 'Falscher Projektpfad.' }
if (-not (Test-Path -LiteralPath $localPath -PathType Leaf)) { throw 'Lokale Redirect-Datei fehlt.' }
$content = [IO.File]::ReadAllText($localPath)
if ($content -notmatch '(?m)^RewriteRule \^\(\.\*\)\$ /repair_neu/public/\$1 \[R=302,L,NE\]$') {
    throw 'Die geprüfte Redirect-Regel fehlt.'
}
if ($ValidateOnly) {
    Write-Host 'Legacy-Redirect lokal validiert; keine Credentials und kein Netzwerk verwendet.'
    exit 0
}

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force
if (-not (Test-MzTechCredential -Target $target)) { throw 'Hauptwebsite-FTPS-Credential fehlt.' }
$stored = Get-MzTechCredential -Target $target
$password = $null

function Get-Hash([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

try {
    $metadata = $stored.Metadata | ConvertFrom-Json
    if (-not $metadata.explicit_tls -or [int]$metadata.port -ne 21) {
        throw 'FTPS-Credential verwendet nicht explizites TLS auf Port 21.'
    }
    $password = ConvertFrom-MzTechSecureString -Secret $stored.Secret

    function New-Request([string]$Method) {
        $escaped = (($remotePath.Split('/')) | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
        $request = [Net.FtpWebRequest]::Create(
            [uri]("ftp://{0}:{1}/{2}" -f $metadata.host, $metadata.port, $escaped)
        )
        $request.Method = $Method
        $request.Credentials = New-Object Net.NetworkCredential([string]$metadata.user, $password)
        $request.EnableSsl = $true
        $request.UsePassive = $true
        $request.UseBinary = $true
        $request.KeepAlive = $false
        $request.Timeout = 45000
        $request.ReadWriteTimeout = 45000
        return $request
    }

    function Receive-Bytes {
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            try {
                $request = New-Request ([Net.WebRequestMethods+Ftp]::DownloadFile)
                $response = $request.GetResponse()
                try {
                    $memory = New-Object IO.MemoryStream
                    $stream = $response.GetResponseStream()
                    try { $stream.CopyTo($memory); return $memory.ToArray() }
                    finally { $stream.Dispose(); $memory.Dispose() }
                } finally { $response.Dispose() }
            } catch {
                if ($attempt -ge 3) { throw }
                Start-Sleep -Milliseconds (1000 * $attempt)
            }
        }
    }

    $current = Receive-Bytes
    $replacement = [IO.File]::ReadAllBytes($localPath)
    try {
        $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
        $backupRoot = Join-Path $projectRoot "backups\production\legacy_repair_redirect_$timestamp"
        New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
        $backupPath = Join-Path $backupRoot '.htaccess.original'
        [IO.File]::WriteAllBytes($backupPath, $current)
        $manifest = [pscustomobject]@{
            file = $remotePath
            original_sha256 = Get-Hash $current
            replacement_sha256 = Get-Hash $replacement
            verified = $false
        }
        $manifestPath = Join-Path $backupRoot 'redirect_manifest.json'
        $manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8

        $completed = $false
        for ($attempt = 1; $attempt -le 3 -and -not $completed; $attempt++) {
            try {
                $request = New-Request ([Net.WebRequestMethods+Ftp]::UploadFile)
                $request.ContentLength = $replacement.Length
                $stream = $request.GetRequestStream()
                try { $stream.Write($replacement, 0, $replacement.Length) } finally { $stream.Dispose() }
                $response = $request.GetResponse()
                $response.Dispose()
            } catch {
                $uploadError = $_
                try {
                    $check = Receive-Bytes
                    try { if ((Get-Hash $check) -eq (Get-Hash $replacement)) { $completed = $true } }
                    finally { [Array]::Clear($check, 0, $check.Length) }
                } catch {}
                if (-not $completed -and $attempt -ge 3) { throw $uploadError }
                if (-not $completed) { Start-Sleep -Milliseconds (1000 * $attempt) }
                continue
            }
            $completed = $true
        }
        $verified = Receive-Bytes
        try {
            if ((Get-Hash $verified) -ne (Get-Hash $replacement)) { throw 'Upload-Hashprüfung fehlgeschlagen.' }
        } finally { [Array]::Clear($verified, 0, $verified.Length) }
        $manifest.verified = $true
        $manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8
        Write-Host "LEGACY_REDIRECT_VERIFIED Backup=$backupRoot"
    } finally {
        [Array]::Clear($current, 0, $current.Length)
        [Array]::Clear($replacement, 0, $replacement.Length)
    }
} finally {
    $password = $null
    if ($stored) { $stored.Secret.Dispose() }
}
