# Foneday-Synchronisierung

Der Foneday-Token ist absichtlich nur benutzergebunden auf diesem Windows-Rechner per DPAPI gespeichert. Der KAS-Server kann diesen Token nicht entschlüsseln. Eine serverseitige KAS-Cronausführung darf deshalb nicht als aktiv bezeichnet werden.

Sicherer lokaler Katalogabruf:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "M:\MZ_Tech_Reparatursystem\deployment\tools\fetch_foneday_catalog.ps1"
```

Vorgesehener Rhythmus nach erfolgreichem Verbindungstest, Dry-Run und Erstimport: 00:00, 06:00, 12:00 und 18:00 Uhr.

Status: **noch manuell einzurichten**. Für einen vollautomatischen Serverimport wäre eine gesondert freizugebende, sichere serverseitige Tokenablage erforderlich. Der Token wird nicht automatisch dupliziert.

