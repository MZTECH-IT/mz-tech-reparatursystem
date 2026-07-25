<?php
/**
 * MZ Tech – Angebote (eigenständiges Dokument, Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Ein Angebot ("quotes") ist – anders als der Kostenvoranschlag (KV, der
 * weiterhin fest an einen bereits angelegten Reparaturauftrag gebunden
 * bleibt, siehe private/invoicing.php) – bewusst UNABHÄNGIG von einem
 * Reparaturauftrag erstellbar (z. B. Vorab-Angebot, bevor ein Auftrag
 * überhaupt existiert), kann aber optional mit einem bereits bestehenden
 * Reparaturauftrag verknüpft werden (repair_id, analog zum bereits
 * etablierten Muster bei Tickets, siehe private/tickets.php).
 *
 * Eigener Nummernkreis "ANG" (seit Phase 2 reserviert, ab hier erstmals
 * aktiv genutzt). Nutzt für die Kundenentscheidungs-Protokollierung
 * dieselbe Tabelle wie der Kostenvoranschlag (quote_decisions,
 * doc_type='angebot'), siehe quote_decision_record() in
 * private/invoicing.php.
 */

require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/invoicing.php'; // build_document_snapshot()

function quotes_list(array $filters = []): array {
    $db = get_db();
    $where = [];
    $params = [];
    if (!empty($filters['status']))      { $where[] = 'q.status = ?';      $params[] = $filters['status']; }
    if (!empty($filters['customer_id'])) { $where[] = 'q.customer_id = ?'; $params[] = $filters['customer_id']; }
    if (!empty($filters['company_id']))  { $where[] = 'q.company_id = ?';  $params[] = $filters['company_id']; }
    $sql = 'SELECT q.*, c.first_name, c.last_name, co.company_name
              FROM quotes q
              LEFT JOIN customers c  ON c.id  = q.customer_id
              LEFT JOIN companies co ON co.id = q.company_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY q.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function quote_find(int $id): ?array {
    $stmt = get_db()->prepare(
        'SELECT q.*, c.first_name, c.last_name, c.address, c.email, c.phone, co.company_name
           FROM quotes q
           LEFT JOIN customers c  ON c.id  = q.customer_id
           LEFT JOIN companies co ON co.id = q.company_id
          WHERE q.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function quote_items_list(int $quoteId): array {
    $stmt = get_db()->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY position ASC, id ASC');
    $stmt->execute([$quoteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Berechnet Zwischensumme/MwSt./Gesamt aus den aktuellen Positionen und speichert sie auf dem Angebot. */
function quote_totals_recalc(int $quoteId): void {
    $db = get_db();
    $items = quote_items_list($quoteId);
    $subtotal = 0.0;
    foreach ($items as $i) {
        $subtotal += (float)$i['quantity'] * (float)$i['unit_price'];
    }
    $stmt = $db->prepare('SELECT tax_rate FROM quotes WHERE id = ?');
    $stmt->execute([$quoteId]);
    $taxRate = (float)($stmt->fetchColumn() ?: 0);
    $ustg = function_exists('pdf_ustg_active') ? pdf_ustg_active() : ($taxRate <= 0);
    $tax = $ustg ? 0.0 : round($subtotal * $taxRate / 100, 2);
    $total = round($subtotal + $tax, 2);
    $db->prepare('UPDATE quotes SET subtotal = ?, tax_amount = ?, total = ? WHERE id = ?')
       ->execute([round($subtotal, 2), $tax, $total, $quoteId]);
}

/** Legt ein neues Angebot im Status "entwurf" an (noch keine Nummernvergabe). */
function quote_create_draft(array $data, array $items, ?int $userId): int {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO quotes (status, repair_id, customer_id, company_id, title, notes, valid_until, currency, tax_rate, created_by)
             VALUES ("entwurf", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['repair_id'] ?: null,
            $data['customer_id'] ?: null,
            $data['company_id'] ?: null,
            trim($data['title'] ?? '') ?: null,
            trim($data['notes'] ?? '') ?: null,
            $data['valid_until'] ?: null,
            $data['currency'] ?: 'EUR',
            (float)str_replace(',', '.', (string)($data['tax_rate'] ?? get_setting('tax_rate', '0'))),
            $userId,
        ]);
        $quoteId = (int)$db->lastInsertId();
        quote_items_replace($quoteId, $items);
        $db->commit();
        log_activity('create', 'quotes', $quoteId);
        return $quoteId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function quote_update(int $quoteId, array $data, array $items): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE quotes SET repair_id = ?, customer_id = ?, company_id = ?, title = ?, notes = ?, valid_until = ?, currency = ?, tax_rate = ?
              WHERE id = ?'
        )->execute([
            $data['repair_id'] ?: null,
            $data['customer_id'] ?: null,
            $data['company_id'] ?: null,
            trim($data['title'] ?? '') ?: null,
            trim($data['notes'] ?? '') ?: null,
            $data['valid_until'] ?: null,
            $data['currency'] ?: 'EUR',
            (float)str_replace(',', '.', (string)($data['tax_rate'] ?? 0)),
            $quoteId,
        ]);
        quote_items_replace($quoteId, $items);
        $db->commit();
        log_activity('update', 'quotes', $quoteId);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function quote_items_replace(int $quoteId, array $items): void {
    $db = get_db();
    $db->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$quoteId]);
    $stmt = $db->prepare('INSERT INTO quote_items (quote_id, part_id, description, quantity, unit_price, position) VALUES (?,?,?,?,?,?)');
    $pos = 0;
    foreach ($items as $item) {
        $desc = trim($item['description'] ?? '');
        if ($desc === '') continue;
        $stmt->execute([
            $quoteId,
            !empty($item['part_id']) ? (int)$item['part_id'] : null,
            $desc,
            max(1, (int)($item['quantity'] ?? 1)),
            (float)str_replace(',', '.', (string)($item['unit_price'] ?? 0)),
            $pos++,
        ]);
    }
    quote_totals_recalc($quoteId);
}

/**
 * Gibt ein Angebot verbindlich frei: vergibt die ANG-Nummer (nur beim
 * ersten Mal), speichert einen unveränderlichen Snapshot, Status auf
 * "freigegeben".
 */
function quote_release(int $quoteId, ?int $userId): array {
    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM quotes WHERE id = ? FOR UPDATE');
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) throw new RuntimeException('Angebot nicht gefunden.');
        if (in_array($quote['status'], ['angenommen', 'abgelehnt', 'storniert'], true)) {
            throw new RuntimeException('Dieses Angebot befindet sich bereits in einem abschließenden Status und kann nicht erneut freigegeben werden.');
        }

        $number = $quote['quote_number'];
        if (empty($number)) {
            $number = generate_document_number('ANG');
        }

        $items = quote_items_list($quoteId);
        $customer = null;
        if ($quote['customer_id']) {
            $cs = $db->prepare('SELECT * FROM customers WHERE id = ?');
            $cs->execute([$quote['customer_id']]);
            $customer = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $snapshot = build_document_snapshot([
            'type'          => 'angebot',
            'quote_number'  => $number,
            'quote'         => $quote,
            'customer'      => $customer,
            'items'         => $items,
            'company'       => function_exists('pdf_company_info') ? pdf_company_info() : [],
            'released_by'   => $userId,
            'released_at'   => date('Y-m-d H:i:s'),
        ]);

        $db->prepare('UPDATE quotes SET quote_number = ?, status = "freigegeben", released_by = ?, released_at = NOW(), snapshot_json = ? WHERE id = ?')
           ->execute([$number, $userId, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $quoteId]);

        $db->commit();
        log_activity('release', 'quotes', $quoteId, $number);
        return ['success' => true, 'message' => "Angebot $number wurde freigegeben.", 'quote_number' => $number];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function quote_set_status(int $quoteId, string $status): void {
    $allowed = ['entwurf', 'freigegeben', 'gesendet', 'angenommen', 'abgelehnt', 'abgelaufen', 'storniert'];
    if (!in_array($status, $allowed, true)) return;
    get_db()->prepare('UPDATE quotes SET status = ? WHERE id = ?')->execute([$status, $quoteId]);
    log_activity('update_status', 'quotes', $quoteId, "status=$status");
}

/**
 * Übernimmt die Positionen eines angenommenen Angebots als Ersatzteil-/
 * Leistungspositionen in einen BEREITS bestehenden Reparaturauftrag
 * (repair_parts, mit eingefrorenem Preis zum Übernahmezeitpunkt – exakt
 * das bestehende Schnappschuss-Muster aus Phase 1, keine neue Mechanik).
 * Das Angebot selbst bleibt dabei unverändert erhalten, es wird lediglich
 * die Referenz converted_to_repair_id gesetzt.
 */
function quote_convert_to_repair(int $quoteId, int $repairId): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $items = quote_items_list($quoteId);
        foreach ($items as $item) {
            if (empty($item['part_id'])) continue; // nur echte Ersatzteil-Positionen übernehmbar
            $partStmt = $db->prepare('SELECT purchase_price, selling_price FROM parts WHERE id = ?');
            $partStmt->execute([$item['part_id']]);
            $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            if (!$part) continue;
            $db->prepare(
                'INSERT INTO repair_parts (repair_id, part_id, quantity, purchase_price_at_time, selling_price_at_time)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$repairId, $item['part_id'], $item['quantity'], $part['purchase_price'], $item['unit_price']]);
        }
        $db->prepare('UPDATE quotes SET converted_to_repair_id = ? WHERE id = ?')->execute([$repairId, $quoteId]);
        $db->commit();
        log_activity('convert_to_repair', 'quotes', $quoteId, 'repair_id=' . $repairId);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
