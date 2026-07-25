<?php
/**
 * MZ Tech – Ticketsystem (Phase 5)
 * ----------------------------------------------------------------------
 * Geschäftslogik für Tickets, Kommentare und Anhänge. Ein Ticket kann
 * WAHLWEISE mit einem Reparaturauftrag verknüpft sein (repair_id) ODER
 * eigenständig stehen (repair_id = NULL) – beides ist laut Architektur-
 * entscheidung (Master-Auftrag Phase 3-5) ausdrücklich gewünscht.
 *
 * Polymorpher Akteur (created_by_type/author_type): 'staff' (Mitarbeiter,
 * ref = users.id), 'customer' (Privatkunde, ref = customers.id) oder
 * 'company_contact' (Firmenkunden-Ansprechpartner, ref = company_contacts.id).
 * Vermeidet drei separate Tabellen/Spaltensätze für denselben Zweck.
 */

function ticket_valid_statuses(): array {
    return ['offen', 'in_bearbeitung', 'wartet_auf_kunde', 'geloest', 'geschlossen'];
}

function ticket_status_label(string $status): string {
    return match ($status) {
        'offen'            => 'Offen',
        'in_bearbeitung'   => 'In Bearbeitung',
        'wartet_auf_kunde' => 'Wartet auf Kunde',
        'geloest'          => 'Gelöst',
        'geschlossen'      => 'Geschlossen',
        default            => ucfirst($status),
    };
}

function ticket_status_badge(string $status): string {
    $cls = match ($status) {
        'offen'            => 'badge-blue',
        'in_bearbeitung'   => 'badge-yellow',
        'wartet_auf_kunde' => 'badge-orange',
        'geloest'          => 'badge-green',
        'geschlossen'      => 'badge-gray',
        default            => 'badge-gray',
    };
    return '<span class="badge ' . $cls . '">' . h(ticket_status_label($status)) . '</span>';
}

function ticket_priority_label(string $priority): string {
    return match ($priority) {
        'niedrig'  => 'Niedrig',
        'normal'   => 'Normal',
        'hoch'     => 'Hoch',
        'dringend' => 'Dringend',
        default    => ucfirst($priority),
    };
}

function ticket_priority_badge(string $priority): string {
    $cls = match ($priority) {
        'niedrig'  => 'badge-gray',
        'normal'   => 'badge-blue',
        'hoch'     => 'badge-orange',
        'dringend' => 'badge-red',
        default    => 'badge-gray',
    };
    return '<span class="badge ' . $cls . '">' . h(ticket_priority_label($priority)) . '</span>';
}

/**
 * Legt ein neues Ticket an. $actor: ['type' => 'staff'|'customer'|'company_contact', 'ref' => int|null].
 * $data: subject, description, priority, repair_id, customer_id, company_contact_id, assigned_technician_id
 */
function ticket_create(array $data, array $actor): array {
    $subject = trim($data['subject'] ?? '');
    if ($subject === '') {
        return ['success' => false, 'message' => 'Bitte einen Betreff angeben.'];
    }

    $priority = in_array($data['priority'] ?? '', ['niedrig', 'normal', 'hoch', 'dringend'], true)
        ? $data['priority'] : 'normal';

    $db = get_db();
    $ticket_number = generate_document_number('TIC');

    $stmt = $db->prepare(
        'INSERT INTO tickets
            (ticket_number, subject, description, status, priority, repair_id, customer_id,
             company_contact_id, assigned_technician_id, created_by_type, created_by_ref)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $ticket_number,
        $subject,
        trim($data['description'] ?? '') ?: null,
        'offen',
        $priority,
        !empty($data['repair_id']) ? (int)$data['repair_id'] : null,
        !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
        !empty($data['company_contact_id']) ? (int)$data['company_contact_id'] : null,
        !empty($data['assigned_technician_id']) ? (int)$data['assigned_technician_id'] : null,
        $actor['type'] ?? 'staff',
        $actor['ref'] ?? null,
    ]);
    $ticketId = (int)$db->lastInsertId();

    // Initiale Beschreibung zusätzlich als erster (öffentlicher) Kommentar
    // spiegeln, damit der Gesprächsverlauf durchgängig über ticket_comments
    // abgebildet ist.
    if (!empty($data['description'])) {
        ticket_add_comment($ticketId, trim($data['description']), $actor, false, notify: false);
    }

    log_activity('ticket_created', 'tickets', $ticketId, 'Ticket ' . $ticket_number . ' erstellt');

    // Benachrichtigung, falls das Ticket NICHT vom Kunden/Ansprechpartner
    // selbst erstellt wurde (sonst würde man sich selbst benachrichtigen).
    if (($actor['type'] ?? 'staff') === 'staff') {
        ticket_notify('ticket_erstellt', $ticketId);
    }

    return ['success' => true, 'message' => 'Ticket ' . $ticket_number . ' wurde erstellt.', 'id' => $ticketId, 'ticket_number' => $ticket_number];
}

