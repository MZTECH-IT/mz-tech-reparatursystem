# Phase 6 — Server-Checkliste (Produktivsystem)

Diese Liste enthält ausschließlich technische Anforderungen und Prüfpunkte.
**Keine echten Zugangsdaten, Passwörter, API-Keys oder Produktivwerte sind
hier enthalten** — nur Feldnamen/Einstellungsnamen, die auf dem echten
Server gesetzt bzw. geprüft werden müssen.

## 1. PHP

- ☐ PHP-Version 8.x (wie im Repository vorausgesetzt; genaue Minor-Version
  beim Hoster prüfen, z. B. 8.1/8.2/8.3)
- ☐ Erforderliche Erweiterungen aktiv: `pdo_mysql`, `mbstring`, `zip`,
  `simplexml`, `libxml`, `curl`
- ☐ Optionale Erweiterungen je nach genutzten Lieferanten-Adaptern:
  `soap` (SoapApiAdapter), `ssh2` (SftpAdapter — Adapter degradiert
  kontrolliert, falls nicht vorhanden, kein Absturz)
- ☐ CLI-PHP-Pfad für Cronjobs ermitteln (z. B. `/usr/bin/php8.2` oder
  laut Hoster-Dokumentation — variiert je Anbieter, im Hosting-Panel
  nachsehen)

## 2. PHP-Konfiguration (php.ini bzw. Hoster-Panel)

- ☐ `upload_max_filesize` ausreichend für größte erwartete
  Lieferanten-Preisliste (ZIP/CSV/XML) — mindestens etwas über dem
  Repository-seitigen `MAX_UPLOAD_SIZE`-Wert aus `private/config.php`
- ☐ `post_max_size` größer als `upload_max_filesize`
- ☐ `memory_limit` ausreichend für ZIP-/XML-Parsing großer Dateien
  (Richtwert: mindestens 128M, je nach Dateigröße höher)
- ☐ `max_execution_time` ausreichend für Lieferanten-Sync mit vielen
  Artikeln (insbesondere beim HTTP-Cron-Endpunkt
  `public/api/supplier_cron.php` — Zeitlimit des Webservers beachten;
  beim CLI-Worker unkritischer, da eigener Prozess)
- ☐ `session.cookie_secure` = 1 (nur bei HTTPS-Betrieb, Pflicht für den
  Kundenportal-Login)
- ☐ `session.cookie_httponly` = 1
- ☐ `session.use_strict_mode` = 1

## 3. MariaDB / MySQL

- ☐ Version 5.7 oder neuer (Migrationen sind bewusst ohne `DELIMITER` und
  mit `INFORMATION_SCHEMA`-Prüfungen geschrieben, kompatibel mit älteren
  Versionen)
- ☐ Zugriff auf phpMyAdmin oder `mysql`-CLI für die manuelle
  Migrationsausführung vorhanden
- ☐ Ausreichend Speicherplatz/Quota für neue Tabellen aus Phase 2a (12
  Tabellen + zusätzliche `parts`-Spalten)

## 4. Dateisystem / Document Root

- ☐ Document Root zeigt auf `public/` (nicht auf das Projekt-Root) —
  `private/` und `sql/` dürfen NICHT per HTTP erreichbar sein
- ☐ `private/` liegt entweder komplett außerhalb des Webroots, oder ist
  per Webserver-Konfiguration (z. B. `.htaccess`/`deny all` bei Apache,
  entsprechende `location`-Sperre bei Nginx) blockiert
- ☐ Stichprobe: `https://<domain>/private/config.php` direkt im Browser
  aufrufen → muss 403/404 liefern, NICHT den Dateiinhalt
- ☐ `private/cli/supplier_sync_worker.php` bei versehentlichem HTTP-Aufruf
  bricht mit HTTP 403 ab (im Code bereits vorgesehen — auf dem echten
  Server einmal gegenprüfen)

## 5. Upload-Verzeichnis

- ☐ `UPLOAD_PATH` (definiert in `private/config.php`, nicht im Repository)
  liegt außerhalb des öffentlich per HTTP erreichbaren Verzeichnisses
- ☐ Verzeichnis ist für den PHP-Prozess beschreibbar
- ☐ Unterordner `repairs/<id>` werden mit Modus `0750` angelegt (bereits
  bestehendes, verifiziertes Verhalten — auf dem echten Server einmal
  nach einem Test-Upload den tatsächlichen Modus prüfen)
