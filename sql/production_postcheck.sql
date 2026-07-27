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

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND (
  (TABLE_NAME='product_supplier_offers' AND COLUMN_NAME='packaging_unit') OR
  (TABLE_NAME='purchase_order_items' AND COLUMN_NAME IN ('quantity_received','assigned_company_id','assigned_project_id','assigned_ticket_id'))
)
ORDER BY TABLE_NAME, COLUMN_NAME;

SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('parts','product_supplier_offers','purchase_orders','purchase_order_items','accounting_document_sync')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
