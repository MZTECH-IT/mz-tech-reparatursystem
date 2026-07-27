[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Import-Module (Join-Path $PSScriptRoot 'MzTechFonedayDpapi.psm1') -Force

$form = New-Object Windows.Forms.Form
$form.Text = 'MZ Tech – Foneday-Token'
$form.StartPosition = 'CenterScreen'
$form.Size = New-Object Drawing.Size(650, 260)
$form.MinimumSize = $form.Size
$form.MaximumSize = $form.Size
$form.TopMost = $true
$form.Font = New-Object Drawing.Font('Segoe UI', 9)
$script:tokenSaved = $false

$message = New-Object Windows.Forms.Label
$message.Location = New-Object Drawing.Point(20, 20)
$message.Size = New-Object Drawing.Size(590, 45)
$message.Text = 'FONEDAY_API_TOKEN verdeckt eingeben. Eine vorhandene DPAPI-Datei wird sicher ersetzt.'
$form.Controls.Add($message)

$tokenInput = New-Object Windows.Forms.TextBox
$tokenInput.Location = New-Object Drawing.Point(20, 80)
$tokenInput.Size = New-Object Drawing.Size(590, 27)
$tokenInput.UseSystemPasswordChar = $true
$tokenInput.PasswordChar = [char]0x25CF
$tokenInput.MaxLength = 0
$form.Controls.Add($tokenInput)

$saveButton = New-Object Windows.Forms.Button
$saveButton.Location = New-Object Drawing.Point(350, 145)
$saveButton.Size = New-Object Drawing.Size(125, 34)
$saveButton.Text = 'Sicher speichern'
$form.Controls.Add($saveButton)

$cancelButton = New-Object Windows.Forms.Button
$cancelButton.Location = New-Object Drawing.Point(485, 145)
$cancelButton.Size = New-Object Drawing.Size(125, 34)
$cancelButton.Text = 'Abbrechen'
$cancelButton.DialogResult = [Windows.Forms.DialogResult]::Cancel
$form.Controls.Add($cancelButton)
$form.CancelButton = $cancelButton

$saveButton.Add_Click({
    if ($script:tokenSaved) {
        $form.DialogResult = [Windows.Forms.DialogResult]::OK
        $form.Close()
        return
    }
    try {
        if ([string]::IsNullOrWhiteSpace($tokenInput.Text)) {
            throw 'Der Foneday-Token darf nicht leer sein.'
        }
        $secureToken = ConvertTo-SecureString $tokenInput.Text -AsPlainText -Force
        $tokenInput.Clear()
        Set-MzTechFonedayToken -Token $secureToken
        $secureToken.Dispose()

        $script:tokenSaved = $true
        $tokenInput.Visible = $false
        $message.Text = 'Foneday-Token sicher gespeichert.'
        $saveButton.Text = 'Schließen'
        $cancelButton.Visible = $false
    }
    catch {
        if ($secureToken) {
            $secureToken.Dispose()
        }
        $tokenInput.Clear()
        [Windows.Forms.MessageBox]::Show(
            'Der Foneday-Token konnte nicht sicher gespeichert werden.',
            'Speicherung fehlgeschlagen',
            [Windows.Forms.MessageBoxButtons]::OK,
            [Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    }
})

$result = $form.ShowDialog()
$tokenInput.Clear()
$form.Dispose()
if ($result -ne [Windows.Forms.DialogResult]::OK) {
    exit 1
}
exit 0