- ☐ `ALLOWED_EXTENSIONS` und `MAX_UPLOAD_SIZE` (beide in
  `private/config.php`) auf sinnvolle Werte geprüft

## 6. ZIP-/XML-Import (Phase-5-Härtung)

- ☐ Zip-Bomben-Limits (500 Dateien / 100 MB unkomprimiert, siehe Patch I)
  gegen reale, größere Lieferanten-Preislisten testen — bei Bedarf Limits
  in `private/import_engine.php` anpassen, falls legitime Dateien darüber
  liegen
- ☐ XML-Import (`LIBXML_NONET`, Patch H) mit einer echten
  Lieferanten-XML-Preisliste testen (Regressionstest)

## 7. Login / Rate-Limiting / Session (Kundenportal)

- ☐ `PORTAL_MAX_LOGIN_ATTEMPTS` und `PORTAL_LOGIN_LOCKOUT_MINUTES` (beide
  in `private/config.php`) auf sinnvolle Werte geprüft
- ☐ Tabelle `portal_login_attempts` existiert nach der Migration
  (`sql/update.sql`) und wird befüllt (Testlogin mit falschem Passwort
  durchführen, Eintrag prüfen)
- ☐ Session-Regenerierung nach Login funktioniert (bereits im Code
  vorhanden — `session_regenerate_id(true)` in `portal_login_as()`)

## 8. Cronjobs

- ☐ Empfohlener Weg: Cronjob, der
  `php /pfad/zu/private/cli/supplier_sync_worker.php` ausführt
  (CLI-PHP-Pfad aus Punkt 1 verwenden)
- ☐ Alternative (falls kein direkter Cronjob-Zugriff möglich): externer
  Uptime-/Cron-Dienst ruft
  `https://<domain>/.../public/api/supplier_cron.php` per **POST** mit
  Secret auf (POST bevorzugt gegenüber GET — siehe Patch J,
  GET wird weiterhin funktionieren, aber protokolliert)
- ☐ Cron-Intervall sinnvoll gewählt (nicht kürzer als eine realistische
  Sync-Laufzeit — dank Patch K/Sperrdatei führt ein zu kurzes Intervall
  jetzt nicht mehr zu doppelter Verarbeitung, ist aber trotzdem
  unnötige Serverlast)
- ☐ Sperrdatei des CLI-Workers wird im System-Temp-Verzeichnis angelegt —
  prüfen, dass der PHP-Prozess dorthin schreiben darf (Standard bei den
  meisten Hostern ohne Zusatzkonfiguration gegeben)

## 9. Logging

- ☐ Log-Verzeichnis(se) des Servers (PHP-Error-Log, Webserver-Access-/
  Error-Log) vorhanden und für den PHP-Prozess beschreibbar
- ☐ `activity_log`-Tabelle (Anwendungs-internes Logging, u. a.
  `cron_secret_via_get`-Einträge aus Patch J) nach der Migration
  vorhanden und wird befüllt

## 10. E-Mail / SMTP

- ☐ SMTP-Konfiguration (Host, Port, Verschlüsselung, Absenderadresse) in
  `private/config.php` (nicht im Repository) korrekt für den
  Produktivbetrieb gesetzt — konkrete Werte konnten aus dem Repository
  nicht eingesehen werden (korrektes Verhalten, keine Zugangsdaten im
  Git)
- ☐ Testmail (z. B. Terminbestätigung oder Portal-Registrierung) einmal
  im Produktivsystem auslösen und Zustellung prüfen

## 11. Backup vor Migration

- ☐ Vollständiges Datenbank-Backup (z. B. `mysqldump`) unmittelbar vor
  Ausführung der `sql/update.sql`-Ergänzungen erstellen
- ☐ Backup an einem Ort sichern, der unabhängig vom Produktivserver ist

## 12. Datenbank-Migration — exakte Reihenfolge

1. Backup erstellen (siehe Punkt 11).
2. Aktuellen Stand von `sql/update.sql` (inkl. aller bisherigen und der
   in Phase 6 ergänzten Statements) vollständig gegen die Produktiv-DB
   ausführen — per phpMyAdmin (SQL-Tab, gesamten Dateiinhalt einfügen)
   oder `mysql -u <user> -p <datenbank> < sql/update.sql`.
