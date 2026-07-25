<?php
/**
 * MZ Tech – Kundenportal: Foto-Auslieferung
 *
 * Liefert ein einzelnes Reparatur-Foto (repair_photos) ausschließlich an den
 * eingeloggten Kundenportal-Nutzer aus, dem der zugehörige Reparaturauftrag
 * tatsächlich gehört.
 *
 * WICHTIG (IDOR-Schutz): Die Foto-ID allein genügt nicht – die Zugehörigkeit
 * wird zwingend zusätzlich über repairs.customer_id = portal_current_customer_id()
 * geprüft. Dieses Skript nutzt bewusst NICHT init.php / require_auth() (Admin-
 * Session), sondern ausschließlich die getrennte Portal-Session aus
 * portal_auth.php, damit Admin- und Kunden-Zugriffsrechte niemals vermischt
 * werden.
 */

$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/portal_auth.php';

start_portal_session();

if (!portal_is_logged_in()) {
    http_response_code(403);
    exit;
}

$db = get_db();

$photo_id = intval($_GET['id'] ?? 0);
if (!$photo_id) {
    http_response_code(400);
    exit;
}

// Zwingend über einen JOIN auf repairs.customer_id prüfen – niemals allein
// nach der vom Client übergebenen Foto-ID auflösen.
$stmt = $db->prepare(
    'SELECT rp.* FROM repair_photos rp
     JOIN repairs r ON r.id = rp.repair_id
     WHERE rp.id = ? AND r.customer_id = ? LIMIT 1'
);
$stmt->execute([$photo_id, portal_current_customer_id()]);
$photo = $stmt->fetch();

if (!$photo) {
    http_response_code(404);
    exit;
}

serve_photo($photo['filename'], (int)$photo['repair_id']);
