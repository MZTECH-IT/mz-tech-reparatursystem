<?php
/**
 * MZ Tech – E-Mail API
 * POST: action=send_status → send status update email to customer
 */
$private = dirname(dirname(__DIR__)) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
require_once $private . '/mailer.php';
require_once dirname(__DIR__) . '/includes/icons.php';

start_secure_session();
require_auth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Methode nicht erlaubt.'], 405);
}

verify_csrf();

$action    = $_POST['action'] ?? '';
$repair_id = (int)($_POST['repair_id'] ?? 0);

if ($repair_id < 1) json_response(['success' => false, 'message' => 'Ungültige Reparatur-ID.']);

// Reparatur + Kunde laden
$stmt = get_db()->prepare(
    'SELECT r.*, c.first_name, c.last_name, c.email, c.phone
     FROM repairs r
     JOIN customers c ON c.id = r.customer_id
     WHERE r.id = ?'
);
$stmt->execute([$repair_id]);
$data = $stmt->fetch();

if (!$data) json_response(['success' => false, 'message' => 'Reparatur nicht gefunden.']);
if (!$data['email']) json_response(['success' => false, 'message' => 'Kunde hat keine E-Mail-Adresse.']);

$repair   = $data;
$customer = ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email']];

if ($action === 'send_status') {
    $ok = send_repair_status_email($repair, $customer, $repair['status']);
    if ($ok) {
        log_activity('email_sent', 'repairs', $repair_id, 'Status-E-Mail gesendet: ' . repair_status_label($repair['status']));
        json_response(['success' => true, 'message' => 'E-Mail erfolgreich gesendet.']);
    } else {
        json_response(['success' => false, 'message' => 'E-Mail-Versand fehlgeschlagen. SMTP prüfen.']);
    }
}

if ($action === 'send_custom') {
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');
    if (!$subject || !$body) json_response(['success' => false, 'message' => 'Betreff und Text erforderlich.']);

    $html  = nl2br(h($body));
    $ok    = send_email($customer['email'], $customer['first_name'] . ' ' . $customer['last_name'], $subject, $html, $body);

    if ($ok) {
        log_activity('email_sent', 'repairs', $repair_id, 'Benutzerdefinierte E-Mail: ' . $subject);
        json_response(['success' => true, 'message' => 'E-Mail gesendet.']);
    } else {
        json_response(['success' => false, 'message' => 'E-Mail-Versand fehlgeschlagen.']);
    }
}

json_response(['success' => false, 'message' => 'Unbekannte Aktion.']);
