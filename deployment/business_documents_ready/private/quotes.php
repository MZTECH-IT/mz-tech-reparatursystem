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
require_once __DIR__ . '/billing.php';

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
    $subtotalCents = 0;
    foreach ($items as $i) {
        $quantityHundredths = (int)round((float)$i['quantity'] * 100);
        $unitCents = (int)round((float)$i['unit_price'] * 100);
        $subtotalCents += (int)round($quantityHundredths * $unitCents / 100);
    }
    $stmt = $db->prepare('SELECT billing_mode, tax_rate FROM quotes WHERE id = ?');
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $amounts = billing_amounts(
        number_format($subtotalCents / 100, 2, '.', ''),
        $quote['billing_mode'] ?? billing_mode(),
        $quote['tax_rate'] ?? null
    );
    $db->prepare('UPDATE quotes SET subtotal = ?, tax_amount = ?, total = ? WHERE id = ?')
       ->execute([$amounts['subtotal'], $amounts['tax_amount'], $amounts['total'], $quoteId]);
}

/** Legt ein neues Angebot im Status "entwurf" an (noch keine Nummernvergabe). */
function quote_create_draft(array $data, array $items, ?int $userId): int {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO quotes (status, repair_id, customer_id, company_id, title, notes, planned_work, internal_notes,
                                 valid_until, currency, billing_mode, tax_rate, legal_notice, version_no, created_by)
             VALUES ("entwurf", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $data['repair_id'] ?: null,
            $data['customer_id'] ?: null,
            $data['company_id'] ?: null,
            trim($data['title'] ?? '') ?: null,
            trim($data['notes'] ?? '') ?: null,
            trim($data['planned_work'] ?? '') ?: null,
            trim($data['internal_notes'] ?? '') ?: null,
            $data['valid_until'] ?: null,
            $data['currency'] ?: 'EUR',
            billing_mode(),
            billing_tax_rate(),
            billing_legal_notice(),
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
            'UPDATE quotes SET repair_id = ?, customer_id = ?, company_id = ?, title = ?, notes = ?, planned_work = ?, internal_notes = ?,
                               valid_until = ?, currency = ?, billing_mode = ?, tax_rate = ?, legal_notice = ?
              WHERE id = ?'
        )->execute([
            $data['repair_id'] ?: null,
            $data['customer_id'] ?: null,
            $data['company_id'] ?: null,
            trim($data['title'] ?? '') ?: null,
            trim($data['notes'] ?? '') ?: null,
            trim($data['planned_work'] ?? '') ?: null,
            trim($data['internal_notes'] ?? '') ?: null,
            $data['valid_until'] ?: null,
            $data['currency'] ?: 'EUR',
            billing_mode(),
            billing_tax_rate(),
            billing_legal_notice(),
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
    $stmt = $db->prepare('INSERT INTO quote_items (quote_id, item_type, part_id, description, quantity, unit_price, position) VALUES (?,?,?,?,?,?,?)');
    $pos = 0;
    foreach ($items as $item) {
        $desc = trim($item['description'] ?? '');
        if ($desc === '') continue;
        $stmt->execute([
            $quoteId,
            in_array($item['item_type'] ?? '', ['part', 'labor', 'service'], true) ? $item['item_type'] : (!empty($item['part_id']) ? 'part' : 'service'),
            !empty($item['part_id']) ? (int)$item['part_id'] : null,
            $desc,
            repair_decimal_input($item['quantity'] ?? '1', 'Menge', '999999.99'),
            repair_decimal_input($item['unit_price'] ?? '0', 'Einzelpreis', '9999999.99'),
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
        if (in_array($quote['status'], ['angenommen', 'abgelehnt', 'storniert', 'in_rechnung_umgewandelt'], true)) {
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
        $customerCompany = null;
        if ($quote['company_id']) {
            $cos = $db->prepare('SELECT * FROM companies WHERE id = ?');
            $cos->execute([$quote['company_id']]);
            $customerCompany = $cos->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $snapshot = build_document_snapshot([
            'type'          => 'angebot',
            'quote_number'  => $number,
            'quote'         => $quote,
            'customer'      => $customer,
            'customer_company' => $customerCompany,
            'items'         => $items,
            'company'       => function_exists('pdf_company_info') ? pdf_company_info() : [],
            'billing'       => [
                'billing_mode' => $quote['billing_mode'] ?? billing_mode(),
                'tax_rate' => $quote['tax_rate'] ?? billing_tax_rate(),
                'tax_amount' => $quote['tax_amount'] ?? '0.00',
                'legal_notice' => $quote['legal_notice'] ?? billing_legal_notice(),
                'small_business' => billing_is_small_business($quote['billing_mode'] ?? null),
            ],
            'version_no'    => (int)($quote['version_no'] ?? 1),
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
    $allowed = ['entwurf', 'zur_pruefung', 'freigegeben', 'gesendet', 'angenommen', 'abgelehnt', 'abgelaufen', 'storniert', 'in_rechnung_umgewandelt'];
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
            $quantity = repair_decimal_input($item['quantity'], 'Menge', '999999.99');
            if ((float)$quantity !== (float)(int)$quantity || (int)$quantity < 1) {
                throw new InvalidArgumentException('Ersatzteilmengen müssen positive ganze Zahlen sein.');
            }
            $partStmt = $db->prepare(
                'SELECT stock_quantity, purchase_price, markup_percent, automatic_selling_price, selling_price
                   FROM parts WHERE id = ? FOR UPDATE'
            );
            $partStmt->execute([$item['part_id']]);
            $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            if (!$part) throw new RuntimeException('Eine Angebotsposition verweist auf ein nicht mehr vorhandenes Ersatzteil.');
            if ((int)$part['stock_quantity'] < (int)$quantity) {
                throw new RuntimeException('Der Lagerbestand reicht für die Angebotsübernahme nicht aus.');
            }
            $db->prepare(
                'INSERT INTO repair_parts
                    (repair_id, part_id, quantity, purchase_price_at_time, selling_price_at_time,
                     markup_percent_at_time, automatic_selling_price_at_time, selling_price_manual)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $repairId, $item['part_id'], (int)$quantity, $part['purchase_price'], $item['unit_price'],
                $part['markup_percent'], $part['automatic_selling_price'],
                ((float)$item['unit_price'] !== (float)$part['automatic_selling_price']) ? 1 : 0,
            ]);
            $db->prepare('UPDATE parts SET stock_quantity = stock_quantity - ? WHERE id = ?')
               ->execute([(int)$quantity, $item['part_id']]);
        }
        $db->prepare('UPDATE quotes SET converted_to_repair_id = ? WHERE id = ?')->execute([$repairId, $quoteId]);
        $db->commit();
        log_activity('convert_to_repair', 'quotes', $quoteId, 'repair_id=' . $repairId);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Erstellt aus einem angenommenen Angebot einen Rechnungsentwurf auf dem
 * verknüpften Reparaturauftrag. Das Angebot und sein Snapshot bleiben
 * unverändert; eine Rechnungsnummer wird erst bei invoice_release() vergeben.
 */
function quote_convert_to_invoice_draft(
    int $quoteId,
    int $repairId,
    array $selectedQuantities,
    ?string $serviceDate,
    ?int $userId
): array {
    $db = get_db();
    $db->beginTransaction();
    try {
        $qs = $db->prepare('SELECT * FROM quotes WHERE id = ? FOR UPDATE');
        $qs->execute([$quoteId]);
        $quote = $qs->fetch(PDO::FETCH_ASSOC);
        if (!$quote) throw new RuntimeException('Angebot nicht gefunden.');
        if ($quote['status'] !== 'angenommen') {
            throw new RuntimeException('Nur ein angenommenes Angebot kann in eine Rechnung übernommen werden.');
        }
        if (!empty($quote['converted_to_invoice_repair_id'])) {
            throw new RuntimeException('Dieses Angebot wurde bereits in einen Rechnungsentwurf übernommen.');
        }

        $rs = $db->prepare('SELECT * FROM repairs WHERE id = ? FOR UPDATE');
        $rs->execute([$repairId]);
        $repair = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$repair) throw new RuntimeException('Reparaturauftrag nicht gefunden.');
        if (!empty($repair['invoice_number'])) {
            throw new RuntimeException('Der Reparaturauftrag besitzt bereits eine freigegebene Rechnung.');
        }
        if (!empty($quote['repair_id']) && (int)$quote['repair_id'] !== $repairId) {
            throw new RuntimeException('Das Angebot ist einem anderen Reparaturauftrag zugeordnet.');
        }
        if (!empty($quote['customer_id']) && (int)$quote['customer_id'] !== (int)$repair['customer_id']) {
            throw new RuntimeException('Angebot und Reparaturauftrag gehören nicht zum selben Kunden.');
        }

        $items = quote_items_list($quoteId);
        $serviceCents = 0;
        $laborCents = 0;
        $laborHoursHundredths = 0;
        $selectedSnapshot = [];
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            if (!array_key_exists($itemId, $selectedQuantities)) continue;
            $quantity = repair_decimal_input($selectedQuantities[$itemId], 'Menge', '999999.99');
            if ((float)$quantity <= 0) continue;
            $unitPrice = repair_decimal_input($item['unit_price'], 'Einzelpreis', '9999999.99');
            $selectedSnapshot[] = [
                'quote_item_id' => $itemId,
                'item_type' => $item['item_type'] ?? (!empty($item['part_id']) ? 'part' : 'service'),
                'part_id' => $item['part_id'] ? (int)$item['part_id'] : null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
            $itemType = $item['item_type'] ?? (!empty($item['part_id']) ? 'part' : 'service');
            if ($itemType === 'part' || !empty($item['part_id'])) {
                if (empty($item['part_id'])) continue;
                if ((float)$quantity !== (float)(int)$quantity) {
                    throw new InvalidArgumentException('Ersatzteilmengen müssen ganzzahlig sein.');
                }
                $ps = $db->prepare(
                    'SELECT stock_quantity, purchase_price, markup_percent, automatic_selling_price
                       FROM parts WHERE id = ? FOR UPDATE'
                );
                $ps->execute([(int)$item['part_id']]);
                $part = $ps->fetch(PDO::FETCH_ASSOC);
                if (!$part) throw new RuntimeException('Eine ausgewählte Ersatzteilposition ist nicht mehr vorhanden.');
                if ((int)$part['stock_quantity'] < (int)$quantity) {
                    throw new RuntimeException('Der Lagerbestand reicht für die Rechnungsübernahme nicht aus.');
                }
                $db->prepare(
                    'INSERT INTO repair_parts
                        (repair_id, part_id, quantity, purchase_price_at_time, selling_price_at_time,
                         markup_percent_at_time, automatic_selling_price_at_time, selling_price_manual)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $repairId, (int)$item['part_id'], (int)$quantity,
                    $part['purchase_price'], $unitPrice, $part['markup_percent'],
                    $part['automatic_selling_price'],
                    ((float)$unitPrice !== (float)$part['automatic_selling_price']) ? 1 : 0,
                ]);
                $db->prepare('UPDATE parts SET stock_quantity = stock_quantity - ? WHERE id = ?')
                   ->execute([(int)$quantity, (int)$item['part_id']]);
            } elseif ($itemType === 'labor') {
                $quantityHundredths = (int)round((float)$quantity * 100);
                $unitCents = (int)round((float)$unitPrice * 100);
                $laborHoursHundredths += $quantityHundredths;
                $laborCents += (int)round($quantityHundredths * $unitCents / 100);
            } else {
                $quantityHundredths = (int)round((float)$quantity * 100);
                $unitCents = (int)round((float)$unitPrice * 100);
                $serviceCents += (int)round($quantityHundredths * $unitCents / 100);
            }
        }
        if (!$selectedSnapshot) throw new RuntimeException('Es wurde keine Position zur Übernahme ausgewählt.');

        $newServicePrice = number_format(((int)round((float)($repair['price'] ?? 0) * 100) + $serviceCents) / 100, 2, '.', '');
        $workingHours = $laborHoursHundredths > 0 ? number_format($laborHoursHundredths / 100, 2, '.', '') : ($repair['working_hours'] ?? '0.00');
        $laborCost = $laborHoursHundredths > 0 ? number_format($laborCents / 100, 2, '.', '') : ($repair['labor_cost'] ?? '0.00');
        $hourlyRate = $laborHoursHundredths > 0 ? number_format($laborCents * 100 / $laborHoursHundredths / 100, 2, '.', '') : ($repair['hourly_rate'] ?? '79.00');
        $db->prepare(
            'UPDATE repairs SET price=?,working_hours=?,hourly_rate=?,labor_cost=?,
                    performed_work=COALESCE(NULLIF(performed_work,""),?),service_date=?,quote_source_id=?,invoice_status="entwurf" WHERE id=?'
        )->execute([$newServicePrice,$workingHours,$hourlyRate,$laborCost,trim((string)($quote['planned_work'] ?? '')) ?: null,$serviceDate ?: null,$quoteId,$repairId]);

        $history = build_document_snapshot([
            'type' => 'quote_to_invoice_draft',
            'quote_id' => $quoteId,
            'quote_number' => $quote['quote_number'],
            'repair_id' => $repairId,
            'selected_items' => $selectedSnapshot,
            'service_date' => $serviceDate,
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->prepare(
            'INSERT INTO invoice_conversion_history (quote_id, repair_id, selected_items_json, created_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$quoteId, $repairId, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
        $db->prepare(
            'UPDATE quotes SET status = "in_rechnung_umgewandelt", converted_to_invoice_repair_id = ?, converted_at = NOW(), converted_by = ? WHERE id = ?'
        )->execute([$repairId, $userId, $quoteId]);
        $db->commit();
        log_activity('convert_to_invoice_draft', 'quotes', $quoteId, 'repair_id=' . $repairId);
        return ['success' => true, 'repair_id' => $repairId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
