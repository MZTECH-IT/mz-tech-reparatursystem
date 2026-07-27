<?php
/**
 * Berechtigungsgeprüfter Download von Ticket-Anhängen für beide Portale.
 * Interne Notizen und deren Anhänge sind für Portalnutzer nie abrufbar.
 */
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/portal_security.php';
require_once $private . '/portal_auth.php';
require_once $private . '/business_auth.php';
require_once $private . '/tickets.php';

$id = (int)($_GET['id'] ?? 0);
$attachment = $id ? ticket_attachment_find($id) : null;
if (!$attachment || !empty($attachment['is_internal'])) {
    http_response_code(404);
    exit;
}

$allowed = false;
$mode = (string)($_GET['portal'] ?? '');
if ($mode === 'customer') {
    start_portal_session();
    $customerId = portal_current_customer_id();
    $allowed = $customerId && ticket_access_for_customer((int)$attachment['ticket_id'], $customerId);
} elseif ($mode === 'business') {
    start_business_session();
    $companyId = business_current_company_id();
    $allowed = $companyId && ticket_access_for_company((int)$attachment['ticket_id'], $companyId);
}

if (!$allowed) {
    http_response_code(404);
    exit;
}

serve_ticket_attachment($id);
