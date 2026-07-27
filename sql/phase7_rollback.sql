-- =========================================================================
-- MZ Tech Reparatursystem - Phase 7 - ROLLBACK
-- =========================================================================
-- Entfernt AUSSCHLIESSLICH die in sql/phase7_complete_integrations.sql neu
-- angelegten Strukturen. Fasst NIEMALS Phase-2a/2b-Tabellen/-Daten an
-- (suppliers, product_supplier_offers-Zeilen, purchase_orders, etc.) und
-- NIEMALS das bestehende Dokumentenmodul (repairs.invoice_*,
-- invoice_corrections). Reihenfolge beachtet Fremdschluessel-Abhaengigkeiten
-- (erst abhaengige Tabelle, dann Spalte).
--
-- ACHTUNG: DROP TABLE loescht die betroffenen Daten unwiderruflich
-- (Wareneingangs-Historie bzw. Buchhaltungs-Synchronisationsprotokoll/
-- -Mapping). Vor Ausfuehrung ein Datenbank-Backup anlegen, falls diese
-- Historie erhalten bleiben soll. NUR nach expliziter Freigabe ausfuehren.
-- =========================================================================

-- 1. Buchhaltungs-Synchronisationsprotokoll
DROP TABLE IF EXISTS `accounting_document_sync`;

-- 2. Buchhaltungs-Kontakt-Mapping
DROP TABLE IF EXISTS `accounting_contact_mapping`;

-- 3. Wareneingangsbuchungen (haengt per FK an purchase_order_items)
DROP TABLE IF EXISTS `purchase_order_receipts`;

-- 4. product_supplier_offers.packaging_unit (Spalte, nicht die Tabelle
--    selbst - product_supplier_offers stammt aus Phase 2a und bleibt
--    unangetastet).
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_supplier_offers' AND COLUMN_NAME = 'packaging_unit'
);
SET @ddl := IF(@col_exists > 0,
    'ALTER TABLE `product_supplier_offers` DROP COLUMN `packaging_unit`',
    'SELECT ''product_supplier_offers.packaging_unit bereits entfernt/nicht vorhanden - uebersprungen.'' AS hinweis'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- Hinweis: die Berechtigung 'manage_accounting' (Tabelle `permissions`,
-- sowie ggf. zugewiesene Zeilen in `role_permissions`) wird hier bewusst
-- NICHT geloescht - das folgt demselben additiven Prinzip wie alle
-- frueheren Berechtigungen in diesem System und wird nicht per SQL
-- zurueckgerollt (Entfernen des PERMISSION_DEFINITIONS-Eintrags in
-- private/permissions.php genuegt, falls das Buchhaltungsmodul komplett
-- deinstalliert werden soll).
-- -------------------------------------------------------------------------

SELECT 'Phase 7 (Beschaffung/Buchhaltung): Rollback abgeschlossen.' AS ergebnis;
