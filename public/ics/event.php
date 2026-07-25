<?php
/**
 * MZ Tech – Admin: Einzelnen Termin als .ics-Datei herunterladen
 *
 * Für Mitarbeiter, die einen einzelnen Termin manuell in ihren privaten
 * Kalender importieren möchten (Doppelklick-Import statt Abo-Feed).
 */
require_once dirname(__DIR__) . '/init.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    die('Ungültige Termin-ID.');
}

$stmt = $db->prepare('SELECT id, title, start_datetime, end_datetime, type, notes, ics_uid FROM appointments WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$appointment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appointment) {
    http_response_code(404);
    die('Termin nicht gefunden.');
}

$filename = 'termin-' . $appointment['id'] . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo build_vcalendar([$appointment], get_setting('company_name', 'MZ Tech') . ' – Termin');
