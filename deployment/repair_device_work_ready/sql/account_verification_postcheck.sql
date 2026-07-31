-- Rein lesender Postcheck.
SELECT DATABASE() AS active_schema,
       CASE WHEN DATABASE() IS NULL OR DATABASE() = '' THEN 'FEHLER' ELSE 'OK' END AS status;

SELECT expected.table_name,
       CASE WHEN t.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'portal_admin_notifications' AS table_name
  UNION ALL SELECT 'portal_activation_attempts'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = expected.table_name;

SELECT expected.table_name, expected.column_name,
       CASE
         WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT'
         WHEN LOWER(c.COLUMN_TYPE) <> expected.column_type THEN 'FEHLER'
         WHEN c.IS_NULLABLE <> expected.is_nullable THEN 'FEHLER'
         ELSE 'OK'
       END AS status,
       c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT
FROM (
  SELECT 'customer_accounts' AS table_name, 'verified_at' AS column_name, 'datetime' AS column_type, 'YES' AS is_nullable
  UNION ALL SELECT 'customer_accounts', 'verified_by', 'int(10) unsigned', 'YES'
  UNION ALL SELECT 'company_contacts', 'verified_at', 'datetime', 'YES'
  UNION ALL SELECT 'company_contacts', 'verified_by', 'int(10) unsigned', 'YES'
  UNION ALL SELECT 'company_contacts', 'password_initialized', 'tinyint(1)', 'NO'
  UNION ALL SELECT 'portal_admin_notifications', 'status', 'enum(''pending'',''done'')', 'NO'
  UNION ALL SELECT 'portal_activation_attempts', 'ip_address', 'varchar(45)', 'NO'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = expected.table_name
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name;

SELECT COUNT(*) AS plaintext_customer_tokens
FROM customer_accounts
WHERE verify_token IS NOT NULL OR reset_token IS NOT NULL;

SELECT COUNT(*) AS plaintext_company_tokens
FROM company_contacts
WHERE verify_token IS NOT NULL OR reset_token IS NOT NULL;

SELECT COUNT(*) AS untracked_pending_customer_accounts
FROM customer_accounts ca
LEFT JOIN portal_admin_notifications n
  ON n.account_type = 'customer' AND n.account_id = ca.id
 AND n.event_type = 'account_pending_verification'
WHERE ca.is_verified = 0 AND n.id IS NULL;

SELECT COUNT(*) AS untracked_pending_company_accounts
FROM company_contacts cc
LEFT JOIN portal_admin_notifications n
  ON n.account_type = 'company_contact' AND n.account_id = cc.id
 AND n.event_type = 'account_pending_verification'
WHERE cc.is_verified = 0 AND n.id IS NULL;

SELECT 'ACCOUNT_VERIFICATION_POSTCHECK_COMPLETE' AS result;
