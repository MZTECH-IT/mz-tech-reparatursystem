<?php
/**
 * MZ Tech – Firmenkundenportal: Dokument-Download (Phase 5)
 * IDOR-Schutz: nur Dokumente der EIGENEN Firma sind abrufbar.
 */
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/business_auth.php';
require_once $private . '/companies.php';

start_business_session();
business_require_login();

$id = (int)($_GET['id'] ?? 0);
$doc = $id ? company_document_find($id) : null;

if (!$doc || (int)$doc['company_id'] !== business_current_company_id()) {
    http_response_code(404);
    exit;
}

serve_company_document($id);
