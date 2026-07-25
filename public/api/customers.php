<?php
$private = dirname(dirname(__DIR__)) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
require_once dirname(__DIR__) . '/includes/icons.php';
start_secure_session();
require_auth();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

// ── Autocomplete-Suche ────────────────────────────────
if (isset($_GET['q']) && (empty($action) || $action === 'autocomplete' || $_GET['format'] === 'autocomplete')) {
    $q = trim($_GET['q']);
    if ($q === '') {
        echo json_encode([]);
        exit;
    }

    $like = '%' . $q . '%';
    $stmt = get_db()->prepare(
        'SELECT id, first_name, last_name, phone, email
         FROM customers
         WHERE first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ?
         ORDER BY last_name, first_name
         LIMIT 20'
    );
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $label = trim($row['first_name'] . ' ' . $row['last_name']);
        if ($row['phone']) {
            $label .= ' (' . $row['phone'] . ')';
        }
        $result[] = [
            'id'         => (int)$row['id'],
            'label'      => $label,
            'first_name' => $row['first_name'],
            'last_name'  => $row['last_name'],
            'phone'      => $row['phone'],
            'email'      => $row['email'],
        ];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Einzelnen Kunden abrufen ──────────────────────────
if ($action === 'get' && !empty($_GET['id'])) {
    $id = intval($_GET['id']);
    if ($id <= 0) {
        echo json_encode(['error' => 'Ungültige ID']);
        exit;
    }

    $stmt = get_db()->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['error' => 'Kunde nicht gefunden']);
        exit;
    }

    echo json_encode($customer, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Fallback ──────────────────────────────────────────
http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
