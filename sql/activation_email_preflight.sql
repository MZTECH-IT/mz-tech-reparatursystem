-- Rein lesender Preflight für den Aktivierungs-E-Mail-Versand.
SELECT DATABASE() AS active_schema,
       CASE WHEN DATABASE() IS NULL OR DATABASE() = '' THEN 'FEHLER' ELSE 'OK' END AS context_status;

SELECT expected.table_name,
       CASE WHEN t.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'settings' AS table_name
  UNION ALL SELECT 'users'
  UNION ALL SELECT 'customer_accounts'
  UNION ALL SELECT 'company_contacts'
  UNION ALL SELECT 'portal_invitations'
  UNION ALL SELECT 'portal_activity_log'
  UNION ALL SELECT 'email_templates'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

SELECT expected.table_name, expected.column_name,
       CASE WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'customer_accounts' AS table_name, 'verify_token_hash' AS column_name
  UNION ALL SELECT 'customer_accounts', 'verify_expires'
  UNION ALL SELECT 'customer_accounts', 'is_verified'
  UNION ALL SELECT 'customer_accounts', 'is_active'
  UNION ALL SELECT 'company_contacts', 'verify_token_hash'
  UNION ALL SELECT 'company_contacts', 'verify_expires'
  UNION ALL SELECT 'company_contacts', 'is_verified'
  UNION ALL SELECT 'company_contacts', 'is_active'
  UNION ALL SELECT 'company_contacts', 'password_initialized'
  UNION ALL SELECT 'portal_invitations', 'token_hash'
  UNION ALL SELECT 'portal_invitations', 'expires_at'
  UNION ALL SELECT 'portal_invitations', 'accepted_at'
  UNION ALL SELECT 'portal_invitations', 'revoked_at'
  UNION ALL SELECT 'settings', 'setting_key'
  UNION ALL SELECT 'settings', 'setting_value'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = expected.table_name
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name;

SELECT COUNT(*) AS plaintext_customer_activation_tokens
FROM customer_accounts WHERE verify_token IS NOT NULL;

SELECT COUNT(*) AS plaintext_company_activation_tokens
FROM company_contacts WHERE verify_token IS NOT NULL;

SELECT 'ACTIVATION_EMAIL_PREFLIGHT_COMPLETE' AS result;
