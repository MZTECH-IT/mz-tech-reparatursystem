# Produktionsbereitstellung

## Voraussetzungen

- Projektpfad: `M:\MZ_Tech_Reparatursystem`
- vollständiges, aktuelles Datenbankbackup
- explizites FTPS über AUTH TLS auf Port 21
- interne Administratoranmeldung
- PHP-Syntax- und lokale Tests ohne Fehler

`private/config.php` darf niemals gelesen, heruntergeladen, verändert oder überschrieben werden.

## SQL-Reihenfolge

Der geschützte temporäre Runner führt ausschließlich diese fest eingebetteten Dateien aus:

1. `sql/repair_device_work_preflight.sql` – rein lesend
2. `sql/repair_device_work_migration.sql`
3. `sql/repair_device_work_postcheck.sql` – rein lesend
4. `sql/account_verification_preflight.sql` – rein lesend
5. `sql/account_verification_migration.sql`
6. `sql/account_verification_postcheck.sql` – rein lesend

Bei jeder Abweichung stoppt der Ablauf. Dann werden keine Anwendungsdateien hochgeladen.

## Datei-Upload

Nur die Verzeichnisse `private/` und `public/` dieses Pakets werden relativ zum Reparatursystem-Stamm hochgeladen. Der Ordner `sql/`, Dokumentation, Tests, Git-Dateien, Logs und Sicherungen gehören nicht in den Webbereich.

Vor jeder vorhandenen Serverdatei wird eine zeitgestempelte lokale Einzelsicherung mit Originalhash erstellt. Nach jedem Upload wird die Datei erneut heruntergeladen und per SHA-256 mit der lokalen Zielversion verglichen.

## Produktivtests

- neue TEST-Reparatur mit Fernseher, 1,25 Stunden, 79,00 Euro und 98,75 Euro Arbeitskosten
- Reparaturdetail, Liste, Filter, Kostenvoranschlag, Rechnung, Reparaturbericht, Auftrag und Abholschein
- Kunden-, Firmen- und Gastportal
- interne Notizen intern sichtbar, in allen Kundenausgaben abwesend
- TEST-Kundenkonto: Benachrichtigung, manuelle Bestätigung, Linkerstellung, Widerruf, Einmalverwendung
- TEST-Firmenkontakt: dieselben Verifizierungsfälle und Mandantentrennung
- keine echten E-Mails, Angebote oder Rechnungen versenden

## Rollback

Bei einem Anwendungsfehler werden ausschließlich die zeitgestempelten Serverdateisicherungen zurückgespielt und erneut per Hash geprüft. Die additiven Datenbankstrukturen bleiben erhalten, damit keine Geschäftsdaten verloren gehen. Details stehen in `ROLLBACK_ANLEITUNG.md`.
