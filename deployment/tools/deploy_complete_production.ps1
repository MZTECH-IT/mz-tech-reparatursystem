[CmdletBinding()]
param(
    [switch]$ValidateOnly,
    [switch]$PortalOnly,
    [switch]$PreflightOnly
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$packageRoot = if ($PortalOnly) {
    Join-Path $projectRoot 'deployment\portal_ticket_ready'
} else {
    Join-Path $projectRoot 'deployment\complete_production_ready'
}
$runtimeRoot = Join-Path $projectRoot 'deployment\runtime_secure\complete_deployment'
$php = 'C:\xampp\php\php.exe'
$credentialTarget = 'MZTech.Reparatursystem.ProductionFTPS.v1'

Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

function Assert-DeploymentPrerequisites {
    if ($projectRoot -ne 'M:\MZ_Tech_Reparatursystem') {
        throw 'Falscher Projektpfad.'
    }
    foreach ($path in @(
        $packageRoot,
        $php,
        (Join-Path $PSScriptRoot 'server_runner_template.php'),
        (Join-Path $projectRoot 'sql\portal_ticket_preflight.sql'),
        (Join-Path $projectRoot 'sql\portal_ticket_migration.sql'),
        (Join-Path $projectRoot 'sql\portal_ticket_postcheck.sql'),
        (Join-Path $projectRoot 'sql\foneday_preflight.sql'),
        (Join-Path $projectRoot 'sql\foneday_migration.sql'),
        (Join-Path $projectRoot 'sql\foneday_postcheck.sql')
    )) {
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

function Send-FtpsFile {
    param([object]$Credential, [string]$RelativePath, [string]$LocalPath)
    $request = New-FtpsRequest -Credential $Credential -RelativePath $RelativePath `
        -Method ([Net.WebRequestMethods+Ftp]::UploadFile)
    $bytes = [IO.File]::ReadAllBytes($LocalPath)
    $request.ContentLength = $bytes.Length
    $stream = $request.GetRequestStream()
    try {
        $stream.Write($bytes, 0, $bytes.Length)
    } finally {
        $stream.Dispose()
        [Array]::Clear($bytes, 0, $bytes.Length)
    }
    $response = $request.GetResponse()
    $response.Dispose()
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

    $template = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'server_runner_template.php'))
    $template = $template.Replace('__RUNNER_ID__', $runnerId)
    $template = $template.Replace('__DEPLOYMENT_SCOPE__', $(if ($PreflightOnly) {
        'preflight'
    } elseif ($PortalOnly) {
        'portal'
    } else {
        'complete'
    }))
    $template = $template.Replace('__TOKEN_HASH__', (
        Get-ByteHash -Bytes ([Text.Encoding]::UTF8.GetBytes($token))
    ))
    $template = $template.Replace('__STATUS_FILE__', $statusName)
    $sqlMap = @{
        PORTAL_PREFLIGHT = 'portal_ticket_preflight.sql'
        PORTAL_MIGRATION = 'portal_ticket_migration.sql'
        PORTAL_POSTCHECK = 'portal_ticket_postcheck.sql'
        FONEDAY_PREFLIGHT = 'foneday_preflight.sql'
        FONEDAY_MIGRATION = 'foneday_migration.sql'
        FONEDAY_POSTCHECK = 'foneday_postcheck.sql'
    }
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
    Set-Clipboard -Value $Runner.Token
    $url = "https://mztech-it.de/repair_neu/public/$($Runner.RunnerName)"
    Start-Process $url
    Write-Host 'Der geschützte Runner wurde im Browser geöffnet; das einmalige Token liegt in der Zwischenablage.'
    Write-Host ($(if ($PreflightOnly) {
        'Es wird ausschließlich der lesende Portal-/Ticket-Preflight bestätigt. Geheimnisse werden nicht ausgegeben.'
    } elseif ($PortalOnly) {
        'Die drei Portal-/Ticket-Schritte müssen dort einzeln bestätigt werden. Geheimnisse werden nicht ausgegeben.'
    } else {
        'Die sechs Schritte müssen dort einzeln bestätigt werden. Geheimnisse werden nicht ausgegeben.'
    }))

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
    $backupRoot = Join-Path $projectRoot "backups\production\complete_$timestamp"
    $manifest = @()

    foreach ($file in $files) {
        $relative = $file.FullName.Substring($prefix.Length)
        if ($relative -ieq 'private\config.php') {
            throw 'Sicherheitsabbruch: private/config.php in Uploadliste.'
        }
        $serverBytes = Receive-FtpsBytes -Credential $Credential -RelativePath $relative -AllowMissing
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

    foreach ($file in $files) {
        $relative = $file.FullName.Substring($prefix.Length)
        Send-FtpsFile -Credential $Credential -RelativePath $relative -LocalPath $file.FullName
        $verified = Receive-FtpsBytes -Credential $Credential -RelativePath $relative
        $local = [IO.File]::ReadAllBytes($file.FullName)
        if ((Get-ByteHash $verified) -ne (Get-ByteHash $local)) {
            throw "Upload-Verifikation fehlgeschlagen: $relative"
        }
        ($manifest | Where-Object file -eq $relative.Replace('\', '/')).verified = $true
        Write-Host "Verifiziert: $relative"
    }
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    $manifest | ConvertTo-Json -Depth 5 |
        Set-Content -LiteralPath (Join-Path $backupRoot 'deployment_manifest.json') -Encoding UTF8
    return [pscustomobject]@{ BackupRoot = $backupRoot; Manifest = $manifest }
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
    $runner = New-ServerRunner
    Invoke-ServerSqlSequence -Credential $credential -Runner $runner
    if ($PreflightOnly) {
        Write-Host 'Der ausschließlich lesende Produktiv-Preflight wurde abgeschlossen.' -ForegroundColor Green
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
