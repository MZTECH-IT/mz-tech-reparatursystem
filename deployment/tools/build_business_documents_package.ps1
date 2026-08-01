[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
$projectRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if($projectRoot -ne 'M:\MZ_Tech_Reparatursystem'){throw 'Falscher Projektpfad.'}
$target=Join-Path $projectRoot 'deployment\business_documents_ready'
$files=@(
 'private\billing.php','private\invoicing.php','private\quotes.php','private\payments.php','private\invoice_corrections.php','private\pdf_common.php','private\permissions.php','private\numbering.php',
 'public\init.php','public\settings.php','public\parts.php','public\quotes_form.php','public\payments.php','public\repairs_view.php','public\portal.php','public\portal_business.php','public\api\repairs.php','public\assets\css\style.css',
 'public\includes\header.php','public\pdf\angebot.php','public\pdf\rechnung.php','public\pdf\kostenvoranschlag.php','public\pdf\gutschrift.php',
 'sql\business_documents_preflight.sql','sql\business_documents_migration.sql','sql\business_documents_postcheck.sql','sql\business_documents_rollback.sql'
)
foreach($relative in $files){
 if($relative -ieq 'private\config.php'){throw 'Sicherheitsabbruch: private/config.php darf nicht paketiert werden.'}
 $source=Join-Path $projectRoot $relative;if(-not(Test-Path -LiteralPath $source)){throw "Quelldatei fehlt: $relative"}
 $destination=Join-Path $target $relative;$parent=Split-Path $destination
 if(-not(Test-Path -LiteralPath $parent)){New-Item -ItemType Directory -Path $parent -Force|Out-Null}
 Copy-Item -LiteralPath $source -Destination $destination -Force
}
Write-Output ('PACKAGE_FILES='+$files.Count)
Write-Output ('PACKAGE_PATH='+$target)
