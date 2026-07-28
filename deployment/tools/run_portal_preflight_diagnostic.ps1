[CmdletBinding()]
param([switch]$ValidateOnly)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$checksRoot = Join-Path $projectRoot 'sql\portal_preflight_checks'
$templatePath = Join-Path $PSScriptRoot 'portal_preflight_diagnostic_template.php'
$runtimeRoot = Join-Path $projectRoot 'deployment\runtime_secure\portal_preflight_diagnostic'
$php = 'C:\xampp\php\php.exe'
$credentialTarget = 'MZTech.Reparatursystem.ProductionFTPS.v1'

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

function Get-Hash {
    param([byte[]]$Bytes)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function Get-ChecksPayload {
    $scopes = @{
        1 = 'Datenbankverbindung, aktives Schema und Version'
        2 = 'Erforderliche Basistabellen'
        3 = 'Portal-Zieltabellen'
        4 = 'Erforderliche Basisspalten'
        5 = 'Erforderliche eindeutige Indizes'
        6 = 'Grundspalten bereits vorhandener Portal-Zieltabellen'
        7 = 'Geplante additive Portalspalten'
        8 = 'Storage-Engine und Zeichensatz'
        9 = 'Diagnose-Abschlussmarker'
    }
    $files = @(Get-ChildItem -LiteralPath $checksRoot -File -Filter '*.sql' | Sort-Object Name)
    if ($files.Count -ne 9) {
        throw 'Es werden exakt neun nummerierte Diagnoseprüfungen erwartet.'
    }
    $checks = @()
    foreach ($file in $files) {
        if ($file.BaseName -notmatch '^(\d{3})_') {
            throw "Nicht nummerierte Diagnosedatei: $($file.Name)"
        }
        $number = [int] $Matches[1]
        $sql = [IO.File]::ReadAllText($file.FullName).Trim()
        $withoutTrailing = $sql.TrimEnd(';').Trim()
        if ($withoutTrailing -notmatch '^(?is)(SELECT|SHOW|DESCRIBE)\b' -or
            $withoutTrailing.Contains(';') -or
            $withoutTrailing -match '(?is)\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|CALL|DO|SET|LOAD|HANDLER|GRANT|REVOKE|LOCK|UNLOCK)\b') {
            throw "Nicht rein lesende Diagnosedatei: $($file.Name)"
        }
        $bytes = [Text.Encoding]::UTF8.GetBytes($withoutTrailing)
        $checks += [ordered]@{
            number = $number
            name = $file.Name
            scope = $scopes[$number]
            sql_b64 = [Convert]::ToBase64String($bytes)
            sha256 = Get-Hash $bytes
        }
    }
    $json = $checks | ConvertTo-Json -Depth 5 -Compress
    return [Text.Encoding]::UTF8.GetBytes($json)
}

function Assert-Prerequisites {
    if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') {
        throw 'Falscher Projektpfad.'
    }
    foreach ($path in @($checksRoot, $templatePath, $php)) {
        if (-not (Test-Path -LiteralPath $path)) {
            throw "Erforderlicher Pfad fehlt: $path"
        }
    }
    Get-ChecksPayload | Out-Null
    & $php -l $templatePath | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'PHP-Syntaxprüfung des Diagnose-Runners fehlgeschlagen.'
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
        throw 'FTPS-Credential-Metadaten sind ungültig.'
    }
    if (-not $metadata.explicit_tls -or [int] $metadata.port -ne 21 -or
        [string] $metadata.base_path -ne '/mztech-it.de/repair_neu/') {
        $credential.Secret.Dispose()
        throw 'FTPS-Credential entspricht nicht dem freigegebenen Ziel.'
    }
    return [pscustomobject]@{ Metadata = $metadata; Secret = $credential.Secret }
}

function Get-FtpsUri {
    param([object]$Credential, [string]$Path)
    $segments = @($Path.Replace('\', '/').Split('/') | Where-Object { $_ } | ForEach-Object {
        [uri]::EscapeDataString($_)
    })
    return [uri]("ftp://{0}:{1}/{2}" -f
        $Credential.Metadata.host, $Credential.Metadata.port, ($segments -join '/'))
}

