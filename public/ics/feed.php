<?php
/**
 * MZ Tech – Abonnierbarer Kalender-Feed (.ics, token-geschützt)
 *
 * Ermöglicht das Abonnieren aller internen Termine per URL in externen
 * Kalender-Apps (Google Kalender, Apple Kalender, Outlook, Thunderbird
 * usw.: "Kalender per URL/Internet abonnieren"). Läuft komplett lokal
 * auf All-Inkl, ohne externe Dienste oder Kosten.
 *
 * Zugriffsschutz: Da Kalender-Apps den Feed ohne interaktiven Login
 * abrufen müssen, erfolgt die Absicherung über ein langes, zufälliges
 * Token (?token=…) statt über eine Session. Das Token kann in den
 * Einstellungen jederzeit neu generiert werden, um den Feed zu sperren.
 */
$private = dirname(__DIR__, 2) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/ics.php';

header('Content-Type: text/calendar; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!ics_feed_enabled()) {
    http_response_code(404);
    exit;
}

$token = trim($_GET['token'] ?? '');
$valid = $token !== '' && hash_equals(ics_feed_token(), $token);

if (!$valid) {
    http_response_code(403);
    exit;
}

$db = get_db();

// Alle zukünftigen Termine sowie die der letzten 30 Tage (falls kurzfristig
// aktualisiert/verschoben wurde und der Kalender noch nicht neu geladen hat).
$stmt = $db->prepare(
    "SELECT id, title, start_datetime, end_datetime, type, notes, ics_uid
     FROM appointments
     WHERE start_datetime >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY start_datetime ASC
     LIMIT 2000"
);
$stmt->execute();
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$company = get_setting('company_name', 'MZ Tech');

header('Content-Disposition: inline; filename="mztech-termine.ics"');
echo build_vcalendar($appointments, $company . ' – Termine');
