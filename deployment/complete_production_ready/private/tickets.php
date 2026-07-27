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
    return ['offen', 'in_bearbeitung', 'wartet_auf_kunde', 'wartet_intern', 'geloest', 'geschlossen', 'storniert'];
}

function ticket_status_label(string $status): string {
    return match ($status) {
        'offen'            => 'Offen',
        'in_bearbeitung'   => 'In Bearbeitung',
        'wartet_auf_kunde' => 'Wartet auf Kunde',
        'wartet_intern'    => 'Wartet intern',
        'geloest'          => 'Gelöst',
        'geschlossen'      => 'Geschlossen',
        'storniert'        => 'Storniert',
        default            => ucfirst($status),
    };
}

function ticket_status_badge(string $status): string {
    $cls = match ($status) {
        'offen'            => 'badge-blue',
        'in_bearbeitung'   => 'badge-yellow',
        'wartet_auf_kunde' => 'badge-orange',
        'wartet_intern'    => 'badge-yellow',
        'geloest'          => 'badge-green',
        'geschlossen'      => 'badge-gray',
        'storniert'        => 'badge-red',
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
    $actorType = in_array(($actor['type'] ?? ''), ['staff', 'customer', 'company_contact'], true)
        ? $actor['type'] : 'staff';
    $actorRef = !empty($actor['ref']) ? (int)$actor['ref'] : null;
    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
    $contactId = !empty($data['company_contact_id']) ? (int)$data['company_contact_id'] : null;
    $companyId = !empty($data['company_id']) ? (int)$data['company_id'] : null;
    $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;

    if ($actorType === 'customer') {
        $customerId = $actorRef;
        $contactId = $companyId = $projectId = null;
    } elseif ($actorType === 'company_contact') {
        $contactStmt = $db->prepare(
            'SELECT company_id, is_active, is_verified FROM company_contacts WHERE id = ? LIMIT 1'
        );
        $contactStmt->execute([$actorRef]);
        $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact || !(int)$contact['is_active'] || !(int)$contact['is_verified']) {
            return ['success' => false, 'message' => 'Der Firmenzugang ist nicht aktiv.'];
        }
        $contactId = $actorRef;
        $companyId = (int)$contact['company_id'];
        $customerId = null;
        if ($projectId) {
            $projectStmt = $db->prepare(
                "SELECT id FROM projects WHERE id = ? AND company_id = ? AND status = 'aktiv' LIMIT 1"
            );
            $projectStmt->execute([$projectId, $companyId]);
            if (!$projectStmt->fetchColumn()) {
                return ['success' => false, 'message' => 'Das ausgewählte Projekt ist nicht verfügbar.'];
            }
        }
    } elseif ($contactId && !$companyId) {
        $companyStmt = $db->prepare('SELECT company_id FROM company_contacts WHERE id = ? LIMIT 1');
        $companyStmt->execute([$contactId]);
        $resolvedCompany = $companyStmt->fetchColumn();
        $companyId = $resolvedCompany ? (int)$resolvedCompany : null;
    }

    $stmt = $db->prepare(
        'INSERT INTO tickets
            (ticket_number, subject, description, status, priority, category, repair_id, customer_id,
             company_id, company_contact_id, project_id, assigned_technician_id, team, due_at,
             preferred_contact, preferred_date, location, customer_reference, project_request_text,
             created_by_type, created_by_ref)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $ticket_number,
        $subject,
        trim($data['description'] ?? '') ?: null,
        'offen',
        $priority,
        trim($data['category'] ?? '') ?: 'support',
        !empty($data['repair_id']) ? (int)$data['repair_id'] : null,
        $customerId,
        $companyId,
        $contactId,
        $projectId,
        !empty($data['assigned_technician_id']) ? (int)$data['assigned_technician_id'] : null,
        trim($data['team'] ?? '') ?: null,
        !empty($data['due_at']) ? $data['due_at'] : null,
        trim($data['preferred_contact'] ?? '') ?: null,
        !empty($data['preferred_date']) ? $data['preferred_date'] : null,
        trim($data['location'] ?? '') ?: null,
        trim($data['customer_reference'] ?? '') ?: null,
        trim($data['project_request_text'] ?? '') ?: null,
        $actorType,
        $actorRef,
    ]);
    $ticketId = (int)$db->lastInsertId();

    // Initiale Beschreibung zusätzlich als erster (öffentlicher) Kommentar
    // spiegeln, damit der Gesprächsverlauf durchgängig über ticket_comments
    // abgebildet ist.
    if (!empty($data['description'])) {
        ticket_add_comment($ticketId, trim($data['description']), $actor, false, notify: false);
    }

    log_activity('ticket_created', 'tickets', $ticketId, 'Ticket ' . $ticket_number . ' erstellt');
    ticket_add_history($ticketId, 'created', $actor, null, 'offen');
    portal_audit('ticket_created', $actorType, $actorRef, 'tickets', $ticketId, [
        'company_id' => $companyId,
        'customer_id' => $customerId,
    ]);

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
                cc.first_name AS contact_first_name, cc.last_name AS contact_last_name,
                COALESCE(t.company_id, cc.company_id) AS company_id,
                u.full_name AS technician_name,
                comp.company_name, p.name AS project_name, p.project_number
         FROM tickets t
         LEFT JOIN repairs r ON r.id = t.repair_id
         LEFT JOIN customers c ON c.id = t.customer_id
         LEFT JOIN company_contacts cc ON cc.id = t.company_contact_id
         LEFT JOIN companies comp ON comp.id = COALESCE(t.company_id, cc.company_id)
         LEFT JOIN projects p ON p.id = t.project_id
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
    if (!empty($filters['project_id'])) { $where[] = 't.project_id = ?'; $params[] = (int)$filters['project_id']; }
    if (!empty($filters['company_id'])) { $where[] = 'COALESCE(t.company_id, cc.company_id) = ?'; $params[] = (int)$filters['company_id']; }
    if (!empty($filters['company_contact_id'])) { $where[] = 't.company_contact_id = ?'; $params[] = (int)$filters['company_contact_id']; }
    if (!empty($filters['date_from'])) { $where[] = 't.created_at >= ?'; $params[] = $filters['date_from'] . ' 00:00:00'; }
    if (!empty($filters['date_to'])) { $where[] = 't.created_at <= ?'; $params[] = $filters['date_to'] . ' 23:59:59'; }
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
            LEFT JOIN companies comp ON comp.id = COALESCE(t.company_id, cc.company_id)
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
         LEFT JOIN company_contacts cc ON cc.id = t.company_contact_id
         WHERE t.company_id = ? OR cc.company_id = ?
         ORDER BY t.created_at DESC'
    );
    $stmt->execute([$companyId, $companyId]);
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
    if ($body === '') return 0;
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO ticket_comments (ticket_id, author_type, author_ref, body, is_internal) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$ticketId, $actor['type'] ?? 'staff', $actor['ref'] ?? null, $body, $isInternal ? 1 : 0]);
    $commentId = (int)$db->lastInsertId();
    ticket_add_history($ticketId, $isInternal ? 'internal_note' : 'public_reply', $actor);

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
        'INSERT INTO ticket_attachments
            (ticket_id, comment_id, filename, original_name, file_size, mime_type)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $ticketId, $commentId, $fileInfo['filename'], $fileInfo['original_name'],
        $fileInfo['file_size'], $fileInfo['mime_type'] ?? 'application/octet-stream',
    ]);
}