function New-FtpsRequest {
    param([object]$Credential, [string]$Path, [string]$Method)
    $plain = ConvertFrom-MzTechSecureString -Secret $Credential.Secret
    try {
        $request = [Net.FtpWebRequest]::Create(
            (Get-FtpsUri -Credential $Credential -Path $Path)
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

function Send-FtpsFile {
    param([object]$Credential, [string]$Path, [string]$LocalPath)
    $request = New-FtpsRequest -Credential $Credential -Path $Path `
        -Method ([Net.WebRequestMethods+Ftp]::UploadFile)
    $bytes = [IO.File]::ReadAllBytes($LocalPath)
    $request.ContentLength = $bytes.Length
    $stream = $request.GetRequestStream()
    try {
        $stream.Write($bytes, 0, $bytes.Length)
    } finally {
        $stream.Dispose()
    }
    $response = $request.GetResponse()
    $response.Dispose()
}

function Receive-FtpsBytes {
    param([object]$Credential, [string]$Path, [switch]$AllowMissing)
    $request = New-FtpsRequest -Credential $Credential -Path $Path `
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

function Remove-FtpsFile {
    param([object]$Credential, [string]$Path)
    $request = New-FtpsRequest -Credential $Credential -Path $Path `
        -Method ([Net.WebRequestMethods+Ftp]::DeleteFile)
    $response = $request.GetResponse()
    $response.Dispose()
}

function New-DiagnosticRunner {
    $random = New-Object byte[] 24
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($random)
    } finally {
        $rng.Dispose()
    }
    $id = ([BitConverter]::ToString($random)).Replace('-', '').ToLowerInvariant()
    [Array]::Clear($random, 0, $random.Length)
    $runnerName = "__mzdiag_$id.php"
    $statusName = "__mzdiag_$id.json"
    $checksBytes = Get-ChecksPayload
    $template = [IO.File]::ReadAllText($templatePath)
    $template = $template.Replace('__RUNNER_ID__', $id)
    $template = $template.Replace('__STATUS_FILE__', $statusName)
    $template = $template.Replace('__CHECKS_B64__', [Convert]::ToBase64String($checksBytes))
    $template = $template.Replace('__CHECKS_HASH__', (Get-Hash $checksBytes))
    New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
    $localPath = Join-Path $runtimeRoot $runnerName
    [IO.File]::WriteAllText($localPath, $template, (New-Object Text.UTF8Encoding($false)))
    & $php -l $localPath | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Generierter Diagnose-Runner ist syntaktisch ungültig.'
    }
    return [pscustomobject]@{
        Id = $id
        RunnerName = $runnerName
        StatusName = $statusName
        LocalPath = $localPath
    }
}

function Invoke-Diagnostic {
    param([object]$Credential, [object]$Runner)
    $runnerPath = 'public/' + $Runner.RunnerName
    $statusPath = 'private/' + $Runner.StatusName
    Send-FtpsFile -Credential $Credential -Path $runnerPath -LocalPath $Runner.LocalPath
    $remote = Receive-FtpsBytes -Credential $Credential -Path $runnerPath
    $local = [IO.File]::ReadAllBytes($Runner.LocalPath)
    if ((Get-Hash $remote) -ne (Get-Hash $local)) {
        throw 'Hashprüfung des Diagnose-Runners fehlgeschlagen.'
    }

    Add-Type -AssemblyName System.Windows.Forms
    Start-Process 'https://mztech-it.de/repair_neu/public/index.php'
    $choice = [Windows.Forms.MessageBox]::Show(
        'Bitte als interner Administrator anmelden. Danach auf OK klicken. Der Diagnose-Runner bleibt mindestens 20 Minuten verfügbar.',
        'MZ Tech – Portal-Preflight-Diagnose',
        [Windows.Forms.MessageBoxButtons]::OKCancel,
        [Windows.Forms.MessageBoxIcon]::Information
    )
    if ($choice -ne [Windows.Forms.DialogResult]::OK) {
        Start-Process ("https://mztech-it.de/repair_neu/public/{0}" -f $Runner.RunnerName)
        Write-Host 'Der Diagnose-Runner bleibt für einen späteren ausdrücklichen Abbruch verfügbar.'
        return $null
    }
    Start-Process ("https://mztech-it.de/repair_neu/public/{0}" -f $Runner.RunnerName)
    Write-Host 'Der rein lesende Diagnose-Runner ist geöffnet. Kein Token muss kopiert werden.'

    $deadline = (Get-Date).AddMinutes(30)
    $terminal = @('diagnostic_complete', 'failed', 'aborted', 'expired')
    $status = $null
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 4
        $bytes = Receive-FtpsBytes -Credential $Credential -Path $statusPath -AllowMissing
        if ($null -eq $bytes) {
            continue
        }
        try {
            $candidate = [Text.Encoding]::UTF8.GetString($bytes) | ConvertFrom-Json
        } catch {
            throw 'Bereinigter Diagnosestatus ist ungültig.'
        }
        if ($candidate.runner_id -ne $Runner.Id) {
            throw 'Diagnosestatus gehört nicht zum aktuellen Runner.'
        }
        if ([string] $candidate.state -in $terminal) {
            $status = $candidate
            break
        }
    }
    if ($null -eq $status) {
        Write-Host 'Wartezeit beendet. Der Runner wurde nicht automatisch entfernt.' -ForegroundColor Yellow
        return $null
    }

    $resultDirectory = Join-Path $runtimeRoot 'results'
    New-Item -ItemType Directory -Path $resultDirectory -Force | Out-Null
    $resultPath = Join-Path $resultDirectory (
        'portal_preflight_diagnostic_{0}.json' -f (Get-Date -Format 'yyyyMMdd_HHmmss')
    )
    $status | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $resultPath -Encoding UTF8

    Remove-FtpsFile -Credential $Credential -Path $runnerPath
    Remove-FtpsFile -Credential $Credential -Path $statusPath
    if ($null -ne (Receive-FtpsBytes -Credential $Credential -Path $runnerPath -AllowMissing) -or
        $null -ne (Receive-FtpsBytes -Credential $Credential -Path $statusPath -AllowMissing)) {
        throw 'Entfernung des ausgewerteten Diagnose-Runners nicht bestätigt.'
    }
    Write-Host "Bereinigtes Diagnoseergebnis: $resultPath"
    return $status
}

Assert-Prerequisites
if ($ValidateOnly) {
    Write-Host 'Diagnose-Runner lokal geprüft: neun Einzelabfragen, ausschließlich lesend.'
    exit 0
}

$credential = $null
$runner = $null
try {
    $credential = Get-FtpsCredentialData
    $runner = New-DiagnosticRunner
    $status = Invoke-Diagnostic -Credential $credential -Runner $runner
    if ($null -eq $status) {
        exit 3
    }
    $summary = $status.summary
    Write-Host ('DATABASE_CONNECTION=' + $summary.database_connection)
    Write-Host ('DATABASE_TYPE_VERSION_RECOGNIZED=' + $summary.database_type_version_recognized)
    Write-Host ('DATABASE_TYPE=' + $summary.database_type)
    Write-Host ('DATABASE_VERSION=' + $summary.database_version)
    Write-Host ('FAILED_CHECK=' + $summary.failed_check)
    Write-Host ('SQLSTATE=' + $summary.sqlstate)
    Write-Host ('ERROR_CATEGORY=' + $summary.error_category)
    Write-Host ('AFFECTED_OBJECT=' + $summary.affected_object)
    Write-Host ('PREFLIGHT_CANDIDATE=' + $summary.preflight_candidate)
    if ($status.state -ne 'diagnostic_complete') {
        exit 2
    }
} finally {
    if ($runner -and (Test-Path -LiteralPath $runner.LocalPath)) {
        Remove-Item -LiteralPath $runner.LocalPath -Force
    }
    if ($credential) {
        $credential.Secret.Dispose()
    }
}
