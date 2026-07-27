-- =========================================================================
-- MZ Tech Reparatursystem - schema-sichere Diagnose nach der Migration
-- =========================================================================
-- Ausschliesslich SELECT-Abfragen. Keine USE-Anweisung, keine temporaeren
-- Tabellen, keine Sessionvariablen und keine Daten-/Schemaaenderung.
--
-- Der aktive phpMyAdmin-Kontext wird nur angezeigt und bewertet. Fuer die
-- eigentlichen Pruefungen werden Anwendungsschemas anhand der vier stabilen
-- Basistabellen users, settings, repairs und parts erkannt. Gibt es mehrere
-- passende Schemas, werden sie bewusst alle mit Schemanamen ausgegeben.
-- =========================================================================

-- 1. Aktiven phpMyAdmin-/SQL-Kontext sichtbar machen.
SELECT
  DATABASE() AS active_database,
  CASE
    WHEN DATABASE() IS NULL THEN
      'WARNUNG: Keine aktive Datenbank gewaehlt; schemaerkannte Ergebnisse unten verwenden.'
    WHEN (
      SELECT COUNT(DISTINCT t.TABLE_NAME)
      FROM INFORMATION_SCHEMA.TABLES AS t
      WHERE t.TABLE_SCHEMA = DATABASE()
        AND t.TABLE_NAME IN ('users','settings','repairs','parts')
    ) = 4 THEN
      'OK: Aktive Datenbank enthaelt alle vier Ankertabellen.'
    ELSE
      'WARNUNG: Aktive Datenbank ist nicht eindeutig das Reparatursystem.'
  END AS context_status;

-- 1b. Tabellen des tatsaechlich aktiven Kontextes direkt anzeigen.
-- Bei active_database = NULL ist diese Ergebnismenge erwartungsgemaess leer.
SELECT
  DATABASE() AS active_database,
  t.TABLE_NAME,
  t.ENGINE,
  t.TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES AS t
WHERE t.TABLE_SCHEMA = DATABASE()
ORDER BY t.TABLE_NAME;

-- 2. Alle anhand der Ankertabellen erkannten Anwendungsschemas.
SELECT
  t.TABLE_SCHEMA AS detected_database,
  COUNT(DISTINCT CASE
    WHEN t.TABLE_NAME IN ('users','settings','repairs','parts')
    THEN t.TABLE_NAME END) AS anchor_tables,
  COUNT(DISTINCT CASE
    WHEN t.TABLE_NAME IN (
      'suppliers','supplier_interface_profiles','supplier_documents',
      'supplier_shipping_rules','import_profiles','import_jobs',
      'product_supplier_offers','product_price_history',
      'product_availability_history','purchase_orders',
      'purchase_order_items','purchase_order_receipts','pricing_rules',
      'accounting_contact_mapping','accounting_document_sync'
    ) THEN t.TABLE_NAME END) AS integration_tables,
  IF(t.TABLE_SCHEMA = DATABASE(), 'AKTIVER KONTEXT', 'ERKANNTES SCHEMA') AS context_relation
FROM INFORMATION_SCHEMA.TABLES AS t
WHERE t.TABLE_SCHEMA NOT IN ('information_schema','mysql','performance_schema','sys')
GROUP BY t.TABLE_SCHEMA
HAVING COUNT(DISTINCT CASE
  WHEN t.TABLE_NAME IN ('users','settings','repairs','parts')
  THEN t.TABLE_NAME END) = 4
ORDER BY (t.TABLE_SCHEMA = DATABASE()) DESC, integration_tables DESC, t.TABLE_SCHEMA;

-- 3. Alle real vorhandenen Tabellen der erkannten Anwendungsschemas.
SELECT
  actual.TABLE_SCHEMA AS detected_database,
  actual.TABLE_NAME,
  actual.ENGINE,
  actual.TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES AS actual
JOIN (
  SELECT t.TABLE_SCHEMA
  FROM INFORMATION_SCHEMA.TABLES AS t
  WHERE t.TABLE_SCHEMA NOT IN ('information_schema','mysql','performance_schema','sys')
  GROUP BY t.TABLE_SCHEMA
  HAVING COUNT(DISTINCT CASE
    WHEN t.TABLE_NAME IN ('users','settings','repairs','parts')
    THEN t.TABLE_NAME END) = 4
) AS detected
  ON detected.TABLE_SCHEMA = actual.TABLE_SCHEMA
ORDER BY actual.TABLE_SCHEMA, actual.TABLE_NAME;

-- 4. Erwartete Integrationstabellen je erkanntem Schema.
SELECT
  detected.TABLE_SCHEMA AS detected_database,
  required.TABLE_NAME,
  IF(actual.TABLE_NAME IS NULL, 'FEHLT', 'OK') AS status
