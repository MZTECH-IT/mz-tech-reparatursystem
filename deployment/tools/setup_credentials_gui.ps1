[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

$targets = @{
    Database = 'MZTech.Reparatursystem.ProductionDB.v1'
    Ftps = 'MZTech.Reparatursystem.ProductionFTPS.v1'
    Foneday = 'MZTech.Reparatursystem.FONEDAY_API_TOKEN.v1'
}

$form = New-Object Windows.Forms.Form
$form.Text = 'MZ Tech – sichere Zugangsdaten-Einrichtung'
$form.StartPosition = 'CenterScreen'
$form.Size = New-Object Drawing.Size(690, 700)
$form.MinimumSize = $form.Size
$form.MaximumSize = $form.Size
$form.TopMost = $true
$form.Font = New-Object Drawing.Font('Segoe UI', 9)

$title = New-Object Windows.Forms.Label
$title.Location = New-Object Drawing.Point(20, 15)
$title.Size = New-Object Drawing.Size(640, 45)
$title.Text = "Einmalige, benutzergebundene Speicherung im Windows Credential Manager.`r`nGeheimnisse werden nicht angezeigt oder in Dateien geschrieben."
$form.Controls.Add($title)

$fields = [ordered]@{}
function Add-Field {
    param(
        [string]$Key,
        [string]$Label,
        [int]$Top,
        [string]$Default = '',
        [switch]$Password
    )
    $caption = New-Object Windows.Forms.Label
    $caption.Location = New-Object Drawing.Point(20, $Top)
    $caption.Size = New-Object Drawing.Size(250, 24)
    $caption.Text = $Label
    $form.Controls.Add($caption)

    $input = New-Object Windows.Forms.TextBox
    $input.Location = New-Object Drawing.Point(280, ($Top - 3))
    $input.Size = New-Object Drawing.Size(370, 24)
    $input.Text = $Default
    if ($Password) {
        $input.UseSystemPasswordChar = $true
    }
    $form.Controls.Add($input)
    $script:fields[$Key] = $input
}

$dbHeading = New-Object Windows.Forms.Label
$dbHeading.Location = New-Object Drawing.Point(20, 70)
$dbHeading.Size = New-Object Drawing.Size(640, 25)
$dbHeading.Font = New-Object Drawing.Font('Segoe UI', 10, [Drawing.FontStyle]::Bold)
$dbHeading.Text = 'Produktivdatenbank'
$form.Controls.Add($dbHeading)

Add-Field 'db_host' 'Server' 105
Add-Field 'db_port' 'Port' 140 '3306'
Add-Field 'db_name' 'Datenbankname' 175
Add-Field 'db_user' 'Benutzer' 210
Add-Field 'db_ca' 'TLS-CA-Datei (optional)' 245
Add-Field 'db_password' 'Passwort' 280 '' -Password

$ftpsHeading = New-Object Windows.Forms.Label
$ftpsHeading.Location = New-Object Drawing.Point(20, 320)
$ftpsHeading.Size = New-Object Drawing.Size(640, 25)
$ftpsHeading.Font = New-Object Drawing.Font('Segoe UI', 10, [Drawing.FontStyle]::Bold)
$ftpsHeading.Text = 'Explizites FTPS'
$form.Controls.Add($ftpsHeading)

Add-Field 'ftps_host' 'Server' 355 'w021c224.kasserver.com'
Add-Field 'ftps_port' 'Port' 390 '21'
Add-Field 'ftps_user' 'Benutzer' 425 'f01890e0'
Add-Field 'ftps_path' 'Zielverzeichnis' 460 '/mztech-it.de/repair_neu/'
Add-Field 'ftps_password' 'Passwort' 495 '' -Password

$fonedayCheckbox = New-Object Windows.Forms.CheckBox
$fonedayCheckbox.Location = New-Object Drawing.Point(20, 535)
$fonedayCheckbox.Size = New-Object Drawing.Size(250, 24)
$fonedayCheckbox.Text = 'FONEDAY_API_TOKEN speichern'
$form.Controls.Add($fonedayCheckbox)
Add-Field 'foneday_token' 'FONEDAY_API_TOKEN' 570 '' -Password
$fields.foneday_token.Enabled = $false
$fonedayCheckbox.Add_CheckedChanged({
    $fields.foneday_token.Enabled = $fonedayCheckbox.Checked
})

$saveButton = New-Object Windows.Forms.Button
$saveButton.Location = New-Object Drawing.Point(390, 615)
$saveButton.Size = New-Object Drawing.Size(125, 32)
$saveButton.Text = 'Sicher speichern'
$form.Controls.Add($saveButton)

$cancelButton = New-Object Windows.Forms.Button
$cancelButton.Location = New-Object Drawing.Point(525, 615)
$cancelButton.Size = New-Object Drawing.Size(125, 32)
$cancelButton.Text = 'Abbrechen'
$cancelButton.DialogResult = [Windows.Forms.DialogResult]::Cancel
$form.Controls.Add($cancelButton)
$form.CancelButton = $cancelButton

$saveButton.Add_Click({
    try {
        foreach ($required in @('db_host', 'db_port', 'db_name', 'db_user', 'db_password',
            'ftps_host', 'ftps_port', 'ftps_user', 'ftps_path', 'ftps_password')) {
            if ([string]::IsNullOrWhiteSpace($fields[$required].Text)) {
                throw "Pflichtfeld fehlt: $required"
            }
        }
        if ($fields.db_port.Text -notmatch '^\d{1,5}$' -or
            [int]$fields.db_port.Text -lt 1 -or [int]$fields.db_port.Text -gt 65535) {
            throw 'Der Datenbankport ist ungültig.'
        }
        if ($fields.ftps_port.Text -ne '21') {
            throw 'Für dieses Projekt ist ausschließlich FTPS-Port 21 erlaubt.'
        }
        if ($fields.ftps_path.Text -ne '/mztech-it.de/repair_neu/') {
            throw 'Das FTPS-Zielverzeichnis muss exakt /mztech-it.de/repair_neu/ lauten.'
        }
        if ($fields.db_ca.Text -and -not (Test-Path -LiteralPath $fields.db_ca.Text -PathType Leaf)) {
            throw 'Die angegebene TLS-CA-Datei wurde nicht gefunden.'
        }
        if ($fonedayCheckbox.Checked -and [string]::IsNullOrWhiteSpace($fields.foneday_token.Text)) {
            throw 'FONEDAY_API_TOKEN wurde aktiviert, ist aber leer.'
        }

        $dbMetadata = @{
            host = $fields.db_host.Text.Trim()
            port = [int]$fields.db_port.Text
            database = $fields.db_name.Text.Trim()
            user = $fields.db_user.Text.Trim()
            ssl_ca = $fields.db_ca.Text.Trim()
        } | ConvertTo-Json -Compress
        $dbSecret = ConvertTo-SecureString $fields.db_password.Text -AsPlainText -Force
        Set-MzTechCredential -Target $targets.Database -Metadata $dbMetadata -Secret $dbSecret

        $ftpsMetadata = @{
            host = $fields.ftps_host.Text.Trim()
            port = [int]$fields.ftps_port.Text
            user = $fields.ftps_user.Text.Trim()
            base_path = $fields.ftps_path.Text.Trim()
            explicit_tls = $true
        } | ConvertTo-Json -Compress
        $ftpsSecret = ConvertTo-SecureString $fields.ftps_password.Text -AsPlainText -Force
        Set-MzTechCredential -Target $targets.Ftps -Metadata $ftpsMetadata -Secret $ftpsSecret

        if ($fonedayCheckbox.Checked) {
            $fonedaySecret = ConvertTo-SecureString $fields.foneday_token.Text -AsPlainText -Force
            Set-MzTechCredential -Target $targets.Foneday -Metadata '{"service":"foneday"}' -Secret $fonedaySecret
        }

        foreach ($passwordField in @('db_password', 'ftps_password', 'foneday_token')) {
            $fields[$passwordField].Clear()
        }
        $dbSecret.Dispose()
        $ftpsSecret.Dispose()
        if ($fonedaySecret) {
            $fonedaySecret.Dispose()
        }
        [Windows.Forms.MessageBox]::Show(
            'Die projektspezifischen Zugangsdaten wurden sicher gespeichert.',
            'MZ Tech',
            [Windows.Forms.MessageBoxButtons]::OK,
            [Windows.Forms.MessageBoxIcon]::Information
        ) | Out-Null
        $form.DialogResult = [Windows.Forms.DialogResult]::OK
        $form.Close()
    }
    catch {
        [Windows.Forms.MessageBox]::Show(
            $_.Exception.Message,
            'Einrichtung fehlgeschlagen',
            [Windows.Forms.MessageBoxButtons]::OK,
            [Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    }
})

$result = $form.ShowDialog()
foreach ($passwordField in @('db_password', 'ftps_password', 'foneday_token')) {
    $fields[$passwordField].Clear()
}
$form.Dispose()

if ($result -ne [Windows.Forms.DialogResult]::OK) {
    exit 1
}
exit 0
