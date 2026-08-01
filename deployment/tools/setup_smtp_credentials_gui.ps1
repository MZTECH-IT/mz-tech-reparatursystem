[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

$credentialTarget = 'MZTech.Reparatursystem.ProductionSMTP.v1'
$form = New-Object Windows.Forms.Form
$form.Text = 'MZ Tech – SMTP sicher speichern'
$form.StartPosition = 'CenterScreen'
$form.Size = New-Object Drawing.Size(650, 510)
$form.MinimumSize = $form.Size
$form.MaximumSize = $form.Size
$form.TopMost = $true
$form.Font = New-Object Drawing.Font('Segoe UI', 9)
$script:saved = $false
$script:fields = [ordered]@{}

$heading = New-Object Windows.Forms.Label
$heading.Location = New-Object Drawing.Point(20, 15)
$heading.Size = New-Object Drawing.Size(595, 48)
$heading.Text = "Authentifiziertes SMTP mit TLS. Das Passwort wird ausschließlich`r`nim Windows Credential Manager gespeichert und niemals angezeigt."
$form.Controls.Add($heading)

function Add-Field {
    param([string]$Key, [string]$Label, [int]$Top, [string]$Default = '', [switch]$Password)
    $caption = New-Object Windows.Forms.Label
    $caption.Location = New-Object Drawing.Point(20, $Top)
    $caption.Size = New-Object Drawing.Size(190, 24)
    $caption.Text = $Label
    $form.Controls.Add($caption)

    $input = New-Object Windows.Forms.TextBox
    $input.Location = New-Object Drawing.Point(220, ($Top - 3))
    $input.Size = New-Object Drawing.Size(395, 24)
    $input.Text = $Default
    if ($Password) { $input.UseSystemPasswordChar = $true }
    $form.Controls.Add($input)
    $script:fields[$Key] = $input
}

Add-Field 'host' 'SMTP-Server' 80
Add-Field 'port' 'SMTP-Port' 115 '587'

$encryptionLabel = New-Object Windows.Forms.Label
$encryptionLabel.Location = New-Object Drawing.Point(20, 150)
$encryptionLabel.Size = New-Object Drawing.Size(190, 24)
$encryptionLabel.Text = 'Verschlüsselung'
$form.Controls.Add($encryptionLabel)
$encryption = New-Object Windows.Forms.ComboBox
$encryption.Location = New-Object Drawing.Point(220, 147)
$encryption.Size = New-Object Drawing.Size(395, 24)
$encryption.DropDownStyle = [Windows.Forms.ComboBoxStyle]::DropDownList
[void]$encryption.Items.Add('STARTTLS')
[void]$encryption.Items.Add('SMTPS')
$encryption.SelectedIndex = 0
$form.Controls.Add($encryption)

Add-Field 'username' 'SMTP-Benutzername' 185
Add-Field 'password' 'SMTP-Passwort' 220 '' -Password
Add-Field 'from_email' 'Absender-E-Mail-Adresse' 255
Add-Field 'from_name' 'Absendername' 290 'MZ Tech'

$targetLabel = New-Object Windows.Forms.Label
$targetLabel.Location = New-Object Drawing.Point(20, 330)
$targetLabel.Size = New-Object Drawing.Size(595, 44)
$targetLabel.Text = "Credential: $credentialTarget`r`nSpeicherung: Windows Credential Manager, aktueller Benutzer"
$form.Controls.Add($targetLabel)

$saveButton = New-Object Windows.Forms.Button
$saveButton.Location = New-Object Drawing.Point(355, 400)
$saveButton.Size = New-Object Drawing.Size(125, 32)
$saveButton.Text = 'Sicher speichern'
$form.Controls.Add($saveButton)

$cancelButton = New-Object Windows.Forms.Button
$cancelButton.Location = New-Object Drawing.Point(490, 400)
$cancelButton.Size = New-Object Drawing.Size(125, 32)
$cancelButton.Text = 'Abbrechen'
$form.Controls.Add($cancelButton)
$form.CancelButton = $cancelButton

$saveButton.Add_Click({
    if ($script:saved) { $form.Close(); return }
    $secret = $null
    try {
        foreach ($required in @('host','port','username','password','from_email','from_name')) {
            if ([string]::IsNullOrWhiteSpace($fields[$required].Text)) { throw "Pflichtfeld fehlt: $required" }
        }
        if ($fields.port.Text -notmatch '^\d{1,5}$' -or [int]$fields.port.Text -lt 1 -or [int]$fields.port.Text -gt 65535) {
            throw 'Der SMTP-Port ist ungültig.'
        }
        if (-not [Net.Mail.MailAddress]::new($fields.from_email.Text.Trim())) { throw 'Die Absenderadresse ist ungültig.' }
        $mode = if ($encryption.SelectedItem -eq 'SMTPS') { 'smtps' } else { 'starttls' }
        if (($mode -eq 'smtps' -and [int]$fields.port.Text -ne 465) -or
            ($mode -eq 'starttls' -and [int]$fields.port.Text -eq 465)) {
            throw 'Port und Verschlüsselungsart passen nicht zusammen (STARTTLS üblicherweise 587, SMTPS 465).'
        }
        $metadata = @{
            host = $fields.host.Text.Trim()
            port = [int]$fields.port.Text
            encryption = $mode
            username = $fields.username.Text.Trim()
            from_email = $fields.from_email.Text.Trim()
            from_name = $fields.from_name.Text.Trim()
            smtp_auth = $true
            certificate_validation = $true
            scope = 'repair_system_activation_mail'
        } | ConvertTo-Json -Compress
        $secret = ConvertTo-SecureString $fields.password.Text -AsPlainText -Force
        Set-MzTechCredential -Target $credentialTarget -Metadata $metadata -Secret $secret
        $fields.password.Clear()
        foreach ($input in $fields.Values) { $input.Enabled = $false }
        $encryption.Enabled = $false
        $script:saved = $true
        $heading.Text = 'SMTP-Zugangsdaten erfolgreich gespeichert.'
        $heading.ForeColor = [Drawing.Color]::DarkGreen
        $saveButton.Text = 'Schließen'
        $cancelButton.Visible = $false
    } catch {
        $fields.password.Clear()
        [Windows.Forms.MessageBox]::Show($_.Exception.Message, 'Speicherung fehlgeschlagen',
            [Windows.Forms.MessageBoxButtons]::OK, [Windows.Forms.MessageBoxIcon]::Error) | Out-Null
    } finally {
        if ($secret) { $secret.Dispose() }
    }
})

$cancelButton.Add_Click({ $fields.password.Clear(); $form.Close() })
$form.Add_FormClosed({ $fields.password.Clear() })
[void]$form.ShowDialog()
$fields.password.Clear()
$form.Dispose()
