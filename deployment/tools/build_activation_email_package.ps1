[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ($root -ne 'M:\MZ_Tech_Reparatursystem') { throw 'Falscher Projektpfad.' }
$target = Join-Path $root 'deployment\activation_email_ready'
$files = @(
    'private/mailer.php','private/account_verification.php','private/portal_security.php',
    'private/customer_auth.php','private/companies.php','public/companies_form.php','public/portal_access.php','public/settings.php',
    'sql/activation_email_preflight.sql','sql/activation_email_migration.sql',
    'sql/activation_email_postcheck.sql','sql/activation_email_rollback.sql'
)
foreach ($relative in $files) {
    $source = Join-Path $root $relative; $destination = Join-Path $target $relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "Quelldatei fehlt: $relative" }
    New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination -Force
}
$files -join "`n" | Set-Content -LiteralPath (Join-Path $target 'CHANGED_FILES.txt') -Encoding UTF8
Write-Output "ACTIVATION_EMAIL_PACKAGE_FILES=$(@(Get-ChildItem -LiteralPath $target -Recurse -File).Count)"
Write-Output "ACTIVATION_EMAIL_PACKAGE_PATH=$target"
