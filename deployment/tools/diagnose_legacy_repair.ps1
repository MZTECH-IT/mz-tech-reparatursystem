[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') { throw 'Falscher Projektpfad.' }

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force
$target = 'MZTech.MainWebsite.ProductionFTPS.v1'
if (-not (Test-MzTechCredential -Target $target)) { throw 'Hauptwebsite-FTPS-Credential fehlt.' }
$stored = Get-MzTechCredential -Target $target
$password = $null
try {
    $metadata = $stored.Metadata | ConvertFrom-Json
    if (-not $metadata.explicit_tls -or [int]$metadata.port -ne 21) {
        throw 'FTPS-Credential verwendet nicht explizites TLS auf Port 21.'
    }
    $password = ConvertFrom-MzTechSecureString -Secret $stored.Secret
    $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
    $outputRoot = Join-Path $projectRoot "backups\production\legacy_repair_diagnose_$timestamp"
    $files = @('repair/public/.htaccess')
    $summary = @()
    foreach ($relative in $files) {
        if ($relative -match '(?i)(^|/)config\.php$') { throw 'Sicherheitsabbruch: config.php.' }
        $escaped = (($relative.Split('/')) | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
        $request = [Net.FtpWebRequest]::Create(
            [uri]("ftp://{0}:{1}/{2}" -f $metadata.host, $metadata.port, $escaped)
        )
        $request.Method = [Net.WebRequestMethods+Ftp]::DownloadFile
        $request.Credentials = New-Object Net.NetworkCredential([string]$metadata.user, $password)
        $request.EnableSsl = $true
        $request.UsePassive = $true
        $request.UseBinary = $true
        $request.KeepAlive = $false
        $request.Timeout = 30000
        try {
            $response = $request.GetResponse()
            try {
                $memory = New-Object IO.MemoryStream
                $stream = $response.GetResponseStream()
                try { $stream.CopyTo($memory); $bytes = $memory.ToArray() }
                finally { $stream.Dispose(); $memory.Dispose() }
            } finally { $response.Dispose() }
        } catch [Net.WebException] {
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode -eq
                [Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
                $_.Exception.Response.Dispose()
                $summary += [pscustomobject]@{ File = $relative; Found = $false; Sha256 = $null }
                continue
            }
            throw
        }
        $destination = Join-Path $outputRoot ($relative -replace '/', '\')
        New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
        [IO.File]::WriteAllBytes($destination, $bytes)
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $hash = ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
        finally { $sha.Dispose() }
        $summary += [pscustomobject]@{ File = $relative; Found = $true; Sha256 = $hash }
        [Array]::Clear($bytes, 0, $bytes.Length)
    }
    [pscustomobject]@{ OutputRoot = $outputRoot; Files = $summary } | ConvertTo-Json -Depth 4
} finally {
    $password = $null
    if ($stored) { $stored.Secret.Dispose() }
}
