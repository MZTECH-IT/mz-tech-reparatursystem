-- Rein lesender Preflight für Konto-Verifizierung.
SELECT DATABASE() AS active_schema,
       CASE WHEN DATABASE() IS NULL OR DATABASE() = '' THEN 'FEHLER' ELSE 'OK' END AS status;

SELECT expected.table_name,
       CASE WHEN t.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'customer_accounts' AS table_name
  UNION ALL SELECT 'company_contacts'
  UNION ALL SELECT 'customers'
  UNION ALL SELECT 'companies'
  UNION ALL SELECT 'portal_invitations'
  UNION ALL SELECT 'portal_activity_log'
  UNION ALL SELECT 'users'
  UNION ALL SELECT 'settings'
  UNION ALL SELECT 'email_templates'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

SELECT expected.table_name, expected.column_name,
       CASE WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status,
       c.COLUMN_TYPE
FROM (
  SELECT 'customer_accounts' AS table_name, 'id' AS column_name
  UNION ALL SELECT 'customer_accounts', 'is_verified'
  UNION ALL SELECT 'customer_accounts', 'verify_token_hash'
  UNION ALL SELECT 'customer_accounts', 'verify_expires'
  UNION ALL SELECT 'customer_accounts', 'created_at'
  UNION ALL SELECT 'customer_accounts', 'updated_at'
  UNION ALL SELECT 'company_contacts', 'id'
  UNION ALL SELECT 'company_contacts', 'is_verified'
  UNION ALL SELECT 'company_contacts', 'verify_token_hash'
  UNION ALL SELECT 'company_contacts', 'verify_expires'
  UNION ALL SELECT 'company_contacts', 'password_hash'
  UNION ALL SELECT 'company_contacts', 'created_at'
  UNION ALL SELECT 'company_contacts', 'updated_at'
  UNION ALL SELECT 'portal_invitations', 'token_hash'
  UNION ALL SELECT 'portal_invitations', 'accepted_at'
  UNION ALL SELECT 'portal_invitations', 'revoked_at'
  UNION ALL SELECT 'email_templates', 'status_key'
  UNION ALL SELECT 'email_templates', 'subject'
  UNION ALL SELECT 'email_templates', 'body'
  UNION ALL SELECT 'email_templates', 'enabled'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = expected.table_name
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name;

SELECT 'customer' AS account_type, COUNT(*) AS pending_accounts
FROM customer_accounts WHERE is_verified = 0
UNION ALL
SELECT 'company_contact', COUNT(*)
FROM company_contacts WHERE is_verified = 0;
