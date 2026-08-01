[CmdletBinding()]
param(
    [ValidateSet('inspect','create-company-test','send','send-company','rate-limit','verify-activated')]
    [string]$Action = 'inspect'
)

$ErrorActionPreference = 'Stop'
$target = 'MZTech.Reparatursystem.ActivationTestRecipient.v1'
$module = Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1'
$runner = Join-Path $PSScriptRoot 'cdp_activation_email_e2e.mjs'

Import-Module $module -Force
if (-not (Test-MzTechCredential -Target $target)) {
    throw 'Die bestaetigte TEST-Empfaengeradresse ist nicht gespeichert.'
}

$stored = Get-MzTechCredential -Target $target
try {
    $metadata = $stored.Metadata | ConvertFrom-Json
    if (-not $metadata.confirmed -or -not [Net.Mail.MailAddress]::new([string]$metadata.recipient)) {
        throw 'Die gespeicherte TEST-Empfaengeradresse ist nicht bestaetigt.'
    }
    $env:MZTECH_TEST_RECIPIENT = [string]$metadata.recipient
    node $runner $Action
    if ($LASTEXITCODE -ne 0) { throw 'Der Browser-Test ist fehlgeschlagen.' }
} finally {
    Remove-Item Env:MZTECH_TEST_RECIPIENT -ErrorAction SilentlyContinue
    if ($stored -and $stored.Secret) { $stored.Secret.Dispose() }
}
