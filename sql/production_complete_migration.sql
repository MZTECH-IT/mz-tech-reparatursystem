-- =====================================================================
-- PHASE 2a: Datenbank-Fundament fuer Grosshaendler-/Lieferantenmodul
-- =====================================================================
-- Zweck: Legt die 12 Tabellen an, die von private/supplier_adapters.php,
--        private/suppliers.php, private/products.php,
--        private/purchase_orders.php, private/import_engine.php,
--        private/price_guard.php sowie den zugehoerigen public/*.php-
--        Seiten bereits vorausgesetzt werden, aber bisher in keiner
--        SQL-Datei des Repositories enthalten waren.
--        Ergaenzt ausserdem die von private/products.php tatsaechlich
--        benoetigten, fehlenden Spalten der bestehenden Tabelle `parts`.
--
-- WICHTIG:
--   - Additiv: keine bestehende Tabelle wird veraendert ausser durch
--     kontrolliertes Hinzufuegen neuer, nullable bzw. mit sicherem
--     DEFAULT versehener Spalten zu `parts`.
--   - Idempotent: mehrfaches Ausfuehren ist unschaedlich
--     (CREATE TABLE IF NOT EXISTS; Spalten werden nur ergaenzt, wenn sie
--     noch nicht existieren -- siehe INFORMATION_SCHEMA-Wächter unten).
--   - Es werden KEINE Daten geloescht und KEINE bestehenden Spalten
--     veraendert oder entfernt.
--   - Vor dem Einspielen: Backup-Hinweis am Ende dieser Datei beachten.
--   - NOCH NICHT AUF DER PRODUKTIVDATENBANK AUSFUEHREN
--     (siehe Phase-2a-Zusammenfassung -- wartet auf Freigabe).
--
-- Reihenfolge ist bewusst gewaehlt (Tabellen ohne Fremdschluessel-
-- Abhaengigkeiten zuerst, referenzierende Tabellen danach), damit die
-- Datei auch bei strikt aktivierten Fremdschluessel-Pruefungen in einem
-- Durchlauf fehlerfrei durchlaeuft.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. suppliers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `short_code` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('aktiv','inaktiv','archiviert') NOT NULL DEFAULT 'aktiv',
  `contact_person` VARCHAR(150) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `address_street` VARCHAR(255) DEFAULT NULL,
  `address_zip` VARCHAR(10) DEFAULT NULL,
  `address_city` VARCHAR(100) DEFAULT NULL,
  `address_country` VARCHAR(2) DEFAULT 'DE',
  `customer_number_at_supplier` VARCHAR(100) DEFAULT NULL,
  `vat_id` VARCHAR(50) DEFAULT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `default_tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  `payment_terms` VARCHAR(255) DEFAULT NULL,
  `delivery_time_days` SMALLINT UNSIGNED DEFAULT NULL,
  `minimum_order_value` DECIMAL(10,2) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `auto_sync_mode` ENUM('manuell','stuendlich','taeglich','woechentlich') NOT NULL DEFAULT 'manuell',
  `auto_sync_cron_expression` VARCHAR(100) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `last_sync_at` DATETIME DEFAULT NULL,
  `last_sync_status` VARCHAR(50) DEFAULT NULL,
  `last_sync_message` TEXT DEFAULT NULL,
  `next_scheduled_sync_at` DATETIME DEFAULT NULL,
  `archived_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_suppliers_short_code` (`short_code`),
  KEY `idx_suppliers_status` (`status`),
  KEY `idx_suppliers_next_sync` (`next_scheduled_sync_at`),
  CONSTRAINT `fk_suppliers_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. supplier_interface_profiles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `supplier_interface_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(150) DEFAULT NULL,
  `interface_type` ENUM(
    'rest_api','graphql_api','http_download','ftp','sftp',
    'soap_api','edi','ugl_ugs','manual_upload'
  ) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `endpoint_url` VARCHAR(500) DEFAULT NULL,
  `auth_type` ENUM('none','basic','bearer_token','api_key','oauth2','custom') NOT NULL DEFAULT 'none',
  `ftp_host` VARCHAR(255) DEFAULT NULL,
  `ftp_port` SMALLINT UNSIGNED DEFAULT NULL,
  `ftp_path` VARCHAR(500) DEFAULT NULL,
  `ftp_passive` TINYINT(1) NOT NULL DEFAULT 1,
  `format` ENUM('csv','tsv','txt','xlsx','xml','json','zip') DEFAULT NULL,
  `delimiter` VARCHAR(5) DEFAULT ',',
  -- Zugangsdaten NIEMALS im Klartext: AES-256-CBC ueber die bestehenden
  -- Funktionen encrypt_passcode()/decrypt_passcode(); Chiffrat und IV
  -- bewusst getrennt gespeichert.
  `credentials_encrypted` TEXT DEFAULT NULL,
  `credentials_iv` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sip_supplier` (`supplier_id`),
  KEY `idx_sip_active` (`is_active`),
  CONSTRAINT `fk_sip_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. supplier_documents
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `supplier_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `description` VARCHAR(500) DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sd_supplier` (`supplier_id`),
  CONSTRAINT `fk_sd_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sd_uploaded_by` FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. supplier_shipping_rules
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `supplier_shipping_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `rule_type` ENUM(
    'fest','kostenlos_ab','express_zuschlag','sperrgut_zuschlag',
    'gefahrgut_zuschlag','pro_versandklasse','pro_land'
  ) NOT NULL,
  `condition_value` DECIMAL(10,2) DEFAULT NULL,
  `condition_text` VARCHAR(100) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ssr_supplier_priority` (`supplier_id`, `priority`),
  CONSTRAINT `fk_ssr_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. import_profiles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `import_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) DEFAULT NULL,
  `column_mapping_json` TEXT NOT NULL,
  `dedupe_key` VARCHAR(50) NOT NULL DEFAULT 'supplier_sku',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_supplier` (`supplier_id`),
  CONSTRAINT `fk_ip_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. import_jobs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `import_jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED DEFAULT NULL,
  `import_profile_id` INT UNSIGNED DEFAULT NULL,
  `source_type` ENUM('upload','adapter') NOT NULL DEFAULT 'upload',
  `source_filename` VARCHAR(255) DEFAULT NULL,
  `source_path` VARCHAR(500) DEFAULT NULL,
  `status` ENUM(
    'vorschau','wartet_auf_freigabe','importiert','zurueckgerollt','fehlgeschlagen'
  ) NOT NULL DEFAULT 'vorschau',
  `rows_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_new` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_updated` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_unchanged` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_duplicate` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_error` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_warning` INT UNSIGNED NOT NULL DEFAULT 0,
  `preview_json` LONGTEXT DEFAULT NULL,
  `snapshot_before_json` LONGTEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rolled_back_by` INT UNSIGNED DEFAULT NULL,
  `rolled_back_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ij_supplier` (`supplier_id`),
  KEY `idx_ij_status` (`status`),
  KEY `idx_ij_created_at` (`created_at`),
  CONSTRAINT `fk_ij_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ij_import_profile` FOREIGN KEY (`import_profile_id`)
    REFERENCES `import_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ij_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ij_approved_by` FOREIGN KEY (`approved_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ij_rolled_back_by` FOREIGN KEY (`rolled_back_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. product_supplier_offers
--    (haengt von `parts` ab -- die parts-Erweiterung weiter unten in
--    Abschnitt 12 kann unabhaengig davon vorher oder danach laufen,
--    da nur Spalten ergaenzt werden, nicht die Tabelle selbst angelegt.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_supplier_offers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_id` INT UNSIGNED NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `supplier_sku` VARCHAR(100) NOT NULL,
  `supplier_product_name` VARCHAR(255) DEFAULT NULL,
  `ean` VARCHAR(20) DEFAULT NULL,
  `mpn` VARCHAR(100) DEFAULT NULL,
  `purchase_price` DECIMAL(10,2) DEFAULT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `tax_rate` DECIMAL(5,2) DEFAULT NULL,
  `is_net_price` TINYINT(1) NOT NULL DEFAULT 1,
  `availability` ENUM('auf_lager','bestellbar','unbekannt','nicht_verfuegbar') NOT NULL DEFAULT 'unbekannt',
  `stock_quantity_at_supplier` INT DEFAULT NULL,
  `delivery_time_days` SMALLINT UNSIGNED DEFAULT NULL,
  `minimum_order_quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `shipping_cost_estimate` DECIMAL(10,2) DEFAULT NULL,
  `shipping_cost_is_estimate` TINYINT(1) NOT NULL DEFAULT 1,
  `is_preferred` TINYINT(1) NOT NULL DEFAULT 0,
  `is_flagged_faulty` TINYINT(1) NOT NULL DEFAULT 0,
  `flagged_reason` VARCHAR(255) DEFAULT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `source_import_job_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pso_part_supplier_sku` (`part_id`, `supplier_id`, `supplier_sku`),
  KEY `idx_pso_part` (`part_id`),
  KEY `idx_pso_supplier` (`supplier_id`),
  KEY `idx_pso_ean` (`ean`),
  KEY `idx_pso_mpn` (`mpn`),
  KEY `idx_pso_availability` (`availability`),
  CONSTRAINT `fk_pso_part` FOREIGN KEY (`part_id`)
    REFERENCES `parts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pso_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nachtraeglicher FK von import_jobs -> product_supplier_offers.source_import_job_id
-- wird bewusst NICHT als FK auf import_jobs zurueck von product_supplier_offers aus
-- modelliert (source_import_job_id verweist auf import_jobs, nicht umgekehrt):
-- Wird per ALTER ergaenzt, da import_jobs beim Anlegen von product_supplier_offers
-- oben bereits existiert -- direkte Aufnahme in CREATE TABLE ist ebenfalls möglich,
-- hier zur Robustheit gegen abweichende Ausfuehrungsreihenfolgen per ALTER nachgezogen.
SET @fk_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_supplier_offers'
    AND CONSTRAINT_NAME = 'fk_pso_source_import_job'
);
SET @stmt := IF(@fk_exists = 0,
  'ALTER TABLE `product_supplier_offers` ADD CONSTRAINT `fk_pso_source_import_job` FOREIGN KEY (`source_import_job_id`) REFERENCES `import_jobs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 8. product_price_history
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_price_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_id` INT UNSIGNED NOT NULL,
  `supplier_id` INT UNSIGNED DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `price_type` VARCHAR(20) NOT NULL DEFAULT 'einkauf',
  `source` VARCHAR(50) NOT NULL DEFAULT 'import',
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pph_part_recorded` (`part_id`, `recorded_at`),
  KEY `idx_pph_supplier` (`supplier_id`),
  CONSTRAINT `fk_pph_part` FOREIGN KEY (`part_id`)
    REFERENCES `parts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pph_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. product_availability_history
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_availability_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_id` INT UNSIGNED NOT NULL,
  `supplier_id` INT UNSIGNED DEFAULT NULL,
  `availability` ENUM('auf_lager','bestellbar','unbekannt','nicht_verfuegbar') NOT NULL,
  `stock_quantity` INT DEFAULT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pah_part_recorded` (`part_id`, `recorded_at`),
  KEY `idx_pah_supplier` (`supplier_id`),
  CONSTRAINT `fk_pah_part` FOREIGN KEY (`part_id`)
    REFERENCES `parts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pah_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. purchase_orders
--     Bestellnummern-Vergabe NICHT hier -- nutzt die bestehende Tabelle
--     `number_ranges` ueber generate_document_number('BE') aus
--     private/numbering.php (bereits produktiv vorhanden, "BE" bereits
--     reserviert). Keine parallele Nummernstruktur.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `status` ENUM(
    'entwurf','freigegeben','bestellt','teilweise_geliefert','geliefert','storniert'
  ) NOT NULL DEFAULT 'entwurf',
  `order_number` VARCHAR(30) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `ordered_at` DATETIME DEFAULT NULL,
  `shipping_cost` DECIMAL(10,2) DEFAULT NULL,
  `shipping_cost_is_estimate` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_order_number` (`order_number`),
  KEY `idx_po_supplier` (`supplier_id`),
  KEY `idx_po_status` (`status`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_po_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. purchase_order_items
--     assigned_company_id / assigned_project_id / assigned_ticket_id
--     BEWUSST OHNE Fremdschluessel: die Tabellen companies/projects/
--     tickets konnten in sql/schema.sql und sql/update.sql nicht
--     gefunden werden (siehe Feld- und Abhaengigkeitsmatrix, Abschnitt 2).
--     Die Spalten werden additiv als einfache INT UNSIGNED NULL angelegt,
--     damit spaeter -- sobald die tatsaechliche Struktur dieser Tabellen
--     verifiziert ist -- per separater, additiver Migration ein FK
--     nachgezogen werden kann, ohne bestehende Daten zu gefaehrden.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_order_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_id` INT UNSIGNED NOT NULL,
  `part_id` INT UNSIGNED NOT NULL,
  `supplier_offer_id` INT UNSIGNED DEFAULT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `purchase_price_at_time` DECIMAL(10,2) DEFAULT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `assigned_customer_id` INT UNSIGNED DEFAULT NULL,
  `assigned_company_id` INT UNSIGNED DEFAULT NULL,   -- kein FK, siehe Kommentar oben
  `assigned_project_id` INT UNSIGNED DEFAULT NULL,   -- kein FK, siehe Kommentar oben
  `assigned_repair_id` INT UNSIGNED DEFAULT NULL,
  `assigned_ticket_id` INT UNSIGNED DEFAULT NULL,    -- kein FK, siehe Kommentar oben
  `assigned_stock` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) DEFAULT NULL,
  `quantity_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_poi_po` (`purchase_order_id`),
  KEY `idx_poi_part` (`part_id`),
  KEY `idx_poi_customer` (`assigned_customer_id`),
  KEY `idx_poi_repair` (`assigned_repair_id`),
  CONSTRAINT `fk_poi_purchase_order` FOREIGN KEY (`purchase_order_id`)
    REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_poi_part` FOREIGN KEY (`part_id`)
    REFERENCES `parts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_poi_supplier_offer` FOREIGN KEY (`supplier_offer_id`)
    REFERENCES `product_supplier_offers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_poi_customer` FOREIGN KEY (`assigned_customer_id`)
    REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_poi_repair` FOREIGN KEY (`assigned_repair_id`)
    REFERENCES `repairs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_poi_quantity_positive` CHECK (`quantity` > 0),
  CONSTRAINT `chk_poi_received_not_over` CHECK (`quantity_received` <= `quantity` * 2)
  -- Hinweis chk_poi_received_not_over: großzügige obere Grenze (statt exakt
  -- <= quantity), da Teillieferungen/Nachbesserungen in der Praxis leicht
  -- von der bestellten Menge abweichen koennen; verhindert nur grobe
  -- Fehleingaben. Wird auf MySQL 5.7 / alten MariaDB-Versionen syntaktisch
  -- akzeptiert, aber nicht durchgesetzt (siehe Testcheckliste).
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. pricing_rules
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pricing_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) DEFAULT NULL,
  `scope_type` ENUM('global','kategorie','lieferant','einzelprodukt') NOT NULL,
  `scope_value` VARCHAR(100) DEFAULT NULL,
  `calculation_type` ENUM('prozent_aufschlag','fester_aufschlag','fixer_preis') NOT NULL,
  `value` DECIMAL(10,2) NOT NULL,
  `min_margin_percent` DECIMAL(5,2) DEFAULT NULL,
  `min_profit_amount` DECIMAL(10,2) DEFAULT NULL,
  `min_price` DECIMAL(10,2) DEFAULT NULL,
  `rounding_mode` VARCHAR(20) DEFAULT NULL,
  `price_ending` VARCHAR(10) DEFAULT NULL,
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_scope` (`scope_type`, `scope_value`),
  KEY `idx_pr_priority` (`priority`)
  -- Bewusst kein FK auf scope_value: das Feld ist polymorph (Kategorie-
  -- Text, Lieferanten-ID oder Produkt-ID je nach scope_type) und kann
  -- daher nicht auf eine einzelne Zieltabelle verweisen.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 13. Erweiterung der bestehenden Tabelle `parts`
--     Kontrolliertes, wiederholt sicheres Hinzufuegen einzelner Spalten
--     ueber INFORMATION_SCHEMA-Wächter (funktioniert identisch auf
--     MariaDB und MySQL 5.7+, im Gegensatz zu `ADD COLUMN IF NOT EXISTS`,
--     das auf MySQL < 8.0.29 nicht existiert).
--     ES WERDEN KEINE BESTEHENDEN SPALTEN VERAENDERT ODER ENTFERNT.
--
--     Bewusst OHNE Stored Procedures/DELIMITER umgesetzt (reine
--     SET/PREPARE/EXECUTE/DEALLOCATE-Bloecke, durch normale Semikolons
--     getrennt), damit die Datei unveraendert sowohl im phpMyAdmin-
--     SQL-Tab als auch ueber die mysql/mariadb-CLI oder einen einfachen,
--     nicht DELIMITER-fähigen Statement-Splitter funktioniert.
-- =====================================================================

-- --- parts.ean ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='ean');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `ean` VARCHAR(20) DEFAULT NULL AFTER `sku`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.mpn ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='mpn');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `mpn` VARCHAR(100) DEFAULT NULL AFTER `ean`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.brand ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='brand');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `brand` VARCHAR(100) DEFAULT NULL AFTER `manufacturer`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.model_compatibility ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='model_compatibility');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `model_compatibility` VARCHAR(255) DEFAULT NULL AFTER `brand`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.device_type ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='device_type');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `device_type` VARCHAR(100) DEFAULT NULL AFTER `category`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.subcategory ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='subcategory');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `subcategory` VARCHAR(100) DEFAULT NULL AFTER `category`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.is_discontinued ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='is_discontinued');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `is_discontinued` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.is_price_flagged ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='is_price_flagged');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `is_price_flagged` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.price_flag_reason ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='price_flag_reason');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `price_flag_reason` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.preferred_supplier_id ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='preferred_supplier_id');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `preferred_supplier_id` INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.stock_location ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='stock_location');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `stock_location` VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.reserved_stock ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='reserved_stock');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `reserved_stock` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.last_price_check_at ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='last_price_check_at');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `last_price_check_at` DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.requires_serial_or_batch ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='requires_serial_or_batch');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `requires_serial_or_batch` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.quality_tier ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='quality_tier');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `quality_tier` VARCHAR(50) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.warranty_note ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='warranty_note');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `warranty_note` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.image_url ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='image_url');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `image_url` VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.product_url ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='product_url');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `product_url` VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.datasheet_url ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='datasheet_url');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `datasheet_url` VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.weight_grams ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='weight_grams');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `weight_grams` INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- parts.packaging_unit ---
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND COLUMN_NAME='packaging_unit');
SET @ddl := IF(@col_exists=0, 'ALTER TABLE `parts` ADD COLUMN `packaging_unit` VARCHAR(50) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indizes auf den neuen parts-Spalten (gleiches Waechter-Muster, damit
-- wiederholtes Ausfuehren keinen "Duplicate key name"-Fehler wirft).

-- --- idx_parts_ean ---
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND INDEX_NAME='idx_parts_ean');
SET @ddl := IF(@idx_exists=0, 'ALTER TABLE `parts` ADD INDEX `idx_parts_ean` (`ean`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- idx_parts_mpn ---
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND INDEX_NAME='idx_parts_mpn');
SET @ddl := IF(@idx_exists=0, 'ALTER TABLE `parts` ADD INDEX `idx_parts_mpn` (`mpn`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- idx_parts_preferred_supplier ---
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND INDEX_NAME='idx_parts_preferred_supplier');
SET @ddl := IF(@idx_exists=0, 'ALTER TABLE `parts` ADD INDEX `idx_parts_preferred_supplier` (`preferred_supplier_id`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Fremdschluessel parts.preferred_supplier_id -> suppliers.id NACHTRAEGLICH,
-- da suppliers erst durch diese Migration entsteht (Waechter-Muster wie
-- oben bei product_supplier_offers.source_import_job_id).
SET @fk_exists2 := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'parts'
    AND CONSTRAINT_NAME = 'fk_parts_preferred_supplier'
);
SET @stmt2 := IF(@fk_exists2 = 0,
  'ALTER TABLE `parts` ADD CONSTRAINT `fk_parts_preferred_supplier` FOREIGN KEY (`preferred_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt2 FROM @stmt2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- =====================================================================
-- Ende Phase-2a-Migration (Lieferanten-/Bestellmodul-Fundament)
-- =====================================================================
-- MZ Tech Reparatursystem - Phase 2b, abschliessende Beschaffungs-Verknuepfung
-- Additiv. Voraussetzung: Phase 2a sowie die bereits produktiv vorhandenen
-- Phase-2b-Tabellen companies, projects und tickets.

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items' AND INDEX_NAME='idx_poi_company');
SET @ddl := IF(@idx_exists=0, 'ALTER TABLE `purchase_order_items` ADD INDEX `idx_poi_company` (`assigned_company_id`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items' AND INDEX_NAME='idx_poi_project');
SET @ddl := IF(@idx_exists=0, 'ALTER TABLE `purchase_order_items` ADD INDEX `idx_poi_project` (`assigned_project_id`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items' AND INDEX_NAME='idx_poi_ticket');
SET @ddl := IF(@idx_exists=0, 'ALTER TABLE `purchase_order_items` ADD INDEX `idx_poi_ticket` (`assigned_ticket_id`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_poi_company');
SET @target_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies');
SET @ddl := IF(@fk_exists=0 AND @target_exists=1, 'ALTER TABLE `purchase_order_items` ADD CONSTRAINT `fk_poi_company` FOREIGN KEY (`assigned_company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_poi_project');
SET @target_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='projects');
SET @ddl := IF(@fk_exists=0 AND @target_exists=1, 'ALTER TABLE `purchase_order_items` ADD CONSTRAINT `fk_poi_project` FOREIGN KEY (`assigned_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_poi_ticket');
SET @target_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tickets');
SET @ddl := IF(@fk_exists=0 AND @target_exists=1, 'ALTER TABLE `purchase_order_items` ADD CONSTRAINT `fk_poi_ticket` FOREIGN KEY (`assigned_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Phase 2b: Beschaffungs-Verknuepfungen geprueft.' AS ergebnis;
-- MZ Tech Reparatursystem - vollstaendige defensive Produktionsmigration
-- Reihenfolge in dieser Datei: Phase 2a, Phase 2b-Verknuepfungen, Phase 7.
-- Vorher production_preflight.sql, danach production_postcheck.sql ausfuehren.
-- Keine DROP-, TRUNCATE- oder DELETE-Anweisungen.

-- =========================================================================
-- MZ Tech Reparatursystem - Phase 7 - Beschaffung/Buchhaltung fertigstellen
-- =========================================================================
-- Rein ADDITIV und IDEMPOTENT - kann mehrfach ausgefuehrt werden, ohne
-- Fehler oder doppelte Strukturen zu erzeugen. Nutzt fuer neue Tabellen
-- CREATE TABLE IF NOT EXISTS und fuer die neue Spalte denselben
-- INFORMATION_SCHEMA-gesteuerten PREPARE/EXECUTE-Ansatz wie bereits in
-- sql/2a_append_to_update.sql (keine DELIMITER-Aenderung noetig, funktioniert
-- identisch in phpMyAdmin, mysql-CLI und einfachen Statement-Splittern).
--
-- Beruecksichtigt ausdruecklich bereits vorhandene Phase-2a/2b-Strukturen:
-- veraendert oder loescht KEINE bestehende Tabelle/Spalte/Daten, ergaenzt
-- nur. Voraussetzung: sql/2a_append_to_update.sql wurde bereits eingespielt
-- (siehe sql/phase7_preflight_checks.sql, Abschnitt 1).
--
-- WICHTIG: erstellt KEINE neue invoices-/credit_notes-/companies-Tabelle -
-- das Buchhaltungsmodul (private/accounting.php) liest ausschliesslich aus
-- den bereits bestehenden Strukturen repairs.invoice_* und
-- invoice_corrections (siehe private/invoicing.php,
-- private/invoice_corrections.php). Diese Datei legt nur die Protokoll-/
-- Mapping-Tabellen fuer die EXTERNE Anbindung (lexoffice/sevDesk) an.
-- =========================================================================

-- -------------------------------------------------------------------------
-- 1. product_supplier_offers.packaging_unit -- NEU: Verpackungseinheit des
--    Lieferantenangebots (z. B. "10er-Pack"), siehe private/products.php
--    import_upsert_offer() und private/import_engine.php import_target_
--    fields(). Auf product_supplier_offers, NICHT auf parts - bewusst vom
--    bereits vorhandenen, artikelbezogenen parts.packaging_unit getrennt.
-- -------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_supplier_offers' AND COLUMN_NAME = 'packaging_unit'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `product_supplier_offers` ADD COLUMN `packaging_unit` VARCHAR(50) DEFAULT NULL AFTER `minimum_order_quantity`',
    'SELECT ''product_supplier_offers.packaging_unit existiert bereits - uebersprungen.'' AS hinweis'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Transport-, Datei- und Authentifizierungsarten an die Adapter/UI angleichen.
-- MODIFY ist datenbewahrend, da alle bisherigen ENUM-Werte enthalten bleiben.
ALTER TABLE `supplier_interface_profiles`
  MODIFY `interface_type` ENUM(
    'rest_api','graphql_api','http_download','ftp','ftps','sftp',
    'soap_api','edi','ugl_ugs','manual_upload','url_fetch',
    'csv','tsv','txt','xls','xlsx','xml','json','zip'
  ) NOT NULL DEFAULT 'manual_upload',
  MODIFY `auth_type` ENUM(
    'none','basic','bearer_token','api_key','oauth2','custom'
  ) NOT NULL DEFAULT 'none',
  MODIFY `format` ENUM('csv','tsv','txt','xls','xlsx','xml','json','zip') DEFAULT NULL;

-- -------------------------------------------------------------------------
-- 2. purchase_order_receipts -- NEU: Wareneingangsbuchungen je Bestell-
--    position (Lieferscheinnummer/-datum/Notiz), siehe
--    purchase_order_item_receive()/purchase_order_receipts_for_item() in
--    private/purchase_orders.php und public/purchase_order_receive.php.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_order_receipts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_item_id` INT UNSIGNED NOT NULL,
  `quantity_delta` INT NOT NULL,
  `delivery_note_number` VARCHAR(100) DEFAULT NULL,
  `received_date` DATE DEFAULT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  `received_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_por_item` (`purchase_order_item_id`),
  KEY `idx_por_received_by` (`received_by`),
  CONSTRAINT `fk_por_item` FOREIGN KEY (`purchase_order_item_id`)
    REFERENCES `purchase_order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_por_user` FOREIGN KEY (`received_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 3. accounting_contact_mapping -- NEU: welcher Kunde/Lieferant entspricht
--    welchem externen Kontakt bei lexoffice/sevDesk, siehe
--    accounting_contact_mapping_get()/_set() in private/accounting.php.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_contact_mapping` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(20) NOT NULL,
  `entity_type` VARCHAR(20) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `external_id` VARCHAR(100) NOT NULL,
  `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acm_provider_entity` (`provider`, `entity_type`, `entity_id`),
  KEY `idx_acm_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 4. accounting_document_sync -- NEU: Uebertragungsprotokoll je Beleg
--    (Rechnung/Gutschrift/Eingangsbeleg) mit Dublettenschutz (UNIQUE),
--    siehe accounting_document_sync_record()/_already_synced() in
--    private/accounting.php.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_document_sync` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(20) NOT NULL,
  `document_type` VARCHAR(30) NOT NULL,
  `reference_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `external_id` VARCHAR(100) DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ads_provider_type_ref` (`provider`, `document_type`, `reference_id`),
  KEY `idx_ads_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Hinweis zu Berechtigungen: die neue Berechtigung 'manage_accounting'
-- (private/permissions.php, PERMISSION_DEFINITIONS) wird beim naechsten
-- Aufruf von permissions_all()/permissions_sync_definitions() automatisch
-- additiv in die bestehende Tabelle `permissions` eingetragen - dafuer ist
-- KEIN eigenes SQL-Statement noetig (identisches Verhalten wie bei jeder
-- fruehreren neuen Berechtigung in diesem System).
-- -------------------------------------------------------------------------

SELECT 'Phase 7 (Beschaffung/Buchhaltung): Struktur-Update abgeschlossen.' AS ergebnis;
