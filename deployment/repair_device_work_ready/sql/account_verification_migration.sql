-- Additive Konto-Verifizierungs- und Administratorbenachrichtigungs-Migration.
SET NAMES utf8mb4;

ALTER TABLE customer_accounts
  ADD COLUMN IF NOT EXISTS verified_at DATETIME DEFAULT NULL AFTER is_verified,
  ADD COLUMN IF NOT EXISTS verified_by INT UNSIGNED DEFAULT NULL AFTER verified_at;

ALTER TABLE company_contacts
  ADD COLUMN IF NOT EXISTS verified_at DATETIME DEFAULT NULL AFTER is_verified,
  ADD COLUMN IF NOT EXISTS verified_by INT UNSIGNED DEFAULT NULL AFTER verified_at,
  ADD COLUMN IF NOT EXISTS password_initialized TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

CREATE TABLE IF NOT EXISTS portal_admin_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_type ENUM('customer','company_contact') NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL DEFAULT 'account_pending_verification',
  status ENUM('pending','done') NOT NULL DEFAULT 'pending',
  resolution VARCHAR(80) DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  resolved_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_portal_admin_notification (account_type, account_id, event_type),
  KEY idx_portal_admin_notification_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_activation_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_portal_activation_attempts_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE customer_accounts
SET verified_at = COALESCE(verified_at, updated_at, created_at)
WHERE is_verified = 1 AND verified_at IS NULL;

UPDATE company_contacts
SET verified_at = COALESCE(verified_at, updated_at, created_at),
    password_initialized = 1
WHERE is_verified = 1 AND verified_at IS NULL;

UPDATE company_contacts
SET password_initialized = 1
WHERE is_verified = 1 AND password_initialized = 0;

INSERT INTO portal_admin_notifications (account_type, account_id, event_type, status, created_at)
SELECT 'customer', id, 'account_pending_verification', 'pending', created_at
FROM customer_accounts WHERE is_verified = 0
ON DUPLICATE KEY UPDATE account_id = VALUES(account_id);

INSERT INTO portal_admin_notifications (account_type, account_id, event_type, status, created_at)
SELECT 'company_contact', id, 'account_pending_verification', 'pending', created_at
FROM company_contacts WHERE is_verified = 0
ON DUPLICATE KEY UPDATE account_id = VALUES(account_id);

INSERT INTO email_templates (status_key, subject, body, enabled) VALUES
('konto_verifizieren','Bitte bestätigen Sie Ihren Zugang bei MZ Tech',
'<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihre E-Mail-Adresse wurde ein Zugang bei MZ Tech erstellt.</p><p><a href="{{verify_link}}">Zugang jetzt bestätigen</a></p><p>Der Aktivierungslink ist 72 Stunden gültig und kann nur einmal verwendet werden.</p><p>Nach der Bestätigung können Sie sich anmelden und – abhängig von Ihrem Zugang – Reparaturen, Tickets, Projekte, Dokumente und Statusinformationen einsehen.</p><p>Falls Sie diesen Zugang nicht angefordert haben, können Sie diese Nachricht ignorieren oder sich direkt an MZ Tech wenden.</p><p>{{verify_link}}</p><p>Freundliche Grüße<br>MZ Tech<br>Technik · Reparatur · Service</p>',1),
('firmenkontakt_konto_erstellt','Bitte bestätigen Sie Ihren Zugang bei MZ Tech',
'<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihre E-Mail-Adresse wurde ein Firmenzugang bei MZ Tech erstellt.</p><p><a href="{{verify_link}}">Zugang jetzt bestätigen</a></p><p>Der Aktivierungslink ist 72 Stunden gültig und kann nur einmal verwendet werden.</p><p>Nach der Bestätigung können Sie sich anmelden und – abhängig von Ihrem Zugang – Reparaturen, Tickets, Projekte, Dokumente und Statusinformationen einsehen.</p><p>Falls Sie diesen Zugang nicht angefordert haben, können Sie diese Nachricht ignorieren oder sich direkt an MZ Tech wenden.</p><p>{{verify_link}}</p><p>Freundliche Grüße<br>MZ Tech<br>Technik · Reparatur · Service</p>',1)
ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body);

SELECT 'ACCOUNT_VERIFICATION_MIGRATION_COMPLETE' AS result, DATABASE() AS active_schema;
