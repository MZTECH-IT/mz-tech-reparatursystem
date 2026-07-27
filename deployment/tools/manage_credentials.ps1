[CmdletBinding()]
param(
    [ValidateSet('status', 'change', 'delete')]
    [string]$Action = 'status',
    [ValidateSet('all', 'database', 'ftps', 'foneday')]
    [string]$Credential = 'all'
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

$targets = [ordered]@{
    database = 'MZTech.Reparatursystem.ProductionDB.v1'
    ftps = 'MZTech.Reparatursystem.ProductionFTPS.v1'
    foneday = 'MZTech.Reparatursystem.FONEDAY_API_TOKEN.v1'
}

if ($Action -eq 'change') {
    & (Join-Path $PSScriptRoot 'setup_credentials.ps1')
    exit $LASTEXITCODE
}

$selected = if ($Credential -eq 'all') { @($targets.Keys) } else { @($Credential) }
foreach ($name in $selected) {
    $target = $targets[$name]
    if ($Action -eq 'delete') {
        $answer = (Read-Host "Credential '$target' wirklich löschen? [j/N]").Trim()
        if ($answer -match '^(j|ja|y|yes)$') {
            $removed = Remove-MzTechCredential -Target $target
            Write-Host "$target : $(if ($removed) { 'GELÖSCHT' } else { 'NICHT VORHANDEN' })"
        } else {
            Write-Host "$target : ÜBERSPRUNGEN"
        }
    } else {
        $present = Test-MzTechCredential -Target $target
        Write-Host "$target : $(if ($present) { 'VORHANDEN' } else { 'FEHLT' })"
    }
}
