[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') {
    throw 'Falscher Projektpfad.'
}

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force
$target = 'MZTech.Reparatursystem.ProductionFTPS.v1'
if (-not (Test-MzTechCredential -Target $target)) {
    throw 'FTPS-Credential fehlt.'
}
$stored = Get-MzTechCredential -Target $target
try {
    $metadata = $stored.Metadata | ConvertFrom-Json
    if (-not $metadata.explicit_tls -or [int]$metadata.port -ne 21 -or
        [string]$metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        throw 'FTPS-Credential entspricht nicht dem freigegebenen TLS-Ziel.'
    }
    $password = ConvertFrom-MzTechSecureString -Secret $stored.Secret
    $candidates = @('logs/error.log')
    $result = @()
    foreach ($relative in $candidates) {
        $uri = [uri]("ftp://{0}:{1}/{2}" -f $metadata.host, $metadata.port, $relative)
        $request = [Net.FtpWebRequest]::Create($uri)
        $request.Method = [Net.WebRequestMethods+Ftp]::DownloadFile
        $request.Credentials = New-Object Net.NetworkCredential([string]$metadata.user, $password)
        $request.EnableSsl = $true
        $request.UsePassive = $true
        $request.UseBinary = $true
        $request.KeepAlive = $false
        $request.Timeout = 20000
        try {
            $response = $request.GetResponse()
            try {
                $reader = New-Object IO.StreamReader($response.GetResponseStream())
                try { $content = $reader.ReadToEnd() } finally { $reader.Dispose() }
            } finally { $response.Dispose() }

            $matches = [regex]::Matches($content, '(?im)^.*(?:PHP Fatal error|Uncaught (?:Error|TypeError|PDOException)|Parse error).*$')
            $safe = [ordered]@{ Log = $relative; Found = $true; ErrorFound = ($matches.Count -gt 0) }
            if ($matches.Count -gt 0) {
                $line = $matches[$matches.Count - 1].Value
                $safe.Kind = if ($line -match 'PDOException|SQLSTATE') { 'Datenbankfehler' }
                    elseif ($line -match 'TypeError') { 'TypeError' }
                    elseif ($line -match 'Parse error') { 'ParseError' }
                    else { 'PHP-Fatal-Error' }
                $safe.Timestamp = if ($line -match '^\[([^\]]+)\]') { $Matches[1] } else { $null }
                $safe.Reason = if ($line -match '(?i)Cannot redeclare') { 'Doppelte-Funktionsdefinition' }
                    elseif ($line -match '(?i)Call to undefined function') { 'Fehlende-Funktion' }
                    elseif ($line -match '(?i)(?:require|include).*Failed opening') { 'Include-Fehler' }
                    elseif ($line -match '(?i)Class .* not found') { 'Fehlende-Klasse' }
                    elseif ($line -match '(?i)Unknown column') { 'Fehlende-Spalte' }
                    elseif ($line -match '(?i)Table .* doesn.t exist') { 'Fehlende-Tabelle' }
                    else { 'Sonstiger-Fatal-Error' }
                $safe.Symbol = if ($line -match '(?i)(?:Cannot redeclare|undefined function)\s+([a-z_][a-z0-9_]*)') {
                    $Matches[1]
                } else { $null }
                $safe.File = if ($line -match '(?i)(?:in|thrown in)\s+[^\r\n]*?[\\/]([^\\/:\r\n]+\.php)') { $Matches[1] } else { $null }
                $safe.Line = if ($line -match '(?i)(?:on line|:)\s*(\d+)\s*$') { [int]$Matches[1] } else { $null }
                $safe.SqlState = if ($line -match 'SQLSTATE\[([A-Z0-9]+)\]') { $Matches[1] } else { $null }
            }
            $result += [pscustomobject]$safe
        } catch [Net.WebException] {
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode -eq
                [Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
                $_.Exception.Response.Dispose()
                continue
            }
            throw
        }
    }
    if ($result.Count -eq 0) {
        [pscustomobject]@{ Log = $null; Found = $false; ErrorFound = $false } | ConvertTo-Json
    } else {
        $result | ConvertTo-Json -Depth 3
    }
} finally {
    $password = $null
    if ($stored) { $stored.Secret.Dispose() }
}
