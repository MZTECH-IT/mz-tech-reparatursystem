<?php
$private = dirname(dirname(__DIR__)) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
// Phase 6: für product_snapshot_for_use() (Lieferanten-Schnappschuss beim
// Hinzufügen eines Ersatzteils zu einer Reparatur, siehe add_part unten).
require_once $private . '/suppliers.php';
require_once $private . '/products.php';
require_once dirname(__DIR__) . '/includes/icons.php';
start_secure_session();
require_auth();

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$db = get_db();

// CSRF-Schutz für alle schreibenden (POST-)Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

// ── change_status ─────────────────────────────────────
if ($action === 'change_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $repair_id = intval($_POST['repair_id'] ?? 0);
    $status    = trim($_POST['status'] ?? '');
    $note      = trim($_POST['note'] ?? '');

    if (!$repair_id || !$status) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'repair_id und status erforderlich']);
        exit;
    }

    // Gültige Status-Werte (alte Statuswerte werden verlustfrei auf die
    // neue 14-stufige Pipeline abgebildet, siehe repair_status_normalize()).
    $status = repair_status_normalize($status);
    $valid_statuses = repair_valid_statuses();
    if (!in_array($status, $valid_statuses, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültiger Status']);
        exit;
    }

    // Status aktualisieren
    $db->prepare('UPDATE repairs SET status = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$status, $repair_id]);

    // Abschluss-Zeitstempel setzen
    if ($status === 'fertig') {
        $db->prepare('UPDATE repairs SET completed_at = NOW() WHERE id = ?')
           ->execute([$repair_id]);
    }
    if ($status === 'abgeholt') {
        $db->prepare('UPDATE repairs SET picked_up_at = NOW() WHERE id = ?')
           ->execute([$repair_id]);
    }

    // DSGVO / Datensicherheit: Geräte-Passcode automatisch löschen,
    // sobald die Reparatur fertiggestellt, abholbereit oder abgeholt wurde.
    // Der Code wird ab diesem Zeitpunkt nicht mehr benötigt und darf aus
    // Datenschutzgründen nicht unbegrenzt gespeichert bleiben.
    if (repair_status_is_completed($status)) {
        $db->prepare(
            'UPDATE repairs SET passcode_encrypted = NULL, passcode_iv = NULL WHERE id = ?'
        )->execute([$repair_id]);
    }

    // Historie eintragen
    try {
        $db->prepare(
            'INSERT INTO repair_status_history (repair_id, status, note, user_id, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$repair_id, $status, $note, $_SESSION['user_id'] ?? null]);
    } catch (PDOException $e) {
        error_log('repair_status_history insert failed: ' . $e->getMessage());
    }

    // E-Mail an Kunden senden
    try {
        $stmt = $db->prepare(
            'SELECT r.*, c.first_name, c.last_name, c.email
             FROM repairs r JOIN customers c ON r.customer_id = c.id
             WHERE r.id = ?'
        );
        $stmt->execute([$repair_id]);
        $row = $stmt->fetch();
        if ($row && !empty($row['email'])) {
            if (function_exists('send_repair_status_email')) {
                $repair_data   = $row;
                $customer_data = [
                    'first_name' => $row['first_name'],
                    'last_name'  => $row['last_name'],
                    'email'      => $row['email'],
                ];
                send_repair_status_email($repair_data, $customer_data, $status);
            } elseif (function_exists('send_email')) {
                $subject = 'Status Ihres Reparaturauftrags geändert: ' . repair_status_label($status);
                $body    = 'Ihr Reparaturauftrag hat nun den Status: ' . repair_status_label($status);
                if ($note) $body .= "\n\nHinweis: " . $note;
                send_email($row['email'], $row['first_name'] . ' ' . $row['last_name'], $subject, nl2br($body), $body);
            } else {
                $subject = 'Status Ihres Reparaturauftrags geändert';
                $body    = 'Ihr Reparaturauftrag hat nun den Status: ' . repair_status_label($status);
                if ($note) $body .= "\n\nHinweis: " . $note;
                mail($row['email'], $subject, $body);
            }
        }
    } catch (Throwable $e) {
        error_log('Email send failed: ' . $e->getMessage());
    }

    log_activity('change_status', 'repairs', $repair_id, 'Neuer Status: ' . $status);

    echo json_encode(['success' => true]);
    exit;
}

// ── upload_photo ──────────────────────────────────────
if ($action === 'upload_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $repair_id   = intval($_POST['repair_id'] ?? 0);
    $photo_type  = trim($_POST['photo_type'] ?? 'vorher');
    $description = trim($_POST['description'] ?? '');

    $valid_photo_types = ['vorher', 'nachher', 'sonstiges'];
    if (!in_array($photo_type, $valid_photo_types, true)) {
        $photo_type = 'sonstiges';
    }

    if (!$repair_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'repair_id erforderlich']);
        exit;
    }

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Kein gültiges Foto hochgeladen']);
        exit;
    }

    $filename      = null;
    $original_name = null;
    $file_size     = null;

    if (function_exists('save_upload')) {
        $result = save_upload($_FILES['photo'], $repair_id);
        if ($result === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Upload fehlgeschlagen (Dateityp oder Größe)']);
            exit;
        }
        $filename      = $result['filename'];
        $original_name = $result['original_name'];
        $file_size     = $result['file_size'];
    } else {
        // Inline-Fallback
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültiger Dateityp']);
            exit;
        }
        if ($_FILES['photo']['size'] > 10 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Datei zu groß (max. 10 MB)']);
            exit;
        }
        $upload_dir = BASE_PATH . '/uploads/repairs/' . $repair_id;
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0750, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $upload_dir . '/' . $filename;
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern']);
            exit;
        }
        $original_name = basename($_FILES['photo']['name']);
        $file_size     = $_FILES['photo']['size'];
    }

    $stmt = $db->prepare(
        'INSERT INTO repair_photos (repair_id, filename, original_name, photo_type, description, file_size, uploaded_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$repair_id, $filename, $original_name, $photo_type, $description, $file_size, $_SESSION['user_id'] ?? null]);
    $photo_id = (int)$db->lastInsertId();

    log_activity('upload_photo', 'repairs', $repair_id);

    echo json_encode(['success' => true, 'photo_id' => $photo_id]);
    exit;
}

