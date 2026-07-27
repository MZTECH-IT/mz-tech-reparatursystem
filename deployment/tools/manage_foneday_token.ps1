[CmdletBinding()]
param(
    [ValidateSet('replace', 'delete')]
    [string]$Action = 'replace'
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'MzTechFonedayDpapi.psm1') -Force

if ($Action -eq 'replace') {
    Start-Process -FilePath 'powershell.exe' -ArgumentList @(
        '-NoProfile',
        '-STA',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        (Join-Path $PSScriptRoot 'setup_foneday_token_gui.ps1')
    ) -WindowStyle Normal
    exit 0
}

$confirmation = (Read-Host 'Foneday-Token wirklich löschen? [j/N]').Trim()
if ($confirmation -match '^(j|ja|y|yes)$') {
    Remove-MzTechFonedayToken | Out-Null
}
