-- Datenverlustfreier Rollback: Anwendungsdateien zurückspielen, additive
-- Verifizierungsdaten für Audit und spätere Wiederaufnahme beibehalten.
SELECT DATABASE() AS active_schema;
SELECT 'NO_DESTRUCTIVE_SQL_ROLLBACK_REQUIRED' AS result,
       'Gesicherte PHP-Dateien wiederherstellen; Verifizierungs- und Auditdaten nicht löschen.' AS action;
SELECT status, COUNT(*) AS notification_count
FROM portal_admin_notifications
GROUP BY status;
