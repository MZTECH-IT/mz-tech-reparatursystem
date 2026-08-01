-- Additive, defensive Migration. Erst nach erfolgreichem Preflight und Backup ausführen.
SET NAMES utf8mb4;
SET @mz_schema := DATABASE();

ALTER TABLE parts
  ADD COLUMN IF NOT EXISTS markup_percent DECIMAL(7,2) NOT NULL DEFAULT 10.00 AFTER purchase_price,
  ADD COLUMN IF NOT EXISTS automatic_selling_price DECIMAL(10,2) DEFAULT NULL AFTER markup_percent,
  ADD COLUMN IF NOT EXISTS selling_price_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER selling_price,
  ADD COLUMN IF NOT EXISTS selling_price_changed_by INT UNSIGNED DEFAULT NULL AFTER selling_price_manual,
  ADD COLUMN IF NOT EXISTS selling_price_changed_at DATETIME DEFAULT NULL AFTER selling_price_changed_by;

UPDATE parts SET markup_percent = 10.00 WHERE markup_percent IS NULL OR markup_percent < 0;
UPDATE parts
SET automatic_selling_price = ROUND(COALESCE(purchase_price,0) * (1 + markup_percent / 100), 2)
WHERE automatic_selling_price IS NULL AND purchase_price IS NOT NULL;
UPDATE parts SET selling_price = automatic_selling_price
WHERE selling_price IS NULL AND automatic_selling_price IS NOT NULL;

ALTER TABLE repair_parts
  ADD COLUMN IF NOT EXISTS markup_percent_at_time DECIMAL(7,2) NOT NULL DEFAULT 10.00 AFTER purchase_price_at_time,
  ADD COLUMN IF NOT EXISTS automatic_selling_price_at_time DECIMAL(10,2) DEFAULT NULL AFTER markup_percent_at_time,
  ADD COLUMN IF NOT EXISTS selling_price_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER selling_price_at_time,
  ADD COLUMN IF NOT EXISTS customer_description TEXT DEFAULT NULL AFTER selling_price_manual,
  ADD COLUMN IF NOT EXISTS serial_number VARCHAR(120) DEFAULT NULL AFTER customer_description,
  ADD COLUMN IF NOT EXISTS warranty_note VARCHAR(255) DEFAULT NULL AFTER serial_number,
  ADD COLUMN IF NOT EXISTS internal_note TEXT DEFAULT NULL AFTER warranty_note,
  ADD COLUMN IF NOT EXISTS part_status VARCHAR(30) NOT NULL DEFAULT 'geplant' AFTER internal_note;

ALTER TABLE repairs
  ADD COLUMN IF NOT EXISTS service_date DATE DEFAULT NULL AFTER advance_payment,
  ADD COLUMN IF NOT EXISTS payment_due_date DATE DEFAULT NULL AFTER service_date,
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(30) NOT NULL DEFAULT 'offen' AFTER payment_due_date,
  ADD COLUMN IF NOT EXISTS quote_source_id INT UNSIGNED DEFAULT NULL AFTER payment_status,
  ADD COLUMN IF NOT EXISTS quote_number VARCHAR(40) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS quote_status VARCHAR(35) NOT NULL DEFAULT 'entwurf',
  ADD COLUMN IF NOT EXISTS quote_snapshot LONGTEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS quote_released_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS quote_released_by INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(40) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS invoice_status VARCHAR(35) NOT NULL DEFAULT 'entwurf',
  ADD COLUMN IF NOT EXISTS invoice_snapshot LONGTEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS invoice_released_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS invoice_released_by INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS invoice_correction_status VARCHAR(30) DEFAULT NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_repairs_invoice_number (invoice_number),
  ADD UNIQUE INDEX IF NOT EXISTS uq_repairs_quote_number (quote_number),
  ADD INDEX IF NOT EXISTS idx_repairs_payment_status (payment_status),
  ADD INDEX IF NOT EXISTS idx_repairs_quote_source (quote_source_id);

