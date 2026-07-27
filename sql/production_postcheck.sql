-- NUR LESEND: nach production_complete_migration.sql ausfuehren.
SELECT required.table_name,
       IF(t.TABLE_NAME IS NULL, 'FEHLT', 'OK') AS status
FROM (
  SELECT 'suppliers' table_name UNION ALL SELECT 'supplier_interface_profiles'
  UNION ALL SELECT 'supplier_documents' UNION ALL SELECT 'supplier_shipping_rules'
  UNION ALL SELECT 'import_profiles' UNION ALL SELECT 'import_jobs'
  UNION ALL SELECT 'product_supplier_offers' UNION ALL SELECT 'product_price_history'
  UNION ALL SELECT 'product_availability_history' UNION ALL SELECT 'purchase_orders'
  UNION ALL SELECT 'purchase_order_items' UNION ALL SELECT 'purchase_order_receipts'
  UNION ALL SELECT 'pricing_rules' UNION ALL SELECT 'accounting_contact_mapping'
  UNION ALL SELECT 'accounting_document_sync'
) required
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=required.table_name
ORDER BY required.table_name;

-- Exakte Kontrolle der neu benoetigten Angebotsspalte. Im Gegensatz zu
-- einer reinen INFORMATION_SCHEMA-Trefferliste wird auch bei Fehlen genau
-- eine Ergebniszeile mit Status FEHLT ausgegeben.
SELECT
  'product_supplier_offers' AS TABLE_NAME,
  'packaging_unit' AS COLUMN_NAME,
  CASE
    WHEN c.COLUMN_NAME IS NULL THEN 'FEHLT'
    WHEN minimum_order_quantity.COLUMN_NAME IS NULL THEN 'ABWEICHEND'
    WHEN c.DATA_TYPE <> 'varchar'
      OR c.CHARACTER_MAXIMUM_LENGTH <> 50
      OR c.IS_NULLABLE <> 'YES'
      OR c.COLUMN_DEFAULT IS NOT NULL
      OR c.ORDINAL_POSITION <> minimum_order_quantity.ORDINAL_POSITION + 1
      THEN 'ABWEICHEND'
    ELSE 'OK'
  END AS status,
  c.COLUMN_TYPE,
  c.IS_NULLABLE,
  c.COLUMN_DEFAULT,
  CASE
    WHEN c.COLUMN_NAME IS NULL THEN 'NICHT VORHANDEN'
    WHEN c.ORDINAL_POSITION = minimum_order_quantity.ORDINAL_POSITION + 1
      THEN 'OK: nach minimum_order_quantity'
    ELSE 'ABWEICHEND'
  END AS position_status
FROM (SELECT 1 AS one_row) AS expected
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = 'product_supplier_offers'
 AND c.COLUMN_NAME = 'packaging_unit'
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS minimum_order_quantity
  ON minimum_order_quantity.TABLE_SCHEMA = DATABASE()
 AND minimum_order_quantity.TABLE_NAME = 'product_supplier_offers'
 AND minimum_order_quantity.COLUMN_NAME = 'minimum_order_quantity';

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='purchase_order_items'
  AND COLUMN_NAME IN ('quantity_received','assigned_company_id','assigned_project_id','assigned_ticket_id')
ORDER BY COLUMN_NAME;

SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('parts','product_supplier_offers','purchase_orders','purchase_order_items','accounting_document_sync')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
