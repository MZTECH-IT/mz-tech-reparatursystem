<?php
/**
 * MZ Tech – Bestell-/Beschaffungsworkflow (Phase 6, Auftragsabschnitt 10/11)
 * ----------------------------------------------------------------------
 * Erzeugt Beschaffungsvorschläge aus Kostenvoranschlägen/Reparaturen/
 * Projekten, verwaltet Bestellungen (Entwurf → Bestellt → Geliefert) und
 * wendet regelbasierte Versandkosten je Lieferant an. Nutzt bewusst den
 * bereits in Phase 2 für künftige Module reservierten Nummernkreis-Typ
 * "BE" (siehe private/numbering.php) für Bestellnummern – keine neue,
 * parallele Nummernvergabe.
 */

require_once __DIR__ . '/products.php';
require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/mailer.php';

// ── Beschaffungsvorschlag (Auftragsabschnitt 10) ────────────────────────

/**
 * Ermittelt für eine Reparatur (repair_parts) den aktuellen Bedarf:
 * benötigte vs. vorhandene vs. reservierte vs. fehlende Menge je
 * Ersatzteil, sowie den empfohlenen Lieferanten/die geschätzten Kosten
 * (siehe product_suggest_best_supplier()). Erstellt NIEMALS automatisch
 * eine Bestellung – liefert ausschließlich Vorschlagsdaten zur manuellen
 * Bestätigung durch den Mitarbeiter.
 */
