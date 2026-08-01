-- Rein lesender Postcheck.
SELECT 'C00_DATABASE_CONTEXT' AS check_id, DATABASE() AS active_schema,
       IF(DATABASE() IS NULL OR DATABASE()='','FEHLER','OK') AS status, @@version AS database_version;
SELECT 'C01_EXPECTED_TABLES' AS check_id, e.table_name, IF(t.TABLE_NAME IS NULL,'FEHLT','OK') status
FROM (SELECT 'number_ranges' table_name UNION ALL SELECT 'quotes' UNION ALL SELECT 'quote_items'
      UNION ALL SELECT 'quote_decisions' UNION ALL SELECT 'invoice_conversion_history'
      UNION ALL SELECT 'invoice_corrections' UNION ALL SELECT 'payments') e
LEFT JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=e.table_name
ORDER BY e.table_name;
SELECT 'C02_EXPECTED_COLUMNS' AS check_id,e.table_name,e.column_name,IF(c.COLUMN_NAME IS NULL,'FEHLT','OK') status,
       c.COLUMN_TYPE,c.IS_NULLABLE,c.COLUMN_DEFAULT
FROM (
 SELECT 'parts' table_name,'markup_percent' column_name UNION ALL SELECT 'parts','automatic_selling_price'
 UNION ALL SELECT 'parts','selling_price_manual' UNION ALL SELECT 'repair_parts','markup_percent_at_time'
 UNION ALL SELECT 'repair_parts','customer_description' UNION ALL SELECT 'repairs','service_date'
 UNION ALL SELECT 'repairs','quote_snapshot'
 UNION ALL SELECT 'repairs','payment_status' UNION ALL SELECT 'repairs','quote_source_id'
 UNION ALL SELECT 'quotes','billing_mode' UNION ALL SELECT 'quotes','internal_notes'
 UNION ALL SELECT 'quotes','converted_to_invoice_repair_id' UNION ALL SELECT 'quote_items','item_type'
) e LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=e.table_name AND c.COLUMN_NAME=e.column_name
ORDER BY e.table_name,e.column_name;
SELECT 'C03_EXPECTED_INDEXES' check_id,e.table_name,e.index_name,IF(s.INDEX_NAME IS NULL,'FEHLT','OK') status
FROM (SELECT 'quotes' table_name,'uq_quotes_number' index_name UNION ALL SELECT 'quote_items','idx_quote_items_quote'
      UNION ALL SELECT 'invoice_conversion_history','uq_invoice_conversion_quote'
      UNION ALL SELECT 'payments','idx_payments_repair_date' UNION ALL SELECT 'repairs','idx_repairs_payment_status') e
LEFT JOIN INFORMATION_SCHEMA.STATISTICS s ON s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME=e.table_name AND s.INDEX_NAME=e.index_name
GROUP BY e.table_name,e.index_name,s.INDEX_NAME ORDER BY e.table_name,e.index_name;
SELECT 'C04_SMALL_BUSINESS_SETTINGS' check_id,e.setting_key,s.setting_value,
 IF(s.setting_key IS NULL,'FEHLT',IF((e.setting_key='billing_mode' AND s.setting_value='small_business_19_ustg') OR
    (e.setting_key='tax_rate' AND CAST(s.setting_value AS DECIMAL(6,2))=0.00) OR
    (e.setting_key='ustg_notice_text' AND s.setting_value='Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG. Es wird keine Umsatzsteuer berechnet.'),'OK','ABWEICHUNG')) status
FROM (SELECT 'billing_mode' setting_key UNION ALL SELECT 'tax_rate' UNION ALL SELECT 'ustg_notice_text') e
LEFT JOIN settings s ON s.setting_key=e.setting_key ORDER BY e.setting_key;
SELECT 'C05_INVALID_VALUES' check_id,
 (SELECT COUNT(*) FROM parts WHERE markup_percent<0) invalid_markups,
 (SELECT COUNT(*) FROM payments WHERE amount<=0) invalid_payments,
 (SELECT COUNT(*) FROM repairs WHERE advance_payment IS NULL) null_advance_payments,
 (SELECT COUNT(*) FROM quotes WHERE billing_mode='small_business_19_ustg' AND (tax_rate<>0 OR tax_amount<>0 OR total<>subtotal)) invalid_small_business_quotes;
SELECT 'REPAIR_DEVICE_WORK_POSTCHECK_COMPLETE' AS result;
