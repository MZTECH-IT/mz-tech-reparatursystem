-- =====================================================
-- MZ Tech Repair System — update.sql
-- Kumulatives Update-Skript für bestehende Installationen
-- =====================================================
--
-- Für WEN ist dieses Skript?
--   NUR für bereits bestehende, produktive Installationen,
--   deren Datenbank noch mit einer älteren Version dieses
--   Systems angelegt wurde.
--
--   Bei einer KOMPLETT NEUEN Installation über setup.php wird
--   automatisch die aktuelle schema.sql verwendet — dieses
--   Update-Skript wird dafür NICHT benötigt.
--
--   Dieses Skript wächst mit jeder Funktionserweiterung des
--   Systems. Es kann jederzeit erneut ausgeführt werden (auch
--   auf einer bereits aktualisierten Datenbank) und bringt eine
--   bestehende Installation immer non-destruktiv auf den
--   aktuellen Stand.
--
-- Sicherheit:
--   - Dieses Skript LÖSCHT KEINE Daten.
--   - Alle Schritte sind idempotent (mehrfaches Ausführen ist
--     unschädlich).
--   - Bestehende Reparaturen mit alten Statuswerten
--     (eingegangen, in_arbeit, warte_auf_teile, repariert)
--     werden non-destruktiv auf die neuen, gleichwertigen
--     Statuswerte umgestellt.
--   - Es wird dringend empfohlen, vor dem Ausführen ein
--     Datenbank-Backup zu erstellen (z. B. über die
--     Backup-Funktion im Admin-Bereich oder phpMyAdmin
--     "Exportieren").
--
-- Anwendung (z. B. über phpMyAdmin bei All-Inkl):
--   1. Backup der Datenbank erstellen.
--   2. Diese Datei im SQL-Tab der Datenbank ausführen.
--   3. Fertig — keine weiteren manuellen Schritte nötig.
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- 1) Status-ENUM erweitern (additiv, nichts wird entfernt)
-- -----------------------------------------------------
ALTER TABLE `repairs`
  MODIFY COLUMN `status` ENUM(
    'anfrage_eingegangen','termin_angefragt','termin_bestaetigt','angenommen',
    'diagnose','kostenvoranschlag','freigabe_ausstehend','ersatzteil_bestellt',
    'in_reparatur','funktionstest','fertig','abholbereit','abgeholt','storniert',
    'eingegangen','in_arbeit','warte_auf_teile','repariert'
  ) NOT NULL DEFAULT 'angenommen';

-- -----------------------------------------------------
-- 2) Bestehende Datensätze auf die neuen, gleichwertigen
--    Statuswerte migrieren (alte Werte bleiben im ENUM
--    erhalten, werden hier aber nicht mehr neu vergeben)
-- -----------------------------------------------------
UPDATE `repairs` SET `status` = 'angenommen'         WHERE `status` = 'eingegangen';
UPDATE `repairs` SET `status` = 'in_reparatur'       WHERE `status` = 'in_arbeit';
UPDATE `repairs` SET `status` = 'ersatzteil_bestellt' WHERE `status` = 'warte_auf_teile';
UPDATE `repairs` SET `status` = 'fertig'             WHERE `status` = 'repariert';

-- Gleiches für die Status-Historie, falls dort ebenfalls
-- alte Statuswerte protokolliert wurden (Tabelle optional,
-- daher in einem eigenen, fehlertoleranten Block).
UPDATE `repair_status_history` SET `status` = 'angenommen'          WHERE `status` = 'eingegangen';
UPDATE `repair_status_history` SET `status` = 'in_reparatur'        WHERE `status` = 'in_arbeit';
UPDATE `repair_status_history` SET `status` = 'ersatzteil_bestellt' WHERE `status` = 'warte_auf_teile';
UPDATE `repair_status_history` SET `status` = 'fertig'              WHERE `status` = 'repariert';

-- -----------------------------------------------------
-- 3) Tabelle für admin-editierbare E-Mail-Vorlagen anlegen
--    (falls in dieser Installation noch nicht vorhanden)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_key`  VARCHAR(50)  NOT NULL,
  `subject`     VARCHAR(255) NOT NULL,
  `body`        TEXT         NOT NULL,
  `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_status_key` (`status_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------
