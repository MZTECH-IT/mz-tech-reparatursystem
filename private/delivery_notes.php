<?php
/**
 * MZ Tech – Lieferscheine (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Eigener Nummernkreis "LS" (seit Phase 2 reserviert, ab hier erstmals
 * aktiv genutzt). Ein Lieferschein kann optional mit Kunde, Firma,
 * Projekt, Reparaturauftrag (der zugleich die Rechnung ist, siehe
 * private/invoicing.php) und/oder einer Lieferanten-Bestellung
 * (purchase_orders, Phase 6) verknüpft werden.
 */

require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/invoicing.php'; // build_document_snapshot()

function delivery_notes_list(array $filters = []): array {
    $db = get_db();
    $where = [];
    $params = [];
    if (!empty($filters['status']))  { $where[] = 'dn.status = ?';  $params[] = $filters['status']; }
    if (!empty($filters['repair_id'])) { $where[] = 'dn.repair_id = ?'; $params[] = $filters['repair_id']; }
    $sql = 'SELECT dn.*, c.first_name, c.last_name, co.company_name
              FROM delivery_notes dn
              LEFT JOIN customers c  ON c.id  = dn.customer_id
              LEFT JOIN companies co ON co.id = dn.company_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY dn.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function delivery_note_find(int $id): ?array {
    $stmt = get_db()->prepare(
        'SELECT dn.*, c.first_name, c.last_name, c.address, co.company_name
           FROM delivery_notes dn
           LEFT JOIN customers c  ON c.id  = dn.customer_id
           LEFT JOIN companies co ON co.id = dn.company_id
          WHERE dn.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function delivery_note_items_list(int $id): array {
    $stmt = get_db()->prepare('SELECT * FROM delivery_note_items WHERE delivery_note_id = ? ORDER BY position ASC, id ASC');
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function delivery_note_create_draft(array $data, array $items, ?int $userId): int {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO delivery_notes (status, customer_id, company_id, project_id, repair_id, purchase_order_id, notes, created_by)
             VALUES ("entwurf", ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['customer_id'] ?: null,
            $data['company_id'] ?: null,
            $data['project_id'] ?: null,
            $data['repair_id'] ?: null,
            $data['purchase_order_id'] ?: null,
            trim($data['notes'] ?? '') ?: null,
            $userId,
        ]);
        $id = (int)$db->lastInsertId();
        delivery_note_items_replace($id, $items);
        $db->commit();
        log_activity('create', 'delivery_notes', $id);
        return $id;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function delivery_note_items_replace(int $id, array $items): void {
    $db = get_db();
    $db->prepare('DELETE FROM delivery_note_items WHERE delivery_note_id = ?')->execute([$id]);
    $stmt = $db->prepare('INSERT INTO delivery_note_items (delivery_note_id, part_id, description, quantity, position) VALUES (?,?,?,?,?)');
    $pos = 0;
    foreach ($items as $item) {
        $desc = trim($item['description'] ?? '');
        if ($desc === '') continue;
        $stmt->execute([
            $id,
            !empty($item['part_id']) ? (int)$item['part_id'] : null,
            $desc,
            max(1, (int)($item['quantity'] ?? 1)),
            $pos++,
        ]);
    }
}

/**
 * Finalisiert einen Lieferschein: vergibt die LS-Nummer, friert die
 * Positionen als Snapshot ein, Status auf "erstellt" (bereit zum
 * Versand). Danach gilt der Lieferschein als abgeschlossen; jede weitere
 * Änderung muss dokumentiert erfolgen (keine stille Änderung, siehe
 * Abschnitt 6 des Auftrags) – hier über das ohnehin vorhandene
 * Aktivitätsprotokoll sichergestellt.
 */
function delivery_note_finalize(int $id, ?int $userId): array {
    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM delivery_notes WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $dn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dn) throw new RuntimeException('Lieferschein nicht gefunden.');
        if (!empty($dn['delivery_note_number'])) {
            throw new RuntimeException('Dieser Lieferschein wurde bereits finalisiert (Nummer ' . $dn['delivery_note_number'] . ').');
        }

        $number = generate_document_number('LS');
        $items = delivery_note_items_list($id);

        $snapshot = build_document_snapshot([
            'type'                  => 'lieferschein',
            'delivery_note_number'  => $number,
            'delivery_note'         => $dn,
            'items'                 => $items,
            'company'               => function_exists('pdf_company_info') ? pdf_company_info() : [],
            'finalized_by'          => $userId,
            'finalized_at'          => date('Y-m-d H:i:s'),
        ]);

        $db->prepare('UPDATE delivery_notes SET delivery_note_number = ?, status = "erstellt", finalized_by = ?, finalized_at = NOW(), snapshot_json = ? WHERE id = ?')
           ->execute([$number, $userId, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);

        $db->commit();
        log_activity('finalize', 'delivery_notes', $id, $number);
        return ['success' => true, 'message' => "Lieferschein $number wurde erstellt.", 'delivery_note_number' => $number];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function delivery_note_set_status(int $id, string $status): void {
    $allowed = ['entwurf', 'erstellt', 'versendet', 'zugestellt', 'storniert'];
    if (!in_array($status, $allowed, true)) return;
    get_db()->prepare('UPDATE delivery_notes SET status = ? WHERE id = ?')->execute([$status, $id]);
    log_activity('update_status', 'delivery_notes', $id, "status=$status");
}
