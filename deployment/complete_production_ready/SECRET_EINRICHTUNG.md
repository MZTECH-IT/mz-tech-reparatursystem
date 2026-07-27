# Geheimnisse

- Produktivdatenbank und FTPS: Windows Credential Manager, projektspezifische Namen.
- Foneday: `%LOCALAPPDATA%\MZTechRepairSystem\secrets\foneday_token.dat`, DPAPI `CurrentUser`, restriktive ACL.
- Token ersetzen: `deployment/tools/manage_foneday_token.ps1 -Action replace`
- Token löschen: `deployment/tools/manage_foneday_token.ps1 -Action delete`
- Foneday-Token, Passwort und Authorization-Header niemals ausgeben oder protokollieren.
- `private/config.php` niemals lesen, herunterladen, verändern, überschreiben oder committen.

