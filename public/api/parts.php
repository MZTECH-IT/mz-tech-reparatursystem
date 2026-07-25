<?php
/**
 * API: Parts – stock adjustment
 * POST action=adjust  id=<int>  delta=<int>  csrf_token=<token>
 */
require_once dirname(__DIR__) . '/init.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data): void {
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_out(['success' => false, 'message' => 'Method not allowed']);
}

// CSRF (from FormData/body or X-CSRF-Token header)
verify_csrf();

$action = trim($_POST['action'] ?? '');

if ($action === 'adjust') {
    $id    = (int)($_POST['id']    ?? 0);
    $delta = (int)($_POST['delta'] ?? 0);

    if ($id <= 0 || $delta === 0) {
        json_out(['success' => false, 'message' => 'Ungültige Parameter']);
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT stock_quantity, min_stock FROM parts WHERE id = ?');
    $stmt->execute([$id]);
    $part = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$part) {
        json_out(['success' => false, 'message' => 'Ersatzteil nicht gefunden']);
    }

    $new_qty = max(0, (int)$part['stock_quantity'] + $delta);
    $db->prepare('UPDATE parts SET stock_quantity = ? WHERE id = ?')->execute([$new_qty, $id]);
    log_activity('update', 'parts', $id);

    $is_low = $part['min_stock'] > 0 && $new_qty <= (int)$part['min_stock'];

    json_out([
        'success'      => true,
        'new_quantity' => $new_qty,
        'is_low'       => $is_low,
    ]);
}

http_response_code(400);
json_out(['success' => false, 'message' => 'Unbekannte Aktion']);
