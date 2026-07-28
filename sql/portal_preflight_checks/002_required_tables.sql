SELECT
  2 AS check_number,
  expected.object_name,
  CASE WHEN actual.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'users' AS object_name
  UNION ALL SELECT 'customers'
  UNION ALL SELECT 'repairs'
  UNION ALL SELECT 'settings'
  UNION ALL SELECT 'email_templates'
  UNION ALL SELECT 'customer_portal_access'
  UNION ALL SELECT 'customer_accounts'
  UNION ALL SELECT 'portal_login_attempts'
) AS expected
LEFT JOIN INFORMATION_SCHEMA.TABLES AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.object_name
ORDER BY expected.object_name
