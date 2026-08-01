-- Kontrollierter Rückfall ohne Löschung von Konten oder Versandhistorie.
-- Deaktiviert ausschließlich den automatischen Aktivierungsversand.
INSERT INTO settings (setting_key, setting_value)
VALUES ('portal_email_delivery_enabled', '0')
ON DUPLICATE KEY UPDATE setting_value = '0';

SELECT 'ACTIVATION_EMAIL_ROLLBACK_COMPLETE' AS result;
