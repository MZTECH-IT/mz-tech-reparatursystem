# Produktionsbereitstellung

Produktivziel: `/mztech-it.de/repair_neu/` über explizites FTPS (AUTH TLS, Port 21).

1. Vollständige Datenbanksicherung bestätigen.
2. Temporären Runner mit zufälligem Namen nach `public/` laden.
3. Als interner Administrator anmelden und mit dem einmaligen Token nacheinander ausführen:
   1. `portal_ticket_preflight.sql` (lesend)
   2. `portal_ticket_migration.sql`
   3. `portal_ticket_postcheck.sql` (lesend)
   4. `foneday_preflight.sql` (lesend)
   5. `foneday_migration.sql`
   6. `foneday_postcheck.sql` (lesend)
4. Bei jeder Abweichung stoppen; keine Anwendungsdateien hochladen.
5. Runner und bereinigte Statusdatei entfernen und die Entfernung prüfen.
6. Vor jeder vorhandenen Produktivdatei Serverkopie, Zeitstempel und SHA-256 sichern.
7. Ausschließlich die Inhalte aus `private/` und `public/` relativ zum Produktivstamm hochladen.
8. Jede hochgeladene Datei erneut herunterladen und per SHA-256 prüfen.
9. Die SQL-, `website/`- und Dokumentationsdateien werden nicht in den öffentlichen Webbereich geladen.
10. Portal-, Berechtigungs-, Ticket-, Download- und Foneday-Adminfunktionen testen.

`private/config.php` darf weder gelesen noch heruntergeladen, verändert oder überschrieben werden.