// ── get_request_photo (Foto einer Online-Reparaturanfrage) ────────────
if ($action === 'get_request_photo' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $photo_id = intval($_GET['id'] ?? 0);
    if (!$photo_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID erforderlich']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM repair_request_photos WHERE id = ?');
    $stmt->execute([$photo_id]);
    $photo = $stmt->fetch();

    if (!$photo) {
        http_response_code(404);
        echo json_encode(['error' => 'Foto nicht gefunden']);
        exit;
    }

    serve_request_photo($photo['filename'], (int)$photo['request_id']);
    exit;
}

// ── get_passcode ──────────────────────────────────────
// Hinweis (Phase 1 – risikoarme Fehlerbereinigung): Diese Abfrage liefert ein
// entschlüsseltes Geräte-Passwort und läuft daher bewusst über POST statt GET,
// damit die Anfrage nicht durch Browser/Proxys zwischengespeichert oder in der
// Adresszeile/History sichtbar wird. Verhalten und Rückgabeformat unverändert.
if ($action === 'get_passcode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID erforderlich']);
        exit;
    }

    $stmt = $db->prepare('SELECT passcode_encrypted, passcode_iv FROM repairs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Reparatur nicht gefunden']);
        exit;
    }

    $passcode = $row['passcode_encrypted'] ?? '';

    // Entschlüsseln falls IV vorhanden
    if (!empty($row['passcode_iv']) && !empty($passcode) && function_exists('decrypt_passcode')) {
        $passcode = decrypt_passcode($passcode, $row['passcode_iv']);
    }

    log_activity('view_passcode', 'repairs', $id);

    echo json_encode(['success' => true, 'passcode' => $passcode]);
    exit;
}

