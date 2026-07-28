SELECT
  3 AS check_number,
  expected.object_name,
  CASE WHEN actual.TABLE_NAME IS NULL THEN 'NEUE_TABELLE' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'companies' AS object_name
  UNION ALL SELECT 'company_contacts'
  UNION ALL SELECT 'projects'
  UNION ALL SELECT 'company_documents'
  UNION ALL SELECT 'tickets'
  UNION ALL SELECT 'ticket_comments'
  UNION ALL SELECT 'ticket_attachments'
) AS expected
LEFT JOIN INFORMATION_SCHEMA.TABLES AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.object_name
ORDER BY expected.object_name