/** Speichert einen Ticket-Anhang (beliebiger Nutzer-Upload, gleiches Muster wie company_document_save()). */
function ticket_attachment_save(array $file, int $ticketId): array|false {
    $dir = UPLOAD_PATH . '/tickets/' . $ticketId;
    return portal_safe_upload($file, $dir);
}

function ticket_attachment_find(int $attachmentId): ?array {
    $stmt = get_db()->prepare(
        'SELECT ta.*, tc.is_internal
         FROM ticket_attachments ta
         LEFT JOIN ticket_comments tc ON tc.id = ta.comment_id
         WHERE ta.id = ? LIMIT 1'
    );
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ticket_access_for_customer(int $ticketId, int $customerId): bool {
    $stmt = get_db()->prepare('SELECT 1 FROM tickets WHERE id = ? AND customer_id = ? LIMIT 1');
    $stmt->execute([$ticketId, $customerId]);
    return (bool)$stmt->fetchColumn();
}

function ticket_access_for_company(int $ticketId, int $companyId): bool {
    $stmt = get_db()->prepare(
        'SELECT 1 FROM tickets t
         LEFT JOIN company_contacts cc ON cc.id = t.company_contact_id
         WHERE t.id = ? AND (t.company_id = ? OR cc.company_id = ?)
         LIMIT 1'
    );
    $stmt->execute([$ticketId, $companyId, $companyId]);
    return (bool)$stmt->fetchColumn();
}

function serve_ticket_attachment(int $attachmentId): void {
    $stmt = get_db()->prepare('SELECT * FROM ticket_attachments WHERE id = ? LIMIT 1');
    $stmt->execute([$attachmentId]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$att) { http_response_code(404); exit; }

    $path = UPLOAD_PATH . '/tickets/' . $att['ticket_id'] . '/' . $att['filename'];
    if (!file_exists($path)) { http_response_code(404); exit; }

    header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . rawurlencode($att['original_name'] ?: $att['filename']) . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function ticket_update_status(int $ticketId, string $status): void {
    if (!in_array($status, ticket_valid_statuses(), true)) return;
    $db = get_db();
    $oldStmt = $db->prepare('SELECT status FROM tickets WHERE id = ? LIMIT 1');
    $oldStmt->execute([$ticketId]);
    $oldStatus = (string)$oldStmt->fetchColumn();
    $closed_at = in_array($status, ['geloest', 'geschlossen'], true) ? date('Y-m-d H:i:s') : null;
    $db->prepare('UPDATE tickets SET status = ?, closed_at = ? WHERE id = ?')->execute([$status, $closed_at, $ticketId]);
    ticket_add_history(
        $ticketId,
        'status_changed',
        ['type' => 'staff', 'ref' => $_SESSION['user_id'] ?? null],
        $oldStatus,
        $status
    );
    log_activity('ticket_status_changed', 'tickets', $ticketId, 'Status geändert auf: ' . $status);
    ticket_notify('ticket_status', $ticketId, ['status' => ticket_status_label($status)]);
}

function ticket_assign_technician(int $ticketId, ?int $technicianId): void {
    get_db()->prepare('UPDATE tickets SET assigned_technician_id = ? WHERE id = ?')->execute([$technicianId, $ticketId]);
    ticket_add_history(
        $ticketId,
        'assignment_changed',
        ['type' => 'staff', 'ref' => $_SESSION['user_id'] ?? null],
        null,
        $technicianId ? (string)$technicianId : null
    );
    log_activity('ticket_assigned', 'tickets', $ticketId, 'Techniker zugewiesen');
}

function ticket_add_history(
    int $ticketId,
    string $eventType,
    array $actor,
    ?string $oldValue = null,
    ?string $newValue = null,
    array $metadata = []
): void {
    try {
        get_db()->prepare(
            'INSERT INTO ticket_history
                (ticket_id, event_type, actor_type, actor_ref, old_value, new_value, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ticketId,
            $eventType,
            $actor['type'] ?? 'system',
            $actor['ref'] ?? null,
            $oldValue,
            $newValue,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        error_log('ticket_add_history: ' . $e->getMessage());
    }
}

function ticket_history(int $ticketId, bool $includeInternal = true): array {
    $sql = 'SELECT * FROM ticket_history WHERE ticket_id = ?';
    if (!$includeInternal) {
        $sql .= " AND event_type NOT IN ('internal_note','assignment_changed','system')";
    }
    $sql .= ' ORDER BY created_at ASC';
    $stmt = get_db()->prepare($sql);
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticket_links(int $ticketId, bool $portalOnly = false): array {
    $sql = 'SELECT * FROM ticket_links WHERE ticket_id = ?';
    if ($portalOnly) $sql .= ' AND is_portal_visible = 1';
    $sql .= ' ORDER BY created_at ASC';
    $stmt = get_db()->prepare($sql);
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticket_link_add(int $ticketId, string $linkType, int $linkId, bool $portalVisible, ?int $createdBy): bool {
    if (!in_array($linkType, ['repair', 'project', 'purchase_order', 'document'], true) || $linkId < 1) {
        return false;
    }
    get_db()->prepare(
        'INSERT INTO ticket_links (ticket_id, link_type, link_id, is_portal_visible, created_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_portal_visible = VALUES(is_portal_visible)'
    )->execute([$ticketId, $linkType, $linkId, $portalVisible ? 1 : 0, $createdBy]);
    ticket_add_history(
        $ticketId,
        'link_added',
        ['type' => 'staff', 'ref' => $createdBy],
        null,
        $linkType . ':' . $linkId
    );
    return true;
}

/**
 * Sendet eine Benachrichtigung an den Kunden ODER Firmenkontakt eines
 * Tickets (je nachdem, wer zugeordnet ist) über die generische
 * Vorlagen-E-Mail-Funktion. "Best effort" – ein Mailfehler darf niemals
 * die eigentliche Ticket-Aktion zum Scheitern bringen.
 */
function ticket_notify(string $templateKey, int $ticketId, array $extraPlaceholders = []): void {
    if (!portal_email_delivery_enabled()) return;
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
