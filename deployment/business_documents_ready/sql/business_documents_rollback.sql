-- Kontrollierter, datenbewahrender Rückbauhinweis.
-- Diese Datei löscht bewusst keine Tabellen, Spalten, Angebote, Rechnungen oder Zahlungen.
-- Bei einem Anwendungsrollback werden die vor dem Upload gesicherten PHP-Dateien wiederhergestellt.
-- Die additiven Datenbankfelder bleiben kompatibel und dürfen erst nach gesonderter Datenprüfung entfernt werden.
SELECT 'ROLLBACK_IS_APPLICATION_FILES_ONLY' AS result,
       'Additive Datenbankstrukturen bleiben zum Schutz bestehender Geschäftsdaten erhalten.' AS action;
SELECT DATABASE() AS active_database,
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes') AS quotes_table_present,
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments') AS payments_table_present;
