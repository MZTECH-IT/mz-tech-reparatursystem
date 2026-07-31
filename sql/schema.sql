-- =====================================================
-- MZ Tech Repair Management System
-- Datenbankschema v1.0
-- MariaDB / MySQL 5.7+
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------
-- Benutzer
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `email`         VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `role`          ENUM('admin','techniker','empfang') NOT NULL DEFAULT 'techniker',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `last_login`    DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Login-Versuche (Brute-Force-Schutz)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `username`     VARCHAR(50)  DEFAULT NULL,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip`           (`ip_address`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Kunden
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`   VARCHAR(100) NOT NULL,
  `last_name`    VARCHAR(100) NOT NULL,
  `phone`        VARCHAR(50)  DEFAULT NULL,
  `phone2`       VARCHAR(50)  DEFAULT NULL,
  `email`        VARCHAR(255) DEFAULT NULL,
  `address`      VARCHAR(255) DEFAULT NULL,
  `city`         VARCHAR(100) DEFAULT NULL,
  `zip`          VARCHAR(10)  DEFAULT NULL,
  `notes`        TEXT         DEFAULT NULL,
  `gdpr_consent` TINYINT(1)   NOT NULL DEFAULT 0,
  `gdpr_date`    DATETIME     DEFAULT NULL,
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_last_name` (`last_name`),
  KEY `idx_phone`     (`phone`),
  KEY `idx_email`     (`email`),
  CONSTRAINT `fk_customers_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Zentrale Gerätearten-Stammdaten
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `device_types` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `technical_key` VARCHAR(80)  NOT NULL,
  `display_name`  VARCHAR(100) NOT NULL,
  `category`      VARCHAR(100) DEFAULT NULL,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_types_key` (`technical_key`),
  KEY `idx_device_types_active_sort` (`is_active`,`sort_order`,`display_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `device_types` (`technical_key`,`display_name`,`category`,`sort_order`,`is_active`) VALUES
('smartphone','Smartphone','Mobilgeräte',10,1),
('tablet','Tablet','Mobilgeräte',20,1),
('pc','PC','Computer',30,1),
('laptop','Laptop','Computer',40,1),
('fernseher','Fernseher','Unterhaltungselektronik',50,1),
('monitor','Monitor','Computer',60,1),
('hifi_anlage','HiFi-Anlage','Unterhaltungselektronik',70,1),
('verstaerker','Verstärker','Unterhaltungselektronik',80,1),
('radio','Radio','Unterhaltungselektronik',90,1),
('dvd_bluray_player','DVD-/Blu-ray-Player','Unterhaltungselektronik',100,1),
('spielkonsole','Spielkonsole','Gaming',110,1),
('controller','Controller','Gaming',120,1),
('smartwatch','Smartwatch','Mobilgeräte',130,1),
('firmenhardware','Firmenhardware','Firmenkunden',140,1),
('it_service','IT-Service','Dienstleistung',150,1),
('sonstiges','Sonstiges','Sonstiges',999,1)
ON DUPLICATE KEY UPDATE `technical_key` = VALUES(`technical_key`);