3. Alle Statements sind additiv und mit `CREATE TABLE IF NOT EXISTS`
   bzw. `INFORMATION_SCHEMA`-geprüften `ALTER TABLE`-Anweisungen
   geschrieben — ein wiederholtes Ausführen der gesamten Datei ist
   unschädlich (idempotent), falls die Migration unterbrochen wird und
   erneut gestartet werden muss.
4. Nach der Migration: Stichprobenartig prüfen, dass die neuen Tabellen
   existieren (`purchase_orders`, `purchase_order_items`,
   `portal_login_attempts` u. a. aus Phase 2a) und dass bestehende
   Tabellen (`parts`, `repairs`, `activity_log` usw.) unverändert
   funktionieren.
5. Phase 6 selbst fügt **keine** neuen Tabellen/Spalten hinzu (reine
   Code-Härtung) — falls beim Server-Check festgestellt wird, dass die
   Phase-2a-Ergänzung bereits früher eingespielt wurde, ist Schritt 2
   dank Idempotenz trotzdem gefahrlos wiederholbar.

## 13. Rollback-Prozedur

**Code-Rollback (Git):**
1. Falls der Release-Branch bereits auf den Server deployed wurde und
   Probleme auftreten: zurück auf den vorherigen produktiven Commit/Tag
   wechseln (`git checkout <vorheriger-stand>`) bzw. den zuvor aktiven
   Branch erneut deployen.
2. Der lokale Backup-Branch aus SCHRITT 1 der Orchestrierung
   (`backup/vor-phase6-<Zeitstempel>`) enthält den exakten Stand vor
   allen Phase-6-Änderungen und kann jederzeit als Referenz dienen.
3. Einzelne Commits können bei Bedarf mit `git revert <commit>` rückgängig
   gemacht werden, ohne die Historie umzuschreiben (kein `reset --hard`
   auf bereits gepushte Branches, kein Force-Push).

**Datenbank-Rollback:**
1. Falls die Migration (Punkt 12) zu Problemen führt: das unter Punkt 11
   erstellte Backup vor der Migration zurückspielen
   (`mysql -u <user> -p <datenbank> < backup_vor_migration.sql`).
2. Da alle Migrationsschritte rein additiv sind (neue Tabellen/Spalten,
   keine Änderung oder Löschung bestehender Spalten), ist ein Rollback im
   Normalfall gar nicht nötig, um den vorherigen Funktionsumfang wieder
   herzustellen — im Zweifel ist das Zurückspielen des Backups aber der
   sichere Weg.
3. Es existiert kein gesondertes Rollback-SQL-Skript für Phase 6, da
   keine neuen Tabellen/Spalten hinzukommen (siehe
   `phase5_append_to_INSTALLATION.md`, Abschnitt „Backup und Rollback").
   Für die Phase-2a-Tabellen existiert weiterhin
   `phase2a_03_rollback_supplier_foundation.sql`.

## 14. Punkte, die ausschließlich auf dem echten Server geprüft werden können

- ☐ Tatsächliche Werte von `PORTAL_MAX_LOGIN_ATTEMPTS`,
  `PORTAL_LOGIN_LOCKOUT_MINUTES`, `MAX_UPLOAD_SIZE`,
  `ALLOWED_EXTENSIONS`, `UPLOAD_PATH`, SMTP-Zugangsdaten in der
  produktiven `private/config.php`
- ☐ Tatsächliche Lage von `UPLOAD_PATH` relativ zum Webroot
- ☐ Tatsächliche Aktivierung der PHP-Erweiterungen beim Hoster (`zip`,
  `simplexml`, `curl`, optional `soap`/`ssh2`)
- ☐ Tatsächliche Einrichtung und Intervall des Cronjobs beim Hoster
- ☐ Tatsächliche Dateiberechtigungen von `private/` und dem
  Upload-Verzeichnis auf dem Produktivserver
- ☐ Reales Lastverhalten der Zip-Bomben-Limits mit echten,
  größeren Lieferanten-Preislisten
- ☐ Tatsächliche PHP-CLI-Pfad und Cron-Syntax des jeweiligen Hosters
- ☐ Tatsächliches Zeitverhalten von `max_execution_time` beim
  HTTP-Cron-Endpunkt unter realer Serverlast
