-- Additive, wiederholbare Migration für sicheren Aktivierungs-E-Mail-Versand.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS portal_activation_mail_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_type ENUM('customer','company_contact') NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  recipient_email VARCHAR(190) NOT NULL,
  delivery_method ENUM('smtp_tls') NOT NULL DEFAULT 'smtp_tls',
  result ENUM('sent','failed') NOT NULL,
  error_category VARCHAR(40) DEFAULT NULL,
  admin_id INT UNSIGNED DEFAULT NULL,
  template_key VARCHAR(100) NOT NULL,
  invitation_id BIGINT UNSIGNED DEFAULT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activation_mail_account_time (account_type, account_id, attempted_at),
  KEY idx_activation_mail_result_time (result, attempted_at),
  KEY idx_activation_mail_invitation (invitation_id),
  CONSTRAINT fk_activation_mail_invitation
    FOREIGN KEY (invitation_id) REFERENCES portal_invitations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('portal_activation_lifetime_hours', '72'),
  ('portal_activation_resend_minutes', '5'),
  ('portal_email_delivery_enabled', '0'),
  ('smtp_encryption', 'starttls')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT INTO email_templates (status_key, subject, body, enabled) VALUES
('konto_verifizieren','Ihr Zugang zum MZ-Tech-Kundenportal',
'<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihre E-Mail-Adresse wurde ein Zugang zum MZ-Tech-Kundenportal eingerichtet.</p><p><a href="{{verify_link}}">Konto jetzt aktivieren</a></p><p>Der Aktivierungslink ist 72 Stunden gültig und kann nur einmal verwendet werden.</p><p>Falls die Schaltfläche nicht funktioniert, öffnen Sie diesen Link: {{verify_link}}</p><p>Freundliche Grüße<br>MZ Tech<br>Technik · Reparatur · Service</p>',1),
('firmenkontakt_konto_erstellt','Ihr Zugang zum MZ-Tech-Firmenportal',
'<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihre E-Mail-Adresse wurde ein Zugang zum MZ-Tech-Firmenportal eingerichtet.</p><p><a href="{{verify_link}}">Konto jetzt aktivieren</a></p><p>Der Aktivierungslink ist 72 Stunden gültig und kann nur einmal verwendet werden.</p><p>Falls die Schaltfläche nicht funktioniert, öffnen Sie diesen Link: {{verify_link}}</p><p>Freundliche Grüße<br>MZ Tech<br>Technik · Reparatur · Service</p>',1)
ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), enabled = VALUES(enabled);

SELECT 'ACTIVATION_EMAIL_MIGRATION_COMPLETE' AS result, DATABASE() AS active_schema;