-- -----------------------------------------------------
-- Reparaturen
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `repairs` (
  `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `repair_number`       VARCHAR(20)      NOT NULL,
  `customer_id`         INT UNSIGNED     NOT NULL,
  `device_type`         VARCHAR(100)     NOT NULL,
  `device_type_id`      INT UNSIGNED     DEFAULT NULL,
  `device_type_legacy_value` VARCHAR(100) DEFAULT NULL,
  `manufacturer`        VARCHAR(100)     DEFAULT NULL,
  `model`               VARCHAR(100)     DEFAULT NULL,
  `color`               VARCHAR(50)      DEFAULT NULL,
  `imei`                VARCHAR(50)      DEFAULT NULL,
  `serial_number`       VARCHAR(100)     DEFAULT NULL,
  `problem_description` TEXT             NOT NULL,
  `problem_type`        VARCHAR(100)     DEFAULT NULL,
  `is_water_damage`     TINYINT(1)       NOT NULL DEFAULT 0,
  `internal_notes`      TEXT             DEFAULT NULL,
  `performed_work`      TEXT             DEFAULT NULL,
  `status`              ENUM('anfrage_eingegangen','termin_angefragt','termin_bestaetigt','angenommen',
                             'diagnose','kostenvoranschlag','freigabe_ausstehend','ersatzteil_bestellt',
                             'in_reparatur','funktionstest','fertig','abholbereit','abgeholt','storniert',
                             'eingegangen','in_arbeit','warte_auf_teile','repariert')
                        NOT NULL DEFAULT 'angenommen',
  `price`               DECIMAL(10,2)    DEFAULT NULL,
  `working_hours`       DECIMAL(8,2)     NOT NULL DEFAULT 0.00,
  `hourly_rate`         DECIMAL(10,2)    NOT NULL DEFAULT 79.00,
  `labor_cost`          DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  `advance_payment`     DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `technician_id`       INT UNSIGNED     DEFAULT NULL,
  `passcode_encrypted`  TEXT             DEFAULT NULL,
  `passcode_iv`         VARCHAR(64)      DEFAULT NULL,
  `warranty_months`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `estimated_ready`     DATETIME         DEFAULT NULL,
  `created_by`          INT UNSIGNED     DEFAULT NULL,
  `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at`        DATETIME         DEFAULT NULL,
  `picked_up_at`        DATETIME         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repair_number` (`repair_number`),
  KEY `idx_customer`   (`customer_id`),
  KEY `idx_status`     (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_technician` (`technician_id`),
  KEY `idx_repairs_device_type_id` (`device_type_id`),
  CONSTRAINT `fk_repairs_customer`   FOREIGN KEY (`customer_id`)   REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_repairs_technician` FOREIGN KEY (`technician_id`) REFERENCES `users`     (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_repairs_creator`    FOREIGN KEY (`created_by`)    REFERENCES `users`     (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_repairs_device_type` FOREIGN KEY (`device_type_id`) REFERENCES `device_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Statusverlauf
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `repair_status_history` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repair_id` INT UNSIGNED NOT NULL,
  `status`    VARCHAR(50)  NOT NULL,
  `note`      TEXT         DEFAULT NULL,
  `user_id`   INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_repair` (`repair_id`),
  CONSTRAINT `fk_rsh_repair` FOREIGN KEY (`repair_id`) REFERENCES `repairs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsh_user`   FOREIGN KEY (`user_id`)   REFERENCES `users`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Reparatur-Fotos
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `repair_photos` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `repair_id`     INT UNSIGNED  NOT NULL,
  `filename`      VARCHAR(255)  NOT NULL,
  `original_name` VARCHAR(255)  DEFAULT NULL,
  `photo_type`    ENUM('vorher','nachher','sonstiges') NOT NULL DEFAULT 'sonstiges',
  `description`   VARCHAR(255)  DEFAULT NULL,
  `file_size`     INT UNSIGNED  DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED  DEFAULT NULL,
  `source`        ENUM('staff','customer') NOT NULL DEFAULT 'staff',
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_repair` (`repair_id`),
  CONSTRAINT `fk_photos_repair`   FOREIGN KEY (`repair_id`)   REFERENCES `repairs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photos_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Ersatzteile / Lager
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `parts` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sku`            VARCHAR(50)   DEFAULT NULL,
  `name`           VARCHAR(255)  NOT NULL,
  `category`       VARCHAR(100)  DEFAULT NULL,
  `manufacturer`   VARCHAR(100)  DEFAULT NULL,
  `description`    TEXT          DEFAULT NULL,
  `stock_quantity` INT           NOT NULL DEFAULT 0,
  `min_stock`      INT           NOT NULL DEFAULT 0,
  `purchase_price` DECIMAL(10,2) DEFAULT NULL,
  `selling_price`  DECIMAL(10,2) DEFAULT NULL,
  `created_by`     INT UNSIGNED  DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sku`   (`sku`),
  KEY `idx_name`        (`name`),
  KEY `idx_category`    (`category`),
  CONSTRAINT `fk_parts_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Eingesetzte Teile je Reparatur
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `repair_parts` (
  `id`                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `repair_id`              INT UNSIGNED  NOT NULL,
  `part_id`                INT UNSIGNED  NOT NULL,
  `quantity`               INT           NOT NULL DEFAULT 1,
  `purchase_price_at_time` DECIMAL(10,2) DEFAULT NULL,
  `selling_price_at_time`  DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_repair` (`repair_id`),
  KEY `idx_part`   (`part_id`),
  CONSTRAINT `fk_rp_repair` FOREIGN KEY (`repair_id`) REFERENCES `repairs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_part`   FOREIGN KEY (`part_id`)   REFERENCES `parts`   (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Kalender / Termine
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `appointments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repair_id`      INT UNSIGNED DEFAULT NULL,
  `customer_id`    INT UNSIGNED DEFAULT NULL,
  `title`          VARCHAR(255) NOT NULL,
  `start_datetime` DATETIME     NOT NULL,
  `end_datetime`   DATETIME     DEFAULT NULL,
  `type`           ENUM('eingang','reparatur','abholung','sonstiges') NOT NULL DEFAULT 'sonstiges',
  `notes`          TEXT         DEFAULT NULL,
  `reminder_sent`  TINYINT(1)   NOT NULL DEFAULT 0,
  `google_event_id` VARCHAR(255) DEFAULT NULL,
  `ics_uid`         VARCHAR(120) DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_start`    (`start_datetime`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_repair`   (`repair_id`),
  KEY `idx_google_event` (`google_event_id`),
  CONSTRAINT `fk_app_repair`   FOREIGN KEY (`repair_id`)   REFERENCES `repairs`   (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Aktivitätsprotokoll
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(50)  NOT NULL,
  `entity_type` VARCHAR(50)  DEFAULT NULL,
  `entity_id`   INT UNSIGNED DEFAULT NULL,
  `details`     TEXT         DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_entity`  (`entity_type`, `entity_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Systemeinstellungen
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- E-Mail-Vorlagen je Reparaturstatus (admin-editierbar)
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

-- =====================================================
-- Initialdaten
-- =====================================================

-- Standard-Einstellungen
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('company_name',     'MZ Tech'),
  ('company_address',  'Heidestraße 7, 33818 Leopoldshöhe'),
  ('company_phone',    ''),
  ('company_email',    'info@mztech-it.de'),
  ('company_website',  'https://www.mztech-it.de'),
  ('company_iban',     ''),
  ('company_bic',      ''),
  ('company_tax_id',   ''),
  ('invoice_prefix',   'RE'),
  ('repair_prefix',    'MZ'),
  ('next_invoice_nr',  '1'),
  ('smtp_host',        ''),
  ('smtp_port',        '587'),
  ('smtp_user',        ''),
  ('smtp_pass',        ''),
  ('smtp_from_name',   'MZ Tech'),
  ('smtp_from_email',  'info@mztech-it.de'),
  ('warranty_default', '3'),
  ('currency_symbol',  '€'),
  -- Standardmäßig Kleinunternehmerregelung (§19 UStG, kein MwSt-Ausweis).
  -- Kann unter Einstellungen > Firmendaten jederzeit auf den tatsächlichen
  -- MwSt-Satz umgestellt werden, falls die Regelbesteuerung greift.
  ('tax_rate',         '0'),
  ('ustg_notice_text',
   'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet und ausgewiesen (Kleinunternehmerregelung).'),
  ('privacy_notice_text',
   '<p>Ihre Angaben werden ausschließlich zur Bearbeitung Ihrer Anfrage bzw. Ihres Reparaturauftrags durch {{firma}} genutzt und nicht an Dritte weitergegeben. Sie können der Verarbeitung Ihrer Daten jederzeit formlos per E-Mail an {{email}} widersprechen. Die vollständige Datenschutzerklärung erhalten Sie auf Anfrage.</p>')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- Standard-E-Mail-Vorlagen je Reparaturstatus
-- Platzhalter: {{vorname}} {{nachname}} {{firma}} {{auftragsnummer}} {{status}}
--              {{geraet}} {{hersteller}} {{modell}} {{portal_link}}
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
   '<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihren Auftrag {{auftragsnummer}} liegt nun ein Kostenvoranschlag vor. Bitte nutzen Sie das Kundenportal, um diesen einzusehen und freizugeben.</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Jetzt im Kundenportal ansehen &amp; freigeben</a></p><p>Ihr MZ Tech Team</p>', 1),
  ('freigabe_ausstehend',   'Freigabe erforderlich – Auftrag #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>wir warten auf Ihre Freigabe zum Kostenvoranschlag für Auftrag {{auftragsnummer}}, um mit der Reparatur fortzufahren.</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Jetzt im Kundenportal ansehen &amp; freigeben</a></p><p>Ihr MZ Tech Team</p>', 1),
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
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Auftrag {{auftragsnummer}} wurde storniert. Bei Fragen kontaktieren Sie uns gerne.</p><p>Ihr MZ Tech Team</p>', 1),
  ('buchung_angefragt',      'Ihre Terminanfrage bei MZ Tech – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Terminanfrage bei MZ Tech. Ihre Wunschzeit: <strong>{{status}}</strong>.</p><p>Wir prüfen die Verfügbarkeit und bestätigen Ihnen den Termin in Kürze per E-Mail. Ihre Anfragenummer lautet <strong>{{auftragsnummer}}</strong>.</p><p>Ihr MZ Tech Team</p>', 1),
  ('buchung_bestaetigt',     'Ihr Termin wurde bestätigt – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin bei MZ Tech wurde bestätigt: <strong>{{status}}</strong>.</p><p>Wir freuen uns auf Ihren Besuch. Anfragenummer: {{auftragsnummer}}.</p><p>Ihr MZ Tech Team</p>', 1),
  ('buchung_abgelehnt',      'Ihre Terminanfrage konnte leider nicht bestätigt werden – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>leider können wir Ihre Terminanfrage ({{auftragsnummer}}) zum gewünschten Zeitpunkt nicht bestätigen. Bitte wählen Sie gerne einen anderen Termin oder kontaktieren Sie uns direkt.</p><p>Ihr MZ Tech Team</p>', 1),
  ('buchung_umgeplant',      'Ihr Termin wurde verschoben – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin bei MZ Tech wurde auf einen neuen Zeitpunkt verschoben: <strong>{{status}}</strong>.</p><p>Anfragenummer: {{auftragsnummer}}.</p><p>Ihr MZ Tech Team</p>', 1)
ON DUPLICATE KEY UPDATE `status_key` = `status_key`;

-- -----------------------------------------------------
-- Öffentliche Terminanfragen (Terminbuchung ohne Login)
-- -----------------------------------------------------
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

-- Standard-Einstellungen für die öffentliche Terminbuchung
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

-- -----------------------------------------------------
-- Öffentliche Reparaturanfragen (ohne Termin, ohne Login)
-- -----------------------------------------------------
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

-- E-Mail-Vorlagen für die öffentliche Reparaturanfrage
INSERT INTO `email_templates` (`status_key`, `subject`, `body`, `enabled`) VALUES
  ('ranfrage_neu',        'Ihre Reparaturanfrage bei MZ Tech – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Reparaturanfrage bei MZ Tech für Ihr Gerät: <strong>{{geraet}}</strong>.</p><p>Wir prüfen Ihre Anfrage und melden uns in Kürze bei Ihnen. Ihre Anfragenummer lautet <strong>{{auftragsnummer}}</strong>.</p><p>Ihr MZ Tech Team</p>', 1),
  ('ranfrage_abgelehnt',  'Ihre Reparaturanfrage – #{{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>leider können wir Ihre Reparaturanfrage ({{auftragsnummer}}) nicht bearbeiten. Bei Fragen kontaktieren Sie uns gerne direkt.</p><p>Ihr MZ Tech Team</p>', 1)
ON DUPLICATE KEY UPDATE `status_key` = `status_key`;

-- Standard-Einstellungen für die öffentliche Reparaturanfrage
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('repair_request_enabled', '1'),
  ('next_request_nr',        '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- -----------------------------------------------------
-- Kundenportal (Zugang ohne Passwort via Link/PIN/QR)
-- -----------------------------------------------------
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

-- Standard-Einstellungen für das Kundenportal
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('portal_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- -----------------------------------------------------
-- Kundenkonten (registrierte Kunden, E-Mail + Passwort)
-- Läuft PARALLEL zum passwortlosen Gast-Zugang (customer_portal_access).
-- Ein Kunde kann wahlweise Gast bleiben oder sich zusätzlich ein
-- vollständiges Konto anlegen (1:1 zu customers).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_accounts` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    INT UNSIGNED NOT NULL,
  `email`          VARCHAR(190) NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `is_verified`    TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_at`    DATETIME     DEFAULT NULL,
  `verified_by`    INT UNSIGNED DEFAULT NULL,
  `verify_token`   VARCHAR(64)  DEFAULT NULL,
  `verify_token_hash` CHAR(64)  DEFAULT NULL,
  `verify_expires` DATETIME     DEFAULT NULL,
  `reset_token`    VARCHAR(64)  DEFAULT NULL,
  `reset_token_hash` CHAR(64)   DEFAULT NULL,
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
  KEY `idx_customer_accounts_verify_hash` (`verify_token_hash`),
  KEY `idx_reset_token`  (`reset_token`),
  KEY `idx_customer_accounts_reset_hash` (`reset_token_hash`),
  CONSTRAINT `fk_account_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Nachrichten zwischen Kunde (Portal) und MZ Tech
-- -----------------------------------------------------
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

-- -----------------------------------------------------
-- Fotos zu einer öffentlichen Reparaturanfrage (vor Auftragsanlage,
-- daher eigene Tabelle statt repair_photos, da noch kein repair_id
-- existiert). Beim Umwandeln in einen Auftrag können diese optional
-- in repair_photos übernommen werden.
-- -----------------------------------------------------
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

-- Standard-Einstellungen: Kundenkonten, Kalender-Sync (.ics + optional Google)
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

-- Standard-E-Mail-Vorlagen: Kundenkonto (Registrierung, Verifizierung,
-- Passwort-Reset) sowie neue Nachricht im Kundenportal.
INSERT INTO `email_templates` (`status_key`, `subject`, `body`, `enabled`) VALUES
  ('konto_verifizieren', 'Bitte bestätigen Sie Ihr MZ Tech Kundenkonto',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Registrierung im MZ Tech Kundenportal. Bitte bestätigen Sie Ihre E-Mail-Adresse, um Ihr Konto zu aktivieren:</p><p><a href="{{verify_link}}" style="color:#0057B8;font-weight:bold;">E-Mail-Adresse jetzt bestätigen</a></p><p>Falls Sie sich nicht registriert haben, ignorieren Sie diese E-Mail einfach.</p><p>Ihr MZ Tech Team</p>', 1),
  ('konto_passwort_reset', 'Passwort zurücksetzen – MZ Tech Kundenportal',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Sie haben eine Zurücksetzung Ihres Passworts angefordert. Klicken Sie auf den folgenden Link, um ein neues Passwort zu vergeben (Link ist 60 Minuten gültig):</p><p><a href="{{reset_link}}" style="color:#0057B8;font-weight:bold;">Neues Passwort vergeben</a></p><p>Falls Sie dies nicht angefordert haben, ignorieren Sie diese E-Mail — Ihr Passwort bleibt unverändert.</p><p>Ihr MZ Tech Team</p>', 1),
  ('konto_nachricht',     'Neue Nachricht zu Ihrem Auftrag {{auftragsnummer}}',
   '<p>Hallo {{vorname}} {{nachname}},</p><p>Sie haben eine neue Nachricht von MZ Tech zu Ihrem Auftrag {{auftragsnummer}} erhalten. Bitte loggen Sie sich in Ihr Kundenportal ein, um sie zu lesen und zu antworten:</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Zum Kundenportal</a></p><p>Ihr MZ Tech Team</p>', 1)
ON DUPLICATE KEY UPDATE `status_key` = `status_key`;
