<?php
/**
 * API: Calendar appointments
 *
 * POST action=save        – create appointment
 * POST action=delete      – delete appointment
 * POST action=reschedule  – move appointment to new date/time (drag & drop)
 * GET  action=search_customers&q=<str> – live customer search
 */
require_once dirname(__DIR__) . '/init.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$db = get_db();

// ── GET: customer search ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim($_GET['action'] ?? '');
    if ($action === 'search_customers') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            json_out(['customers' => []]);
        }
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            'SELECT id, first_name, last_name FROM customers
             WHERE first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name," ",last_name) LIKE ?
             ORDER BY last_name, first_name LIMIT 10'
        );
        $stmt->execute([$like, $like, $like]);
        json_out(['customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    http_response_code(400);
    json_out(['success' => false, 'message' => 'Unbekannte Aktion']);
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_out(['success' => false, 'message' => 'Method not allowed']);
}

// CSRF check (from FormData/body or X-CSRF-Token header)
verify_csrf();

$action = trim($_POST['action'] ?? '');

// ── Save appointment ──────────────────────────────────────────────────────
if ($action === 'save') {
    $title       = trim($_POST['title']         ?? '');
    $date        = trim($_POST['date']          ?? '');
    $time        = trim($_POST['time']          ?? '');
    $type        = trim($_POST['type']          ?? 'sonstiges');
    $repair_id   = (int)($_POST['repair_id']    ?? 0) ?: null;
    $customer_id = (int)($_POST['customer_id']  ?? 0) ?: null;
    $notes       = trim($_POST['notes']         ?? '');

    $valid_types = ['eingang', 'reparatur', 'abholung', 'sonstiges'];
    if (!in_array($type, $valid_types, true)) $type = 'sonstiges';

    if ($title === '') {
        json_out(['success' => false, 'message' => 'Titel ist erforderlich.']);
    }
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_out(['success' => false, 'message' => 'Datum ist erforderlich.']);
    }

    $start_datetime = $date . ($time !== '' ? ' ' . $time . ':00' : ' 00:00:00');

    // If customer_id not resolved via hidden field, try lookup by name
    if (!$customer_id) {
        $cname = trim($_POST['customer_name'] ?? '');
        if ($cname !== '') {
            $parts = explode(' ', $cname, 2);
            $fn = $parts[0];
            $ln = $parts[1] ?? '';
            $cs = $db->prepare('SELECT id FROM customers WHERE first_name = ? AND last_name = ? LIMIT 1');
            $cs->execute([$fn, $ln]);
            $cr = $cs->fetch(PDO::FETCH_COLUMN);
            if ($cr) $customer_id = (int)$cr;
        }
    }

    $created_by = (int)($_SESSION['user_id'] ?? 0) ?: null;

    $stmt = $db->prepare(
        'INSERT INTO appointments (repair_id, customer_id, title, start_datetime, type, notes, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$repair_id, $customer_id, $title, $start_datetime, $type, $notes, $created_by]);
    $new_id = (int)$db->lastInsertId();
    log_activity('create', 'appointments', $new_id);

    // Optionale Google-Kalender-Synchronisation (no-op, falls nicht konfiguriert)
    sync_appointment_calendar($new_id);

    json_out(['success' => true, 'id' => $new_id]);
}

// ── Reschedule appointment (drag & drop) ────────────────────────────────────
if ($action === 'reschedule') {
    $id   = (int)($_POST['id'] ?? 0);
    $date = trim($_POST['date'] ?? '');
    $time = trim($_POST['time'] ?? '');

    if ($id <= 0) {
        json_out(['success' => false, 'message' => 'Ungültige ID']);
    }
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_out(['success' => false, 'message' => 'Ungültiges Datum']);
    }
    if ($time === '' || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        $time = '00:00';
    }

    $stmt = $db->prepare('SELECT id, start_datetime, end_datetime FROM appointments WHERE id = ?');
    $stmt->execute([$id]);
    $apt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$apt) {
        json_out(['success' => false, 'message' => 'Termin nicht gefunden']);
    }

    $new_start = $date . ' ' . $time . ':00';

    // Dauer beibehalten, falls ein Enddatum gesetzt ist
    $new_end = null;
    if (!empty($apt['end_datetime'])) {
        $old_start_ts = strtotime($apt['start_datetime']);
        $old_end_ts   = strtotime($apt['end_datetime']);
        $duration     = max(0, $old_end_ts - $old_start_ts);
        $new_end      = date('Y-m-d H:i:s', strtotime($new_start) + $duration);
    }

    $stmt = $db->prepare('UPDATE appointments SET start_datetime = ?, end_datetime = ? WHERE id = ?');
    $stmt->execute([$new_start, $new_end, $id]);
    log_activity('update', 'appointments', $id);

    // Optionale Google-Kalender-Synchronisation (no-op, falls nicht konfiguriert)
    sync_appointment_calendar($id);

    json_out(['success' => true, 'start_datetime' => $new_start]);
}

// ── Delete appointment ────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        json_out(['success' => false, 'message' => 'Ungültige ID']);
    }

    $stmt = $db->prepare('SELECT id, google_event_id FROM appointments WHERE id = ?');
    $stmt->execute([$id]);
    $apt_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$apt_to_delete) {
        json_out(['success' => false, 'message' => 'Termin nicht gefunden']);
    }

    $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
    log_activity('delete', 'appointments', $id);

    // Optionale Google-Kalender-Synchronisation (no-op, falls nicht konfiguriert)
    gcal_delete_event($apt_to_delete['google_event_id'] ?? null);

    json_out(['success' => true]);
}

http_response_code(400);
json_out(['success' => false, 'message' => 'Unbekannte Aktion']);
