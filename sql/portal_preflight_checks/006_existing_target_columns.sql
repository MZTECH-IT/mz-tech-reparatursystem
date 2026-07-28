SELECT
  6 AS check_number,
  CONCAT(expected.table_name, '.', expected.column_name) AS object_name,
  CASE
    WHEN present_table.TABLE_NAME IS NULL THEN 'NEUE_TABELLE'
    WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT'
    ELSE 'VORHANDEN'
  END AS status
FROM (
  SELECT 'companies' AS table_name, 'id' AS column_name
  UNION ALL SELECT 'companies','company_name'
  UNION ALL SELECT 'projects','id'
  UNION ALL SELECT 'projects','project_number'
  UNION ALL SELECT 'projects','company_id'
  UNION ALL SELECT 'projects','name'
  UNION ALL SELECT 'company_contacts','id'
  UNION ALL SELECT 'company_contacts','company_id'
  UNION ALL SELECT 'company_contacts','email'
  UNION ALL SELECT 'company_contacts','password_hash'
  UNION ALL SELECT 'company_documents','id'
  UNION ALL SELECT 'company_documents','company_id'
  UNION ALL SELECT 'company_documents','filename'
  UNION ALL SELECT 'tickets','id'
  UNION ALL SELECT 'tickets','ticket_number'
  UNION ALL SELECT 'tickets','subject'
  UNION ALL SELECT 'tickets','status'
  UNION ALL SELECT 'tickets','priority'
  UNION ALL SELECT 'tickets','customer_id'
  UNION ALL SELECT 'ticket_comments','id'
  UNION ALL SELECT 'ticket_comments','ticket_id'
  UNION ALL SELECT 'ticket_comments','author_type'
  UNION ALL SELECT 'ticket_comments','body'
  UNION ALL SELECT 'ticket_attachments','id'
  UNION ALL SELECT 'ticket_attachments','ticket_id'
  UNION ALL SELECT 'ticket_attachments','filename'
) AS expected
LEFT JOIN INFORMATION_SCHEMA.TABLES AS present_table
  ON present_table.TABLE_SCHEMA = DATABASE()
 AND present_table.TABLE_NAME = expected.table_name
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name
