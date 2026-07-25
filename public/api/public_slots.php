<?php
/**
 * Öffentlicher, nicht-authentifizierter JSON-Endpunkt für die
 * Terminbuchungsseite (public/termin.php): liefert die freien
 * Zeitslots für ein gegebenes Datum zurück.
 *
 * Bewusst OHNE require_auth() – wird von nicht eingeloggten
 * Besuchern der öffentlichen Buchungsseite per fetch() aufgerufen.
 */
$private = dirname(dirname(__DIR__)) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
start_secure_session();

header('Content-Type: application/json; charset=utf-8');

$date = trim($_GET['date'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges Datum', 'slots' => []]);
    exit;
}

// Datum muss ein tatsächlich gültiges Kalenderdatum sein (z. B. kein 2026-02-30)
$parts = explode('-', $date);
if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges Datum', 'slots' => []]);
    exit;
}

$booking_settings = booking_get_settings();

if (empty($booking_settings['enabled'])) {
    echo json_encode(['success' => true, 'slots' => []]);
    exit;
}

if (!booking_date_is_bookable($date)) {
    echo json_encode(['success' => true, 'slots' => []]);
    exit;
}

try {
    $slots = booking_available_slots($date);
} catch (Throwable $e) {
    error_log('public_slots.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Termine', 'slots' => []]);
    exit;
}

echo json_encode(['success' => true, 'slots' => array_values($slots)]);
