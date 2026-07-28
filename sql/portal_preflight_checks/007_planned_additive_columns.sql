SELECT
  7 AS check_number,
  CONCAT(expected.table_name, '.', expected.column_name) AS object_name,
  CASE WHEN actual.COLUMN_NAME IS NULL THEN 'MIGRATION_ERFORDERLICH' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'customers' AS table_name, 'company_id' AS column_name
  UNION ALL SELECT 'repairs','project_id'
  UNION ALL SELECT 'customer_portal_access','token_hash'
  UNION ALL SELECT 'customer_portal_access','expires_at'
  UNION ALL SELECT 'customer_portal_access','revoked_at'
  UNION ALL SELECT 'customer_accounts','verify_token_hash'
  UNION ALL SELECT 'customer_accounts','reset_token_hash'
  UNION ALL SELECT 'company_contacts','portal_role'
  UNION ALL SELECT 'company_contacts','verify_token_hash'
  UNION ALL SELECT 'company_contacts','reset_token_hash'
  UNION ALL SELECT 'tickets','company_id'
  UNION ALL SELECT 'tickets','project_id'
  UNION ALL SELECT 'tickets','category'
  UNION ALL SELECT 'tickets','team'
  UNION ALL SELECT 'tickets','due_at'
  UNION ALL SELECT 'tickets','escalation_status'
  UNION ALL SELECT 'tickets','preferred_contact'
  UNION ALL SELECT 'tickets','preferred_date'
  UNION ALL SELECT 'tickets','location'
  UNION ALL SELECT 'tickets','customer_reference'
  UNION ALL SELECT 'tickets','project_request_text'
  UNION ALL SELECT 'ticket_attachments','mime_type'
) AS expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.table_name
 AND actual.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name
