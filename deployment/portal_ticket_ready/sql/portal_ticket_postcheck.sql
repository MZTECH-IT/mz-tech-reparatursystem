-- Rein lesender Postcheck nach portal_ticket_migration.sql.
SET @portal_schema := DATABASE();
SELECT @portal_schema AS active_schema,
       CASE WHEN @portal_schema IS NULL OR @portal_schema = ''
            THEN 'FEHLER: Kein aktives Schema'
            ELSE 'OK' END AS context_status;

SELECT expected.TABLE_NAME,
       CASE WHEN actual.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'companies' TABLE_NAME UNION ALL SELECT 'company_contacts'
  UNION ALL SELECT 'projects' UNION ALL SELECT 'company_documents'
  UNION ALL SELECT 'tickets' UNION ALL SELECT 'ticket_comments'
  UNION ALL SELECT 'ticket_attachments' UNION ALL SELECT 'ticket_history'
  UNION ALL SELECT 'portal_guest_access' UNION ALL SELECT 'portal_activity_log'
  UNION ALL SELECT 'portal_invitations' UNION ALL SELECT 'ticket_links'
  UNION ALL SELECT 'company_contact_project_access' UNION ALL SELECT 'portal_document_access'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES actual
  ON actual.TABLE_SCHEMA = @portal_schema AND actual.TABLE_NAME = expected.TABLE_NAME
ORDER BY expected.TABLE_NAME;

SELECT expected.TABLE_NAME, expected.COLUMN_NAME,
       CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status,
       actual.COLUMN_TYPE, actual.IS_NULLABLE, actual.COLUMN_DEFAULT
FROM (
  SELECT 'customer_portal_access' TABLE_NAME,'token_hash' COLUMN_NAME
  UNION ALL SELECT 'customer_portal_access','token_last4'
  UNION ALL SELECT 'customer_portal_access','expires_at'
  UNION ALL SELECT 'customer_portal_access','revoked_at'
  UNION ALL SELECT 'customer_accounts','verify_token_hash'
  UNION ALL SELECT 'customer_accounts','reset_token_hash'
  UNION ALL SELECT 'company_contacts','portal_role'
  UNION ALL SELECT 'company_contacts','verify_token_hash'
  UNION ALL SELECT 'company_contacts','reset_token_hash'
  UNION ALL SELECT 'tickets','category'
  UNION ALL SELECT 'tickets','company_id'
  UNION ALL SELECT 'tickets','project_id'
  UNION ALL SELECT 'tickets','team'
  UNION ALL SELECT 'tickets','due_at'
  UNION ALL SELECT 'tickets','escalation_status'
  UNION ALL SELECT 'tickets','preferred_contact'
  UNION ALL SELECT 'tickets','preferred_date'
  UNION ALL SELECT 'tickets','location'
  UNION ALL SELECT 'tickets','customer_reference'
  UNION ALL SELECT 'tickets','project_request_text'
  UNION ALL SELECT 'ticket_attachments','mime_type'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS actual
  ON actual.TABLE_SCHEMA = @portal_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT expected.TABLE_NAME, expected.INDEX_NAME,
       CASE WHEN actual.INDEX_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'customer_portal_access' TABLE_NAME,'uq_customer_portal_token_hash' INDEX_NAME
  UNION ALL SELECT 'company_contacts','idx_company_contacts_verify_hash'
  UNION ALL SELECT 'company_contacts','idx_company_contacts_reset_hash'
  UNION ALL SELECT 'tickets','idx_tickets_company'
  UNION ALL SELECT 'tickets','idx_tickets_project'
  UNION ALL SELECT 'ticket_history','idx_ticket_history_ticket'
  UNION ALL SELECT 'portal_guest_access','uq_portal_guest_token_hash'
  UNION ALL SELECT 'portal_activity_log','idx_portal_activity_time'
) expected
LEFT JOIN INFORMATION_SCHEMA.STATISTICS actual
  ON actual.TABLE_SCHEMA = @portal_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.INDEX_NAME = expected.INDEX_NAME
GROUP BY expected.TABLE_NAME, expected.INDEX_NAME, actual.INDEX_NAME
ORDER BY expected.TABLE_NAME, expected.INDEX_NAME;

SELECT 'plaintext customer magic tokens' AS check_name, COUNT(*) AS violations
FROM `customer_portal_access` WHERE `token` IS NOT NULL AND `token` <> ''
UNION ALL
SELECT 'plaintext customer activation/reset tokens',
       SUM((`verify_token` IS NOT NULL AND `verify_token` <> '') OR (`reset_token` IS NOT NULL AND `reset_token` <> ''))
FROM `customer_accounts`
UNION ALL
SELECT 'plaintext company activation/reset tokens',
       SUM((`verify_token` IS NOT NULL AND `verify_token` <> '') OR (`reset_token` IS NOT NULL AND `reset_token` <> ''))
FROM `company_contacts`;

SELECT COLUMN_TYPE AS ticket_status_values
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @portal_schema AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'status';

SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = @portal_schema
  AND TABLE_NAME IN ('projects','company_contacts','ticket_comments','ticket_attachments','ticket_history','company_contact_project_access')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT 'POSTCHECK_READ_ONLY_COMPLETE' AS result, @portal_schema AS checked_schema;