FROM (
  SELECT t.TABLE_SCHEMA
  FROM INFORMATION_SCHEMA.TABLES AS t
  WHERE t.TABLE_SCHEMA NOT IN ('information_schema','mysql','performance_schema','sys')
  GROUP BY t.TABLE_SCHEMA
  HAVING COUNT(DISTINCT CASE
    WHEN t.TABLE_NAME IN ('users','settings','repairs','parts')
    THEN t.TABLE_NAME END) = 4
) AS detected
CROSS JOIN (
  SELECT 'suppliers' TABLE_NAME
  UNION ALL SELECT 'supplier_interface_profiles'
  UNION ALL SELECT 'supplier_documents'
  UNION ALL SELECT 'supplier_shipping_rules'
  UNION ALL SELECT 'import_profiles'
  UNION ALL SELECT 'import_jobs'
  UNION ALL SELECT 'product_supplier_offers'
  UNION ALL SELECT 'product_price_history'
  UNION ALL SELECT 'product_availability_history'
  UNION ALL SELECT 'purchase_orders'
  UNION ALL SELECT 'purchase_order_items'
  UNION ALL SELECT 'purchase_order_receipts'
  UNION ALL SELECT 'pricing_rules'
  UNION ALL SELECT 'accounting_contact_mapping'
  UNION ALL SELECT 'accounting_document_sync'
) AS required
LEFT JOIN INFORMATION_SCHEMA.TABLES AS actual
  ON actual.TABLE_SCHEMA = detected.TABLE_SCHEMA
 AND actual.TABLE_NAME = required.TABLE_NAME
ORDER BY detected.TABLE_SCHEMA, required.TABLE_NAME;

-- 5. product_supplier_offers.packaging_unit exakt pruefen.
SELECT
  detected.TABLE_SCHEMA AS detected_database,
  'product_supplier_offers' AS TABLE_NAME,
  'packaging_unit' AS COLUMN_NAME,
  CASE
    WHEN offer_table.TABLE_NAME IS NULL THEN 'TABELLE FEHLT'
    WHEN packaging.COLUMN_NAME IS NULL THEN 'FEHLT'
    WHEN minimum_order_quantity.COLUMN_NAME IS NULL THEN 'ABWEICHEND'
    WHEN packaging.DATA_TYPE <> 'varchar'
      OR packaging.CHARACTER_MAXIMUM_LENGTH <> 50
      OR packaging.IS_NULLABLE <> 'YES'
      OR packaging.COLUMN_DEFAULT IS NOT NULL
      OR packaging.ORDINAL_POSITION <> minimum_order_quantity.ORDINAL_POSITION + 1
      THEN 'ABWEICHEND'
    ELSE 'OK'
  END AS status,
  packaging.COLUMN_TYPE,
  packaging.IS_NULLABLE,
  packaging.COLUMN_DEFAULT,
  packaging.ORDINAL_POSITION,
  CASE
    WHEN packaging.COLUMN_NAME IS NULL THEN 'NICHT VORHANDEN'
    WHEN minimum_order_quantity.COLUMN_NAME IS NULL THEN 'REFERENZSPALTE FEHLT'
    WHEN packaging.ORDINAL_POSITION = minimum_order_quantity.ORDINAL_POSITION + 1
      THEN 'OK: nach minimum_order_quantity'
    ELSE 'ABWEICHEND'
  END AS position_status
FROM (
  SELECT t.TABLE_SCHEMA
  FROM INFORMATION_SCHEMA.TABLES AS t
  WHERE t.TABLE_SCHEMA NOT IN ('information_schema','mysql','performance_schema','sys')
  GROUP BY t.TABLE_SCHEMA
  HAVING COUNT(DISTINCT CASE
    WHEN t.TABLE_NAME IN ('users','settings','repairs','parts')
    THEN t.TABLE_NAME END) = 4
) AS detected
LEFT JOIN INFORMATION_SCHEMA.TABLES AS offer_table
  ON offer_table.TABLE_SCHEMA = detected.TABLE_SCHEMA
 AND offer_table.TABLE_NAME = 'product_supplier_offers'
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS packaging
  ON packaging.TABLE_SCHEMA = detected.TABLE_SCHEMA
 AND packaging.TABLE_NAME = 'product_supplier_offers'
 AND packaging.COLUMN_NAME = 'packaging_unit'
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS minimum_order_quantity
  ON minimum_order_quantity.TABLE_SCHEMA = detected.TABLE_SCHEMA
 AND minimum_order_quantity.TABLE_NAME = 'product_supplier_offers'
 AND minimum_order_quantity.COLUMN_NAME = 'minimum_order_quantity'
ORDER BY detected.TABLE_SCHEMA;

