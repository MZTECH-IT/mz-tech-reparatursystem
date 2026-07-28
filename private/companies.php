<?php
/**
 * MZ Tech – Firmenkunden (Phase 5)
 * ----------------------------------------------------------------------
 * Geschäftslogik für Firmen (companies), Ansprechpartner
 * (company_contacts), Projekte (projects) und Firmendokumente
 * (company_documents). Wird sowohl vom Admin-Bereich (public/companies.php,
 * public/companies_form.php) als auch vom Firmenkundenportal
 * (public/portal_business.php) genutzt.
 *
 * Grundsatz gegen doppelte Kundendaten (siehe Master-Auftrag Phase 5):
 * ein bestehender Kunde (customers) wird über customers.company_id einer
 * Firma zugeordnet – seine Reparaturen, Kostenvoranschläge und Rechnungen
 * werden dadurch automatisch Teil des Firmenkundenportals, OHNE dass
 * irgendetwas dupliziert wird. Es gibt KEINE eigene "Firmen-Reparatur"-
 * Tabelle.
 */

// ── Firmen (companies) ────────────────────────────────────────────────
require_once __DIR__ . '/numbering.php';

function companies_list(bool $onlyActive = false): array {
    $sql = 'SELECT c.*,
                   (SELECT COUNT(*) FROM company_contacts cc WHERE cc.company_id = c.id) AS contact_count,
                   (SELECT COUNT(*) FROM customers cu WHERE cu.company_id = c.id) AS customer_count,
                   (SELECT COUNT(*) FROM projects p WHERE p.company_id = c.id) AS project_count
            FROM companies c';
    if ($onlyActive) $sql .= ' WHERE c.is_active = 1';
    $sql .= ' ORDER BY c.company_name ASC';
    return get_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function company_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function company_create(array $data, ?int $userId): int {
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO companies (company_name, address, city, zip, phone, email, website, tax_id, vat_id, notes, is_active, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,1,?)'
    );
    $stmt->execute([
        trim($data['company_name'] ?? ''),
        trim($data['address'] ?? '') ?: null,
        trim($data['city']    ?? '') ?: null,
        trim($data['zip']     ?? '') ?: null,
        trim($data['phone']   ?? '') ?: null,
        trim($data['email']   ?? '') ?: null,
        trim($data['website'] ?? '') ?: null,
        trim($data['tax_id']  ?? '') ?: null,
        trim($data['vat_id']  ?? '') ?: null,
        trim($data['notes']   ?? '') ?: null,
        $userId,
    ]);
    return (int)$db->lastInsertId();
}

function company_update(int $id, array $data): void {
    get_db()->prepare(
        'UPDATE companies
            SET company_name=?, address=?, city=?, zip=?, phone=?, email=?, website=?, tax_id=?, vat_id=?, notes=?, is_active=?
          WHERE id=?'
    )->execute([
        trim($data['company_name'] ?? ''),
        trim($data['address'] ?? '') ?: null,
        trim($data['city']    ?? '') ?: null,
        trim($data['zip']     ?? '') ?: null,
        trim($data['phone']   ?? '') ?: null,
        trim($data['email']   ?? '') ?: null,
        trim($data['website'] ?? '') ?: null,
        trim($data['tax_id']  ?? '') ?: null,
        trim($data['vat_id']  ?? '') ?: null,
        trim($data['notes']   ?? '') ?: null,
        !empty($data['is_active']) ? 1 : 0,
        $id,
    ]);
}

