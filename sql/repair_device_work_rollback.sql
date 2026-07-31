-- Kontrollierter, datenverlustfreier Rückbau.
-- Die Migration ist ausschließlich additiv. Bei einem Anwendungs-Rollback werden
-- die gesicherten PHP-Dateien zurückgespielt; Stammdaten und Reparaturwerte bleiben
-- absichtlich erhalten. Dieses Skript verändert keine Geschäftsdaten.
SELECT DATABASE() AS active_schema;
SELECT 'NO_DESTRUCTIVE_SQL_ROLLBACK_REQUIRED' AS result,
       'Anwendungsdateien aus den zeitgestempelten Sicherungen wiederherstellen; additive Spalten und Tabellen beibehalten.' AS action;
SELECT COUNT(*) AS device_type_records FROM device_types;
SELECT COUNT(*) AS repairs_with_labor_data
FROM repairs
WHERE working_hours <> 0 OR hourly_rate <> 79.00 OR labor_cost <> 0 OR performed_work IS NOT NULL;