// ── get_photo ─────────────────────────────────────────
if ($action === 'get_photo' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $photo_id = intval($_GET['repair_photo_id'] ?? $_GET['id'] ?? 0);
    if (!$photo_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID erforderlich']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM repair_photos WHERE id = ?');
    $stmt->execute([$photo_id]);
    $photo = $stmt->fetch();

    if (!$photo) {
        http_response_code(404);
        echo json_encode(['error' => 'Foto nicht gefunden']);
        exit;
    }

    $full_path = UPLOAD_PATH . '/repairs/' . (int)$photo['repair_id'] . '/' . basename($photo['filename']);

    if (!file_exists($full_path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Datei nicht gefunden']);
        exit;
    }

    if (function_exists('serve_photo')) {
        serve_photo($photo['filename'], (int)$photo['repair_id']);
    } else {
        $ext  = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full_path));
        header('Cache-Control: private, max-age=3600');
        readfile($full_path);
    }
    exit;
}

// ── delete_photo ──────────────────────────────────────
if ($action === 'delete_photo') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    // CSRF wurde bereits oben für alle POST-Requests geprüft (verify_csrf()).
    $photo_id = intval($_POST['id'] ?? 0);
    if (!$photo_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID erforderlich']);
        exit;
    }

    $stmt = $db->prepare('SELECT filename, repair_id FROM repair_photos WHERE id = ?');
    $stmt->execute([$photo_id]);
    $photo = $stmt->fetch();

    if (!$photo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Foto nicht gefunden']);
        exit;
    }

    $full_path = UPLOAD_PATH . '/repairs/' . (int)$photo['repair_id'] . '/' . basename($photo['filename']);

    if (file_exists($full_path)) {
        unlink($full_path);
    }

    $db->prepare('DELETE FROM repair_photos WHERE id = ?')->execute([$photo_id]);
    log_activity('delete_photo', 'repairs', $photo['repair_id']);

    echo json_encode(['success' => true]);
    exit;
}

// ── add_part ──────────────────────────────────────────
if ($action === 'add_part' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $repair_id = intval($_POST['repair_id'] ?? 0);
    $part_id   = intval($_POST['part_id'] ?? 0);
    $quantity  = max(1, intval($_POST['quantity'] ?? 1));

    if (!$repair_id || !$part_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'repair_id und part_id erforderlich']);
        exit;
    }

    // Lagerbestand prüfen
    $stmt = $db->prepare('SELECT stock_quantity, purchase_price, markup_percent, automatic_selling_price, selling_price, selling_price_manual FROM parts WHERE id = ?');
    $stmt->execute([$part_id]);
    $part = $stmt->fetch();

    if (!$part) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ersatzteil nicht gefunden']);
        exit;
    }

    if ((int)$part['stock_quantity'] < $quantity) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nicht genügend Lagerbestand']);
        exit;
    }

    // Teil zur Reparatur hinzufügen (Preise UND Lieferantenzuordnung zum
    // aktuellen Zeitpunkt einfrieren – Phase 6, Auftragsabschnitt 8: auch
    // bei späteren Preis-/Lieferantenänderungen bleibt diese Reparatur
    // unverändert). product_snapshot_for_use() nutzt den bevorzugten
    // Lieferanten des Produkts, falls hinterlegt (siehe parts.php).
    $snapshot = function_exists('product_snapshot_for_use') ? product_snapshot_for_use($part_id) : [];
    $db->prepare(
        'INSERT INTO repair_parts
            (repair_id, part_id, quantity, purchase_price_at_time, selling_price_at_time,
             markup_percent_at_time, automatic_selling_price_at_time, selling_price_manual,
             supplier_id_at_time, supplier_name_at_time, supplier_offer_id_at_time)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $repair_id, $part_id, $quantity,
        $snapshot['purchase_price_at_time'] ?? $part['purchase_price'],
        $snapshot['selling_price_at_time'] ?? $part['selling_price'],
        $part['markup_percent'] ?? '10.00',
        $part['automatic_selling_price'] ?? null,
        $part['selling_price_manual'] ?? 0,
        $snapshot['supplier_id_at_time'] ?? null,
        $snapshot['supplier_name_at_time'] ?? null,
        $snapshot['supplier_offer_id_at_time'] ?? null,
    ]);

    // Lagerbestand reduzieren
    $db->prepare('UPDATE parts SET stock_quantity = stock_quantity - ? WHERE id = ?')
       ->execute([$quantity, $part_id]);

    log_activity('add_part', 'repairs', $repair_id, 'part_id=' . $part_id . ' qty=' . $quantity);

    echo json_encode(['success' => true]);
    exit;
}

// ── Fallback ──────────────────────────────────────────
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
