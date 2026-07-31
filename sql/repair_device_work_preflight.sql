-- MZ Tech: rein lesender Preflight für Gerätearten, Arbeitszeit und Leistungsbeschreibung.
SELECT DATABASE() AS active_schema,
       CASE WHEN DATABASE() IS NULL OR DATABASE() = '' THEN 'FEHLER' ELSE 'OK' END AS status;

SELECT expected.table_name,
       CASE WHEN t.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
    SELECT 'repairs' AS table_name
    UNION ALL SELECT 'settings'
    UNION ALL SELECT 'users'
    UNION ALL SELECT 'customers'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

SELECT expected.table_name, expected.column_name,
       CASE WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status,
       c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT
FROM (
    SELECT 'repairs' AS table_name, 'id' AS column_name
    UNION ALL SELECT 'repairs', 'device_type'
    UNION ALL SELECT 'repairs', 'price'
    UNION ALL SELECT 'repairs', 'advance_payment'
    UNION ALL SELECT 'repairs', 'internal_notes'
    UNION ALL SELECT 'settings', 'setting_key'
    UNION ALL SELECT 'settings', 'setting_value'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = expected.table_name
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name;

SELECT COUNT(*) AS existing_repairs,
       SUM(CASE WHEN device_type IS NULL OR TRIM(device_type) = '' THEN 1 ELSE 0 END) AS empty_device_types
FROM repairs;

SELECT device_type, COUNT(*) AS usage_count
FROM repairs
GROUP BY device_type
ORDER BY usage_count DESC, device_type;

SELECT expected.table_name, expected.column_name,
       CASE
         WHEN t.TABLE_NAME IS NULL THEN 'NEU'
         WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT'
         WHEN LOWER(c.COLUMN_TYPE) <> expected.column_type THEN 'FEHLER'
         ELSE 'OK'
       END AS status,
       c.COLUMN_TYPE
FROM (
    SELECT 'device_types' AS table_name, 'id' AS column_name, 'int(10) unsigned' AS column_type
    UNION ALL SELECT 'device_types', 'technical_key', 'varchar(80)'
    UNION ALL SELECT 'device_types', 'display_name', 'varchar(100)'
    UNION ALL SELECT 'device_types', 'sort_order', 'smallint(5) unsigned'
    UNION ALL SELECT 'device_types', 'is_active', 'tinyint(1)'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = expected.table_name
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = expected.table_name
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.column_name;

SELECT expected.column_name,
       CASE
         WHEN c.COLUMN_NAME IS NULL THEN 'NEU'
         WHEN LOWER(c.COLUMN_TYPE) <> expected.column_type THEN 'FEHLER'
         ELSE 'OK'
       END AS status,
       c.COLUMN_TYPE
FROM (
    SELECT 'device_type_id' AS column_name, 'int(10) unsigned' AS column_type
    UNION ALL SELECT 'device_type_legacy_value', 'varchar(100)'
    UNION ALL SELECT 'working_hours', 'decimal(8,2)'
    UNION ALL SELECT 'hourly_rate', 'decimal(10,2)'
    UNION ALL SELECT 'labor_cost', 'decimal(12,2)'
    UNION ALL SELECT 'performed_work', 'text'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'repairs'
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.column_name;
