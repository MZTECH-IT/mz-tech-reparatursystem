-- MZ Tech Foneday-Integration: additiv, defensiv, ohne Löschen bestehender Daten.
-- Voraussetzung: erfolgreiche Ausführung von foneday_preflight.sql.
SET NAMES utf8mb4;
SET @foneday_schema := DATABASE();

SELECT @foneday_schema AS migration_context,
       CASE WHEN @foneday_schema IS NULL OR @foneday_schema = ''
            THEN 'FEHLER'
            ELSE 'OK' END AS context_status;

ALTER TABLE `parts`
  ADD COLUMN IF NOT EXISTS `selling_price_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `selling_price`,
  ADD COLUMN IF NOT EXISTS `price_source` VARCHAR(50) DEFAULT NULL AFTER `selling_price_locked`;

ALTER TABLE `product_supplier_offers`
  ADD COLUMN IF NOT EXISTS `external_product_id` VARCHAR(100) DEFAULT NULL AFTER `supplier_sku`,
  ADD COLUMN IF NOT EXISTS `external_artcode` VARCHAR(100) DEFAULT NULL AFTER `mpn`,
  ADD COLUMN IF NOT EXISTS `quality` VARCHAR(100) DEFAULT NULL AFTER `external_artcode`,
  ADD COLUMN IF NOT EXISTS `supplier_category` VARCHAR(190) DEFAULT NULL AFTER `quality`,
  ADD COLUMN IF NOT EXISTS `product_brand` VARCHAR(120) DEFAULT NULL AFTER `supplier_category`,
  ADD COLUMN IF NOT EXISTS `model_brand` VARCHAR(120) DEFAULT NULL AFTER `product_brand`,
  ADD COLUMN IF NOT EXISTS `model_codes_json` LONGTEXT DEFAULT NULL AFTER `model_brand`,
  ADD COLUMN IF NOT EXISTS `suitable_for_json` LONGTEXT DEFAULT NULL AFTER `model_codes_json`,
  ADD COLUMN IF NOT EXISTS `purchase_price_net` DECIMAL(12,2) DEFAULT NULL AFTER `purchase_price`,
  ADD COLUMN IF NOT EXISTS `selling_price_net` DECIMAL(12,2) DEFAULT NULL AFTER `purchase_price_net`,
  ADD COLUMN IF NOT EXISTS `selling_price_gross` DECIMAL(12,2) DEFAULT NULL AFTER `selling_price_net`,
  ADD COLUMN IF NOT EXISTS `price_source` VARCHAR(50) DEFAULT NULL AFTER `is_net_price`,
  ADD COLUMN IF NOT EXISTS `source_fingerprint` CHAR(64) DEFAULT NULL AFTER `price_source`,
  ADD COLUMN IF NOT EXISTS `last_price_check_at` DATETIME DEFAULT NULL AFTER `last_seen_at`,
  ADD COLUMN IF NOT EXISTS `last_stock_check_at` DATETIME DEFAULT NULL AFTER `last_price_check_at`,
  ADD COLUMN IF NOT EXISTS `last_full_sync_at` DATETIME DEFAULT NULL AFTER `last_stock_check_at`,
  ADD COLUMN IF NOT EXISTS `missing_successful_runs` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_full_sync_at`,
  ADD COLUMN IF NOT EXISTS `last_foneday_sync_run_id` BIGINT UNSIGNED DEFAULT NULL AFTER `missing_successful_runs`,
  ADD INDEX IF NOT EXISTS `idx_pso_external_product` (`supplier_id`,`external_product_id`),
  ADD INDEX IF NOT EXISTS `idx_pso_artcode` (`supplier_id`,`external_artcode`),
  ADD INDEX IF NOT EXISTS `idx_pso_foneday_sync` (`supplier_id`,`last_foneday_sync_run_id`),
  ADD INDEX IF NOT EXISTS `idx_pso_supplier_sku` (`supplier_id`,`supplier_sku`);

CREATE TABLE IF NOT EXISTS `foneday_sync_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `run_mode` ENUM('connection_test','dry_run','full_import','price_stock') NOT NULL,
  `status` ENUM('running','success','partial','failed') NOT NULL DEFAULT 'running',
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME DEFAULT NULL,
  `products_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `products_created` INT UNSIGNED NOT NULL DEFAULT 0,
  `products_updated` INT UNSIGNED NOT NULL DEFAULT 0,
  `products_unchanged` INT UNSIGNED NOT NULL DEFAULT 0,
  `products_unavailable` INT UNSIGNED NOT NULL DEFAULT 0,
  `queue_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `pages_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `http_status` SMALLINT UNSIGNED DEFAULT NULL,
  `error_code` VARCHAR(80) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_foneday_runs_supplier_started` (`supplier_id`,`started_at`),
  KEY `idx_foneday_runs_status` (`status`),
  CONSTRAINT `fk_foneday_runs_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_foneday_runs_user` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `foneday_import_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `external_product_id` VARCHAR(100) DEFAULT NULL,
  `supplier_sku` VARCHAR(100) DEFAULT NULL,
  `ean` VARCHAR(20) DEFAULT NULL,
  `artcode` VARCHAR(100) DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `reason_code` VARCHAR(100) NOT NULL,
  `payload_hash` CHAR(64) NOT NULL,
  `payload_json` LONGTEXT NOT NULL,
  `status` ENUM('offen','zugeordnet','ignoriert') NOT NULL DEFAULT 'offen',
  `matched_part_id` INT UNSIGNED DEFAULT NULL,
  `resolved_by` INT UNSIGNED DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_foneday_queue_supplier_hash` (`supplier_id`,`payload_hash`),
  KEY `idx_foneday_queue_status` (`status`,`updated_at`),
  KEY `idx_foneday_queue_sku` (`supplier_id`,`supplier_sku`),
  CONSTRAINT `fk_foneday_queue_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_foneday_queue_part` FOREIGN KEY (`matched_part_id`)
    REFERENCES `parts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_foneday_queue_user` FOREIGN KEY (`resolved_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `foneday_price_conflicts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_id` INT UNSIGNED NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `proposed_selling_price_net` DECIMAL(12,2) NOT NULL,
  `reason` VARCHAR(80) NOT NULL,
  `sync_run_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_foneday_conflict_part` (`part_id`,`created_at`),
  CONSTRAINT `fk_foneday_conflict_part` FOREIGN KEY (`part_id`)
    REFERENCES `parts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_foneday_conflict_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_foneday_conflict_run` FOREIGN KEY (`sync_run_id`)
    REFERENCES `foneday_sync_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `foneday_sync_lock` (
  `lock_name` VARCHAR(50) NOT NULL,
  `lock_token_hash` CHAR(64) NOT NULL,
  `acquired_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`lock_name`),
  KEY `idx_foneday_lock_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'FONEDAY_MIGRATION_COMPLETE' AS result,
       @foneday_schema AS active_schema;
