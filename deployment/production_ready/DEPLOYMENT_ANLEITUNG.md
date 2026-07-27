# Produktionsbereitstellung

Dieses Paket ist ein gezieltes Aktualisierungspaket. Es enthält keine Zugangsdaten und stellt selbst keine Server- oder Datenbankverbindung her.

## 1. Sicherung

Vor jeder Änderung eine vollständige Dateisicherung und einen Datenbank-Dump erstellen. Auf dem Server insbesondere die vorhandenen Verzeichnisse `private/` und `public/` sowie die Datenbank sichern. Die produktive Datei `private/config.php` separat sichern.

`private/config.php` darf niemals aus diesem Paket erzeugt oder überschrieben werden. Sie ist absichtlich nicht enthalten. Ebenso keine lokalen Zugangsdaten, Uploads oder Logs hochladen.

## 2. FileZilla

Aus diesem Paket ausschließlich die Verzeichnisse `private/` und `public/` in die entsprechenden Anwendungsverzeichnisse auf dem Server hochladen. Die Ordnerstruktur beibehalten. Vor dem Überschreiben jeder gleichnamigen Serverdatei deren bisherige Version sichern.

Der Ordner `sql/` und diese Anleitung werden nicht in den Webroot hochgeladen. Die SQL-Dateien werden lokal in phpMyAdmin ausgewählt.

Falls der Server-Cron bereits den Lieferantenabgleich startet, den Pfad auf `private/cli/supplier_sync_worker.php` prüfen. Keine Zugangsdaten als Kommandozeilenargument eintragen. Der öffentliche Endpunkt `public/api/supplier_cron.php` ist nur zu verwenden, wenn das vorhandene, serverseitig konfigurierte Secret geschützt übergeben wird.

Die optionale automatische Buchhaltungssynchronisation benötigt einen separaten CLI-Cronjob für `private/cli/accounting_sync_worker.php`. Sie bleibt in der Anwendung standardmäßig deaktiviert und darf erst nach erfolgreichem Anbieter-Test aktiviert werden.

## 3. SQL über phpMyAdmin

Auf der richtigen Produktivdatenbank in exakt dieser Reihenfolge ausführen:

1. `sql/production_preflight.sql` – ausschließlich lesend. Alle Voraussetzungen prüfen und bei `FEHLT` vor der Migration abbrechen.
2. Datenbank-Dump nochmals verifizieren.
3. `sql/production_complete_migration.sql` – defensive Gesamtmigration. Bereits vorhandene Phase-2a-/Phase-2b-Strukturen werden über `IF NOT EXISTS` beziehungsweise `INFORMATION_SCHEMA` berücksichtigt.
4. `sql/production_postcheck.sql` – ausschließlich lesend. Alle erwarteten Tabellen, Spalten und Indizes müssen als vorhanden erscheinen.

`sql/phase7_rollback.sql` ist nur für einen kontrollierten Rückbau der neu hinzugefügten Phase-7-Strukturen vorgesehen. Nicht vorsorglich ausführen; ein Rollback kann seit der Migration entstandene Mapping-/Protokolldaten entfernen.

## 4. Funktionstest

Nach Datei- und SQL-Import mit einem berechtigten Testbenutzer prüfen:

- Lieferanten & Großhändler
- Lieferanten-API & Adapter
- Lieferantenangebote
- Versandkostenregeln
- Synchronisationsprotokoll
- Beschaffungsvorschläge
- Bestellungen einschließlich PDF und E-Mail-Anhang
- Wareneingang und Lagerbestandsänderung
- Kalkulationsregeln
- Buchhaltung: Einstellungen, Exporte, Synchronisation und Protokoll
- DATEV-/CSV-Export mit einem kleinen Testzeitraum
- Reparaturansicht mit reparaturbezogenem Beschaffungsbedarf

Externe Lieferanten- oder Buchhaltungs-APIs nur mit den echten, vorhandenen Testzugängen des jeweiligen Anbieters testen. Fehlende Zugangsdaten müssen als Hinweis erscheinen. sevDesk-Schreibzugriffe und nicht verifizierte Eingangsbeleg-Endpunkte bleiben absichtlich deaktiviert.

## 5. Rollback

Bei einem Fehler zuerst den Webzugriff auf die betroffenen Verwaltungsseiten sperren. Danach die gesicherten Versionen der hochgeladenen Dateien zurückkopieren und den unmittelbar vor der Migration erstellten Datenbank-Dump wiederherstellen. `private/config.php` bleibt unverändert. Nur wenn kein vollständiger Datenbank-Restore möglich ist und die Auswirkungen geprüft wurden, `sql/phase7_rollback.sql` verwenden.
