<?php
/**
 * MZ Tech – Bootstrap für alle geschützten Seiten
 * Jede geschützte Seite bindet NUR diese Datei ein.
 */
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/numbering.php';
require_once $private . '/auth.php';
require_once $private . '/permissions.php';
require_once $private . '/mailer.php';
require_once $private . '/customer_auth.php';
require_once $private . '/companies.php';
require_once $private . '/tickets.php';
require_once $private . '/ics.php';
require_once $private . '/google_calendar.php';
// Phase 6 – Lieferanten-, Produkt-, Preislisten-, Einkaufs- und
// Beschaffungssystem. suppliers.php bindet supplier_adapters.php,
// import_engine.php, price_guard.php und pricing_rules.php bereits selbst
// ein; products.php ebenfalls import_engine.php/price_guard.php/
// pricing_rules.php – die require_once-Reihenfolge ist daher unkritisch.
require_once $private . '/suppliers.php';
require_once $private . '/products.php';
require_once $private . '/purchase_orders.php';
require_once __DIR__ . '/includes/icons.php';

start_secure_session();
require_auth();
