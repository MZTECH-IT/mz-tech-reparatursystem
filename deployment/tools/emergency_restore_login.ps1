[CmdletBinding()]
param([switch]$ValidateOnly)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$sourceRoot = Join-Path $projectRoot 'backups\production\repair_device_work_20260731_151825'
$credentialTarget = 'MZTech.Reparatursystem.ProductionFTPS.v1'
$targets = @(
    'private\business_auth.php',
    'private\companies.php',
    'private\customer_auth.php',
    'private\functions.php',
    'private\invoicing.php',
    'private\mailer.php',
    'public\includes\header.php',
    'public\init.php',
    'public\pdf\abholschein.php',
    'public\pdf\auftrag.php',
    'public\pdf\kostenvoranschlag.php',
    'public\pdf\rechnung.php'
)

if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') { throw 'Falscher Projektpfad.' }
foreach ($relative in $targets) {
    $source = Join-Path $sourceRoot $relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Wiederherstellungsdatei fehlt: $relative"
    }
    if ([IO.Path]::GetExtension($source) -eq '.php') {
        & 'C:\xampp\php\php.exe' -l $source | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "PHP-Syntaxfehler in Sicherung: $relative" }
    }
}
if ($ValidateOnly) {
    Write-Host 'Notfall-Wiederherstellung lokal validiert; keine Credentials und kein Netzwerk verwendet.'
    exit 0
}

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force
if (-not (Test-MzTechCredential -Target $credentialTarget)) { throw 'FTPS-Credential fehlt.' }
$stored = Get-MzTechCredential -Target $credentialTarget

function Get-Hash([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

try {
    $metadata = $stored.Metadata | ConvertFrom-Json
    if (-not $metadata.explicit_tls -or [int]$metadata.port -ne 21 -or
        [string]$metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        throw 'FTPS-Credential entspricht nicht dem freigegebenen TLS-Ziel.'
    }
    $password = ConvertFrom-MzTechSecureString -Secret $stored.Secret

    function New-Request([string]$Relative, [string]$Method) {
        $escaped = (($Relative.Replace('\', '/').TrimStart('/').Split('/')) |
            ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
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

    function Receive-Bytes([string]$Relative) {
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            try {
                $request = New-Request $Relative ([Net.WebRequestMethods+Ftp]::DownloadFile)
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

    function Send-File([string]$Relative, [string]$LocalPath) {
        $expected = [IO.File]::ReadAllBytes($LocalPath)
        try {
            for ($attempt = 1; $attempt -le 3; $attempt++) {
                try {
                    $request = New-Request $Relative ([Net.WebRequestMethods+Ftp]::UploadFile)
                    $request.ContentLength = $expected.Length
                    $stream = $request.GetRequestStream()
                    try { $stream.Write($expected, 0, $expected.Length) } finally { $stream.Dispose() }
                    $response = $request.GetResponse()
                    $response.Dispose()
                } catch {
                    $uploadError = $_
                    try {
                        $check = Receive-Bytes $Relative
                        try { if ((Get-Hash $check) -eq (Get-Hash $expected)) { return } }
                        finally { [Array]::Clear($check, 0, $check.Length) }
                    } catch {}
                    if ($attempt -ge 3) { throw $uploadError }
                    Start-Sleep -Milliseconds (1000 * $attempt)
                    continue
                }
                return
            }
        } finally { [Array]::Clear($expected, 0, $expected.Length) }
    }

    $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
    $backupRoot = Join-Path $projectRoot "backups\production\emergency_login_$timestamp"
    $manifest = @()
    foreach ($relative in $targets) {
        $current = Receive-Bytes $relative
        try {
            $backupPath = Join-Path $backupRoot $relative
            New-Item -ItemType Directory -Path (Split-Path $backupPath) -Force | Out-Null
            [IO.File]::WriteAllBytes($backupPath, $current)
            $restoreBytes = [IO.File]::ReadAllBytes((Join-Path $sourceRoot $relative))
            try {
                $manifest += [pscustomobject]@{
                    file = $relative.Replace('\', '/')
                    current_sha256 = Get-Hash $current
                    restore_sha256 = Get-Hash $restoreBytes
                    verified = $false
                }
            } finally { [Array]::Clear($restoreBytes, 0, $restoreBytes.Length) }
        } finally { [Array]::Clear($current, 0, $current.Length) }
    }
    $manifestPath = Join-Path $backupRoot 'emergency_restore_manifest.json'
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $manifestPath -Encoding UTF8

    foreach ($relative in $targets) {
        $source = Join-Path $sourceRoot $relative
        Send-File $relative $source
        $verified = Receive-Bytes $relative
        $expected = [IO.File]::ReadAllBytes($source)
        try {
            if ((Get-Hash $verified) -ne (Get-Hash $expected)) {
                throw "Hashprüfung fehlgeschlagen: $relative"
            }
        } finally {
            [Array]::Clear($verified, 0, $verified.Length)
            [Array]::Clear($expected, 0, $expected.Length)
        }
        ($manifest | Where-Object file -eq $relative.Replace('\', '/')).verified = $true
        $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
        Write-Host "Wiederhergestellt und verifiziert: $relative"
    }
    Write-Host "NOTFALL_WIEDERHERSTELLUNG_ERFOLGREICH Backup=$backupRoot"
} finally {
    $password = $null
    if ($stored) { $stored.Secret.Dispose() }
}
