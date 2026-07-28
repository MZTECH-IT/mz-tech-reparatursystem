[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$saved = $false
$decryptable = $false
$notEmpty = $false
$rawToken = $null
$secureToken = $null
$verificationToken = $null
$stage = 'start'

try {
    $stage = 'browser'
    Add-Type -AssemblyName UIAutomationClient
    Add-Type -AssemblyName System.Windows.Forms
    [Windows.Forms.Clipboard]::Clear()

    $chrome = Get-Process chrome -ErrorAction Stop |
        Where-Object { $_.MainWindowHandle -ne 0 } |
        Select-Object -First 1
    if (-not $chrome) {
        throw 'Browserfenster fehlt.'
    }

    $root = [Windows.Automation.AutomationElement]::FromHandle(
        $chrome.MainWindowHandle
    )
    $nameCondition = New-Object Windows.Automation.PropertyCondition(
        [Windows.Automation.AutomationElement]::NameProperty,
        [string][char]0xF0C5
    )
    $typeCondition = New-Object Windows.Automation.PropertyCondition(
        [Windows.Automation.AutomationElement]::ControlTypeProperty,
        [Windows.Automation.ControlType]::Button
    )
    $copyCondition = New-Object Windows.Automation.AndCondition(
        $nameCondition,
        $typeCondition
    )
    $copyButton = $root.FindFirst(
        [Windows.Automation.TreeScope]::Descendants,
        $copyCondition
    )
    if (-not $copyButton -or $copyButton.Current.IsOffscreen) {
        throw 'Kopierschaltfläche fehlt.'
    }

    $stage = 'copy'
    $invoke = $copyButton.GetCurrentPattern(
        [Windows.Automation.InvokePattern]::Pattern
    )
    $invoke.Invoke()
    Start-Sleep -Milliseconds 600

    $stage = 'clipboard'
    $rawToken = [Windows.Forms.Clipboard]::GetText().Trim()
    $rawToken = [regex]::Replace($rawToken, '^(?i:Bearer)\s+', '')
    if ([string]::IsNullOrWhiteSpace($rawToken) -or
        $rawToken.Split('.').Count -ne 3) {
        throw 'Ungültiger Zwischenablageinhalt.'
    }

    $secureToken = New-Object Security.SecureString
    foreach ($character in $rawToken.ToCharArray()) {
        $secureToken.AppendChar($character)
    }
    $secureToken.MakeReadOnly()
    $rawToken = $null
    [Windows.Forms.Clipboard]::Clear()

    $stage = 'dpapi'
    Import-Module (
        Join-Path $PSScriptRoot 'MzTechFonedayDpapi.psm1'
    ) -Force
    Set-MzTechFonedayToken -Token $secureToken
    $stage = 'verify'
    $saved = Test-MzTechFonedayToken
    $verificationToken = Get-MzTechFonedayToken
    $decryptable = $null -ne $verificationToken
    $notEmpty = $decryptable -and $verificationToken.Length -gt 0
}
catch {
    Write-Output ('FONEDAY_TOKEN_SPEICHERUNG_FEHLGESCHLAGEN_PHASE=' + $stage)
    Write-Output ('FONEDAY_TOKEN_FEHLERTYP=' + $_.Exception.GetType().Name)
    Write-Output ('FONEDAY_TOKEN_FEHLERCODE=' + $_.Exception.HResult)
}
finally {
    Add-Type -AssemblyName System.Windows.Forms
    [Windows.Forms.Clipboard]::Clear()
    $rawToken = $null
    if ($secureToken) {
        $secureToken.Dispose()
    }
    if ($verificationToken) {
        $verificationToken.Dispose()
    }
}

Write-Output (
    'SICHERER_CREDENTIAL_EINTRAG_VORHANDEN=' +
    $(if ($saved) { 'JA' } else { 'NEIN' })
)
Write-Output (
    'ENTSCHLUESSELUNG_LOKAL_MOEGLICH=' +
    $(if ($decryptable) { 'JA' } else { 'NEIN' })
)
Write-Output (
    'TOKEN_INTERN_NICHT_LEER=' +
    $(if ($notEmpty) { 'JA' } else { 'NEIN' })
)

if (-not ($saved -and $decryptable -and $notEmpty)) {
    exit 1
}
