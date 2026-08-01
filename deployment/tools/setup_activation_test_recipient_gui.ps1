[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Import-Module (Join-Path $PSScriptRoot 'MzTechCredentialManager.psm1') -Force

$target = 'MZTech.Reparatursystem.ActivationTestRecipient.v1'
$form = New-Object Windows.Forms.Form
$form.Text = 'MZ Tech – TEST-Empfänger bestätigen'
$form.StartPosition = 'CenterScreen'
$form.Size = New-Object Drawing.Size(610, 300)
$form.MinimumSize = $form.Size
$form.MaximumSize = $form.Size
$form.TopMost = $true
$form.Font = New-Object Drawing.Font('Segoe UI', 9)

$heading = New-Object Windows.Forms.Label
$heading.Location = New-Object Drawing.Point(20, 18)
$heading.Size = New-Object Drawing.Size(550, 48)
$heading.Text = "An diese Adresse wird genau eine gekennzeichnete SMTP-Testmail gesendet.`r`nEs werden noch keine Aktivierungslinks an echte Kunden versendet."
$form.Controls.Add($heading)

$label = New-Object Windows.Forms.Label
$label.Location = New-Object Drawing.Point(20, 85)
$label.Size = New-Object Drawing.Size(170, 24)
$label.Text = 'TEST-E-Mail-Adresse'
$form.Controls.Add($label)
$email = New-Object Windows.Forms.TextBox
$email.Location = New-Object Drawing.Point(195, 82)
$email.Size = New-Object Drawing.Size(375, 24)
$form.Controls.Add($email)

$confirm = New-Object Windows.Forms.CheckBox
$confirm.Location = New-Object Drawing.Point(20, 125)
$confirm.Size = New-Object Drawing.Size(550, 48)
$confirm.Text = 'Ich bestätige, dass ich diese Adresse kontrolliere und genau eine SMTP-Testmail empfangen darf.'
$form.Controls.Add($confirm)

$save = New-Object Windows.Forms.Button
$save.Location = New-Object Drawing.Point(310, 195)
$save.Size = New-Object Drawing.Size(125, 32)
$save.Text = 'Sicher bestätigen'
$form.Controls.Add($save)
$cancel = New-Object Windows.Forms.Button
$cancel.Location = New-Object Drawing.Point(445, 195)
$cancel.Size = New-Object Drawing.Size(125, 32)
$cancel.Text = 'Abbrechen'
$form.Controls.Add($cancel)
$form.CancelButton = $cancel
$script:saved = $false

$save.Add_Click({
    if ($script:saved) { $form.Close(); return }
    $secret = $null
    try {
        $address = $email.Text.Trim()
        [void][Net.Mail.MailAddress]::new($address)
        if (-not $confirm.Checked) { throw 'Bitte bestätigen Sie die ausdrückliche Testversand-Freigabe.' }
        $metadata = @{ recipient = $address; confirmed = $true; scope = 'single_activation_smtp_test' } | ConvertTo-Json -Compress
        $secret = ConvertTo-SecureString 'confirmed-test-only' -AsPlainText -Force
        Set-MzTechCredential -Target $target -Metadata $metadata -Secret $secret
        $email.Clear(); $email.Enabled = $false; $confirm.Enabled = $false
        $heading.Text = 'TEST-Empfängeradresse sicher bestätigt.'
        $heading.ForeColor = [Drawing.Color]::DarkGreen
        $save.Text = 'Schließen'; $cancel.Visible = $false; $script:saved = $true
    } catch {
        [Windows.Forms.MessageBox]::Show($_.Exception.Message, 'Bestätigung fehlgeschlagen',
            [Windows.Forms.MessageBoxButtons]::OK, [Windows.Forms.MessageBoxIcon]::Error) | Out-Null
    } finally { if ($secret) { $secret.Dispose() } }
})
$cancel.Add_Click({ $email.Clear(); $form.Close() })
$form.Add_FormClosed({ $email.Clear() })
[void]$form.ShowDialog()
$email.Clear(); $form.Dispose()