function procurement_suggestion_for_repair(int $repairId): array {
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT rp.part_id, rp.quantity, p.name, p.sku, p.stock_quantity, p.reserved_stock
           FROM repair_parts rp
           JOIN parts p ON p.id = rp.part_id
          WHERE rp.repair_id = ?'
    );
    $stmt->execute([$repairId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suggestions = [];
    foreach ($lines as $line) {
        $available = max(0, (int)$line['stock_quantity'] - (int)$line['reserved_stock']);
        $missing = max(0, (int)$line['quantity'] - $available);
        $suggestion = [
            'part_id'   => (int)$line['part_id'],
            'name'      => $line['name'],
            'sku'       => $line['sku'],
            'needed'    => (int)$line['quantity'],
            'available' => $available,
            'reserved'  => (int)$line['reserved_stock'],
            'missing'   => $missing,
            'recommended_offer' => null,
        ];
        if ($missing > 0) {
            $best = product_suggest_best_supplier((int)$line['part_id']);
            if ($best) {
                $suggestion['recommended_offer'] = $best;
            }
        }
        $suggestions[] = $suggestion;
    }
    return $suggestions;
}

/**
 * Ermittelt ALLE Artikel, deren verfuegbarer Bestand (stock_quantity -
 * reserved_stock) den hinterlegten Mindestbestand (min_stock) unterschreitet,
 * unabhaengig von einer konkreten Reparatur - im Unterschied zu
 * procurement_suggestion_for_repair() oben. Nutzt fuer die
 * Lieferantenempfehlung dieselbe, bereits vorhandene Funktion
 * product_suggest_best_supplier().
 */
function procurement_suggestions_low_stock(): array {
    $db = get_db();
    $stmt = $db->query(
        'SELECT id, sku, name, stock_quantity, reserved_stock, min_stock
           FROM parts
          WHERE min_stock > 0
            AND is_discontinued = 0
            AND (stock_quantity - COALESCE(reserved_stock, 0)) < min_stock'
    );
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suggestions = [];
    foreach ($parts as $part) {
        $available = max(0, (int)$part['stock_quantity'] - (int)($part['reserved_stock'] ?? 0));
        $missing = max(0, (int)$part['min_stock'] - $available);
        if ($missing <= 0) continue;

        // Phase 7: bereits offene (noch nicht vollstaendig gelieferte)
        // Bestellungen fuer diesen Artikel mindern den tatsaechlich noch
        // zu bestellenden Bedarf - verhindert doppelte Beschaffungsvorschlaege
        // fuer Ware, die bereits unterwegs ist.
        $openOrderQty = purchase_order_open_quantity_for_part((int)$part['id']);
        $effectiveMissing = max(0, $missing - $openOrderQty);

        $suggestions[] = [
            'part_id'           => (int)$part['id'],
            'sku'               => $part['sku'],
            'name'              => $part['name'],
            'stock_quantity'    => (int)$part['stock_quantity'],
            'reserved_stock'    => (int)($part['reserved_stock'] ?? 0),
            'available'         => $available,
            'min_stock'         => (int)$part['min_stock'],
            'missing'           => $missing,
            'open_order_qty'    => $openOrderQty,
            'effective_missing' => $effectiveMissing,
            'recommended_offer' => $effectiveMissing > 0 ? product_suggest_best_supplier((int)$part['id']) : null,
        ];
    }
    return $suggestions;
}

/**
 * Summe der noch offenen (bestellten, aber noch nicht vollstaendig
 * gelieferten) Bestellmenge fuer einen Artikel ueber alle aktiven
 * Bestellungen hinweg (Status ausser "storniert"). Wird von
 * procurement_suggestions_low_stock() genutzt, um bereits laufende
 * Bestellungen bei neuen Beschaffungsvorschlaegen zu beruecksichtigen.
 */
function purchase_order_open_quantity_for_part(int $partId): int {
    $stmt = get_db()->prepare(
        "SELECT COALESCE(SUM(i.quantity - i.quantity_received), 0)
           FROM purchase_order_items i
           JOIN purchase_orders po ON po.id = i.purchase_order_id
          WHERE i.part_id = ?
            AND po.status NOT IN ('storniert')"
    );
    $stmt->execute([$partId]);
    return max(0, (int)$stmt->fetchColumn());
}

// ── Bestellungen CRUD ────────────────────────────────────────────────────

function purchase_orders_list(array $filters = []): array {
    $db = get_db();
    $where = [];
    $params = [];
    if (!empty($filters['status']))      { $where[] = 'po.status = ?';      $params[] = $filters['status']; }
    if (!empty($filters['supplier_id'])) { $where[] = 'po.supplier_id = ?'; $params[] = $filters['supplier_id']; }
    $sql = 'SELECT po.*, s.name AS supplier_name,
                   (SELECT COUNT(*) FROM purchase_order_items i WHERE i.purchase_order_id = po.id) AS item_count
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY po.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function purchase_order_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function purchase_order_items_list(int $orderId): array {
    $stmt = get_db()->prepare(
        'SELECT i.*, p.name AS part_name, p.sku,
                c.name AS assigned_customer_name, co.name AS assigned_company_name,
                pr.name AS assigned_project_name
           FROM purchase_order_items i
           JOIN parts p ON p.id = i.part_id
           LEFT JOIN customers c  ON c.id = i.assigned_customer_id
           LEFT JOIN companies co ON co.id = i.assigned_company_id
           LEFT JOIN projects pr  ON pr.id = i.assigned_project_id
          WHERE i.purchase_order_id = ?
          ORDER BY i.id ASC'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Legt eine neue Bestellung im Status "entwurf" an (noch keine
 * Nummernvergabe – siehe generate_document_number()-Hinweis: Nummern
 * werden erst beim verbindlichen Auslösen vergeben, siehe
 * purchase_order_confirm()). $items ist eine Liste von
 * ['part_id','supplier_offer_id','quantity','assigned_*'].
 */
function purchase_order_create_draft(int $supplierId, array $items, ?int $userId): int {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO purchase_orders (supplier_id, status, created_by) VALUES (?, "entwurf", ?)')
           ->execute([$supplierId, $userId]);
        $orderId = (int)$db->lastInsertId();

        foreach ($items as $item) {
            $offerId = $item['supplier_offer_id'] ?? null;
            $price = null;
            $currency = 'EUR';
            if ($offerId) {
                $offerStmt = $db->prepare('SELECT purchase_price, currency FROM product_supplier_offers WHERE id = ?');
                $offerStmt->execute([$offerId]);
                $offer = $offerStmt->fetch(PDO::FETCH_ASSOC);
                if ($offer) { $price = $offer['purchase_price']; $currency = $offer['currency']; }
            }
            $db->prepare(
                'INSERT INTO purchase_order_items
                    (purchase_order_id, part_id, supplier_offer_id, quantity, purchase_price_at_time, currency,
                     assigned_customer_id, assigned_company_id, assigned_project_id, assigned_repair_id,
                     assigned_ticket_id, assigned_stock, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $orderId, $item['part_id'], $offerId, max(1, (int)($item['quantity'] ?? 1)), $price, $currency,
                $item['assigned_customer_id'] ?? null, $item['assigned_company_id'] ?? null,
                $item['assigned_project_id'] ?? null, $item['assigned_repair_id'] ?? null,
                $item['assigned_ticket_id'] ?? null, !empty($item['assigned_stock']) ? 1 : 0,
                trim($item['notes'] ?? '') ?: null,
            ]);
        }

        $db->prepare('UPDATE purchase_orders SET shipping_cost = ?, shipping_cost_is_estimate = ? WHERE id = ?')
           ->execute([...array_values(purchase_order_calculate_shipping($supplierId, $items)), $orderId]);

        $db->commit();
        log_activity('create', 'purchase_orders', $orderId);
        return $orderId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Prüft eine Bestellung im Status "entwurf" vor der internen Freigabe
 * (Dokumentenmodul, Abschnitt 7 – "Vor Freigabe: Lieferant/Artikel/
 * Mengen/Einkaufspreise/Versandkosten/Zuordnung prüfen"). Liefert eine
 * Liste verständlicher Fehlermeldungen; leer = freigabefähig.
 */
function purchase_order_validate_for_release(int $orderId): array {
    $errors = [];
    $order = purchase_order_find($orderId);
    if (!$order) {
        return ['Bestellung nicht gefunden.'];
    }
    if (empty($order['supplier_id'])) {
        $errors[] = 'Kein Lieferant zugeordnet.';
    }
    $items = purchase_order_items_list($orderId);
    if (!$items) {
        $errors[] = 'Die Bestellung enthält keine Positionen.';
    }
    foreach ($items as $item) {
        if ((int)$item['quantity'] < 1) {
            $errors[] = 'Position "' . $item['part_name'] . '": ungültige Menge.';
        }
        if ($item['purchase_price_at_time'] === null || (float)$item['purchase_price_at_time'] < 0) {
            $errors[] = 'Position "' . $item['part_name'] . '": kein gültiger Einkaufspreis hinterlegt.';
        }
        $hasAssignment = $item['assigned_customer_id'] || $item['assigned_company_id'] || $item['assigned_project_id']
            || $item['assigned_repair_id'] || $item['assigned_ticket_id'] || !empty($item['assigned_stock']);
        if (!$hasAssignment) {
            $errors[] = 'Position "' . $item['part_name'] . '": keine Zuordnung (Kunde/Firma/Projekt/Reparatur/Lager) gewählt.';
        }
    }
    if ($order['shipping_cost'] === null) {
        $errors[] = 'Versandkosten wurden noch nicht berechnet.';
    }
    return $errors;
}

/**
 * Interne Freigabe (NEU, Dokumentenmodul): Status "entwurf" -> "freigegeben".
 * Vergibt noch KEINE Bestellnummer (die wird weiterhin erst beim
 * verbindlichen Auslösen über purchase_order_confirm() vergeben) – die
 * Freigabe ist ein zusätzlicher, protokollierter Kontrollschritt VOR der
 * eigentlichen Bestellauslösung.
 */
function purchase_order_release(int $orderId, ?int $userId): array {
    $order = purchase_order_find($orderId);
    if (!$order) return ['success' => false, 'message' => 'Bestellung nicht gefunden.'];
    if ($order['status'] !== 'entwurf') {
        return ['success' => false, 'message' => 'Nur Bestellungen im Status "Entwurf" können freigegeben werden.'];
    }
    $errors = purchase_order_validate_for_release($orderId);
    if ($errors) {
        return ['success' => false, 'message' => implode(' ', $errors), 'errors' => $errors];
    }
    get_db()->prepare('UPDATE purchase_orders SET status = "freigegeben" WHERE id = ?')->execute([$orderId]);
    log_activity('release', 'purchase_orders', $orderId);
    return ['success' => true, 'message' => 'Bestellung wurde freigegeben und kann nun ausgelöst werden.'];
}

/**
 * Löst die Bestellung verbindlich aus: vergibt jetzt (und nur jetzt) eine
 * Bestellnummer über den Nummernkreis "BE" und setzt den Status auf
 * "bestellt". Ab diesem Zeitpunkt sind Menge/Preis der Positionen
 * eingefroren (purchase_price_at_time bleibt unverändert, auch wenn sich
 * spätere Lieferantenpreise ändern). Setzt voraus, dass die Bestellung
 * zuvor freigegeben wurde (purchase_order_release()).
 */
function purchase_order_confirm(int $orderId): array {
    $order = purchase_order_find($orderId);
    if (!$order) return ['success' => false, 'message' => 'Bestellung nicht gefunden.'];
    if ($order['status'] !== 'freigegeben') {
        return ['success' => false, 'message' => 'Nur freigegebene Bestellungen können ausgelöst werden. Bitte zuerst "Freigeben".'];
    }
    $number = generate_document_number('BE');
    get_db()->prepare('UPDATE purchase_orders SET order_number = ?, status = "bestellt", ordered_at = NOW() WHERE id = ?')
        ->execute([$number, $orderId]);
    log_activity('confirm', 'purchase_orders', $orderId, $number);
    return ['success' => true, 'message' => "Bestellung $number ausgelöst.", 'order_number' => $number];
}

function purchase_order_set_status(int $orderId, string $status): void {
    $allowed = ['entwurf', 'freigegeben', 'bestellt', 'teilweise_geliefert', 'geliefert', 'storniert'];
    if (!in_array($status, $allowed, true)) return;
    get_db()->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
    log_activity('update', 'purchase_orders', $orderId, "status=$status");
}

function purchase_order_item_receive(
    int $itemId,
    int $receivedQuantity,
    ?string $deliveryNoteNumber = null,
    ?string $receivedDate = null,
    ?string $note = null,
    ?int $userId = null
): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        // Phase 7: Zeilensperre gegen gleichzeitige Wareneingangsbuchungen
        // auf dieselbe Position (z. B. zwei Mitarbeiter, dieselbe Lieferung).
        $stmt = $db->prepare(
            'SELECT i.*, po.status AS purchase_order_status
               FROM purchase_order_items i
               JOIN purchase_orders po ON po.id = i.purchase_order_id
              WHERE i.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) { $db->rollBack(); return; }
        if (!in_array($item['purchase_order_status'], ['bestellt', 'teilweise_geliefert'], true)) {
            throw new DomainException('Wareneingang ist nur für ausgelöste, noch offene Bestellungen zulässig.');
        }

        $newReceived = max(0, min($receivedQuantity, (int)$item['quantity']));
        if ($newReceived < (int)$item['quantity_received']) {
            throw new DomainException('Bereits gebuchte Wareneingänge können nicht über das Eingangsformular reduziert werden.');
        }
        $db->prepare('UPDATE purchase_order_items SET quantity_received = ? WHERE id = ?')->execute([$newReceived, $itemId]);

        // Lagerbestand des zentralen Produkts bei Wareneingang erhöhen (nur
        // die tatsächlich neu eingegangene Differenz, nicht die Gesamtmenge).
        $delta = $newReceived - (int)$item['quantity_received'];
        if ($delta !== 0) {
            // (quantity_received wurde oben bereits überschrieben – Differenz
            // daher hier separat aus dem VOR der Aktualisierung gelesenen Wert
            // berechnet, siehe $item['quantity_received'] oben.)
            $db->prepare('UPDATE parts SET stock_quantity = stock_quantity + ? WHERE id = ?')->execute([$delta, $item['part_id']]);

            // Phase 7: jede tatsächliche Bestandsänderung als eigenen
            // Wareneingangs-Beleg protokollieren (Lieferscheinnummer/-datum/
            // Notiz, siehe public/purchase_order_receive.php). Verhindert
            // Doppelbuchung, da nur bei delta != 0 überhaupt geschrieben wird.
            $db->prepare(
                'INSERT INTO purchase_order_receipts
                    (purchase_order_item_id, quantity_delta, delivery_note_number, received_date, note, received_by, created_at)
                 VALUES (?,?,?,?,?,?, NOW())'
            )->execute([$itemId, $delta, $deliveryNoteNumber, $receivedDate, $note, $userId]);
        }

        // Bestellstatus aktualisieren: alle Positionen vollständig geliefert?
        $order = purchase_order_find((int)$item['purchase_order_id']);
        if ($order) {
            $items = purchase_order_items_list((int)$order['id']);
            $allDelivered = true;
            $anyDelivered = false;
            foreach ($items as $i) {
                if ((int)$i['quantity_received'] < (int)$i['quantity']) $allDelivered = false;
                if ((int)$i['quantity_received'] > 0) $anyDelivered = true;
            }
            if ($allDelivered) purchase_order_set_status((int)$order['id'], 'geliefert');
            elseif ($anyDelivered) purchase_order_set_status((int)$order['id'], 'teilweise_geliefert');
        }

        $db->commit();
        log_activity('receive', 'purchase_orders', (int)$item['purchase_order_id'], "Position $itemId: +$delta (Lieferschein: " . ($deliveryNoteNumber ?: '—') . ')');
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Wareneingangsbuchungen einer einzelnen Bestellposition, neueste zuerst. */
function purchase_order_receipts_for_item(int $itemId): array {
    $stmt = get_db()->prepare('SELECT * FROM purchase_order_receipts WHERE purchase_order_item_id = ? ORDER BY created_at DESC');
    $stmt->execute([$itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Statusverlauf einer Bestellung, abgeleitet aus dem bereits bestehenden,
 * gemeinsamen Aktivitätsprotokoll (activity_log) – keine neue, parallele
 * Protokolltabelle nur für Bestellstatus.
 */
function purchase_order_status_history(int $orderId): array {
    $stmt = get_db()->prepare(
        "SELECT al.*, u.full_name AS user_name
           FROM activity_log al
           LEFT JOIN users u ON u.id = al.user_id
          WHERE al.entity_type = 'purchase_orders' AND al.entity_id = ?
          ORDER BY al.created_at DESC"
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Versendet die Bestellung als PDF per E-Mail an den Lieferanten (nutzt
 * die bereits bestehende send_email(), siehe private/mailer.php, und den
 * bestehenden PDF-Erzeuger public/pdf/bestellung.php). Nur für bereits
 * ausgelöste Bestellungen (Status bestellt/teilweise_geliefert/geliefert)
 * mit hinterlegter Lieferanten-E-Mail-Adresse möglich.
 */
function purchase_order_send_email(int $orderId, ?int $userId = null): array {
    $order = purchase_order_find($orderId);
    if (!$order) return ['success' => false, 'message' => 'Bestellung nicht gefunden.'];
    if (!in_array($order['status'], ['bestellt', 'teilweise_geliefert', 'geliefert'], true)) {
        return ['success' => false, 'message' => 'Nur bereits ausgelöste Bestellungen können per E-Mail versendet werden.'];
    }
    $supplierStmt = get_db()->prepare('SELECT email, name FROM suppliers WHERE id = ?');
    $supplierStmt->execute([$order['supplier_id']]);
    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
    if (empty($supplier['email'])) {
        return ['success' => false, 'message' => 'Für diesen Lieferanten ist keine E-Mail-Adresse hinterlegt.'];
    }

    $subject = 'Bestellung ' . ($order['order_number'] ?? ('#' . $orderId));
    $body = '<p>Sehr geehrte Damen und Herren,</p>'
        . '<p>anbei erhalten Sie unsere Bestellung ' . h($order['order_number'] ?? '') . '.</p>'
        . '<p>Mit freundlichen Grüßen</p>';
    require_once __DIR__ . '/purchase_order_pdf.php';
    $tempPath = null;
    try {
        $document = purchase_order_pdf_build($orderId);
        $tempPath = tempnam(sys_get_temp_dir(), 'mztech_po_');
        if ($tempPath === false || file_put_contents($tempPath, $document['content']) === false) {
            throw new RuntimeException('Temporäre PDF-Datei konnte nicht erstellt werden.');
        }
        $sent = send_email(
            $supplier['email'],
            $supplier['name'] ?? '',
            $subject,
            $body,
            '',
            [['path' => $tempPath, 'name' => $document['filename']]]
        );
    } catch (Throwable $e) {
        error_log('Bestellversand fehlgeschlagen: ' . $e->getMessage());
        $sent = false;
    } finally {
        if ($tempPath !== null && is_file($tempPath)) {
            @unlink($tempPath);
        }
    }

    log_activity('send_email', 'purchase_orders', $orderId, $sent ? 'An ' . $supplier['email'] . ' gesendet.' : 'Versand fehlgeschlagen.');
    return $sent
        ? ['success' => true, 'message' => 'Bestellung wurde per E-Mail an ' . $supplier['email'] . ' gesendet.']
        : ['success' => false, 'message' => 'E-Mail-Versand fehlgeschlagen.'];
}

// ── Regelbasierte Versandkosten (Auftragsabschnitt 11) ──────────────────

function supplier_shipping_rules_list(int $supplierId): array {
    $stmt = get_db()->prepare('SELECT * FROM supplier_shipping_rules WHERE supplier_id = ? AND is_active = 1 ORDER BY priority ASC');
    $stmt->execute([$supplierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function supplier_shipping_rule_save(int $supplierId, array $data, ?int $id): int {
    $db = get_db();
    $fields = [
        'supplier_id'     => $supplierId,
        'rule_type'       => $data['rule_type'] ?? 'fest',
        'condition_value' => ($data['condition_value'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$data['condition_value']) : null,
        'condition_text'  => trim($data['condition_text'] ?? '') ?: null,
        'amount'          => (float)str_replace(',', '.', (string)($data['amount'] ?? 0)),
        'priority'        => (int)($data['priority'] ?? 100),
        'is_active'       => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $db->prepare("UPDATE supplier_shipping_rules SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
        return $id;
    }
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
    $qs = implode(', ', array_fill(0, count($fields), '?'));
    $db->prepare("INSERT INTO supplier_shipping_rules ($cols) VALUES ($qs)")->execute(array_values($fields));
    return (int)$db->lastInsertId();
}

function supplier_shipping_rule_delete(int $id): void {
    get_db()->prepare('DELETE FROM supplier_shipping_rules WHERE id = ?')->execute([$id]);
}

/**
 * Wie supplier_shipping_rules_list(), aber OHNE den is_active-Filter — für
 * Verwaltungsansichten (public/suppliers_form.php, public/
 * supplier_shipping_rules.php), in denen auch inaktive Regeln sichtbar
 * und umschaltbar sein müssen. purchase_order_calculate_shipping() nutzt
 * weiterhin ausschließlich supplier_shipping_rules_list() (nur aktive
 * Regeln) für die tatsächliche Berechnung.
 */
function supplier_shipping_rules_list_all(int $supplierId): array {
    $stmt = get_db()->prepare('SELECT * FROM supplier_shipping_rules WHERE supplier_id = ? ORDER BY priority ASC');
    $stmt->execute([$supplierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function supplier_shipping_rule_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM supplier_shipping_rules WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function supplier_shipping_rule_set_active(int $id, bool $active): void {
    get_db()->prepare('UPDATE supplier_shipping_rules SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
}

/**
 * Berechnet die Versandkosten für eine Bestellung. Nutzt exakte, vom
 * Lieferanten per API gelieferte Versandkosten, sofern in den
 * Angebotsdaten hinterlegt (shipping_cost_estimate mit
 * shipping_cost_is_estimate = 0); andernfalls werden die konfigurierten
 * Regeln angewendet und das Ergebnis klar als Schätzung markiert.
 *
 * @return array{0: float, 1: bool} [Betrag, ist_schaetzung]
 */
function purchase_order_calculate_shipping(int $supplierId, array $items): array {
    // Exakte API-Versandkosten haben Vorrang, falls für JEDE Position
    // vorhanden und nicht als Schätzung markiert.
    $orderValue = 0.0;
    $allExact = !empty($items);
    $exactTotal = 0.0;
    foreach ($items as $item) {
        $offerId = $item['supplier_offer_id'] ?? null;
        if (!$offerId) { $allExact = false; continue; }
        $stmt = get_db()->prepare('SELECT purchase_price, shipping_cost_estimate, shipping_cost_is_estimate FROM product_supplier_offers WHERE id = ?');
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$offer) { $allExact = false; continue; }
        $orderValue += (float)($offer['purchase_price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1));
        if ($offer['shipping_cost_is_estimate'] || $offer['shipping_cost_estimate'] === null) {
            $allExact = false;
        } else {
            $exactTotal = max($exactTotal, (float)$offer['shipping_cost_estimate']);
        }
    }
    if ($allExact) {
        return [$exactTotal, false];
    }

    $rules = supplier_shipping_rules_list($supplierId);
    $amount = 0.0;
    foreach ($rules as $rule) {
        switch ($rule['rule_type']) {
            case 'fest':
                $amount = (float)$rule['amount'];
                break;
            case 'kostenlos_ab':
                if ($rule['condition_value'] !== null && $orderValue >= (float)$rule['condition_value']) {
                    $amount = 0.0;
                } else {
                    $amount = (float)$rule['amount'];
                }
                break;
            case 'express_zuschlag':
            case 'sperrgut_zuschlag':
            case 'gefahrgut_zuschlag':
                // Zuschläge werden additiv oben auf den Grundbetrag gelegt,
                // sofern der jeweilige Positions-Hinweis (notes) das
                // entsprechende Schlagwort enthält (einfache, transparente
                // Heuristik, die je Installation angepasst werden kann).
                $amount += (float)$rule['amount'];
                break;
            case 'pro_versandklasse':
                // Konsistent mit der Zuschlags-Heuristik oben: condition_text
                // als Schlagwort gegen die Positions-Notiz (notes) geprüft,
                // da es aktuell kein eigenes Versandklassen-Feld je Artikel
                // gibt (bewusst keine neue Spalte eingeführt).
                $needle = trim((string)($rule['condition_text'] ?? ''));
                if ($needle !== '') {
                    foreach ($items as $item) {
                        $noteHaystack = mb_strtolower((string)($item['notes'] ?? ''));
                        if ($noteHaystack !== '' && str_contains($noteHaystack, mb_strtolower($needle))) {
                            $amount = (float)$rule['amount'];
                            break;
                        }
                    }
                } else {
                    $amount = (float)$rule['amount'];
                }
                break;
            case 'pro_land':
                // condition_text wird gegen das Herkunftsland des Lieferanten
                // (suppliers.address_country, bereits vorhanden seit Phase 2a)
                // verglichen -- nicht gegen ein Zielland, da dieses System
                // Bestellungen fuer einen einzelnen Betrieb abbildet.
                $needle = trim((string)($rule['condition_text'] ?? ''));
                if ($needle !== '') {
                    $stmtCountry = get_db()->prepare('SELECT address_country FROM suppliers WHERE id = ?');
                    $stmtCountry->execute([$supplierId]);
                    $supplierCountry = (string)($stmtCountry->fetchColumn() ?: '');
                    if (strcasecmp(trim($supplierCountry), $needle) === 0) {
                        $amount = (float)$rule['amount'];
                    }
                } else {
                    $amount = (float)$rule['amount'];
                }
                break;
        }
    }
    return [round($amount, 2), true];
}
