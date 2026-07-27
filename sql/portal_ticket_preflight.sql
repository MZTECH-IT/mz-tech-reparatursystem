-- Rein lesender Preflight. Keine Tabellen, Spalten oder Daten werden verändert.
SET @portal_schema := DATABASE();
SELECT @portal_schema AS active_schema,
       CASE WHEN @portal_schema IS NULL OR @portal_schema = ''
            THEN 'FEHLER: In phpMyAdmin zuerst die Produktivdatenbank auswählen'
            ELSE 'OK' END AS context_status;

SELECT TABLE_SCHEMA, TABLE_NAME, ENGINE, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = @portal_schema
  AND TABLE_NAME IN (
    'users','customers','repairs','settings','email_templates',
    'customer_portal_access','customer_accounts','portal_login_attempts',
    'companies','company_contacts','projects','company_documents',
    'tickets','ticket_comments','ticket_attachments'
  )
ORDER BY TABLE_NAME;

SELECT expected.TABLE_NAME,
       CASE WHEN actual.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'users' TABLE_NAME UNION ALL SELECT 'customers' UNION ALL SELECT 'repairs'
  UNION ALL SELECT 'settings' UNION ALL SELECT 'email_templates'
  UNION ALL SELECT 'customer_portal_access' UNION ALL SELECT 'customer_accounts'
  UNION ALL SELECT 'portal_login_attempts' UNION ALL SELECT 'companies'
  UNION ALL SELECT 'company_contacts' UNION ALL SELECT 'projects'
  UNION ALL SELECT 'company_documents' UNION ALL SELECT 'tickets'
  UNION ALL SELECT 'ticket_comments' UNION ALL SELECT 'ticket_attachments'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES actual
  ON actual.TABLE_SCHEMA = @portal_schema AND actual.TABLE_NAME = expected.TABLE_NAME
ORDER BY expected.TABLE_NAME;

SELECT expected.TABLE_NAME, expected.COLUMN_NAME,
       CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status,
       actual.COLUMN_TYPE, actual.IS_NULLABLE, actual.COLUMN_DEFAULT
FROM (
  SELECT 'customers' TABLE_NAME, 'company_id' COLUMN_NAME
  UNION ALL SELECT 'repairs','project_id'
  UNION ALL SELECT 'customer_portal_access','token'
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
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS actual
  ON actual.TABLE_SCHEMA = @portal_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = @portal_schema
  AND TABLE_NAME IN ('customers','repairs','companies','company_contacts','projects','tickets')
  AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%');

SELECT 'PREFLIGHT_READ_ONLY_COMPLETE' AS result, @portal_schema AS checked_schema;
