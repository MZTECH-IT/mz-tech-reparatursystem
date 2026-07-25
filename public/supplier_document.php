<?php
/**
 * MZ Tech – Streaming-Endpunkt für Lieferantendokumente (Phase 6)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

serve_supplier_document($id);