-- 4) Standard-E-Mail-Vorlagen einfügen, falls noch nicht
--    vorhanden (bestehende, bereits vom Admin angepasste
--    Vorlagen werden NICHT überschrieben)
-- -----------------------------------------------------
INSERT INTO `email_templates` (`status_key`, `subject`, `body`, `enabled`) VALUES
  ('anfrage_eingegangen',  'Ihre Anfrage bei MZ Tech ist eingegangen – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Anfrage bei MZ Tech. Wir haben Ihre Anfrage zu Ihrem Gerät ({{geraet}}) unter der Nummer <strong>{{auftragsnummer}}</strong> erfasst und melden uns in Kürze bei Ihnen.</p><p>Ihr MZ Tech Team</p>', 1),
  ('termin_angefragt',     'Terminanfrage erhalten – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>wir haben Ihre Terminanfrage erhalten und prüfen die Verfügbarkeit. Sie erhalten in Kürze eine Bestätigung.</p><p>Ihr MZ Tech Team</p>', 1),
  ('termin_bestaetigt',    'Ihr Termin wurde bestätigt – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin für {{geraet}} wurde bestätigt. Wir freuen uns auf Ihren Besuch.</p><p>Ihr MZ Tech Team</p>', 1),
  ('angenommen',           'Ihr Gerät wurde angenommen – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>wir haben Ihr Gerät ({{hersteller}} {{modell}}) unter der Auftragsnummer <strong>{{auftragsnummer}}</strong> entgegengenommen. Der aktuelle Status ist: {{status}}.</p><p>Ihr MZ Tech Team</p>', 1),
  ('diagnose',              'Diagnose läuft – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>wir prüfen aktuell Ihr Gerät ({{geraet}}) und melden uns mit dem Ergebnis der Diagnose.</p><p>Ihr MZ Tech Team</p>', 1),
  ('kostenvoranschlag',     'Kostenvoranschlag verfügbar – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihren Auftrag {{auftragsnummer}} liegt nun ein Kostenvoranschlag vor. Bitte kontaktieren Sie uns oder nutzen Sie das Kundenportal, um diesen freizugeben.</p><p>Ihr MZ Tech Team</p>', 1),
  ('freigabe_ausstehend',   'Freigabe erforderlich – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>wir warten auf Ihre Freigabe zum Kostenvoranschlag für Auftrag {{auftragsnummer}}, um mit der Reparatur fortzufahren.</p><p>Ihr MZ Tech Team</p>', 1),
  ('ersatzteil_bestellt',   'Ersatzteil wurde bestellt – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihren Auftrag {{auftragsnummer}} wurde ein benötigtes Ersatzteil bestellt. Sobald es eingetroffen ist, setzen wir die Reparatur fort.</p><p>Ihr MZ Tech Team</p>', 1),
  ('in_reparatur',          'Ihr Gerät wird repariert – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>die Reparatur Ihres Geräts ({{geraet}}) hat begonnen.</p><p>Ihr MZ Tech Team</p>', 1),
  ('funktionstest',         'Funktionstest läuft – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Gerät befindet sich aktuell im abschließenden Funktionstest.</p><p>Ihr MZ Tech Team</p>', 1),
  ('fertig',                'Ihr Gerät ist fertig – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>gute Nachrichten: Ihr Gerät ({{geraet}}) ist fertig repariert.</p><p>Ihr MZ Tech Team</p>', 1),
  ('abholbereit',           'Ihr Gerät ist abholbereit – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Gerät ({{geraet}}) ist abholbereit. Sie können es zu unseren Öffnungszeiten bei uns abholen.</p><p>Ihr MZ Tech Team</p>', 1),
  ('abgeholt',              'Vielen Dank für Ihren Besuch – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank, dass Sie Ihr Gerät ({{geraet}}) bei uns abgeholt haben. Wir wünschen Ihnen viel Freude damit!</p><p>Ihr MZ Tech Team</p>', 1),
  ('storniert',             'Ihr Auftrag wurde storniert – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Auftrag {{auftragsnummer}} wurde storniert. Bei Fragen kontaktieren Sie uns gerne.</p><p>Ihr MZ Tech Team</p>', 1)
