# Portal-Dokumentzugriff – Deployment

Dieses Paket korrigiert ausschließlich die serverseitige Autorisierung von Angeboten, Rechnungen und Kostenvoranschlägen sowie die mobile Darstellung der beiden Portale.

1. Vor jedem Upload alle sechs vorhandenen Produktivdateien einzeln sichern und den SHA-256-Hash festhalten.
2. Die Ordner `private/` und `public/` relativ zum Installationsstamm `/mztech-it.de/repair_neu/` hochladen.
3. `private/config.php` niemals herunterladen, lesen oder überschreiben.
4. Nach jedem Upload die Datei erneut herunterladen und den SHA-256-Hash mit der lokalen Paketdatei vergleichen.
5. Es ist keine SQL-Migration erforderlich.
6. Danach Privatkunden-, Firmenkunden-, Gast- und Mitarbeiterzugriff einschließlich Fremd-ID-Negativtests prüfen.

Rollback: Nur die fehlerhafte Datei aus der unmittelbar vor dem Upload angelegten zeitgestempelten Sicherung zurückspielen und den Rückupload erneut per SHA-256 prüfen.
