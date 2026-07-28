[CmdletBinding()]
param(
    [switch]$ValidateOnly,
    [ValidateSet('Numbering', 'BusinessActivation', 'BusinessProjectDisplay')]
    [string]$Scope = 'Numbering'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = 'C:\xampp\php\php.exe'
$credentialTarget = 'MZTech.Reparatursystem.ProductionFTPS.v1'
$relativeFiles = if ($Scope -eq 'BusinessActivation') {
    @('private/companies.php', 'public/companies_form.php')
} elseif ($Scope -eq 'BusinessProjectDisplay') {
    @('public/portal_business.php')
} else {
    @('private/tickets.php', 'private/companies.php')
}

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
    foreach ($relative in $relativeFiles) {
        if ($relative -eq 'private/config.php' -or
            $relative -notin @('private/tickets.php', 'private/companies.php', 'public/companies_form.php', 'public/portal_business.php')) {
            throw "Unzulässiger Hotfixpfad: $relative"
        }
        $localPath = Join-Path $projectRoot $relative
        if (-not (Test-Path -LiteralPath $localPath -PathType Leaf)) {
            throw "Lokale Datei fehlt: $relative"
        }
        & $php -l $localPath | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "PHP-Syntaxprüfung fehlgeschlagen: $relative"
        }
    }
}

function Get-FtpsCredentialData {
    if (-not (Test-MzTechCredential -Target $credentialTarget)) {
        throw 'Das projektspezifische FTPS-Credential fehlt.'
    }
    $credential = Get-MzTechCredential -Target $credentialTarget
    try {
        $metadata = $credential.Metadata | ConvertFrom-Json
    } catch {
        $credential.Secret.Dispose()
        throw 'Die FTPS-Credential-Metadaten sind ungültig.'
    }
    if (-not $metadata.explicit_tls -or [int]$metadata.port -ne 21 -or
        [string]$metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        $credential.Secret.Dispose()
        throw 'FTPS-Credential entspricht nicht dem freigegebenen TLS-Ziel.'
    }
    $metadata | Add-Member -NotePropertyName effective_base_path -NotePropertyValue ''
    return [pscustomobject]@{ Metadata = $metadata; Secret = $credential.Secret }
}

function Get-FtpsUri {
    param([object]$Credential, [string]$RelativePath)
    $relative = $RelativePath.Replace('\', '/').TrimStart('/')
    $segments = @($relative.Split('/') | Where-Object { $_ } | ForEach-Object {
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
    Write-Host 'Hotfix-Prüfung erfolgreich; keine Credentials gelesen und keine Verbindung hergestellt.' -ForegroundColor Green
    exit 0
}

$credential = $null
$backupRoot = Join-Path $projectRoot (
    'backups\production\portal_hotfix_' + $Scope.ToLowerInvariant() + '_' + (Get-Date -Format 'yyyyMMdd_HHmmss')
)
$manifest = @()

try {
    $credential = Get-FtpsCredentialData
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

    foreach ($relative in $relativeFiles) {
        $serverBytes = Receive-FtpsBytes -Credential $credential -RelativePath $relative
        $backupPath = Join-Path $backupRoot $relative
        New-Item -ItemType Directory -Path (Split-Path $backupPath) -Force | Out-Null
        [IO.File]::WriteAllBytes($backupPath, $serverBytes)

        $localPath = Join-Path $projectRoot $relative
        $localBytes = [IO.File]::ReadAllBytes($localPath)
        $originalHash = Get-ByteHash -Bytes $serverBytes
        $localHash = Get-ByteHash -Bytes $localBytes

        try {
            Send-FtpsBytes -Credential $credential -RelativePath $relative -Bytes $localBytes
            $verifiedBytes = Receive-FtpsBytes -Credential $credential -RelativePath $relative
            $verifiedHash = Get-ByteHash -Bytes $verifiedBytes
            if ($verifiedHash -ne $localHash) {
                throw "Upload-Verifikation fehlgeschlagen: $relative"
            }
        } catch {
            Send-FtpsBytes -Credential $credential -RelativePath $relative -Bytes $serverBytes
            $rollbackBytes = Receive-FtpsBytes -Credential $credential -RelativePath $relative
            if ((Get-ByteHash -Bytes $rollbackBytes) -ne $originalHash) {
                throw "Upload und automatischer Rollback fehlgeschlagen: $relative"
            }
            throw
        }

        $manifest += [pscustomobject]@{
            file = $relative
            backup = $backupPath
            original_sha256 = $originalHash
            deployed_sha256 = $localHash
            verified = $true
        }
        Write-Host "Gesichert, hochgeladen und verifiziert: $relative" -ForegroundColor Green
    }

    $manifest | ConvertTo-Json -Depth 4 |
        Set-Content -LiteralPath (Join-Path $backupRoot 'deployment_manifest.json') -Encoding UTF8
    Write-Host "HOTFIX_BACKUP_ROOT=$backupRoot"
} finally {
    if ($credential) {
        $credential.Secret.Dispose()
    }
}
