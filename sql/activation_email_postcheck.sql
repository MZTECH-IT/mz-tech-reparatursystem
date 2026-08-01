-- Rein lesender Postcheck für den Aktivierungs-E-Mail-Versand.
SELECT DATABASE() AS active_schema,
       CASE WHEN DATABASE() IS NULL OR DATABASE() = '' THEN 'FEHLER' ELSE 'OK' END AS context_status;

SELECT 'portal_activation_mail_log' AS object_name,
       CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'FEHLT' END AS status
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_activation_mail_log';

SELECT expected.column_name,
       CASE
         WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT'
         WHEN c.IS_NULLABLE <> expected.is_nullable THEN 'FEHLER'
         ELSE 'OK'
       END AS status,
       c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT
FROM (
  SELECT 'account_type' AS column_name, 'NO' AS is_nullable
  UNION ALL SELECT 'account_id', 'NO'
  UNION ALL SELECT 'recipient_email', 'NO'
  UNION ALL SELECT 'delivery_method', 'NO'
  UNION ALL SELECT 'result', 'NO'
  UNION ALL SELECT 'error_category', 'YES'
  UNION ALL SELECT 'admin_id', 'YES'
  UNION ALL SELECT 'template_key', 'NO'
  UNION ALL SELECT 'invitation_id', 'YES'
  UNION ALL SELECT 'attempted_at', 'NO'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = 'portal_activation_mail_log'
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.column_name;

SELECT expected.index_name,
       CASE WHEN s.INDEX_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'idx_activation_mail_account_time' AS index_name
  UNION ALL SELECT 'idx_activation_mail_result_time'
  UNION ALL SELECT 'idx_activation_mail_invitation'
) expected
LEFT JOIN INFORMATION_SCHEMA.STATISTICS s
  ON s.TABLE_SCHEMA = DATABASE()
 AND s.TABLE_NAME = 'portal_activation_mail_log'
 AND s.INDEX_NAME = expected.index_name
GROUP BY expected.index_name, s.INDEX_NAME;

SELECT expected.setting_key,
       CASE WHEN s.setting_key IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'portal_activation_lifetime_hours' AS setting_key
  UNION ALL SELECT 'portal_activation_resend_minutes'
  UNION ALL SELECT 'portal_email_delivery_enabled'
  UNION ALL SELECT 'smtp_encryption'
) expected
LEFT JOIN settings s ON s.setting_key = expected.setting_key
ORDER BY expected.setting_key;

SELECT COUNT(*) AS plaintext_customer_activation_tokens
FROM customer_accounts WHERE verify_token IS NOT NULL;

SELECT COUNT(*) AS plaintext_company_activation_tokens
FROM company_contacts WHERE verify_token IS NOT NULL;

SELECT 'ACTIVATION_EMAIL_POSTCHECK_COMPLETE' AS result;
