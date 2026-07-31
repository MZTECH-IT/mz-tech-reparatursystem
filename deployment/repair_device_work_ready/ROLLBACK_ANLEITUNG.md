# Rollback

1. Keine weiteren Uploads oder SQL-Schritte ausführen.
2. Fehler und letzte verifizierte Datei feststellen.
3. Für jede bereits ersetzte Datei die zeitgestempelte lokale Originaldatei zurückspielen.
4. Jede zurückgespielte Datei erneut herunterladen und den SHA-256-Hash bestätigen.
5. Temporären Runner und bereinigte Statusdatei entfernen und ihre Abwesenheit prüfen.
6. `repair_device_work_rollback.sql` und `account_verification_rollback.sql` sind rein dokumentierende, nicht destruktive Kontrollen. Die additiven Tabellen und Spalten bleiben erhalten.

Es werden keine Reparaturen, Konten, Einladungen, Benachrichtigungen oder Dokumente gelöscht.
