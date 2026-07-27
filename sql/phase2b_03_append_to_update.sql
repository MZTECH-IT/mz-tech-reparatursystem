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
