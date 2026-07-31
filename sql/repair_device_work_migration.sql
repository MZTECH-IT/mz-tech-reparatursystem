-- MZ Tech: additive, defensive Migration für Gerätearten und Arbeitsleistung.
-- Voraussetzung: erfolgreicher repair_device_work_preflight.sql und vollständiges Backup.
SET NAMES utf8mb4;
SET @mz_schema := DATABASE();

CREATE TABLE IF NOT EXISTS device_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  technical_key VARCHAR(80) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  category VARCHAR(100) DEFAULT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_device_types_key (technical_key),
  KEY idx_device_types_active_sort (is_active, sort_order, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO device_types (technical_key, display_name, category, sort_order, is_active) VALUES
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
ON DUPLICATE KEY UPDATE technical_key = VALUES(technical_key);

ALTER TABLE repairs
  ADD COLUMN IF NOT EXISTS device_type_id INT UNSIGNED DEFAULT NULL AFTER device_type,
  ADD COLUMN IF NOT EXISTS device_type_legacy_value VARCHAR(100) DEFAULT NULL AFTER device_type_id,
  ADD COLUMN IF NOT EXISTS working_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER price,
  ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 79.00 AFTER working_hours,
  ADD COLUMN IF NOT EXISTS labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER hourly_rate,
  ADD COLUMN IF NOT EXISTS performed_work TEXT DEFAULT NULL AFTER internal_notes,
  ADD INDEX IF NOT EXISTS idx_repairs_device_type_id (device_type_id);

UPDATE repairs
SET device_type_legacy_value = device_type
WHERE device_type_legacy_value IS NULL AND device_type IS NOT NULL AND TRIM(device_type) <> '';

UPDATE repairs r
JOIN device_types d
  ON LOWER(d.technical_key) = LOWER(r.device_type)
  OR LOWER(d.display_name) = LOWER(r.device_type)
  OR (d.technical_key = 'pc' AND LOWER(r.device_type) IN ('pc/desktop','desktop'))
  OR (d.technical_key = 'spielkonsole' AND LOWER(r.device_type) IN ('konsole','spielekonsole'))
  OR (d.technical_key = 'firmenhardware' AND LOWER(r.device_type) IN ('firmen-it','firmen_it'))
SET r.device_type_id = d.id
WHERE r.device_type_id IS NULL;

UPDATE repairs r
JOIN device_types d ON d.technical_key = 'sonstiges'
SET r.device_type_id = d.id
WHERE r.device_type_id IS NULL;

SET @fk_device_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @mz_schema AND CONSTRAINT_NAME = 'fk_repairs_device_type'
);
SET @ddl := IF(
  @fk_device_exists = 0,
  'ALTER TABLE repairs ADD CONSTRAINT fk_repairs_device_type FOREIGN KEY (device_type_id) REFERENCES device_types(id) ON DELETE SET NULL',
  'DO 1'
);
PREPARE mz_stmt FROM @ddl;
EXECUTE mz_stmt;
DEALLOCATE PREPARE mz_stmt;

INSERT INTO settings (setting_key, setting_value)
VALUES ('default_hourly_rate', '79.00')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

SELECT 'REPAIR_DEVICE_WORK_MIGRATION_COMPLETE' AS result, DATABASE() AS active_schema;