function ticket_find(int $id): ?array {
    $stmt = get_db()->prepare(
        'SELECT t.*, r.repair_number,
                c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                cc.first_name AS contact_first_name, cc.last_name AS contact_last_name, cc.company_id,
                u.full_name AS technician_name,
                comp.company_name
         FROM tickets t
         LEFT JOIN repairs r ON r.id = t.repair_id
         LEFT JOIN customers c ON c.id = t.customer_id
         LEFT JOIN company_contacts cc ON cc.id = t.company_contact_id
         LEFT JOIN companies comp ON comp.id = cc.company_id
         LEFT JOIN users u ON u.id = t.assigned_technician_id
         WHERE t.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Admin-Liste mit optionalen Filtern (status, priority, q). */
function tickets_list(array $filters = []): array {
    $where = [];
    $params = [];
    if (!empty($filters['status'])) { $where[] = 't.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['priority'])) { $where[] = 't.priority = ?'; $params[] = $filters['priority']; }
    if (!empty($filters['q'])) {
        $where[] = '(t.ticket_number LIKE ? OR t.subject LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params[] = $like; $params[] = $like;
    }
    $sql = 'SELECT t.*, r.repair_number,
                   c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                   cc.first_name AS contact_first_name, cc.last_name AS contact_last_name,
                   comp.company_name,
                   u.full_name AS technician_name
            FROM tickets t
            LEFT JOIN repairs r ON r.id = t.repair_id
            LEFT JOIN customers c ON c.id = t.customer_id
            LEFT JOIN company_contacts cc ON cc.id = t.company_contact_id
            LEFT JOIN companies comp ON comp.id = cc.company_id
            LEFT JOIN users u ON u.id = t.assigned_technician_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY FIELD(t.priority,\'dringend\',\'hoch\',\'normal\',\'niedrig\'), t.created_at DESC';

    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tickets_list_for_customer(int $customerId): array {
    $stmt = get_db()->prepare('SELECT t.*, r.repair_number FROM tickets t LEFT JOIN repairs r ON r.id = t.repair_id WHERE t.customer_id = ? ORDER BY t.created_at DESC');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tickets_list_for_company(int $companyId): array {
    $stmt = get_db()->prepare(
        'SELECT t.*, r.repair_number, cc.first_name AS contact_first_name, cc.last_name AS contact_last_name
         FROM tickets t
         LEFT JOIN repairs r ON r.id = t.repair_id
         JOIN company_contacts cc ON cc.id = t.company_contact_id
         WHERE cc.company_id = ?
         ORDER BY t.created_at DESC'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticket_comments(int $ticketId, bool $includeInternal): array {
    $sql = 'SELECT tc.*, u.full_name AS staff_name
            FROM ticket_comments tc
            LEFT JOIN users u ON u.id = tc.author_ref AND tc.author_type = \'staff\'
            WHERE tc.ticket_id = ?';
    if (!$includeInternal) $sql .= ' AND tc.is_internal = 0';
    $sql .= ' ORDER BY tc.created_at ASC';
    $stmt = get_db()->prepare($sql);
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticket_attachments(int $ticketId): array {
    $stmt = get_db()->prepare('SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY created_at ASC');
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fügt einen Kommentar/eine Antwort hinzu. $actor: ['type'=>, 'ref'=>].
 * $isInternal: interne Notiz, für Kunden/Firmenkontakte NIE sichtbar.
 * $notify: Benachrichtigungsmail an den/die jeweils andere Seite senden.
 */
function ticket_add_comment(int $ticketId, string $body, array $actor, bool $isInternal = false, bool $notify = true): int {
    $body = trim($body);
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO ticket_comments (ticket_id, author_type, author_ref, body, is_internal) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$ticketId, $actor['type'] ?? 'staff', $actor['ref'] ?? null, $body, $isInternal ? 1 : 0]);
    $commentId = (int)$db->lastInsertId();

    $db->prepare('UPDATE tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticketId]);

    if ($notify && !$isInternal) {
        // Nur Mitarbeiter-Antworten benachrichtigen den Kunden (und umgekehrt
        // löst eine Kunden-Antwort keine automatische Mail an sich selbst aus).
        if (($actor['type'] ?? 'staff') === 'staff') {
            ticket_notify('ticket_kommentar', $ticketId);
        }
    }

    return $commentId;
}

function ticket_add_attachment(int $ticketId, ?int $commentId, array $fileInfo): void {
    get_db()->prepare(
        'INSERT INTO ticket_attachments (ticket_id, comment_id, filename, original_name, file_size) VALUES (?,?,?,?,?)'
    )->execute([$ticketId, $commentId, $fileInfo['filename'], $fileInfo['original_name'], $fileInfo['file_size']]);
}

/** Speichert einen Ticket-Anhang (beliebiger Nutzer-Upload, gleiches Muster wie company_document_save()). */
function ticket_attachment_save(array $file, int $ticketId): array|false {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = array_merge(ALLOWED_EXTENSIONS, ['pdf', 'doc', 'docx', 'txt', 'log']);
    if (!in_array($ext, $allowed, true)) return false;

    $dir = UPLOAD_PATH . '/tickets/' . $ticketId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return false;

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) return false;

    return ['filename' => $filename, 'original_name' => basename($file['name']), 'file_size' => $file['size']];
}

function serve_ticket_attachment(int $attachmentId): void {
    $stmt = get_db()->prepare('SELECT * FROM ticket_attachments WHERE id = ? LIMIT 1');
    $stmt->execute([$attachmentId]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$att) { http_response_code(404); exit; }

    $path = UPLOAD_PATH . '/tickets/' . $att['ticket_id'] . '/' . $att['filename'];
    if (!file_exists($path)) { http_response_code(404); exit; }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: inline; filename="' . rawurlencode($att['original_name'] ?: $att['filename']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function ticket_update_status(int $ticketId, string $status): void {
    if (!in_array($status, ticket_valid_statuses(), true)) return;
    $db = get_db();
    $closed_at = in_array($status, ['geloest', 'geschlossen'], true) ? date('Y-m-d H:i:s') : null;
    $db->prepare('UPDATE tickets SET status = ?, closed_at = ? WHERE id = ?')->execute([$status, $closed_at, $ticketId]);
    log_activity('ticket_status_changed', 'tickets', $ticketId, 'Status geändert auf: ' . $status);
    ticket_notify('ticket_status', $ticketId, ['status' => ticket_status_label($status)]);
}

function ticket_assign_technician(int $ticketId, ?int $technicianId): void {
    get_db()->prepare('UPDATE tickets SET assigned_technician_id = ? WHERE id = ?')->execute([$technicianId, $ticketId]);
    log_activity('ticket_assigned', 'tickets', $ticketId, 'Techniker zugewiesen');
}

/**
 * Sendet eine Benachrichtigung an den Kunden ODER Firmenkontakt eines
 * Tickets (je nachdem, wer zugeordnet ist) über die generische
 * Vorlagen-E-Mail-Funktion. "Best effort" – ein Mailfehler darf niemals
 * die eigentliche Ticket-Aktion zum Scheitern bringen.
 */
function ticket_notify(string $templateKey, int $ticketId, array $extraPlaceholders = []): void {
    try {
        $ticket = ticket_find($ticketId);
        if (!$ticket) return;

        $to = null;
        if (!empty($ticket['customer_id'])) {
            $stmt = get_db()->prepare('SELECT first_name, last_name, email FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$ticket['customer_id']]);
            $to = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif (!empty($ticket['company_contact_id'])) {
            $stmt = get_db()->prepare('SELECT first_name, last_name, email FROM company_contacts WHERE id = ? LIMIT 1');
            $stmt->execute([$ticket['company_contact_id']]);
            $to = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$to || empty($to['email'])) return;

        $portal_link = !empty($ticket['customer_id'])
            ? base_app_url() . '/portal_tickets.php?id=' . $ticketId
            : base_app_url() . '/portal_business.php?view=ticket&id=' . $ticketId;

        send_generic_template_email($templateKey, $to, array_merge([
            'ticketnummer'  => $ticket['ticket_number'],
            'ticketbetreff' => $ticket['subject'],
            'portal_link'   => $portal_link,
        ], $extraPlaceholders));
    } catch (Throwable $e) {
        error_log('ticket_notify: fehlgeschlagen: ' . $e->getMessage());
    }
}
