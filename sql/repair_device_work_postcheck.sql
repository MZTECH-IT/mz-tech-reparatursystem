-- MZ Tech: rein lesender Postcheck.
SELECT DATABASE() AS active_schema,
       CASE WHEN DATABASE() IS NULL OR DATABASE() = '' THEN 'FEHLER' ELSE 'OK' END AS status;

SELECT expected.table_name,
       CASE WHEN t.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
    SELECT 'device_types' AS table_name
    UNION ALL SELECT 'repairs'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = expected.table_name;

SELECT expected.column_name,
       CASE
         WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT'
         WHEN LOWER(c.COLUMN_TYPE) <> expected.column_type THEN 'FEHLER'
         WHEN c.IS_NULLABLE <> expected.is_nullable THEN 'FEHLER'
         WHEN expected.column_default IS NOT NULL
              AND COALESCE(c.COLUMN_DEFAULT, '') <> expected.column_default THEN 'FEHLER'
         ELSE 'OK'
       END AS status,
       c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT
FROM (
    SELECT 'device_type_id' AS column_name, 'int(10) unsigned' AS column_type, 'YES' AS is_nullable, NULL AS column_default
    UNION ALL SELECT 'device_type_legacy_value', 'varchar(100)', 'YES', NULL
    UNION ALL SELECT 'working_hours', 'decimal(8,2)', 'NO', '0.00'
    UNION ALL SELECT 'hourly_rate', 'decimal(10,2)', 'NO', '79.00'
    UNION ALL SELECT 'labor_cost', 'decimal(12,2)', 'NO', '0.00'
    UNION ALL SELECT 'performed_work', 'text', 'YES', NULL
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'repairs'
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.column_name;

SELECT COUNT(*) AS active_device_types,
       SUM(technical_key = 'sonstiges') AS sonstiges_records,
       CASE WHEN COUNT(*) >= 12 AND SUM(technical_key = 'sonstiges') = 1 THEN 'OK' ELSE 'FEHLER' END AS status
FROM device_types
WHERE is_active = 1;

SELECT COUNT(*) AS repairs_without_device_type_id,
       CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'FEHLER' END AS status
FROM repairs
WHERE device_type_id IS NULL;

SELECT COUNT(*) AS invalid_negative_labor_values,
       CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'FEHLER' END AS status
FROM repairs
WHERE working_hours < 0 OR hourly_rate < 0 OR labor_cost < 0;

SELECT CASE WHEN setting_value IS NULL THEN 'FEHLT' ELSE 'OK' END AS status,
       setting_value
FROM (SELECT 1) x
LEFT JOIN settings s ON s.setting_key = 'default_hourly_rate';

SELECT 'REPAIR_DEVICE_WORK_POSTCHECK_COMPLETE' AS result;
