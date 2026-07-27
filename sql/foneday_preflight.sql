-- Rein lesender Foneday-Preflight. Keine Tabellen, Spalten oder Daten werden verändert.
SET @foneday_schema := DATABASE();

SELECT @foneday_schema AS active_schema,
       CASE WHEN @foneday_schema IS NULL OR @foneday_schema = ''
            THEN 'FEHLER'
            ELSE 'OK' END AS context_status;

SELECT expected.TABLE_NAME,
       CASE WHEN actual.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status
FROM (
  SELECT 'users' TABLE_NAME
  UNION ALL SELECT 'suppliers'
  UNION ALL SELECT 'parts'
  UNION ALL SELECT 'product_supplier_offers'
  UNION ALL SELECT 'product_price_history'
  UNION ALL SELECT 'product_availability_history'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES actual
  ON actual.TABLE_SCHEMA = @foneday_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
ORDER BY expected.TABLE_NAME;

SELECT expected.TABLE_NAME, expected.COLUMN_NAME,
       CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'VORHANDEN' END AS status,
       actual.COLUMN_TYPE, actual.IS_NULLABLE
FROM (
  SELECT 'suppliers' TABLE_NAME, 'id' COLUMN_NAME
  UNION ALL SELECT 'suppliers','name'
  UNION ALL SELECT 'suppliers','status'
  UNION ALL SELECT 'parts','id'
  UNION ALL SELECT 'parts','sku'
  UNION ALL SELECT 'parts','ean'
  UNION ALL SELECT 'parts','name'
  UNION ALL SELECT 'parts','category'
  UNION ALL SELECT 'parts','manufacturer'
  UNION ALL SELECT 'parts','brand'
  UNION ALL SELECT 'parts','model_compatibility'
  UNION ALL SELECT 'parts','purchase_price'
  UNION ALL SELECT 'parts','selling_price'
  UNION ALL SELECT 'parts','last_price_check_at'
  UNION ALL SELECT 'product_supplier_offers','id'
  UNION ALL SELECT 'product_supplier_offers','part_id'
  UNION ALL SELECT 'product_supplier_offers','supplier_id'
  UNION ALL SELECT 'product_supplier_offers','supplier_sku'
  UNION ALL SELECT 'product_supplier_offers','ean'
  UNION ALL SELECT 'product_supplier_offers','purchase_price'
  UNION ALL SELECT 'product_supplier_offers','availability'
  UNION ALL SELECT 'product_supplier_offers','last_seen_at'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS actual
  ON actual.TABLE_SCHEMA = @foneday_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT COUNT(*) AS foneday_supplier_count
FROM suppliers
WHERE LOWER(TRIM(name)) = LOWER('Foneday');

SELECT supplier_id, supplier_sku, COUNT(*) AS duplicate_count
FROM product_supplier_offers
WHERE supplier_sku IS NOT NULL
  AND supplier_sku <> ''
  AND supplier_id IN (
    SELECT id FROM suppliers WHERE LOWER(TRIM(name)) = LOWER('Foneday')
  )
GROUP BY supplier_id, supplier_sku
HAVING COUNT(*) > 1;

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = @foneday_schema
  AND TABLE_NAME IN (
    'suppliers','parts','product_supplier_offers',
    'product_price_history','product_availability_history'
  )
  AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%');

SELECT 'FONEDAY_PREFLIGHT_READ_ONLY_COMPLETE' AS result,
       @foneday_schema AS checked_schema;