CREATE TABLE IF NOT EXISTS number_ranges (
  doc_type VARCHAR(10) NOT NULL, label VARCHAR(100) NOT NULL, prefix VARCHAR(20) NOT NULL,
  `separator` VARCHAR(2) NOT NULL DEFAULT '-', digits TINYINT UNSIGNED NOT NULL DEFAULT 6,
  yearly_reset TINYINT(1) NOT NULL DEFAULT 1, start_number INT UNSIGNED NOT NULL DEFAULT 1,
  current_year SMALLINT UNSIGNED DEFAULT NULL, current_number INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO number_ranges (doc_type,label,prefix,`separator`,digits,yearly_reset,start_number,current_number,active) VALUES
('ANG','Angebote','ANG','-',4,1,1,0,1),('RE','Rechnungen','RE','-',4,1,1,0,1),
('GS','Gutschriften','GS','-',4,1,1,0,1),('STO','Stornorechnungen','STO','-',4,1,1,0,1),
('KV','Kostenvoranschläge','KV','-',4,1,1,0,1)
ON DUPLICATE KEY UPDATE doc_type=VALUES(doc_type);

CREATE TABLE IF NOT EXISTS quotes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, quote_number VARCHAR(40) DEFAULT NULL,
  status VARCHAR(35) NOT NULL DEFAULT 'entwurf', version_no INT UNSIGNED NOT NULL DEFAULT 1,
  repair_id INT UNSIGNED DEFAULT NULL, customer_id INT UNSIGNED DEFAULT NULL, company_id INT UNSIGNED DEFAULT NULL,
  title VARCHAR(255) DEFAULT NULL, notes TEXT DEFAULT NULL, planned_work TEXT DEFAULT NULL, internal_notes TEXT DEFAULT NULL,
  valid_until DATE DEFAULT NULL, currency CHAR(3) NOT NULL DEFAULT 'EUR',
  billing_mode VARCHAR(40) NOT NULL DEFAULT 'small_business_19_ustg', tax_rate DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  legal_notice TEXT DEFAULT NULL, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  snapshot_json LONGTEXT DEFAULT NULL, released_by INT UNSIGNED DEFAULT NULL, released_at DATETIME DEFAULT NULL,
  converted_to_repair_id INT UNSIGNED DEFAULT NULL, converted_to_invoice_repair_id INT UNSIGNED DEFAULT NULL,
  converted_at DATETIME DEFAULT NULL, converted_by INT UNSIGNED DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_quotes_number (quote_number), KEY idx_quotes_status (status),
  KEY idx_quotes_customer (customer_id), KEY idx_quotes_company (company_id), KEY idx_quotes_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE quotes
  MODIFY COLUMN status VARCHAR(35) NOT NULL DEFAULT 'entwurf',
  ADD COLUMN IF NOT EXISTS version_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN IF NOT EXISTS planned_work TEXT DEFAULT NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS internal_notes TEXT DEFAULT NULL AFTER planned_work,
  ADD COLUMN IF NOT EXISTS billing_mode VARCHAR(40) NOT NULL DEFAULT 'small_business_19_ustg' AFTER currency,
  ADD COLUMN IF NOT EXISTS legal_notice TEXT DEFAULT NULL AFTER tax_rate,
  ADD COLUMN IF NOT EXISTS converted_to_invoice_repair_id INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS converted_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS converted_by INT UNSIGNED DEFAULT NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_quotes_number (quote_number),
  ADD INDEX IF NOT EXISTS idx_quotes_status (status),
  ADD INDEX IF NOT EXISTS idx_quotes_customer (customer_id),
  ADD INDEX IF NOT EXISTS idx_quotes_company (company_id),
  ADD INDEX IF NOT EXISTS idx_quotes_repair (repair_id);

UPDATE quotes SET billing_mode='small_business_19_ustg', tax_rate=0.00, tax_amount=0.00, total=subtotal,
 legal_notice='Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG. Es wird keine Umsatzsteuer berechnet.'
WHERE snapshot_json IS NULL;

CREATE TABLE IF NOT EXISTS quote_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id INT UNSIGNED NOT NULL,
  item_type VARCHAR(20) NOT NULL DEFAULT 'service', part_id INT UNSIGNED DEFAULT NULL,
  description TEXT NOT NULL, quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), KEY idx_quote_items_quote (quote_id), KEY idx_quote_items_part (part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS item_type VARCHAR(20) NOT NULL DEFAULT 'service' AFTER quote_id;
ALTER TABLE quote_items MODIFY COLUMN quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00;
ALTER TABLE quote_items
  ADD INDEX IF NOT EXISTS idx_quote_items_quote (quote_id),
  ADD INDEX IF NOT EXISTS idx_quote_items_part (part_id);

CREATE TABLE IF NOT EXISTS quote_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, doc_type VARCHAR(20) NOT NULL, repair_id INT UNSIGNED DEFAULT NULL,
  quote_id INT UNSIGNED DEFAULT NULL, decision VARCHAR(20) NOT NULL, decided_by_type VARCHAR(30) NOT NULL,
  decided_by_ref INT UNSIGNED DEFAULT NULL, comment TEXT DEFAULT NULL, document_version VARCHAR(50) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL, decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_quote_decisions_quote (quote_id), KEY idx_quote_decisions_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_conversion_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id INT UNSIGNED NOT NULL, repair_id INT UNSIGNED NOT NULL,
  selected_items_json LONGTEXT NOT NULL, created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_conversion_quote (quote_id), KEY idx_invoice_conversion_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_corrections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, correction_type VARCHAR(20) NOT NULL, repair_id INT UNSIGNED NOT NULL,
  correction_number VARCHAR(40) DEFAULT NULL, amount DECIMAL(12,2) NOT NULL, reason TEXT DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'entwurf', snapshot_json LONGTEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL, released_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, released_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_invoice_corrections_number (correction_number), KEY idx_invoice_corrections_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, repair_id INT UNSIGNED NOT NULL, payment_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL, payment_method VARCHAR(40) NOT NULL, reference VARCHAR(255) DEFAULT NULL,
  internal_note TEXT DEFAULT NULL, is_deposit TINYINT(1) NOT NULL DEFAULT 0, created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id),
  KEY idx_payments_repair_date (repair_id,payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@mz_schema AND CONSTRAINT_NAME='fk_payments_repair');
