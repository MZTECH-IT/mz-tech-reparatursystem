-- Nur lesender Preflight für Abrechnung, Angebote, Ersatzteile und Zahlungen.
-- Keine DDL-/DML-Anweisungen. In der aktiven Anwendungsdatenbank ausführen.
SELECT 'P00_DATABASE_CONTEXT' AS check_id, DATABASE() AS active_schema,
       IF(DATABASE() IS NULL OR DATABASE()='','FEHLER','OK') AS status,
       @@version AS database_version, @@version_comment AS database_product;

SELECT 'P01_REQUIRED_TABLES' AS check_id, e.table_name,
       IF(t.TABLE_NAME IS NULL, 'FEHLT', 'OK') AS status
FROM (
  SELECT 'settings' table_name UNION ALL SELECT 'users' UNION ALL SELECT 'customers'
  UNION ALL SELECT 'repairs' UNION ALL SELECT 'parts' UNION ALL SELECT 'repair_parts'
) e
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = e.table_name
ORDER BY e.table_name;

SELECT 'P02_REQUIRED_COLUMNS' AS check_id, e.table_name, e.column_name,
       IF(c.COLUMN_NAME IS NULL, 'FEHLT', 'OK') AS status,
       c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT
FROM (
  SELECT 'repairs' table_name, 'id' column_name UNION ALL SELECT 'repairs','customer_id'
  UNION ALL SELECT 'repairs','advance_payment' UNION ALL SELECT 'repairs','working_hours'
  UNION ALL SELECT 'repairs','hourly_rate' UNION ALL SELECT 'repairs','labor_cost'
  UNION ALL SELECT 'repairs','performed_work' UNION ALL SELECT 'repairs','internal_notes'
  UNION ALL SELECT 'parts','purchase_price' UNION ALL SELECT 'parts','selling_price'
  UNION ALL SELECT 'repair_parts','repair_id' UNION ALL SELECT 'repair_parts','part_id'
  UNION ALL SELECT 'repair_parts','selling_price_at_time'
) e
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = e.table_name AND c.COLUMN_NAME = e.column_name
ORDER BY e.table_name, e.column_name;

SELECT 'P03_DATA_SAFETY' AS check_id,
       (SELECT COUNT(*) FROM repairs) AS existing_repairs,
       (SELECT COUNT(*) FROM customers) AS existing_customers,
       (SELECT COUNT(*) FROM repair_parts) AS existing_repair_parts,
       (SELECT COUNT(*) FROM repairs WHERE advance_payment IS NULL) AS null_advance_payments,
       (SELECT COUNT(*) FROM repairs WHERE working_hours < 0 OR hourly_rate < 0 OR labor_cost < 0) AS invalid_labor_values;

SELECT 'PREFLIGHT_COMPLETE_READ_ONLY' AS result;
