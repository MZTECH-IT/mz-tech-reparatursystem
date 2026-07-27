# Lokale Testergebnisse

## Erfolgreich ausgeführt

- PHP-Lint: 138 Dateien unter `private/`, `public/` und `tests/`; 0 Fehler.
- Doppelte Funktionsdefinitionen: Produktions-Static-Check; 0 Fehler.
- Statische Include-/Require-Pfade: 231 Pfade; 0 Fehler.
- Interne Menülinks: 35 Links; 0 fehlende Ziele.
- Portal-/Ticket-Sicherheitstest: 43 Prüfungen; 0 Fehler.
- Gesamtlauf aller 15 PHP-Testdateien: 15 erfolgreich, 0 fehlgeschlagen.
- Portal-/Ticket-Unit-Test: 20 Token-, Rollen- und Statusprüfungen; 0 Fehler.
- Lieferanten-, Versand-, Produkt-, Beschaffungs-, Import-, Cron-,
  Worker-Lock- und Wareneingangsregressionstests: erfolgreich.
- SQL-Preflight und SQL-Postcheck: statisch als rein lesend geprüft.
- Deployment-Ausschlüsse: `private/config.php`, Zugangsdaten, Logs, Backups,
  Dumps und Tests sind nicht enthalten.

## Kontrolliert übersprungen oder nicht ausführbar

- Drei ZipArchive-abhängige Importtestfälle: lokale PHP-Erweiterung
  `ZipArchive` fehlt; der vorhandene Test meldete dies kontrolliert.
- Datenbankmigration: nicht lokal oder produktiv ausgeführt.
- Datenbankgestützte Login-, IDOR-, Token- und Ticketfunktionstests:
  ohne migriertes Testschema nicht ausgeführt.
- Reale E-Mails: nicht gesendet.
- Browser-/Responsive-Tests: noch nicht produktiv ausgeführt.
- FTPS-Upload dieses Pakets: noch nicht ausgeführt.

Erfolge für diese nicht ausgeführten Punkte werden ausdrücklich nicht
behauptet.