SET @orphan_count := (SELECT COUNT(*) FROM payments p LEFT JOIN repairs r ON r.id=p.repair_id WHERE r.id IS NULL);
SET @ddl := IF(@fk_exists=0 AND @orphan_count=0,'ALTER TABLE payments ADD CONSTRAINT fk_payments_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE RESTRICT','DO 1');
PREPARE mz_stmt FROM @ddl; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;

SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@mz_schema AND CONSTRAINT_NAME='fk_invoice_conversion_quote');
SET @orphan_count := (SELECT COUNT(*) FROM invoice_conversion_history h LEFT JOIN quotes q ON q.id=h.quote_id WHERE q.id IS NULL);
SET @ddl := IF(@fk_exists=0 AND @orphan_count=0,'ALTER TABLE invoice_conversion_history ADD CONSTRAINT fk_invoice_conversion_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT','DO 1');
PREPARE mz_stmt FROM @ddl; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;

SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@mz_schema AND CONSTRAINT_NAME='fk_invoice_conversion_repair');
SET @orphan_count := (SELECT COUNT(*) FROM invoice_conversion_history h LEFT JOIN repairs r ON r.id=h.repair_id WHERE r.id IS NULL);
SET @ddl := IF(@fk_exists=0 AND @orphan_count=0,'ALTER TABLE invoice_conversion_history ADD CONSTRAINT fk_invoice_conversion_repair FOREIGN KEY (repair_id) REFERENCES repairs(id) ON DELETE RESTRICT','DO 1');
PREPARE mz_stmt FROM @ddl; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;

INSERT INTO settings (setting_key,setting_value) VALUES
('billing_mode','small_business_19_ustg'),('tax_rate','0.00'),
('ustg_notice_text','Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG. Es wird keine Umsatzsteuer berechnet.')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

SELECT 'REPAIR_DEVICE_WORK_MIGRATION_COMPLETE' AS result, DATABASE() AS active_schema;
