-- MZ Tech: Kunden-/Firmenportal und Ticketsystem
-- Defensiv, additiv und für wiederholte Ausführung auf MariaDB ausgelegt.
-- Vor Ausführung: vollständiges Datenbankbackup und portal_ticket_preflight.sql.
SET NAMES utf8mb4;
SET @portal_schema := DATABASE();
SELECT @portal_schema AS migration_context,
       CASE WHEN @portal_schema IS NULL OR @portal_schema = ''
            THEN 'FEHLER: Keine aktive Datenbank gewählt; Import jetzt abbrechen.'
            ELSE 'OK' END AS context_status;

CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_name` VARCHAR(190) NOT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `zip` VARCHAR(20) DEFAULT NULL,
  `city` VARCHAR(120) DEFAULT NULL,
  `tax_id` VARCHAR(80) DEFAULT NULL,
  `vat_id` VARCHAR(80) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_companies_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_number` VARCHAR(40) NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('aktiv','abgeschlossen','pausiert') NOT NULL DEFAULT 'aktiv',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_projects_number` (`project_number`),
  KEY `idx_projects_company_status` (`company_id`,`status`),
  CONSTRAINT `fk_projects_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_contacts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `role_title` VARCHAR(120) DEFAULT NULL,
  `portal_role` ENUM('admin','employee','read_only') NOT NULL DEFAULT 'employee',
  `password_hash` VARCHAR(255) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verify_token` VARCHAR(64) DEFAULT NULL,
  `verify_token_hash` CHAR(64) DEFAULT NULL,
  `verify_expires` DATETIME DEFAULT NULL,
  `reset_token` VARCHAR(64) DEFAULT NULL,
  `reset_token_hash` CHAR(64) DEFAULT NULL,
  `reset_expires` DATETIME DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `login_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_company_contacts_email` (`email`),
  KEY `idx_company_contacts_company` (`company_id`,`is_active`),
  KEY `idx_company_contacts_verify_hash` (`verify_token_hash`),
  KEY `idx_company_contacts_reset_hash` (`reset_token_hash`),
  CONSTRAINT `fk_company_contacts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `file_size` INT UNSIGNED DEFAULT NULL,
  `mime_type` VARCHAR(120) DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_company_documents_company` (`company_id`),
  CONSTRAINT `fk_company_documents_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(40) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('offen','in_bearbeitung','wartet_auf_kunde','wartet_intern','geloest','geschlossen','storniert') NOT NULL DEFAULT 'offen',
  `priority` ENUM('niedrig','normal','hoch','dringend') NOT NULL DEFAULT 'normal',
  `category` VARCHAR(80) NOT NULL DEFAULT 'support',
  `repair_id` INT UNSIGNED DEFAULT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `company_contact_id` INT UNSIGNED DEFAULT NULL,
  `project_id` INT UNSIGNED DEFAULT NULL,
  `assigned_technician_id` INT UNSIGNED DEFAULT NULL,
  `team` VARCHAR(100) DEFAULT NULL,
  `due_at` DATETIME DEFAULT NULL,
  `escalation_status` ENUM('none','warning','escalated') NOT NULL DEFAULT 'none',
  `preferred_contact` VARCHAR(40) DEFAULT NULL,
  `preferred_date` DATETIME DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `customer_reference` VARCHAR(100) DEFAULT NULL,
  `project_request_text` TEXT DEFAULT NULL,
  `created_by_type` ENUM('staff','customer','company_contact','system') NOT NULL DEFAULT 'staff',
  `created_by_ref` INT UNSIGNED DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_tickets_number` (`ticket_number`),
  KEY `idx_tickets_status_priority` (`status`,`priority`),
  KEY `idx_tickets_customer` (`customer_id`),
  KEY `idx_tickets_company` (`company_id`),
  KEY `idx_tickets_contact` (`company_contact_id`),
  KEY `idx_tickets_project` (`project_id`),
  KEY `idx_tickets_repair` (`repair_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `author_type` ENUM('staff','customer','company_contact','system') NOT NULL,
  `author_ref` INT UNSIGNED DEFAULT NULL,
  `body` TEXT NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_ticket_comments_ticket` (`ticket_id`,`created_at`),
  CONSTRAINT `fk_ticket_comments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `comment_id` INT UNSIGNED DEFAULT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED DEFAULT NULL,
  `mime_type` VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_ticket_attachments_ticket` (`ticket_id`),
  CONSTRAINT `fk_ticket_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_attachments_comment` FOREIGN KEY (`comment_id`) REFERENCES `ticket_comments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(80) NOT NULL,
  `actor_type` VARCHAR(40) NOT NULL,
  `actor_ref` INT UNSIGNED DEFAULT NULL,
  `old_value` TEXT DEFAULT NULL,
  `new_value` TEXT DEFAULT NULL,
  `metadata_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_ticket_history_ticket` (`ticket_id`,`created_at`),
  CONSTRAINT `fk_ticket_history_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_guest_access` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64) NOT NULL,
  `scope_type` ENUM('repair','ticket','document') NOT NULL,
  `scope_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `one_time` TINYINT(1) NOT NULL DEFAULT 0,
  `used_at` DATETIME DEFAULT NULL,
  `last_access_at` DATETIME DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `revoked_by` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_portal_guest_token_hash` (`token_hash`),
  KEY `idx_portal_guest_scope` (`scope_type`,`scope_id`),
  KEY `idx_portal_guest_expiry` (`expires_at`,`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_activity_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(100) NOT NULL,
  `actor_type` VARCHAR(40) NOT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `entity_type` VARCHAR(80) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `metadata_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_portal_activity_time` (`created_at`),
  KEY `idx_portal_activity_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_invitations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_type` ENUM('customer','company_contact') NOT NULL,
  `recipient_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_portal_invitation_token` (`token_hash`),
  KEY `idx_portal_invitation_recipient` (`recipient_type`,`recipient_id`),
  KEY `idx_portal_invitation_expiry` (`expires_at`,`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `link_type` ENUM('repair','project','purchase_order','document') NOT NULL,
  `link_id` INT UNSIGNED NOT NULL,
  `is_portal_visible` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ticket_link` (`ticket_id`,`link_type`,`link_id`),
  KEY `idx_ticket_links_target` (`link_type`,`link_id`),
  CONSTRAINT `fk_ticket_links_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_contact_project_access` (
  `company_contact_id` INT UNSIGNED NOT NULL,
  `project_id` INT UNSIGNED NOT NULL,
  `can_write` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`company_contact_id`,`project_id`),
  CONSTRAINT `fk_ccpa_contact` FOREIGN KEY (`company_contact_id`) REFERENCES `company_contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ccpa_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_document_access` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_type` VARCHAR(50) NOT NULL,
  `document_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_portal_document_grant` (`document_type`,`document_id`,`customer_id`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `company_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_customers_company` (`company_id`);
ALTER TABLE `repairs`
  ADD COLUMN IF NOT EXISTS `project_id` INT UNSIGNED DEFAULT NULL AFTER `customer_id`,
  ADD INDEX IF NOT EXISTS `idx_repairs_project` (`project_id`);
ALTER TABLE `customer_accounts`
  ADD COLUMN IF NOT EXISTS `verify_token_hash` CHAR(64) DEFAULT NULL AFTER `verify_token`,
  ADD COLUMN IF NOT EXISTS `reset_token_hash` CHAR(64) DEFAULT NULL AFTER `reset_token`,
  ADD INDEX IF NOT EXISTS `idx_customer_accounts_verify_hash` (`verify_token_hash`),
  ADD INDEX IF NOT EXISTS `idx_customer_accounts_reset_hash` (`reset_token_hash`);
ALTER TABLE `customer_portal_access`
  MODIFY COLUMN `token` VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `token_hash` CHAR(64) DEFAULT NULL AFTER `token`,
  ADD COLUMN IF NOT EXISTS `token_last4` CHAR(4) DEFAULT NULL AFTER `token_hash`,
  ADD COLUMN IF NOT EXISTS `expires_at` DATETIME DEFAULT NULL AFTER `token_last4`,
  ADD COLUMN IF NOT EXISTS `revoked_at` DATETIME DEFAULT NULL AFTER `expires_at`,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_customer_portal_token_hash` (`token_hash`);
ALTER TABLE `company_contacts`
  ADD COLUMN IF NOT EXISTS `portal_role` ENUM('admin','employee','read_only') NOT NULL DEFAULT 'employee' AFTER `role_title`,
  ADD COLUMN IF NOT EXISTS `verify_token_hash` CHAR(64) DEFAULT NULL AFTER `verify_token`,
  ADD COLUMN IF NOT EXISTS `reset_token_hash` CHAR(64) DEFAULT NULL AFTER `reset_token`,
  ADD INDEX IF NOT EXISTS `idx_company_contacts_verify_hash` (`verify_token_hash`),
  ADD INDEX IF NOT EXISTS `idx_company_contacts_reset_hash` (`reset_token_hash`);
ALTER TABLE `tickets`
  MODIFY COLUMN `status` ENUM('offen','in_bearbeitung','wartet_auf_kunde','wartet_intern','geloest','geschlossen','storniert') NOT NULL DEFAULT 'offen',
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(80) NOT NULL DEFAULT 'support' AFTER `priority`,
  ADD COLUMN IF NOT EXISTS `company_id` INT UNSIGNED DEFAULT NULL AFTER `customer_id`,
  ADD COLUMN IF NOT EXISTS `project_id` INT UNSIGNED DEFAULT NULL AFTER `company_contact_id`,
  ADD COLUMN IF NOT EXISTS `team` VARCHAR(100) DEFAULT NULL AFTER `assigned_technician_id`,
  ADD COLUMN IF NOT EXISTS `due_at` DATETIME DEFAULT NULL AFTER `team`,
  ADD COLUMN IF NOT EXISTS `escalation_status` ENUM('none','warning','escalated') NOT NULL DEFAULT 'none' AFTER `due_at`,
  ADD COLUMN IF NOT EXISTS `preferred_contact` VARCHAR(40) DEFAULT NULL AFTER `escalation_status`,
  ADD COLUMN IF NOT EXISTS `preferred_date` DATETIME DEFAULT NULL AFTER `preferred_contact`,
  ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) DEFAULT NULL AFTER `preferred_date`,
  ADD COLUMN IF NOT EXISTS `customer_reference` VARCHAR(100) DEFAULT NULL AFTER `location`,
  ADD COLUMN IF NOT EXISTS `project_request_text` TEXT DEFAULT NULL AFTER `customer_reference`,
  ADD INDEX IF NOT EXISTS `idx_tickets_company` (`company_id`),
  ADD INDEX IF NOT EXISTS `idx_tickets_project` (`project_id`);
ALTER TABLE `ticket_attachments`
  ADD COLUMN IF NOT EXISTS `mime_type` VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream' AFTER `file_size`;

-- Vorhandene Links bleiben gültig, aber die Klartextwerte werden entfernt.
UPDATE `customer_portal_access`
SET `token_hash` = SHA2(`token`, 256), `token_last4` = RIGHT(`token`, 4),
    `expires_at` = COALESCE(`expires_at`, DATE_ADD(NOW(), INTERVAL 30 DAY)), `token` = NULL
WHERE `token` IS NOT NULL AND `token` <> '';
UPDATE `customer_accounts`
SET `verify_token_hash` = SHA2(`verify_token`, 256), `verify_token` = NULL
WHERE `verify_token` IS NOT NULL AND `verify_token` <> '';
UPDATE `customer_accounts`
SET `reset_token_hash` = SHA2(`reset_token`, 256), `reset_token` = NULL
WHERE `reset_token` IS NOT NULL AND `reset_token` <> '';
UPDATE `company_contacts`
SET `verify_token_hash` = SHA2(`verify_token`, 256), `verify_token` = NULL
WHERE `verify_token` IS NOT NULL AND `verify_token` <> '';
UPDATE `company_contacts`
SET `reset_token_hash` = SHA2(`reset_token`, 256), `reset_token` = NULL
WHERE `reset_token` IS NOT NULL AND `reset_token` <> '';

INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
  ('portal_email_delivery_enabled','0'),
  ('portal_guest_default_hours','72')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `email_templates` (`status_key`,`subject`,`body`,`enabled`) VALUES
('portal_einladung','Einladung zum MZ Tech Kundenportal','<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Portalzugang wurde vorbereitet.</p><p><a href="{{verify_link}}">Zugang aktivieren</a></p>',1),
('firmenkontakt_konto_erstellt','Einladung zum MZ Tech Firmenkundenportal','<p>Hallo {{vorname}} {{nachname}},</p><p>Sie wurden für {{firma}} eingeladen.</p><p><a href="{{verify_link}}">Zugang aktivieren</a></p>',1),
('firmenkontakt_passwort_reset','Passwort für das Firmenkundenportal zurücksetzen','<p>Hallo {{vorname}} {{nachname}},</p><p><a href="{{reset_link}}">Neues Passwort festlegen</a></p>',1),
('ticket_erstellt','Ticket {{ticketnummer}} wurde erstellt','<p>Ihr Ticket {{ticketnummer}} wurde erstellt.</p><p><a href="{{portal_link}}">Ticket öffnen</a></p>',1),
('ticket_kommentar','Neue Antwort zu Ticket {{ticketnummer}}','<p>Zu Ticket {{ticketnummer}} liegt eine neue Antwort vor.</p><p><a href="{{portal_link}}">Antwort öffnen</a></p>',1),
('ticket_status','Statusänderung zu Ticket {{ticketnummer}}','<p>Der Status von Ticket {{ticketnummer}} lautet jetzt {{status}}.</p><p><a href="{{portal_link}}">Ticket öffnen</a></p>',1),
('ticket_geschlossen','Ticket {{ticketnummer}} wurde geschlossen','<p>Ticket {{ticketnummer}} wurde geschlossen.</p>',1),
('portal_gastzugang','Zeitlich begrenzter Gastzugang','<p><a href="{{guest_link}}">Freigegebenen Vorgang öffnen</a></p>',1)
ON DUPLICATE KEY UPDATE `status_key` = VALUES(`status_key`);

SELECT 'PORTAL_TICKET_MIGRATION_COMPLETE' AS result, DATABASE() AS active_schema;
