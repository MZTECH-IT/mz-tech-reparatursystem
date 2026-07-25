<?php
/**
 * MZ Tech – Streaming-Endpunkt für Firmendokumente (Admin-Bereich, Phase 5)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_companies');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

serve_company_document($id);
