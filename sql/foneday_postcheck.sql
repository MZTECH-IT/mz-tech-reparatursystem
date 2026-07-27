-- Rein lesender Postcheck nach foneday_migration.sql.
SET @foneday_schema := DATABASE();

SELECT @foneday_schema AS active_schema,
       CASE WHEN @foneday_schema IS NULL OR @foneday_schema = ''
            THEN 'FEHLER'
            ELSE 'OK' END AS context_status;

SELECT expected.TABLE_NAME,
       CASE WHEN actual.TABLE_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'foneday_sync_runs' TABLE_NAME
  UNION ALL SELECT 'foneday_import_queue'
  UNION ALL SELECT 'foneday_price_conflicts'
  UNION ALL SELECT 'foneday_sync_lock'
) expected
LEFT JOIN INFORMATION_SCHEMA.TABLES actual
  ON actual.TABLE_SCHEMA = @foneday_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
ORDER BY expected.TABLE_NAME;

SELECT expected.TABLE_NAME, expected.COLUMN_NAME,
       CASE WHEN actual.COLUMN_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status,
       actual.COLUMN_TYPE, actual.IS_NULLABLE, actual.COLUMN_DEFAULT
FROM (
  SELECT 'parts' TABLE_NAME, 'selling_price_locked' COLUMN_NAME
  UNION ALL SELECT 'parts','price_source'
  UNION ALL SELECT 'product_supplier_offers','external_product_id'
  UNION ALL SELECT 'product_supplier_offers','external_artcode'
  UNION ALL SELECT 'product_supplier_offers','quality'
  UNION ALL SELECT 'product_supplier_offers','supplier_category'
  UNION ALL SELECT 'product_supplier_offers','product_brand'
  UNION ALL SELECT 'product_supplier_offers','model_brand'
  UNION ALL SELECT 'product_supplier_offers','model_codes_json'
  UNION ALL SELECT 'product_supplier_offers','suitable_for_json'
  UNION ALL SELECT 'product_supplier_offers','purchase_price_net'
  UNION ALL SELECT 'product_supplier_offers','selling_price_net'
  UNION ALL SELECT 'product_supplier_offers','selling_price_gross'
  UNION ALL SELECT 'product_supplier_offers','price_source'
  UNION ALL SELECT 'product_supplier_offers','source_fingerprint'
  UNION ALL SELECT 'product_supplier_offers','last_price_check_at'
  UNION ALL SELECT 'product_supplier_offers','last_stock_check_at'
  UNION ALL SELECT 'product_supplier_offers','last_full_sync_at'
  UNION ALL SELECT 'product_supplier_offers','missing_successful_runs'
  UNION ALL SELECT 'product_supplier_offers','last_foneday_sync_run_id'
  UNION ALL SELECT 'foneday_sync_runs','supplier_id'
  UNION ALL SELECT 'foneday_sync_runs','run_mode'
  UNION ALL SELECT 'foneday_sync_runs','status'
  UNION ALL SELECT 'foneday_import_queue','payload_hash'
  UNION ALL SELECT 'foneday_import_queue','payload_json'
  UNION ALL SELECT 'foneday_import_queue','status'
  UNION ALL SELECT 'foneday_price_conflicts','part_id'
  UNION ALL SELECT 'foneday_sync_lock','expires_at'
) expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS actual
  ON actual.TABLE_SCHEMA = @foneday_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

SELECT expected.TABLE_NAME, expected.INDEX_NAME,
       CASE WHEN actual.INDEX_NAME IS NULL THEN 'FEHLT' ELSE 'OK' END AS status
FROM (
  SELECT 'product_supplier_offers' TABLE_NAME, 'idx_pso_supplier_sku' INDEX_NAME
  UNION ALL SELECT 'product_supplier_offers','idx_pso_foneday_sync'
  UNION ALL SELECT 'foneday_sync_runs','idx_foneday_runs_supplier_started'
  UNION ALL SELECT 'foneday_import_queue','uq_foneday_queue_supplier_hash'
  UNION ALL SELECT 'foneday_sync_lock','PRIMARY'
) expected
LEFT JOIN INFORMATION_SCHEMA.STATISTICS actual
  ON actual.TABLE_SCHEMA = @foneday_schema
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.INDEX_NAME = expected.INDEX_NAME
GROUP BY expected.TABLE_NAME, expected.INDEX_NAME, actual.INDEX_NAME
ORDER BY expected.TABLE_NAME, expected.INDEX_NAME;

SELECT COUNT(*) AS foneday_supplier_count
FROM suppliers
WHERE LOWER(TRIM(name)) = LOWER('Foneday');

SELECT 'FONEDAY_POSTCHECK_READ_ONLY_COMPLETE' AS result,
       @foneday_schema AS checked_schema;