/** Liste aller Kunden (customers), die dieser Firma zugeordnet sind. */
function company_customers(int $companyId): array {
    $stmt = get_db()->prepare('SELECT * FROM customers WHERE company_id = ? ORDER BY last_name, first_name');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Alle Reparaturaufträge (inkl. Kostenvoranschlag/Rechnung) der Firma – über die zugeordneten Kunden. */
function company_repairs(int $companyId): array {
    $stmt = get_db()->prepare(
        'SELECT r.*, c.first_name, c.last_name
         FROM repairs r
         JOIN customers c ON c.id = r.customer_id
         WHERE c.company_id = ?
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Ordnet einen bestehenden Kunden einer Firma zu (oder hebt die Zuordnung auf, wenn $companyId = null). */
function customer_assign_company(int $customerId, ?int $companyId): void {
    get_db()->prepare('UPDATE customers SET company_id = ? WHERE id = ?')->execute([$companyId, $customerId]);
}

// ── Ansprechpartner (company_contacts) ────────────────────────────────
function company_contacts_list(int $companyId): array {
    $stmt = get_db()->prepare('SELECT * FROM company_contacts WHERE company_id = ? ORDER BY is_primary DESC, last_name, first_name');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function company_contact_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM company_contacts WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function company_contact_find_by_email(string $email): ?array {
    $stmt = get_db()->prepare('SELECT * FROM company_contacts WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Legt einen neuen Ansprechpartner mit eigenem Login an (Admin-Aktion).
 * Ein zufälliges Initialpasswort wird gesetzt und NIE angezeigt – der
 * Ansprechpartner erhält stattdessen einen Bestätigungslink per E-Mail,
 * über den er sein eigenes Passwort festlegt (analog zur
 * Kundenkonto-Verifizierung).
 */
function company_contact_create(int $companyId, array $data): array {
    $first_name = trim($data['first_name'] ?? '');
    $last_name  = trim($data['last_name']  ?? '');
    $email      = trim($data['email']      ?? '');

    if ($first_name === '' || $last_name === '' || $email === '') {
        return ['success' => false, 'message' => 'Bitte Vorname, Nachname und E-Mail-Adresse angeben.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.'];
    }
    if (company_contact_find_by_email($email)) {
        return ['success' => false, 'message' => 'Für diese E-Mail-Adresse besteht bereits ein Ansprechpartner-Zugang.'];
    }

    $db = get_db();
    $randomPassword = bin2hex(random_bytes(16));
    $hash  = password_hash($randomPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $token = portal_new_token();
    $portalRole = in_array(($data['portal_role'] ?? ''), ['admin', 'employee', 'read_only'], true)
        ? $data['portal_role'] : 'employee';

    $stmt = $db->prepare(
        'INSERT INTO company_contacts
            (company_id, first_name, last_name, email, phone, role_title, portal_role,
             password_hash, is_primary, is_active, is_verified, verify_token, verify_token_hash, verify_expires)
         VALUES (?,?,?,?,?,?,?,?,?,1,0,NULL,?,DATE_ADD(NOW(), INTERVAL 7 DAY))'
    );
    $stmt->execute([
        $companyId, $first_name, $last_name, $email,
        trim($data['phone'] ?? '') ?: null,
        trim($data['role_title'] ?? '') ?: null,
        $portalRole,
        $hash,
        !empty($data['is_primary']) ? 1 : 0,
        $token['hash'],
    ]);
    $contactId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO portal_invitations
            (recipient_type, recipient_id, email, token_hash, expires_at)
         VALUES (\'company_contact\', ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
    )->execute([$contactId, $email, $token['hash']]);

    $verifyLink = base_app_url() . '/portal_business.php?activate=' . $token['plain'];
    try {
        $company = company_find($companyId);
        if (portal_email_delivery_enabled()) {
            send_generic_template_email('firmenkontakt_konto_erstellt', [
                'first_name' => $first_name, 'last_name' => $last_name, 'email' => $email,
            ], [
                'verify_link' => $verifyLink,
                'firma'       => $company['company_name'] ?? get_setting('company_name', 'MZ Tech'),
            ]);
        }
    } catch (Throwable $e) {
        error_log('company_contact_create: Mail fehlgeschlagen: ' . $e->getMessage());
    }

    portal_audit('company_contact_invited', 'staff', null, 'company_contacts', $contactId, [
        'company_id' => $companyId,
        'portal_role' => $portalRole,
        'email_delivery' => portal_email_delivery_enabled(),
    ]);
    return [
        'success' => true,
        'message' => portal_email_delivery_enabled()
            ? 'Ansprechpartner wurde angelegt und die Einladung versendet.'
            : 'Ansprechpartner wurde angelegt. Der Portal-E-Mail-Versand ist deaktiviert; die Einladung wurde nicht versendet.',
        'id' => $contactId,
        'activation_link' => portal_email_delivery_enabled() ? null : $verifyLink,
    ];
}

function company_contact_update(int $id, array $data): void {
    get_db()->prepare(
        'UPDATE company_contacts
         SET first_name=?, last_name=?, phone=?, role_title=?, portal_role=?, is_primary=?, is_active=?
         WHERE id=?'
    )->execute([
        trim($data['first_name'] ?? ''),
        trim($data['last_name']  ?? ''),
        trim($data['phone'] ?? '') ?: null,
        trim($data['role_title'] ?? '') ?: null,
        in_array(($data['portal_role'] ?? ''), ['admin', 'employee', 'read_only'], true)
            ? $data['portal_role'] : 'employee',
        !empty($data['is_primary']) ? 1 : 0,
        !empty($data['is_active']) ? 1 : 0,
        $id,
    ]);
}

/**
 * Aktiviert einen per E-Mail eingeladenen Ansprechpartner-Zugang und
 * setzt das erste, selbst gewählte Passwort (Token aus der Einladungsmail).
 */
function company_contact_activate(string $token, string $password, string $password2): array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Aktivierungslink.'];
    }
    if ($password !== $password2) {
        return ['success' => false, 'message' => 'Die Passwörter stimmen nicht überein.'];
    }
    if (mb_strlen($password) < 8) {
        return ['success' => false, 'message' => 'Das Passwort muss mindestens 8 Zeichen lang sein.'];
    }

    $db = get_db();
    $stmt = $db->prepare(
        'SELECT * FROM company_contacts
         WHERE verify_token_hash = ? OR verify_token = ?
         LIMIT 1'
    );
    $stmt->execute([portal_token_hash($token), $token]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact || (!empty($contact['verify_expires']) && strtotime($contact['verify_expires']) < time())) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Aktivierungslink. Bitte kontaktieren Sie uns für einen neuen Zugang.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $db->prepare(
        'UPDATE company_contacts
         SET password_hash=?, is_verified=1, verify_token=NULL, verify_token_hash=NULL, verify_expires=NULL
         WHERE id=?'
    )
       ->execute([$hash, $contact['id']]);
    $db->prepare(
        'UPDATE portal_invitations SET accepted_at = NOW()
         WHERE recipient_type = \'company_contact\' AND recipient_id = ?
           AND token_hash = ? AND accepted_at IS NULL'
    )->execute([$contact['id'], portal_token_hash($token)]);
    portal_audit('company_contact_activated', 'company_contact', (int)$contact['id'], 'company_contacts', (int)$contact['id']);

    log_activity('business_contact_activated', 'company_contacts', (int)$contact['id'], 'Zugang aktiviert: ' . $contact['email']);

    return ['success' => true, 'message' => 'Ihr Zugang wurde aktiviert. Sie können sich jetzt anmelden.'];
}

function company_contact_request_password_reset(string $email): array {
    $email = trim($email);
    $generic = [
        'success' => true,
        'message' => portal_email_delivery_enabled()
            ? 'Falls zu dieser E-Mail-Adresse ein Zugang besteht, wurde ein Link zum Zurücksetzen versendet.'
            : 'Falls zu dieser E-Mail-Adresse ein Zugang besteht, kontaktieren Sie bitte MZ Tech. Der E-Mail-Versand ist derzeit deaktiviert.',
    ];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $generic;

    $contact = company_contact_find_by_email($email);
    if ($contact && (int)$contact['is_active']) {
        $token = portal_new_token();
        get_db()->prepare(
            'UPDATE company_contacts
             SET reset_token=NULL, reset_token_hash=?, reset_expires=DATE_ADD(NOW(), INTERVAL 60 MINUTE)
             WHERE id=?'
        )->execute([$token['hash'], $contact['id']]);
        try {
            if (portal_email_delivery_enabled()) {
                $reset_link = base_app_url() . '/portal_business.php?reset=' . $token['plain'];
                send_generic_template_email('firmenkontakt_passwort_reset', [
                    'first_name' => $contact['first_name'], 'last_name' => $contact['last_name'], 'email' => $email,
                ], ['reset_link' => $reset_link]);
            }
        } catch (Throwable $e) {
            error_log('company_contact_request_password_reset: Mail fehlgeschlagen: ' . $e->getMessage());
        }
        log_activity('business_contact_password_reset_request', 'company_contacts', (int)$contact['id'], 'Passwort-Reset angefordert');
    }
    return $generic;
}

function company_contact_reset_password(string $token, string $password, string $password2): array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Link.'];
    }
    if ($password !== $password2) {
        return ['success' => false, 'message' => 'Die Passwörter stimmen nicht überein.'];
    }
    if (mb_strlen($password) < 8) {
        return ['success' => false, 'message' => 'Das Passwort muss mindestens 8 Zeichen lang sein.'];
    }

    $db = get_db();
    $stmt = $db->prepare(
        'SELECT * FROM company_contacts
         WHERE reset_token_hash = ? OR reset_token = ?
         LIMIT 1'
    );
    $stmt->execute([portal_token_hash($token), $token]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact || empty($contact['reset_expires']) || strtotime($contact['reset_expires']) < time()) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Link. Bitte fordern Sie einen neuen an.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $db->prepare(
        'UPDATE company_contacts
         SET password_hash=?, reset_token=NULL, reset_token_hash=NULL, reset_expires=NULL, is_verified=1
         WHERE id=?'
    )
       ->execute([$hash, $contact['id']]);
    portal_audit('company_contact_password_reset', 'company_contact', (int)$contact['id'], 'company_contacts', (int)$contact['id']);

    log_activity('business_contact_password_reset', 'company_contacts', (int)$contact['id'], 'Passwort erfolgreich zurückgesetzt');

    return ['success' => true, 'message' => 'Ihr Passwort wurde erfolgreich geändert. Sie können sich jetzt anmelden.'];
}

function company_contact_change_password(int $contactId, string $current, string $new, string $new2): array {
    if ($new !== $new2 || mb_strlen($new) < 8) {
        return ['success' => false, 'message' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein und beide Eingaben müssen übereinstimmen.'];
    }
    $contact = company_contact_find($contactId);
    if (!$contact || !password_verify($current, (string)$contact['password_hash'])) {
        return ['success' => false, 'message' => 'Das aktuelle Passwort ist falsch.'];
    }
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    get_db()->prepare('UPDATE company_contacts SET password_hash = ? WHERE id = ?')->execute([$hash, $contactId]);
    portal_audit('business_password_changed', 'company_contact', $contactId, 'company_contacts', $contactId);
    return ['success' => true, 'message' => 'Das Passwort wurde geändert.'];
}

// ── Projekte (projects) ────────────────────────────────────────────────
function projects_list_for_company(int $companyId): array {
    $stmt = get_db()->prepare('SELECT * FROM projects WHERE company_id = ? ORDER BY created_at DESC');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function project_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function project_create(int $companyId, array $data, ?int $userId): int {
    $db = get_db();
    $project_number = generate_document_number('PRJ');
    $stmt = $db->prepare(
        'INSERT INTO projects (project_number, company_id, name, description, status, created_by)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        $project_number, $companyId,
        trim($data['name'] ?? ''),
        trim($data['description'] ?? '') ?: null,
        in_array($data['status'] ?? '', ['aktiv', 'abgeschlossen', 'pausiert'], true) ? $data['status'] : 'aktiv',
        $userId,
    ]);
    return (int)$db->lastInsertId();
}

function project_update(int $id, array $data): void {
    get_db()->prepare('UPDATE projects SET name=?, description=?, status=? WHERE id=?')->execute([
        trim($data['name'] ?? ''),
        trim($data['description'] ?? '') ?: null,
        in_array($data['status'] ?? '', ['aktiv', 'abgeschlossen', 'pausiert'], true) ? $data['status'] : 'aktiv',
        $id,
    ]);
}

/** Alle Reparaturaufträge, die einem Projekt zugeordnet sind. */
function project_repairs(int $projectId): array {
    $stmt = get_db()->prepare(
        'SELECT r.*, c.first_name, c.last_name FROM repairs r
         JOIN customers c ON c.id = r.customer_id
         WHERE r.project_id = ? ORDER BY r.created_at DESC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Firmendokumente (company_documents) ───────────────────────────────
function company_documents_list(int $companyId): array {
    $stmt = get_db()->prepare(
        'SELECT d.*, u.full_name AS uploaded_by_name FROM company_documents d
         LEFT JOIN users u ON u.id = d.uploaded_by
         WHERE d.company_id = ? ORDER BY d.created_at DESC'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function company_document_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM company_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Speichert ein hochgeladenes Firmendokument (beliebiger Dateityp, siehe ALLOWED_DOCUMENT_EXTENSIONS unten). */
function company_document_save(array $file, int $companyId, ?string $description, ?int $uploadedBy): array|false {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
    if (!in_array($ext, $allowed, true)) return false;

    $dir = UPLOAD_PATH . '/companies/' . $companyId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return false;

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO company_documents (company_id, filename, original_name, file_size, description, uploaded_by)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([$companyId, $filename, basename($file['name']), $file['size'], $description ?: null, $uploadedBy]);

    return ['id' => (int)$db->lastInsertId(), 'filename' => $filename];
}

function company_document_delete(int $id): void {
    $doc = company_document_find($id);
    if (!$doc) return;
    $path = UPLOAD_PATH . '/companies/' . $doc['company_id'] . '/' . $doc['filename'];
    if (is_file($path)) @unlink($path);
    get_db()->prepare('DELETE FROM company_documents WHERE id = ?')->execute([$id]);
}

/** Streamt ein Firmendokument sicher aus (siehe serve_photo() für das gleiche Muster). */
function serve_company_document(int $documentId): void {
    $doc = company_document_find($documentId);
    if (!$doc) { http_response_code(404); exit; }
    $path = UPLOAD_PATH . '/companies/' . $doc['company_id'] . '/' . $doc['filename'];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'pdf'         => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'doc'         => 'application/msword',
        'docx'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'         => 'application/vnd.ms-excel',
        'xlsx'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        default       => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name'] ?: $doc['filename']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}
