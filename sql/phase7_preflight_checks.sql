-- =========================================================================
-- MZ Tech Reparatursystem - Phase 7 - PREFLIGHT-PRUEFUNG
-- =========================================================================
-- Rein LESEND / NICHT DESTRUKTIV. Fuehrt KEINE Aenderung an der Datenbank
-- aus - jede Anweisung hier ist ein SELECT, der den aktuellen Zustand der
-- relevanten Tabellen/Spalten meldet. Bitte VOR sql/phase7_complete_
-- integrations.sql ausfuehren und die Ergebnisse pruefen (siehe
-- deployment/PHASE7_UPLOAD_ANLEITUNG.md, Abschnitt "SQL-Importreihenfolge").
--
-- Hintergrund: Laut Kopfkommentar in sql/2a_append_to_update.sql war diese
-- Datei zum Zeitpunkt ihrer Erstellung als "NOCH NICHT AUF DER PRODUKTIV-
-- DATENBANK AUSGEFUEHRT / wartet auf Freigabe" markiert. Falls diese Datei
-- entgegen der eigenen Kopfzeile bereits gelaufen ist, meldet dieses
-- Preflight-Skript das unten (Abschnitt 1). Falls NICHT, muss sql/2a_
-- append_to_update.sql VOR diesem Phase-7-Paket eingespielt werden - Phase
-- 7 baut direkt auf den dort angelegten Tabellen auf (suppliers,
-- product_supplier_offers, purchase_orders, purchase_order_items, ...).
-- =========================================================================

-- -------------------------------------------------------------------------
-- Abschnitt 1: Sind die Phase-2a/2b-Basistabellen bereits vorhanden?
-- -------------------------------------------------------------------------
SELECT
    TABLE_NAME,
    'Phase 2a/2b - muss VOR Phase 7 vorhanden sein' AS hinweis
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'suppliers', 'supplier_interface_profiles', 'product_supplier_offers',
    'purchase_orders', 'purchase_order_items', 'supplier_shipping_rules',
    'supplier_documents', 'supplier_shipping_rules', 'import_profiles',
    'import_jobs', 'product_price_history', 'product_availability_history',
    'pricing_rules'
  )
ORDER BY TABLE_NAME;

-- Erwartung: 12 Zeilen. Fehlt eine dieser Tabellen, bitte ZUERST
-- sql/2a_append_to_update.sql einspielen und dieses Preflight-Skript
-- danach erneut ausfuehren.

-- -------------------------------------------------------------------------
-- Abschnitt 2: Sind die neuen Phase-7-Strukturen bereits vorhanden?
-- (Informativ - sql/phase7_complete_integrations.sql ist idempotent und
-- kann unabhaengig vom Ergebnis hier gefahrlos ausgefuehrt werden.)
-- -------------------------------------------------------------------------
SELECT
    TABLE_NAME,
    'Phase 7 - wird von phase7_complete_integrations.sql angelegt, falls nicht vorhanden' AS hinweis
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'purchase_order_receipts', 'accounting_contact_mapping', 'accounting_document_sync'
  )
ORDER BY TABLE_NAME;

SELECT
    COLUMN_NAME, TABLE_NAME,
    'Phase 7 - wird von phase7_complete_integrations.sql ergaenzt, falls nicht vorhanden' AS hinweis
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'product_supplier_offers'
  AND COLUMN_NAME = 'packaging_unit';

-- -------------------------------------------------------------------------
-- Abschnitt 3: Dokumentenmodul (Rechnungen/Gutschriften) - wird von Phase 7
-- GELESEN (repairs.invoice_*, invoice_corrections), aber NICHT angelegt.
-- Rein informativ: bestaetigt, dass diese bereits produktiv vorhandenen
-- Strukturen (siehe private/invoicing.php, private/invoice_corrections.php)
-- tatsaechlich existieren, BEVOR das Buchhaltungsmodul (Abschnitt 8) genutzt
-- wird. Fehlt eine dieser Spalten/Tabellen, funktioniert die Rechnungs-/
-- Gutschriften-Synchronisation (private/accounting.php) nicht, obwohl die
-- Buchhaltungsseiten selbst fehlerfrei laden.
-- -------------------------------------------------------------------------
SELECT COLUMN_NAME, 'repairs - Dokumentenmodul' AS hinweis
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repairs'
  AND COLUMN_NAME IN ('invoice_number', 'invoice_status', 'invoice_snapshot', 'invoice_released_at', 'invoice_released_by', 'invoice_correction_status')
ORDER BY COLUMN_NAME;

SELECT TABLE_NAME, 'Dokumentenmodul' AS hinweis
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('invoice_corrections', 'quote_decisions', 'number_ranges');

-- -------------------------------------------------------------------------
-- Abschnitt 4: activity_log - wird von saemtlichen neuen Phase-7-Funktionen
-- fuer die Protokollierung mitgenutzt (keine neue, parallele Log-Tabelle).
-- -------------------------------------------------------------------------
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log';

-- Ende Preflight-Pruefung.
