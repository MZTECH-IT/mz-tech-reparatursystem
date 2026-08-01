# Produktionsbereitstellung: Abrechnung, Angebote, Ersatzteile und Zahlungen

1. Vollständiges Datenbankbackup und aktuellen Serverstand bestätigen.
2. `sql/business_documents_preflight.sql` ausschließlich lesend ausführen. Bei `FEHLT` oder ungültigen Bestandswerten stoppen.
3. `sql/business_documents_migration.sql` einmal ausführen. Die Migration ist additiv und wurde auf erneuten Import getestet.
4. `sql/business_documents_postcheck.sql` ausführen. Alle Tabellen/Spalten und die drei Kleinunternehmer-Einstellungen müssen `OK` melden; ungültige Werte müssen 0 sein.
5. Vor jeder vorhandenen Serverdatei eine lokale zeitgestempelte Sicherung mit Original-SHA-256 anlegen.
6. Ausschließlich die Ordner `private/` und `public/` dieses Pakets pfadgleich nach `/mztech-it.de/repair_neu/` hochladen und jede Datei erneut herunterladen/hashvergleichen.
7. Niemals `private/config.php` lesen, herunterladen oder überschreiben. SQL-, Test-, Backup-, Git- und Dokumentationsdateien gehören nicht in den Webbereich.
8. Nach zentralen Dateien Login, Dashboard und Reparaturübersicht testen. Danach TEST-Reparatur, Ersatzteilkalkulation, Angebot, Annahme, Rechnungsentwurf, Freigabe, PDF, Zahlung und drei Portale testen. Keine echte E-Mail senden.

Rollback: Die zeitgestempelten Serverdatei-Sicherungen pfadgleich wiederherstellen. `business_documents_rollback.sql` ist bewusst rein lesend; additive Datenbankstrukturen und neue Geschäftsdaten werden nicht gelöscht.
