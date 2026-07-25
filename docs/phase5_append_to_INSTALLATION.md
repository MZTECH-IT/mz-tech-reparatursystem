## Phase 5 — Sicherheits-/Robustheitshärtung + Release-Vorbereitung

Baut auf Phase 2a–2d/Integration/Phase 4 auf, keine neue Migration
erforderlich (Phase 5 fügt ausschließlich PHP-Code-Härtungen hinzu, keine
Schemaänderungen).

### Installationsreihenfolge (Gesamtübersicht, konsolidiert)

1. `sql/schema.sql` (nur bei komplett neuer Installation, via `setup.php`).
2. `sql/update.sql` inkl. aller bisherigen Ergänzungen (Phase 2a: 12
   Tabellen + `parts`-Spalten) — manuell über phpMyAdmin oder CLI, siehe
   „Datenbank-Update" unten.
3. PHP-Dateien einspielen (Patches A–K, siehe `integration_01_patches.md`
   und `phase5_patches.md`).
4. Cronjob(s) einrichten, siehe unten.
5. Dateirechte prüfen, siehe unten.
6. Testcheckliste (Abschnitte 21–24) durchgehen.

### Datenbank-Update

Wie bisher: `sql/update.sql`-Ergänzungen sind additiv und wiederholt
ausführbar (`CREATE TABLE IF NOT EXISTS`, `INFORMATION_SCHEMA`-geprüfte
`ALTER TABLE`). Phase 5 ergänzt **keine** neuen Tabellen/Spalten.

### Cronjobs

Zwei gleichwertige, parallel nutzbare Wege für den automatischen
Lieferanten-Sync:

- **Empfohlen:** `php private/cli/supplier_sync_worker.php` als echter
  Server-Cronjob. Seit Phase 5 zusätzlich gegen parallele
  Doppel-Ausführung abgesichert (Datei-Sperre in `sys_get_temp_dir()`) —
  ein zu kurz gewähltes Cron-Intervall führt nicht mehr zu doppelt
  laufenden Sync-Vorgängen.
- **Alternative (falls kein direkter Cronjob-Zugriff möglich):**
  `public/api/supplier_cron.php?secret=...` per externem Uptime-/Cron-Dienst.
  Seit Phase 5 wird jeder Aufruf, der das Secret per GET statt POST
  überträgt, zusätzlich (ohne das Secret selbst) protokolliert
  (`activity_log`, Aktion `cron_secret_via_get`) — dient als Hinweis, ob
  auf den CLI-Worker oder POST umgestellt werden sollte.

### Dateirechte

- `private/` muss außerhalb des öffentlich erreichbaren Webroots liegen
  bzw. per Webserver-Konfiguration blockiert sein (wie bisher).
  `private/cli/supplier_sync_worker.php` bricht zusätzlich hart ab
  (HTTP 403), falls es doch versehentlich per HTTP aufgerufen wird.
- Upload-Verzeichnis (`UPLOAD_PATH`, außerhalb von Git in `config.php`
  definiert) muss beschreibbar sein, Unterordner `repairs/<id>` werden mit
  Modus `0750` angelegt (bereits bestehendes Verhalten, verifiziert).
- Sperrdatei des CLI-Workers wird im System-Temp-Verzeichnis angelegt,
  keine gesonderten Rechte nötig.

### Benötigte PHP-Erweiterungen (konsolidiert)

`pdo_mysql`, `mbstring`, `zip` (für ZIP-Import, jetzt zusätzlich mit
Eintragsanzahl-/Größenlimit gehärtet), `simplexml`/`libxml`, `curl`
(REST/GraphQL-Adapter), optional `soap` (SoapApiAdapter), optional `ssh2`
(SftpAdapter — degradiert sauber, falls nicht vorhanden).

### Servervoraussetzungen

PHP 8.x, MariaDB/MySQL 5.7+ (Kompatibilität mit älteren Versionen
durch `INFORMATION_SCHEMA`-geprüfte, DELIMITER-freie Migrationen
sichergestellt), Zugriff auf phpMyAdmin oder mysql-CLI für die manuelle
Migration.

### Konfiguration außerhalb von Git

`private/config.php` (nicht im Repository, wie vorgesehen) muss u. a.
enthalten: DB-Zugangsdaten, `UPLOAD_PATH`, `MAX_UPLOAD_SIZE`,
`ALLOWED_EXTENSIONS`, `PORTAL_MAX_LOGIN_ATTEMPTS`,
`PORTAL_LOGIN_LOCKOUT_MINUTES`. Diese Werte konnten aus dem Repository
selbst nicht eingesehen werden (korrektes Verhalten, keine
Konfigurationswerte im Git) — vor dem Produktivbetrieb bitte auf dem
echten Server prüfen, dass sinnvolle Werte gesetzt sind (siehe „Offene
reale Serverprüfungen" in `phase5_patches.md`).

### Backup und Rollback

Unverändert gültig wie in Phase 2a beschrieben (`phase2a_00_ZUSAMMENFASSUNG.md`,
`phase2a_03_rollback_supplier_foundation.sql`). Phase 5 fügt keine neuen
Tabellen hinzu, daher kein zusätzliches Rollback-Skript nötig — reine
Code-Patches sind über `git revert` rückgängig zu machen, sobald
committet.
