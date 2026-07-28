SELECT
  5 AS check_number,
  CONCAT(expected.table_name, '.', expected.column_name) AS object_name,
  CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'settings' AS table_name, 'setting_key' AS column_name
  UNION ALL SELECT 'email_templates','status_key'
) AS expected
LEFT JOIN INFORMATION_SCHEMA.STATISTICS AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.COLUMN_NAME = expected.column_name
 AND actual.NON_UNIQUE = 0
GROUP BY expected.table_name, expected.column_name, actual.COLUMN_NAME
ORDER BY expected.table_name, expected.column_name
