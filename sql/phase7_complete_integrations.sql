-- =========================================================================
-- MZ Tech Reparatursystem - Phase 7 - Beschaffung/Buchhaltung fertigstellen
-- =========================================================================
-- Rein ADDITIV und IDEMPOTENT - kann mehrfach ausgefuehrt werden, ohne
-- Fehler oder doppelte Strukturen zu erzeugen. Nutzt fuer neue Tabellen
-- CREATE TABLE IF NOT EXISTS und fuer die neue Spalte denselben
-- INFORMATION_SCHEMA-gesteuerten PREPARE/EXECUTE-Ansatz wie bereits in
-- sql/2a_append_to_update.sql (keine DELIMITER-Aenderung noetig, funktioniert
-- identisch in phpMyAdmin, mysql-CLI und einfachen Statement-Splittern).
--
-- Beruecksichtigt ausdruecklich bereits vorhandene Phase-2a/2b-Strukturen:
-- veraendert oder loescht KEINE bestehende Tabelle/Spalte/Daten, ergaenzt
-- nur. Voraussetzung: sql/2a_append_to_update.sql wurde bereits eingespielt
-- (siehe sql/phase7_preflight_checks.sql, Abschnitt 1).
--
-- WICHTIG: erstellt KEINE neue invoices-/credit_notes-/companies-Tabelle -
-- das Buchhaltungsmodul (private/accounting.php) liest ausschliesslich aus
-- den bereits bestehenden Strukturen repairs.invoice_* und
-- invoice_corrections (siehe private/invoicing.php,
-- private/invoice_corrections.php). Diese Datei legt nur die Protokoll-/
-- Mapping-Tabellen fuer die EXTERNE Anbindung (lexoffice/sevDesk) an.
-- =========================================================================

-- -------------------------------------------------------------------------
-- 1. product_supplier_offers.packaging_unit -- NEU: Verpackungseinheit des
--    Lieferantenangebots (z. B. "10er-Pack"), siehe private/products.php
--    import_upsert_offer() und private/import_engine.php import_target_
--    fields(). Auf product_supplier_offers, NICHT auf parts - bewusst vom
--    bereits vorhandenen, artikelbezogenen parts.packaging_unit getrennt.
-- -------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_supplier_offers' AND COLUMN_NAME = 'packaging_unit'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `product_supplier_offers` ADD COLUMN `packaging_unit` VARCHAR(50) DEFAULT NULL AFTER `minimum_order_quantity`',
    'SELECT ''product_supplier_offers.packaging_unit existiert bereits - uebersprungen.'' AS hinweis'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Transport-, Datei- und Authentifizierungsarten an die Adapter/UI angleichen.
-- MODIFY ist datenbewahrend, da alle bisherigen ENUM-Werte enthalten bleiben.
ALTER TABLE `supplier_interface_profiles`
  MODIFY `interface_type` ENUM(
    'rest_api','graphql_api','http_download','ftp','ftps','sftp',
    'soap_api','edi','ugl_ugs','manual_upload','url_fetch',
    'csv','tsv','txt','xls','xlsx','xml','json','zip'
  ) NOT NULL DEFAULT 'manual_upload',
  MODIFY `auth_type` ENUM(
    'none','basic','bearer_token','api_key','oauth2','custom'
  ) NOT NULL DEFAULT 'none',
  MODIFY `format` ENUM('csv','tsv','txt','xls','xlsx','xml','json','zip') DEFAULT NULL;

-- -------------------------------------------------------------------------
-- 2. purchase_order_receipts -- NEU: Wareneingangsbuchungen je Bestell-
--    position (Lieferscheinnummer/-datum/Notiz), siehe
--    purchase_order_item_receive()/purchase_order_receipts_for_item() in
--    private/purchase_orders.php und public/purchase_order_receive.php.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_order_receipts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_item_id` INT UNSIGNED NOT NULL,
  `quantity_delta` INT NOT NULL,
  `delivery_note_number` VARCHAR(100) DEFAULT NULL,
  `received_date` DATE DEFAULT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  `received_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_por_item` (`purchase_order_item_id`),
  KEY `idx_por_received_by` (`received_by`),
  CONSTRAINT `fk_por_item` FOREIGN KEY (`purchase_order_item_id`)
    REFERENCES `purchase_order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_por_user` FOREIGN KEY (`received_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 3. accounting_contact_mapping -- NEU: welcher Kunde/Lieferant entspricht
--    welchem externen Kontakt bei lexoffice/sevDesk, siehe
--    accounting_contact_mapping_get()/_set() in private/accounting.php.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_contact_mapping` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(20) NOT NULL,
  `entity_type` VARCHAR(20) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `external_id` VARCHAR(100) NOT NULL,
  `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acm_provider_entity` (`provider`, `entity_type`, `entity_id`),
  KEY `idx_acm_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 4. accounting_document_sync -- NEU: Uebertragungsprotokoll je Beleg
--    (Rechnung/Gutschrift/Eingangsbeleg) mit Dublettenschutz (UNIQUE),
--    siehe accounting_document_sync_record()/_already_synced() in
--    private/accounting.php.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_document_sync` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(20) NOT NULL,
  `document_type` VARCHAR(30) NOT NULL,
  `reference_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `external_id` VARCHAR(100) DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ads_provider_type_ref` (`provider`, `document_type`, `reference_id`),
  KEY `idx_ads_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Hinweis zu Berechtigungen: die neue Berechtigung 'manage_accounting'
-- (private/permissions.php, PERMISSION_DEFINITIONS) wird beim naechsten
-- Aufruf von permissions_all()/permissions_sync_definitions() automatisch
-- additiv in die bestehende Tabelle `permissions` eingetragen - dafuer ist
-- KEIN eigenes SQL-Statement noetig (identisches Verhalten wie bei jeder
-- fruehreren neuen Berechtigung in diesem System).
-- -------------------------------------------------------------------------

SELECT 'Phase 7 (Beschaffung/Buchhaltung): Struktur-Update abgeschlossen.' AS ergebnis;
