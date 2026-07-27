-- NUR LESEND: vor production_complete_migration.sql in phpMyAdmin ausfuehren.
SELECT DATABASE() AS aktive_datenbank, VERSION() AS mysql_version;

SELECT required.table_name,
       IF(t.TABLE_NAME IS NULL, 'FEHLT', 'OK') AS status
FROM (
  SELECT 'users' table_name UNION ALL SELECT 'settings' UNION ALL SELECT 'customers'
  UNION ALL SELECT 'repairs' UNION ALL SELECT 'parts' UNION ALL SELECT 'suppliers'
  UNION ALL SELECT 'supplier_interface_profiles' UNION ALL SELECT 'product_supplier_offers'
  UNION ALL SELECT 'purchase_orders' UNION ALL SELECT 'purchase_order_items'
  UNION ALL SELECT 'companies' UNION ALL SELECT 'projects' UNION ALL SELECT 'tickets'
  UNION ALL SELECT 'invoice_corrections' UNION ALL SELECT 'permissions'
  UNION ALL SELECT 'role_permissions'
) required
LEFT JOIN INFORMATION_SCHEMA.TABLES t
  ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=required.table_name
ORDER BY required.table_name;

SELECT required.table_name, required.column_name,
       IF(c.COLUMN_NAME IS NULL, 'FEHLT', 'OK') AS status
FROM (
  SELECT 'parts' table_name, 'reserved_stock' column_name
  UNION ALL SELECT 'parts','preferred_supplier_id'
  UNION ALL SELECT 'product_supplier_offers','packaging_unit'
  UNION ALL SELECT 'purchase_order_items','quantity_received'
  UNION ALL SELECT 'purchase_order_items','assigned_company_id'
  UNION ALL SELECT 'purchase_order_items','assigned_project_id'
  UNION ALL SELECT 'purchase_order_items','assigned_ticket_id'
  UNION ALL SELECT 'repairs','invoice_number'
  UNION ALL SELECT 'repairs','invoice_status'
) required
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=required.table_name AND c.COLUMN_NAME=required.column_name
ORDER BY required.table_name, required.column_name;