ON DUPLICATE KEY UPDATE `status_key` = `status_key`;

-- =====================================================
-- Update-Block: Öffentliche Terminbuchung
-- (fügt Tabelle + Einstellungen + E-Mail-Vorlagen hinzu,
--  falls in dieser Installation noch nicht vorhanden)
-- =====================================================

INSERT INTO `email_templates` (`status_key`, `subject`, `body`, `enabled`) VALUES
  ('buchung_angefragt',      'Ihre Terminanfrage bei MZ Tech – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Terminanfrage bei MZ Tech. Ihre Wunschzeit: <strong>{{status}}</strong>.</p><p>Wir prüfen die Verfügbarkeit und bestätigen Ihnen den Termin in Kürze per E-Mail. Ihre Anfragenummer lautet <strong>{{auftragsnummer}}</strong>.</p><p>Ihr MZ Tech Team</p>', 1),
  ('buchung_bestaetigt',     'Ihr Termin wurde bestätigt – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin bei MZ Tech wurde bestätigt: <strong>{{status}}</strong>.</p><p>Wir freuen uns auf Ihren Besuch. Anfragenummer: {{auftragsnummer}}.</p><p>Ihr MZ Tech Team</p>', 1),
  ('buchung_abgelehnt',      'Ihre Terminanfrage konnte leider nicht bestätigt werden – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>leider können wir Ihre Terminanfrage ({{auftragsnummer}}) zum gewünschten Zeitpunkt nicht bestätigen. Bitte wählen Sie gerne einen anderen Termin oder kontaktieren Sie uns direkt.</p><p>Ihr MZ Tech Team</p>', 1),
  ('buchung_umgeplant',      'Ihr Termin wurde verschoben – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin bei MZ Tech wurde auf einen neuen Zeitpunkt verschoben: <strong>{{status}}</strong>.</p><p>Anfragenummer: {{auftragsnummer}}.</p><p>Ihr MZ Tech Team</p>', 1)
