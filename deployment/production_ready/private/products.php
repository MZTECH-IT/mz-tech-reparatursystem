<?php
/**
 * MZ Tech – Zentrale Produkt- und Ersatzteildatenbank (Phase 6,
 * Auftragsabschnitt 6/7/8).
 * ----------------------------------------------------------------------
 * Baut auf der bereits bestehenden, produktiv genutzten Tabelle `parts`
 * auf (siehe DATENBANKAENDERUNGEN_PHASE6.sql, Abschnitt 1) und ergänzt sie
 * um mehrere Lieferantenangebote je Produkt (product_supplier_offers),
 * Preis-/Verfügbarkeitshistorie sowie die Anwendung/den Rollback von
 * Import-Aufträgen.
 */

require_once __DIR__ . '/import_engine.php';
require_once __DIR__ . '/pricing_engine.php';

// ── Produktsuche (Auftragsabschnitt 6: durchsuchbar nach vielen Feldern) ──

function product_search(array $filters = [], int $page = 1, int $perPage = 25): array {
    $db = get_db();
    $where = [];
    $params = [];

    if (!empty($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(p.sku LIKE ? OR p.ean LIKE ? OR p.mpn LIKE ? OR p.name LIKE ? OR p.manufacturer LIKE ? OR p.brand LIKE ? OR p.model_compatibility LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['category']))     { $where[] = 'p.category = ?';     $params[] = $filters['category']; }
    if (!empty($filters['device_type']))  { $where[] = 'p.device_type = ?';  $params[] = $filters['device_type']; }
    if (!empty($filters['brand']))        { $where[] = 'p.brand = ?';        $params[] = $filters['brand']; }
    if (!empty($filters['supplier_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM product_supplier_offers o WHERE o.part_id = p.id AND o.supplier_id = ?)';
        $params[] = (int)$filters['supplier_id'];
    }
    if (!empty($filters['low_stock'])) {
        $where[] = 'p.min_stock > 0 AND p.stock_quantity <= p.min_stock';
    }
    if (!empty($filters['discontinued_only'])) {
        $where[] = 'p.is_discontinued = 1';
    }
    if (!empty($filters['price_flagged_only'])) {
        $where[] = 'p.is_price_flagged = 1';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $db->prepare("SELECT COUNT(*) FROM parts p $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare(
        "SELECT p.*,
                (SELECT COUNT(*) FROM product_supplier_offers o WHERE o.part_id = p.id) AS offer_count,
                (SELECT MIN(purchase_price) FROM product_supplier_offers o WHERE o.part_id = p.id AND purchase_price IS NOT NULL) AS min_offer_price
           FROM parts p
           $whereSql
           ORDER BY p.name ASC
           LIMIT ? OFFSET ?"
    );
    $stmt->execute([...$params, $perPage, $offset]);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'total_pages' => $totalPages, 'page' => $page];
}

function part_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM parts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ── Lieferantenangebote je Produkt (Auftragsabschnitt 7: Angebotsvergleich) ──

function product_supplier_offers_for_part(int $partId): array {
    $stmt = get_db()->prepare(
        'SELECT o.*, s.name AS supplier_name, s.status AS supplier_status,
                s.delivery_time_days AS supplier_default_delivery_days
           FROM product_supplier_offers o
           JOIN suppliers s ON s.id = o.supplier_id
          WHERE o.part_id = ?
          ORDER BY o.purchase_price ASC'
    );
    $stmt->execute([$partId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Schlägt den "günstigsten sinnvollen Lieferanten" für ein Produkt vor
 * (Auftragsabschnitt 7). Berücksichtigt NICHT nur den reinen Einkaufspreis,
 * sondern eine gewichtete Gesamtkosten-/Eignungsbetrachtung:
 *   Gesamtkosten (Preis + geschätzte Versandkosten) als Hauptkriterium,
 *   Verfügbarkeit (auf_lager bevorzugt), Lieferzeit, sowie eine Abwertung
 *   als "fehlerhaft markierter" Angebote. Bestellt NIEMALS automatisch –
 *   liefert ausschließlich einen Vorschlag zur manuellen Bestätigung.
 */
function product_suggest_best_supplier(int $partId): ?array {
    $offers = array_filter(
        product_supplier_offers_for_part($partId),
        fn($o) => !$o['is_flagged_faulty'] && $o['supplier_status'] === 'aktiv' && $o['purchase_price'] !== null
    );
    if (empty($offers)) return null;

    $scored = [];
    foreach ($offers as $offer) {
        $totalCost = (float)$offer['purchase_price'] + (float)($offer['shipping_cost_estimate'] ?? 0);
        $availabilityScore = match ($offer['availability']) {
            'auf_lager'   => 0,
            'bestellbar'  => 1,
            'unbekannt'   => 2,
            default       => 3, // nicht_verfuegbar
        };
        $deliveryDays = $offer['delivery_time_days'] ?? $offer['supplier_default_delivery_days'] ?? 14;
        // Zusammengesetzter Score: Gesamtkosten zählen am stärksten,
        // Verfügbarkeit/Lieferzeit als Tie-Breaker bei ähnlichem Preis.
        $score = $totalCost + ($availabilityScore * 2) + ($deliveryDays * 0.1);
        $scored[] = ['offer' => $offer, 'score' => $score, 'total_cost' => $totalCost];
    }
    usort($scored, fn($a, $b) => $a['score'] <=> $b['score']);
    $best = $scored[0];
    return [
        'offer' => $best['offer'],
        'total_cost_estimate' => round($best['total_cost'], 2),
        'alternatives_considered' => count($scored),
    ];
}

// ── Preis-/Verfügbarkeitshistorie ────────────────────────────────────────

function product_record_price_history(int $partId, ?int $supplierId, float $price, string $currency, string $priceType, string $source): void {
    get_db()->prepare(
        'INSERT INTO product_price_history (part_id, supplier_id, price, currency, price_type, source) VALUES (?,?,?,?,?,?)'
    )->execute([$partId, $supplierId, $price, $currency, $priceType, $source]);
}

function product_record_availability_history(int $partId, ?int $supplierId, string $availability, ?int $stockQuantity): void {
    get_db()->prepare(
        'INSERT INTO product_availability_history (part_id, supplier_id, availability, stock_quantity) VALUES (?,?,?,?)'
    )->execute([$partId, $supplierId, $availability, $stockQuantity]);
}

function product_price_history_for_part(int $partId, int $limit = 50): array {
    $stmt = get_db()->prepare('SELECT * FROM product_price_history WHERE part_id = ? ORDER BY recorded_at DESC LIMIT ?');
    $stmt->bindValue(1, $partId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Erstellt den einzufrierenden Datensatz für eine Produkt-Übernahme in ein
 * Dokument (Auftragsabschnitt 8: "immer einen vollständigen Schnappschuss
 * der verwendeten Preis-/Lieferantendaten speichern"). Folgt exakt dem
 * bereits in Phase 1 etablierten *_at_time-Muster (repair_parts.
 * purchase_price_at_time/selling_price_at_time) statt einer neuen,
 * parallelen Schnappschuss-Tabelle.
 */
function product_snapshot_for_use(int $partId, ?int $offerId = null): array {
    $part = part_find($partId);
    if (!$part) return [];

    $offer = null;
    if ($offerId) {
        $stmt = get_db()->prepare('SELECT o.*, s.name AS supplier_name FROM product_supplier_offers o JOIN suppliers s ON s.id = o.supplier_id WHERE o.id = ?');
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$offer && $part['preferred_supplier_id']) {
        $stmt = get_db()->prepare('SELECT o.*, s.name AS supplier_name FROM product_supplier_offers o JOIN suppliers s ON s.id = o.supplier_id WHERE o.part_id = ? AND o.supplier_id = ? LIMIT 1');
        $stmt->execute([$partId, $part['preferred_supplier_id']]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'purchase_price_at_time' => $offer['purchase_price'] ?? $part['purchase_price'],
        'selling_price_at_time'  => $part['selling_price'],
        'supplier_id_at_time'    => $offer['supplier_id'] ?? null,
        'supplier_name_at_time'  => $offer['supplier_name'] ?? null,
        'supplier_offer_id_at_time' => $offer['id'] ?? null,
    ];
}

// ── Faulty-Price-Flag auf Produktebene (aggregiert über alle Angebote) ───

function product_recalculate_price_flag(int $partId): void {
    $stmt = get_db()->prepare('SELECT COUNT(*) FROM product_supplier_offers WHERE part_id = ? AND is_flagged_faulty = 1');
    $stmt->execute([$partId]);
    $flaggedCount = (int)$stmt->fetchColumn();
    $reason = $flaggedCount > 0 ? "$flaggedCount Lieferantenangebot(e) mit auffälligem Preis markiert." : null;
    get_db()->prepare('UPDATE parts SET is_price_flagged = ?, price_flag_reason = ?, last_price_check_at = NOW() WHERE id = ?')
        ->execute([$flaggedCount > 0 ? 1 : 0, $reason, $partId]);
}

// ── Import anwenden / zurückrollen (garantierter Rollback, Abschnitt 5) ──

function import_job_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM import_jobs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function import_jobs_list(array $filters = []): array {
    $db = get_db();
    $where = [];
    $params = [];
    if (!empty($filters['supplier_id'])) { $where[] = 'supplier_id = ?'; $params[] = $filters['supplier_id']; }
    if (!empty($filters['status']))      { $where[] = 'status = ?';      $params[] = $filters['status']; }
    $sql = 'SELECT * FROM import_jobs';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at DESC LIMIT 200';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Wendet einen zuvor erstellten Import-Job (Status "wartet_auf_freigabe")
 * verbindlich an: legt neue Parts/Angebote an, aktualisiert bestehende und
 * schreibt VOR jeder Änderung den Vorzustand nach snapshot_before_json,
 * damit import_job_rollback() ihn exakt wiederherstellen kann.
 */
function import_job_apply(int $jobId, int $approvedBy): array {
    $job = import_job_find($jobId);
    if (!$job) return ['success' => false, 'message' => 'Import-Auftrag nicht gefunden.'];
    if ($job['status'] !== 'wartet_auf_freigabe') {
        return ['success' => false, 'message' => 'Dieser Import-Auftrag wartet nicht (mehr) auf Freigabe.'];
    }
    $preview = json_decode($job['preview_json'], true) ?: [];
    $supplierId = (int)$job['supplier_id'];
    $db = get_db();

    $db->beginTransaction();
    try {
        $snapshotBefore = [];

        foreach ($preview['new'] ?? [] as $entry) {
            $data = $entry['data'];
            $partId = import_find_or_create_part($data);
            $offerId = import_upsert_offer($partId, $supplierId, $data, $jobId);
            $snapshotBefore[] = ['action' => 'offer_created', 'offer_id' => $offerId, 'part_id' => $partId];
        }

        foreach ($preview['updated'] ?? [] as $entry) {
            $offerStmt = $db->prepare('SELECT * FROM product_supplier_offers WHERE id = ?');
            $offerStmt->execute([$entry['existing_offer_id']]);
            $before = $offerStmt->fetch(PDO::FETCH_ASSOC);
            if ($before) {
                $snapshotBefore[] = ['action' => 'offer_updated', 'offer_id' => (int)$before['id'], 'part_id' => (int)$before['part_id'], 'before' => $before];
                import_upsert_offer((int)$before['part_id'], $supplierId, $entry['data'], $jobId, (int)$before['id']);
            }
        }
        // "Unverändert"-Zeilen aktualisieren nur last_seen_at (keine Snapshot-Notwendigkeit).
        foreach ($preview['unchanged'] ?? [] as $entry) {
            $db->prepare('UPDATE product_supplier_offers SET last_seen_at = NOW() WHERE id = ?')->execute([$entry['existing_offer_id']]);
        }

        $db->prepare('UPDATE import_jobs SET status = "importiert", approved_by = ?, approved_at = NOW(), snapshot_before_json = ? WHERE id = ?')
           ->execute([$approvedBy, json_encode($snapshotBefore, JSON_UNESCAPED_UNICODE), $jobId]);

        $db->commit();
        log_activity('import_apply', 'import_jobs', $jobId, 'supplier=' . $supplierId);
        return ['success' => true, 'message' => 'Import erfolgreich angewendet.'];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Fehler beim Anwenden des Imports: ' . $e->getMessage()];
    }
}

function import_find_or_create_part(array $data): int {
    $db = get_db();
    $sku = trim($data['sku'] ?? '');
    if ($sku !== '') {
        $stmt = $db->prepare('SELECT id FROM parts WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) return (int)$existingId;
    }
    // Auch über EAN/MPN nach bestehendem Produkt suchen, um Dubletten in
    // der zentralen Produktdatenbank zu vermeiden.
    foreach (['ean', 'mpn'] as $key) {
        if (!empty($data[$key])) {
            $stmt = $db->prepare("SELECT id FROM parts WHERE $key = ? LIMIT 1");
            $stmt->execute([$data[$key]]);
            $existingId = $stmt->fetchColumn();
            if ($existingId) return (int)$existingId;
        }
    }

    $name = trim($data['name'] ?? $data['supplier_product_name'] ?? 'Unbenanntes Produkt');
    $db->prepare(
        'INSERT INTO parts (sku, ean, mpn, name, category, subcategory, device_type, model_compatibility,
            brand, manufacturer, description, quality_tier, warranty_note, image_url, product_url,
            datasheet_url, weight_grams, packaging_unit, is_discontinued, requires_serial_or_batch,
            stock_quantity, min_stock, stock_location, purchase_price, selling_price)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $sku ?: null,
        $data['ean'] ?? null,
        $data['mpn'] ?? null,
        $name,
        $data['category'] ?? null,
        $data['subcategory'] ?? null,
        $data['device_type'] ?? null,
        $data['model_compatibility'] ?? null,
        $data['brand'] ?? null,
        $data['manufacturer'] ?? null,
        $data['description'] ?? null,
        in_array($data['quality_tier'] ?? '', ['original','oem','aftermarket_a','aftermarket_b','generisch'], true) ? $data['quality_tier'] : null,
        $data['warranty_note'] ?? null,
        $data['image_url'] ?? null,
        $data['product_url'] ?? null,
        $data['datasheet_url'] ?? null,
        ($data['weight_grams'] ?? '') !== '' ? (int)$data['weight_grams'] : null,
        ($data['packaging_unit'] ?? '') !== '' ? (int)$data['packaging_unit'] : 1,
        !empty($data['is_discontinued']) ? 1 : 0,
        !empty($data['requires_serial_or_batch']) ? 1 : 0,
        (int)($data['stock_quantity'] ?? 0),
        (int)($data['min_stock'] ?? 0),
        $data['stock_location'] ?? null,
        isset($data['purchase_price']) && $data['purchase_price'] !== '' ? (float)str_replace(',', '.', (string)$data['purchase_price']) : null,
        isset($data['selling_price']) && $data['selling_price'] !== '' ? (float)str_replace(',', '.', (string)$data['selling_price']) : null,
    ]);
    return (int)$db->lastInsertId();
}

function import_upsert_offer(int $partId, int $supplierId, array $data, int $jobId, ?int $existingOfferId = null): int {
    $db = get_db();
    $price = isset($data['purchase_price']) && $data['purchase_price'] !== '' ? (float)str_replace(',', '.', (string)$data['purchase_price']) : null;

    $historyFlag = null;
    if ($price !== null) {
        $historyFlag = price_guard_check_against_history($partId, $supplierId, $price);
        product_record_price_history($partId, $supplierId, $price, $data['currency'] ?: 'EUR', 'einkauf', 'import');
    }
    if (!empty($data['availability'])) {
        product_record_availability_history($partId, $supplierId, $data['availability'], isset($data['stock_quantity_at_supplier']) ? (int)$data['stock_quantity_at_supplier'] : null);
    }

    $fields = [
        'supplier_sku'               => $data['supplier_sku'] ?? null,
        'supplier_product_name'      => $data['supplier_product_name'] ?? null,
        'ean'                        => $data['ean'] ?? null,
        'mpn'                        => $data['mpn'] ?? null,
        'purchase_price'             => $price,
        'currency'                   => $data['currency'] ?: 'EUR',
        'tax_rate'                   => isset($data['tax_rate']) && $data['tax_rate'] !== '' ? (float)str_replace(',', '.', (string)$data['tax_rate']) : null,
        'is_net_price'               => array_key_exists('is_net_price', $data) ? (int)(bool)$data['is_net_price'] : 1,
        'availability'               => in_array($data['availability'] ?? '', ['auf_lager','bestellbar','nicht_verfuegbar'], true) ? $data['availability'] : 'unbekannt',
        'stock_quantity_at_supplier' => isset($data['stock_quantity_at_supplier']) && $data['stock_quantity_at_supplier'] !== '' ? (int)$data['stock_quantity_at_supplier'] : null,
        'delivery_time_days'         => isset($data['delivery_time_days']) && $data['delivery_time_days'] !== '' ? (int)$data['delivery_time_days'] : null,
        'minimum_order_quantity'     => isset($data['minimum_order_quantity']) && $data['minimum_order_quantity'] !== '' ? (int)$data['minimum_order_quantity'] : 1,
        // Phase 7: Verpackungseinheit des Lieferanten (z. B. "10er-Pack") -
        // eigenes Import-Feld 'offer_packaging_unit' (siehe
        // import_target_fields() in private/import_engine.php), bewusst
        // NICHT derselbe Schluessel wie das bereits bestehende, artikel-
        // bezogene 'packaging_unit' (parts.packaging_unit), da beide
        // fachlich unterschiedliche Dinge sind (Lieferanten-Angebot vs.
        // Artikel-Stammdatum) und unabhaengig voneinander gepflegt werden.
        'packaging_unit'             => isset($data['offer_packaging_unit']) ? trim((string)$data['offer_packaging_unit']) ?: null : null,
        'shipping_cost_estimate'     => isset($data['shipping_cost_estimate']) && $data['shipping_cost_estimate'] !== '' ? (float)str_replace(',', '.', (string)$data['shipping_cost_estimate']) : null,
        'is_flagged_faulty'          => $historyFlag['flagged'] ?? false ? 1 : 0,
        'flagged_reason'             => $historyFlag['reason'] ?? null,
        'last_seen_at'               => date('Y-m-d H:i:s'),
        'source_import_job_id'       => $jobId,
    ];

    if ($existingOfferId) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $db->prepare("UPDATE product_supplier_offers SET $sets WHERE id = ?")->execute([...array_values($fields), $existingOfferId]);
        $offerId = $existingOfferId;
    } else {
        $fields['part_id'] = $partId;
        $fields['supplier_id'] = $supplierId;
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
        $qs = implode(', ', array_fill(0, count($fields), '?'));
        $db->prepare("INSERT INTO product_supplier_offers ($cols) VALUES ($qs)
                      ON DUPLICATE KEY UPDATE purchase_price = VALUES(purchase_price), last_seen_at = VALUES(last_seen_at)")
           ->execute(array_values($fields));
        $offerId = (int)$db->lastInsertId();
        if ($offerId === 0) {
            // ON DUPLICATE KEY UPDATE Fall (SKU bereits vorhanden) – Zeile nachladen.
            $lookup = $db->prepare('SELECT id FROM product_supplier_offers WHERE part_id = ? AND supplier_id = ? AND supplier_sku <=> ?');
            $lookup->execute([$partId, $supplierId, $fields['supplier_sku']]);
            $offerId = (int)$lookup->fetchColumn();
        }
    }

    product_recalculate_price_flag($partId);

    // Integrationsphase: automatische Verkaufspreis-Neuberechnung ueber die
    // bereits vorhandenen Funktionen pricing_rule_match()/pricing_calculate_sell_price()
    // (private/pricing_rules.php, hier bereits eingebunden). Diese Funktionen
    // existierten bereits vollstaendig fertig, wurden aber bisher von keiner
    // Stelle im Code aufgerufen. Wird NUR angewendet, wenn eine passende Regel
    // existiert UND price_guard den Preis nicht als verdaechtig markiert hat.
    if ($price !== null && empty($historyFlag['flagged'])) {
        $part = part_find($partId);
        if ($part) {
            $calc = pricing_calculate_sell_price(
                $price,
                [
                    'part_id'     => $partId,
                    'supplier_id' => $supplierId,
                    'category'    => $part['category'] ?? null,
                ],
                $part['selling_price'] !== null ? (float)$part['selling_price'] : null
            );
            if (!empty($calc['applied']) && isset($calc['price'])) {
                $db->prepare('UPDATE parts SET selling_price = ? WHERE id = ?')
                   ->execute([$calc['price'], $partId]);
            }
        }
    }

    return $offerId;
}

/**
 * Garantierter Rollback (Abschnitt 5: "garantiertes Zurückrollen jedes
 * Imports"): stellt exakt den in snapshot_before_json festgehaltenen
 * Vorzustand wieder her – neu angelegte Angebote werden gelöscht,
 * aktualisierte Angebote werden auf ihren vorherigen Stand zurückgesetzt.
 * Neu angelegte PARTS (Produkte) werden dabei bewusst NICHT automatisch
 * gelöscht, falls sie inzwischen anderweitig verwendet werden (z. B. in
 * einer Reparatur) – das entspricht dem bestehenden Lösch-Schutzmuster in
 * public/parts.php ("kann nicht gelöscht werden, wird verwendet").
 */
function import_job_rollback(int $jobId, int $rolledBackBy): array {
    $job = import_job_find($jobId);
    if (!$job) return ['success' => false, 'message' => 'Import-Auftrag nicht gefunden.'];
    if ($job['status'] !== 'importiert') {
        return ['success' => false, 'message' => 'Nur bereits angewendete Importe können zurückgerollt werden.'];
    }
    $snapshot = json_decode($job['snapshot_before_json'] ?? '[]', true) ?: [];
    $db = get_db();
    $db->beginTransaction();
    try {
        foreach ($snapshot as $entry) {
            if ($entry['action'] === 'offer_created') {
                $db->prepare('DELETE FROM product_supplier_offers WHERE id = ? AND source_import_job_id = ?')->execute([$entry['offer_id'], $jobId]);
            } elseif ($entry['action'] === 'offer_updated' && !empty($entry['before'])) {
                $before = $entry['before'];
                $db->prepare(
                    'UPDATE product_supplier_offers SET
                        supplier_sku=?, supplier_product_name=?, ean=?, mpn=?, purchase_price=?, currency=?, tax_rate=?,
                        is_net_price=?, availability=?, stock_quantity_at_supplier=?, delivery_time_days=?,
                        minimum_order_quantity=?, shipping_cost_estimate=?, is_flagged_faulty=?, flagged_reason=?
                     WHERE id = ?'
                )->execute([
                    $before['supplier_sku'], $before['supplier_product_name'], $before['ean'], $before['mpn'],
                    $before['purchase_price'], $before['currency'], $before['tax_rate'], $before['is_net_price'],
                    $before['availability'], $before['stock_quantity_at_supplier'], $before['delivery_time_days'],
                    $before['minimum_order_quantity'], $before['shipping_cost_estimate'], $before['is_flagged_faulty'],
                    $before['flagged_reason'], $entry['offer_id'],
                ]);
            }
            if (!empty($entry['part_id'])) {
                product_recalculate_price_flag((int)$entry['part_id']);
            }
        }
        $db->prepare('UPDATE import_jobs SET status = "zurueckgerollt", rolled_back_by = ?, rolled_back_at = NOW() WHERE id = ?')
           ->execute([$rolledBackBy, $jobId]);
        $db->commit();
        log_activity('import_rollback', 'import_jobs', $jobId);
        return ['success' => true, 'message' => 'Import erfolgreich zurückgerollt.'];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Fehler beim Rollback: ' . $e->getMessage()];
    }
}
