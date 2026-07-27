# Rollback

1. Bei kritischem Fehler sofort alle weiteren SQL- und Uploadschritte stoppen.
2. Temporären Runner sperren und entfernen.
3. Für bereits ersetzte Dateien ausschließlich die zeitgestempelten, hashgeprüften Serverkopien zurückladen.
4. Neue Dateien nicht endgültig löschen; zunächst außerhalb des öffentlichen Pfads sichern oder kontrolliert deaktivieren.
5. `portal_ticket_rollback.sql` beziehungsweise `foneday_rollback.sql` nur nach Fehleranalyse einzeln und ausdrücklich freigegeben verwenden.
6. Die Foneday-Rollbackdatei löscht keine Geschäfts- oder Importdaten; sie beendet nur laufende Zustände/Sperren.
7. Falls struktureller Datenbankrückbau nötig wird, die bestätigte vollständige Datenbanksicherung verwenden.
8. Nach jedem Rückspielen Hash und Kernfunktionen erneut prüfen.

