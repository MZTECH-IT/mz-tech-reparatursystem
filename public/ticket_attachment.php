<?php
/**
 * MZ Tech – Streaming-Endpunkt für Ticket-Anhänge (Admin-Bereich, Phase 5)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_tickets');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

serve_ticket_attachment($id);
