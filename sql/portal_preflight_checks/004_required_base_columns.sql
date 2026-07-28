SELECT
  4 AS check_number,
  CONCAT(expected.table_name, '.', expected.column_name) AS object_name,
  CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'users' AS table_name, 'id' AS column_name
  UNION ALL SELECT 'customers','id'
  UNION ALL SELECT 'repairs','id'
  UNION ALL SELECT 'repairs','customer_id'
  UNION ALL SELECT 'settings','setting_key'
  UNION ALL SELECT 'settings','setting_value'
  UNION ALL SELECT 'email_templates','status_key'
  UNION ALL SELECT 'email_templates','subject'
  UNION ALL SELECT 'email_templates','body'
  UNION ALL SELECT 'email_templates','enabled'
  UNION ALL SELECT 'customer_portal_access','token'
  UNION ALL SELECT 'customer_accounts','verify_token'
  UNION ALL SELECT 'customer_accounts','reset_token'
) AS expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name
