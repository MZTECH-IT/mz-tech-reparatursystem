-- Rein lesender Preflight. Neun einzeln ausführbare SELECT-Prüfungen.
-- Keine Sitzungsvariable, kein SET, kein DDL und kein DML.
SELECT 1 AS check_number,
       DATABASE() AS active_schema,
       CASE WHEN DATABASE() IS NULL OR DATABASE() = ''
            THEN 'FEHLER: In phpMyAdmin zuerst die Produktivdatenbank auswählen'
            ELSE 'OK' END AS context_status;

SELECT 2 AS check_number, TABLE_SCHEMA, TABLE_NAME, ENGINE, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'users','customers','repairs','settings','email_templates',
    'customer_portal_access','customer_accounts','portal_login_attempts',
    'companies','company_contacts','projects','company_documents',
    'tickets','ticket_comments','ticket_attachments'
  )
ORDER BY TABLE_NAME;

SELECT 3 AS check_number, expected.TABLE_NAME,
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
  ON actual.TABLE_SCHEMA = DATABASE() AND actual.TABLE_NAME = expected.TABLE_NAME
ORDER BY expected.TABLE_NAME;

SELECT 4 AS check_number, 'BASE_COLUMN' AS requirement, expected.TABLE_NAME, expected.COLUMN_NAME,
       CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status,
       actual.COLUMN_TYPE, actual.IS_NULLABLE, actual.COLUMN_DEFAULT
FROM (
  SELECT 'users' TABLE_NAME, 'id' COLUMN_NAME
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
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT 5 AS check_number, 'UNIQUE_INDEX' AS requirement, expected.TABLE_NAME, expected.COLUMN_NAME,
       CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'settings' TABLE_NAME, 'setting_key' COLUMN_NAME
  UNION ALL SELECT 'email_templates','status_key'
) expected
LEFT JOIN INFORMATION_SCHEMA.STATISTICS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
 AND actual.NON_UNIQUE = 0
GROUP BY expected.TABLE_NAME, expected.COLUMN_NAME, actual.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT 6 AS check_number, 'EXISTING_TARGET_COLUMN' AS requirement, expected.TABLE_NAME, expected.COLUMN_NAME,
       CASE
         WHEN present_table.TABLE_NAME IS NULL THEN 'NEUE_TABELLE'
         WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT'
         ELSE 'VORHANDEN'
       END AS status,
       actual.COLUMN_TYPE
FROM (
  SELECT 'companies' TABLE_NAME, 'id' COLUMN_NAME
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
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES present_table
  ON present_table.TABLE_SCHEMA = DATABASE()
 AND present_table.TABLE_NAME = expected.TABLE_NAME
LEFT JOIN INFORMATION_SCHEMA.COLUMNS actual
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT 7 AS check_number, expected.TABLE_NAME, expected.COLUMN_NAME,
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
  ON actual.TABLE_SCHEMA = DATABASE()
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT 8 AS check_number, 'ENGINE_OR_COLLATION' AS requirement, TABLE_NAME, ENGINE, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('customers','repairs','companies','company_contacts','projects','tickets')
  AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%');

SELECT 9 AS check_number,
       'PREFLIGHT_READ_ONLY_COMPLETE' AS result,
       DATABASE() AS checked_schema;
