# Sicherer lokaler Credential- und Deployment-Workflow

## Speicherung

Die Geheimnisse werden als generische, benutzergebundene Einträge im Windows
Credential Manager gespeichert:

- `MZTech.Reparatursystem.ProductionDB.v1`
- `MZTech.Reparatursystem.ProductionFTPS.v1`
- `MZTech.Reparatursystem.FONEDAY_API_TOKEN.v1`

Passwörter und Tokens werden weder ausgegeben noch in Git, Quellcode,
SQL-Dateien, Protokolle oder Deployment-Pakete geschrieben. Während eines
Deployments wird ein Geheimnis nur kurzzeitig im Speicher des aktuellen
Benutzerprozesses beziehungsweise in der Umgebung des unmittelbar gestarteten
PHP-Prozesses bereitgestellt und danach entfernt.

## Einmalige Einrichtung

In einer sichtbaren PowerShell:

```powershell
powershell -NoProfile -STA -ExecutionPolicy Bypass -File "M:\MZ_Tech_Reparatursystem\deployment\tools\setup_credentials_gui.ps1"
```

Das sichtbare Windows-Dialogfenster maskiert alle Geheimnisse. FONEDAY kann
bei der Ersteinrichtung ausgelassen und später ergänzt werden. Alternativ steht
für eine bereits sichtbare Konsole `setup_credentials.ps1` zur Verfügung.

## Status prüfen

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "M:\MZ_Tech_Reparatursystem\deployment\tools\manage_credentials.ps1" -Action status
```

Der Statusbefehl zeigt ausschließlich `VORHANDEN` oder `FEHLT`.

## Ändern

Die Einrichtung überschreibt die projektspezifischen Einträge:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "M:\MZ_Tech_Reparatursystem\deployment\tools\manage_credentials.ps1" -Action change
```

## Löschen

Alle drei Einträge mit Einzelbestätigung löschen:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "M:\MZ_Tech_Reparatursystem\deployment\tools\manage_credentials.ps1" -Action delete -Credential all
```

Alternativ `database`, `ftps` oder `foneday` statt `all` angeben. Die Einträge
können außerdem in Windows unter **Anmeldeinformationsverwaltung > Windows-
Anmeldeinformationen > Generische Anmeldeinformationen** entfernt werden.

## Deployment

Lokale Prüfung ohne Credential-Zugriff und ohne Netzwerk:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "M:\MZ_Tech_Reparatursystem\deployment\tools\deploy_portal_ticket.ps1" -ValidateOnly
```

Vollständiges Deployment:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "M:\MZ_Tech_Reparatursystem\deployment\tools\deploy_portal_ticket.ps1"
```

Fehlen DB- oder FTPS-Credentials, öffnet das Deployment-Skript die sichtbare
Ersteinrichtung. Danach führt es in dieser Reihenfolge aus:

1. rein lesender Datenbank-Preflight,
2. Migration nur bei bestandenem Preflight,
3. rein lesender Postcheck,
4. lokale, zeitgestempelte Sicherung jeder vorhandenen Serverdatei,
5. Upload ausschließlich der Paketverzeichnisse `private/` und `public/`,
6. erneuter Download und SHA-256-Vergleich jeder Datei,
7. nicht schreibende HTTP-Smoke-Tests der Portal-Einstiege.

`private/config.php`, SQL-Dateien, Dokumentation, Tests, Git-Dateien, Logs und
Backups werden nie hochgeladen. Bei einem kritischen Fehler stoppt das Skript
sofort.
