<?php
/**
 * Browser-Endpunkt für Bestellungs-PDFs.
 */
$private = dirname(__DIR__, 2) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
require_once $private . '/permissions.php';
require_once $private . '/suppliers.php';
require_once $private . '/products.php';
require_once $private . '/purchase_orders.php';
require_once $private . '/purchase_order_pdf.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Ungültige Bestell-ID.');
}

start_secure_session();
if (empty($_SESSION['user_id']) || !user_has_permission('manage_purchase_orders')) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

try {
    $document = purchase_order_pdf_build($id);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($document['filename']) . '"');
    header('Content-Length: ' . strlen($document['content']));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $document['content'];
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    die($e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    die('Bestellungs-PDF konnte nicht erzeugt werden.');
}