ON DUPLICATE KEY UPDATE `status_key` = `status_key`;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `booking_requests` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_number`      VARCHAR(30)  NOT NULL,
  `status`              ENUM('angefragt','bestaetigt','abgelehnt','umgeplant','storniert','umgewandelt')
                         NOT NULL DEFAULT 'angefragt',
  `device_type`         VARCHAR(30)  NOT NULL,
  `manufacturer`        VARCHAR(100) DEFAULT NULL,
  `model`                VARCHAR(100) DEFAULT NULL,
  `issue_description`   TEXT         DEFAULT NULL,
  `first_name`          VARCHAR(100) NOT NULL,
  `last_name`           VARCHAR(100) NOT NULL,
  `email`               VARCHAR(190) NOT NULL,
  `phone`               VARCHAR(40)  DEFAULT NULL,
  `company`             VARCHAR(150) DEFAULT NULL,
  `preferred_date`      DATE         NOT NULL,
  `preferred_time`      TIME         NOT NULL,
  `confirmed_datetime`  DATETIME     DEFAULT NULL,
  `admin_note`          TEXT         DEFAULT NULL,
  `customer_id`         INT UNSIGNED DEFAULT NULL,
  `repair_id`           INT UNSIGNED DEFAULT NULL,
  `appointment_id`      INT UNSIGNED DEFAULT NULL,
  `privacy_consent`     TINYINT(1)   NOT NULL DEFAULT 0,
  `marketing_consent`   TINYINT(1)   NOT NULL DEFAULT 0,
  `ip_address`          VARCHAR(45)  DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_number` (`booking_number`),
  KEY `idx_status`   (`status`),
  KEY `idx_pref_date` (`preferred_date`),
  KEY `idx_customer`  (`customer_id`),
  KEY `idx_repair`    (`repair_id`),
  CONSTRAINT `fk_booking_customer`    FOREIGN KEY (`customer_id`)    REFERENCES `customers`    (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_repair`      FOREIGN KEY (`repair_id`)      REFERENCES `repairs`      (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('booking_enabled',           '1'),
  ('booking_hours',             '{"mon":{"open":"09:00","close":"18:00"},"tue":{"open":"09:00","close":"18:00"},"wed":{"open":"09:00","close":"18:00"},"thu":{"open":"09:00","close":"18:00"},"fri":{"open":"09:00","close":"18:00"},"sat":{"open":"10:00","close":"14:00"},"sun":null}'),
  ('booking_slot_minutes',      '30'),
  ('booking_capacity_per_slot', '1'),
  ('booking_lead_hours',        '24'),
  ('booking_max_days_ahead',    '30'),
  ('booking_blocked_dates',     '[]'),
  ('next_booking_nr',           '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- =====================================================
-- Update-Block: Öffentliche Reparaturanfragen
-- (fügt Tabelle + Einstellungen + E-Mail-Vorlagen hinzu,
--  falls in dieser Installation noch nicht vorhanden)
-- =====================================================

INSERT INTO `email_templates` (`status_key`, `subject`, `body`, `enabled`) VALUES
  ('ranfrage_neu',        'Ihre Reparaturanfrage bei MZ Tech – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Reparaturanfrage bei MZ Tech für Ihr Gerät: <strong>{{geraet}}</strong>.</p><p>Wir prüfen Ihre Anfrage und melden uns in Kürze bei Ihnen. Ihre Anfragenummer lautet <strong>{{auftragsnummer}}</strong>.</p><p>Ihr MZ Tech Team</p>', 1),
  ('ranfrage_abgelehnt',  'Ihre Reparaturanfrage – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>leider können wir Ihre Reparaturanfrage ({{auftragsnummer}}) nicht bearbeiten. Bei Fragen kontaktieren Sie uns gerne direkt.</p><p>Ihr MZ Tech Team</p>', 1)
ON DUPLICATE KEY UPDATE `status_key` = `status_key`;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `repair_requests` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_number`      VARCHAR(30)  NOT NULL,
  `status`              ENUM('neu','abgelehnt','archiviert','umgewandelt')
                         NOT NULL DEFAULT 'neu',
  `device_type`         VARCHAR(30)  NOT NULL,
  `manufacturer`        VARCHAR(100) DEFAULT NULL,
  `model`                VARCHAR(100) DEFAULT NULL,
  `problem_description` TEXT         NOT NULL,
  `first_name`          VARCHAR(100) NOT NULL,
  `last_name`           VARCHAR(100) NOT NULL,
  `email`               VARCHAR(190) NOT NULL,
  `phone`               VARCHAR(40)  DEFAULT NULL,
  `company`             VARCHAR(150) DEFAULT NULL,
  `admin_note`          TEXT         DEFAULT NULL,
  `customer_id`         INT UNSIGNED DEFAULT NULL,
  `repair_id`           INT UNSIGNED DEFAULT NULL,
  `privacy_consent`     TINYINT(1)   NOT NULL DEFAULT 0,
  `marketing_consent`   TINYINT(1)   NOT NULL DEFAULT 0,
  `ip_address`          VARCHAR(45)  DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_number` (`request_number`),
  KEY `idx_status`   (`status`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_repair`   (`repair_id`),
  CONSTRAINT `fk_request_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_request_repair`   FOREIGN KEY (`repair_id`)   REFERENCES `repairs`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('repair_request_enabled', '1'),
  ('next_request_nr',        '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- =====================================================
-- Update-Block: Kundenportal
-- (fügt Tabellen + Einstellung hinzu, falls in dieser
--  Installation noch nicht vorhanden)
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `customer_portal_access` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `token`            VARCHAR(64)  NOT NULL,
  `pin_encrypted`    TEXT         DEFAULT NULL,
  `pin_iv`           VARCHAR(64)  DEFAULT NULL,
  `last_login_at`    DATETIME     DEFAULT NULL,
  `login_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_customer` (`customer_id`),
  UNIQUE KEY `uq_portal_token` (`token`),
  CONSTRAINT `fk_portal_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_login_attempts` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address`    VARCHAR(45)  NOT NULL,
  `identifier`    VARCHAR(100) NOT NULL,
  `success`       TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('portal_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- Bestehende Standard-E-Mail-Vorlagen (nur falls seit Installation
-- unverändert) um den Kundenportal-Link ergänzen. Die WHERE-Bedingung
-- vergleicht den exakten alten Text, damit bereits vom Admin angepasste
-- Vorlagen NICHT überschrieben werden.
UPDATE `email_templates` SET `body` = '<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihren Auftrag {{auftragsnummer}} liegt nun ein Kostenvoranschlag vor. Bitte nutzen Sie das Kundenportal, um diesen einzusehen und freizugeben.</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Jetzt im Kundenportal ansehen &amp; freigeben</a></p><p>Ihr MZ Tech Team</p>'
  WHERE `status_key` = 'kostenvoranschlag'
    AND `body` = '<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihren Auftrag {{auftragsnummer}} liegt nun ein Kostenvoranschlag vor. Bitte kontaktieren Sie uns oder nutzen Sie das Kundenportal, um diesen freizugeben.</p><p>Ihr MZ Tech Team</p>';

UPDATE `email_templates` SET `body` = '<p>Hallo {{vorname}} {{nachname}},</p><p>wir warten auf Ihre Freigabe zum Kostenvoranschlag für Auftrag {{auftragsnummer}}, um mit der Reparatur fortzufahren.</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Jetzt im Kundenportal ansehen &amp; freigeben</a></p><p>Ihr MZ Tech Team</p>'
  WHERE `status_key` = 'freigabe_ausstehend'
    AND `body` = '<p>Hallo {{vorname}} {{nachname}},</p><p>wir warten auf Ihre Freigabe zum Kostenvoranschlag für Auftrag {{auftragsnummer}}, um mit der Reparatur fortzufahren.</p><p>Ihr MZ Tech Team</p>';

-- Datenschutzhinweistext für die öffentlichen Formulare (Termin, Anfrage,
-- Kundenportal) als admin-editierbare Einstellung ergänzen, damit der Text
-- künftig unter Einstellungen > Datenschutz angepasst werden kann, ohne
-- den Code zu ändern.
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('privacy_notice_text',
   '<p>Ihre Angaben werden ausschließlich zur Bearbeitung Ihrer Anfrage bzw. Ihres Reparaturauftrags durch {{firma}} genutzt und nicht an Dritte weitergegeben. Sie können der Verarbeitung Ihrer Daten jederzeit formlos per E-Mail an {{email}} widersprechen. Die vollständige Datenschutzerklärung erhalten Sie auf Anfrage.</p>')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- Hinweistext für die Kleinunternehmerregelung (§19 UStG) auf den
-- PDF-Dokumenten als admin-editierbare Einstellung ergänzen.
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('ustg_notice_text',
   'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet und ausgewiesen (Kleinunternehmerregelung).')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- MwSt-Satz auf Kleinunternehmerregelung (§19 UStG) umstellen — aber NUR,
-- wenn der Wert noch beim ursprünglichen Installations-Standardwert "19"
-- steht (d. h. noch nie bewusst von einem Admin geändert wurde). Wurde der
-- Steuersatz bereits manuell angepasst (z. B. weil doch regelbesteuert
-- wird), bleibt der bestehende Wert unangetastet.
UPDATE `settings` SET `setting_value` = '0'
  WHERE `setting_key` = 'tax_rate' AND `setting_value` = '19';

-- =====================================================
-- Update-Block: Kundenkonten, Kalender-Sync, Kunden-Uploads
-- (Version mit Registrierung/Login, .ics-Export + optionaler
--  Google-Kalender-Sync, Bild-Upload durch Kunden)
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `customer_accounts` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    INT UNSIGNED NOT NULL,
  `email`          VARCHAR(190) NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `is_verified`    TINYINT(1)   NOT NULL DEFAULT 0,
  `verify_token`   VARCHAR(64)  DEFAULT NULL,
  `verify_expires` DATETIME     DEFAULT NULL,
  `reset_token`    VARCHAR(64)  DEFAULT NULL,
  `reset_expires`  DATETIME     DEFAULT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at`  DATETIME     DEFAULT NULL,
  `login_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_email`    (`email`),
  UNIQUE KEY `uq_account_customer` (`customer_id`),
  KEY `idx_verify_token` (`verify_token`),
  KEY `idx_reset_token`  (`reset_token`),
  CONSTRAINT `fk_account_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_messages` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `repair_id`   INT UNSIGNED DEFAULT NULL,
  `sender`      ENUM('customer','staff') NOT NULL,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `message`     TEXT         NOT NULL,
  `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_repair`   (`repair_id`),
  CONSTRAINT `fk_msg_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_repair`   FOREIGN KEY (`repair_id`)   REFERENCES `repairs`   (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_msg_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `repair_request_photos` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`    INT UNSIGNED NOT NULL,
  `filename`      VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `file_size`     INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  CONSTRAINT `fk_reqphoto_request` FOREIGN KEY (`request_id`) REFERENCES `repair_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Neue Spalten auf bestehenden Tabellen (idempotent dank IF NOT EXISTS,
-- ab MariaDB 10.0.2 unterstützt — All-Inkl-Standardhosting nutzt aktuelle
-- MariaDB-Versionen, die dies unterstützen).
ALTER TABLE `repair_photos`
  ADD COLUMN IF NOT EXISTS `source` ENUM('staff','customer') NOT NULL DEFAULT 'staff' AFTER `uploaded_by`;

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `google_event_id` VARCHAR(255) DEFAULT NULL AFTER `reminder_sent`,
  ADD COLUMN IF NOT EXISTS `ics_uid`         VARCHAR(120) DEFAULT NULL AFTER `google_event_id`;

ALTER TABLE `appointments`
  ADD INDEX IF NOT EXISTS `idx_google_event` (`google_event_id`);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('customer_accounts_enabled',    '1'),
  ('ics_feed_enabled',             '1'),
  ('ics_feed_token',               ''),
  ('gcal_enabled',                 '0'),
  ('gcal_client_id',               ''),
  ('gcal_client_secret_encrypted', ''),
  ('gcal_client_secret_iv',        ''),
  ('gcal_calendar_id',             'primary'),
  ('gcal_refresh_token_encrypted', ''),
  ('gcal_refresh_token_iv',        ''),
  ('gcal_connected_account',       '')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

INSERT INTO `email_templates` (`status_key`, `subject`, `body`, `enabled`) VALUES
  ('konto_verifizieren', 'Bitte bestätigen Sie Ihr MZ Tech Kundenkonto',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Registrierung im MZ Tech Kundenportal. Bitte bestätigen Sie Ihre E-Mail-Adresse, um Ihr Konto zu aktivieren:</p><p><a href="{{verify_link}}" style="color:#0057B8;font-weight:bold;">E-Mail-Adresse jetzt bestätigen</a></p><p>Falls Sie sich nicht registriert haben, ignorieren Sie diese E-Mail einfach.</p><p>Ihr MZ Tech Team</p>', 1),
  ('konto_passwort_reset', 'Passwort zurücksetzen – MZ Tech Kundenportal',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Sie haben eine Zurücksetzung Ihres Passworts angefordert. Klicken Sie auf den folgenden Link, um ein neues Passwort zu vergeben (Link ist 60 Minuten gültig):</p><p><a href="{{reset_link}}" style="color:#0057B8;font-weight:bold;">Neues Passwort vergeben</a></p><p>Falls Sie dies nicht angefordert haben, ignorieren Sie diese E-Mail — Ihr Passwort bleibt unverändert.</p><p>Ihr MZ Tech Team</p>', 1),
  ('konto_nachricht',     'Neue Nachricht zu Ihrem Auftrag {{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Sie haben eine neue Nachricht von MZ Tech zu Ihrem Auftrag {{auftragsnummer}} erhalten. Bitte loggen Sie sich in Ihr Kundenportal ein, um sie zu lesen und zu antworten:</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Zum Kundenportal</a></p><p>Ihr MZ Tech Team</p>', 1)
ON DUPLICATE KEY UPDATE `status_key` = `status_key`;

-- =====================================================
-- Ende update.sql
-- =====================================================