-- 6. Erwartete purchase_order_items-Spalten exakt und lueckenlos pruefen.
SELECT
  detected.TABLE_SCHEMA AS detected_database,
  'purchase_order_items' AS TABLE_NAME,
  required.COLUMN_NAME,
  IF(actual.COLUMN_NAME IS NULL, 'FEHLT', 'OK') AS status,
  actual.COLUMN_TYPE,
  actual.IS_NULLABLE,
  actual.COLUMN_DEFAULT,
  actual.ORDINAL_POSITION
FROM (
  SELECT t.TABLE_SCHEMA
  FROM INFORMATION_SCHEMA.TABLES AS t
  WHERE t.TABLE_SCHEMA NOT IN ('information_schema','mysql','performance_schema','sys')
  GROUP BY t.TABLE_SCHEMA
  HAVING COUNT(DISTINCT CASE
    WHEN t.TABLE_NAME IN ('users','settings','repairs','parts')
    THEN t.TABLE_NAME END) = 4
) AS detected
CROSS JOIN (
  SELECT 'quantity_received' COLUMN_NAME
  UNION ALL SELECT 'assigned_company_id'
  UNION ALL SELECT 'assigned_project_id'
  UNION ALL SELECT 'assigned_ticket_id'
) AS required
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS actual
  ON actual.TABLE_SCHEMA = detected.TABLE_SCHEMA
 AND actual.TABLE_NAME = 'purchase_order_items'
 AND actual.COLUMN_NAME = required.COLUMN_NAME
ORDER BY detected.TABLE_SCHEMA, required.COLUMN_NAME;

-- 7. Erwartete Indizes exakt nach Tabellen- und Indexnamen pruefen.
SELECT
  detected.TABLE_SCHEMA AS detected_database,
  required.TABLE_NAME,
  required.INDEX_NAME,
  IF(COUNT(actual.INDEX_NAME) = 0, 'FEHLT', 'OK') AS status,
  actual.NON_UNIQUE,
  GROUP_CONCAT(actual.COLUMN_NAME ORDER BY actual.SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
FROM (
  SELECT t.TABLE_SCHEMA
  FROM INFORMATION_SCHEMA.TABLES AS t
  WHERE t.TABLE_SCHEMA NOT IN ('information_schema','mysql','performance_schema','sys')
  GROUP BY t.TABLE_SCHEMA
  HAVING COUNT(DISTINCT CASE
    WHEN t.TABLE_NAME IN ('users','settings','repairs','parts')
    THEN t.TABLE_NAME END) = 4
) AS detected
CROSS JOIN (
  SELECT 'parts' TABLE_NAME, 'idx_parts_ean' INDEX_NAME
  UNION ALL SELECT 'parts','idx_parts_mpn'
  UNION ALL SELECT 'parts','idx_parts_preferred_supplier'
  UNION ALL SELECT 'product_supplier_offers','uq_pso_part_supplier_sku'
  UNION ALL SELECT 'product_supplier_offers','idx_pso_part'
  UNION ALL SELECT 'product_supplier_offers','idx_pso_supplier'
  UNION ALL SELECT 'product_supplier_offers','idx_pso_ean'
  UNION ALL SELECT 'product_supplier_offers','idx_pso_mpn'
  UNION ALL SELECT 'product_supplier_offers','idx_pso_availability'
  UNION ALL SELECT 'purchase_orders','uq_po_order_number'
  UNION ALL SELECT 'purchase_orders','idx_po_supplier'
  UNION ALL SELECT 'purchase_orders','idx_po_status'
  UNION ALL SELECT 'purchase_order_items','idx_poi_po'
  UNION ALL SELECT 'purchase_order_items','idx_poi_part'
  UNION ALL SELECT 'purchase_order_items','idx_poi_customer'
  UNION ALL SELECT 'purchase_order_items','idx_poi_repair'
  UNION ALL SELECT 'purchase_order_items','idx_poi_company'
  UNION ALL SELECT 'purchase_order_items','idx_poi_project'
  UNION ALL SELECT 'purchase_order_items','idx_poi_ticket'
  UNION ALL SELECT 'purchase_order_receipts','idx_por_item'
  UNION ALL SELECT 'purchase_order_receipts','idx_por_received_by'
  UNION ALL SELECT 'accounting_document_sync','uq_ads_provider_type_ref'
  UNION ALL SELECT 'accounting_document_sync','idx_ads_status'
) AS required
LEFT JOIN INFORMATION_SCHEMA.STATISTICS AS actual
  ON actual.TABLE_SCHEMA = detected.TABLE_SCHEMA
 AND actual.TABLE_NAME = required.TABLE_NAME
 AND actual.INDEX_NAME = required.INDEX_NAME
GROUP BY detected.TABLE_SCHEMA, required.TABLE_NAME, required.INDEX_NAME, actual.NON_UNIQUE
ORDER BY detected.TABLE_SCHEMA, required.TABLE_NAME, required.INDEX_NAME;
