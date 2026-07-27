# Foneday-Paket

Status: lokal vorbereitet, **nicht produktiv ausgerollt**.

Blocker: Der vorhandene DPAPI-geschützte Token wurde bei zwei ausschließlich lesenden `GET /products`-Tests von Foneday abgelehnt.

Nach sicherem Ersetzen/Freischalten des Tokens:

1. `foneday_preflight.sql` über den geschützten Server-Runner lesend ausführen.
2. Nur bei vollständig erfolgreichem Preflight `foneday_migration.sql` ausführen.
3. `foneday_postcheck.sql` lesend ausführen.
4. Runner vollständig entfernen und Entfernung prüfen.
5. Vor jeder Datei in `private/` und `public/` die Produktivversion zeitgestempelt sichern.
6. Dateien hochladen und einzeln per SHA-256 verifizieren.
7. Lesenden Verbindungstest, Dry-Run und Statistik prüfen.
8. Erst danach einen ausdrücklich bestätigten Erstimport ausführen.

`private/config.php` und der Foneday-Token gehören niemals in dieses Paket oder auf den Server.

