# Deployment Kunden-/Firmenportal und Ticketsystem

## Voraussetzungen

1. Vollständiges, wiederherstellbares Datenbankbackup erstellen und prüfen.
2. Aktive Produktivdatenbank in phpMyAdmin ausdrücklich auswählen.
3. `sql/portal_ticket_preflight.sql` ausführen und alle Ausgaben sichern.
4. Nur fortfahren, wenn der Schemakontext korrekt ist und die Basistabellen vorhanden sind.
5. `private/config.php` niemals herunterladen, überschreiben oder verändern.

## SQL-Reihenfolge

1. `sql/portal_ticket_preflight.sql` – rein lesend.
2. `sql/portal_ticket_migration.sql` – additive Portal-/Ticketmigration.
3. `sql/portal_ticket_postcheck.sql` – rein lesende Vollständigkeitsprüfung.
4. `sql/portal_ticket_rollback.sql` nur bei einem kontrollierten Rollback; nicht im Normalablauf.

## FTPS-Upload

Explizites FTPS über AUTH TLS, Port 21. Ziel ist ausschließlich
`/mztech-it.de/repair_neu/`.

Vor jeder vorhandenen Datei:

1. Serverversion herunterladen.
2. Lokale Sicherung mit Zeitstempel unter `backups/production/` anlegen.
3. Sicherung hashen.
4. Paketdatei und Serverdatei vergleichen.
5. Nur die in `CHANGED_FILES.txt` genannten Anwendungsdateien hochladen.
6. Jede hochgeladene Datei erneut herunterladen und per SHA-256 vergleichen.

Hochzuladen sind die Verzeichnisse `private/` und `public/` aus diesem Paket,
jeweils unter Beibehaltung ihrer Struktur. Der Ordner `sql/` wird nicht per
Webserver ausgeführt; seine Dateien werden ausschließlich manuell in phpMyAdmin
in der oben genannten Reihenfolge verwendet.

Der Ordner `website/` enthält nur dokumentierte Integrationsblöcke. Er darf
nicht blind über eine bestehende Website kopiert werden.

## Nicht überschreiben

- `private/config.php`
- Upload-Verzeichnisse und Kundendateien
- lokale oder serverseitige Logs
- unbekannte Serverdateien außerhalb der Dateiliste

## Funktionstest nach Migration und Upload

1. Kundenlogin, Registrierung, Aktivierung und Passwort-Reset öffnen.
2. Firmenlogin, Aktivierung und Passwort-Reset öffnen.
3. Rollen Firmenadministrator, Firmenmitarbeiter und Nur-Lesen prüfen.
4. Firmenprojektwahl und Ablehnung einer fremden Projekt-ID prüfen.
5. Ticket mit und ohne Projekt anlegen.
6. Öffentliche Antwort, interne Notiz und Anhänge prüfen.
7. Fremde Kunden-, Firmen-, Projekt-, Ticket-, Dokument- und Anhang-ID prüfen.
8. Gastlink auf Ablauf, Widerruf und Einmalnutzung prüfen.
9. Darstellung auf Smartphone, Tablet und Desktop prüfen.

E-Mails bleiben deaktiviert, bis `portal_email_delivery_enabled` nach
geprüfter SMTP-Konfiguration bewusst auf `1` gesetzt wurde.

## Rollback

Siehe `ROLLBACK_ANLEITUNG.md`. Keine Produktivdatei löschen und keine
Fachtabellen droppen.
