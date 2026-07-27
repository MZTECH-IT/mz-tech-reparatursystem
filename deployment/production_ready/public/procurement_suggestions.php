<?php
/**
 * MZ Tech – Beschaffungsvorschläge (Phase 7)
 * ----------------------------------------------------------------------
 * Vollständige, eigenständige Übersichtsseite über alle Artikel, deren
 * verfügbarer Bestand den Mindestbestand unterschreitet (siehe
 * procurement_suggestions_low_stock(), private/purchase_orders.php).
 * Erweitert die dort bereits vorhandene, in public/purchase_orders.php
 * eingebettete Kurzfassung um: freie Lieferantenangebot-Auswahl je
 * Position (nicht nur den automatisch empfohlenen Lieferanten),
 * bearbeitbare Menge, Anzeige von Versandkosten/Gesamtkosten je Angebot,
 * sowie Berücksichtigung bereits offener Bestellungen. Erzeugt aus der
 * Auswahl einen oder mehrere Bestellentwürfe (ein Entwurf je Lieferant,
 * über die bereits bestehende purchase_order_create_draft()).
 *
 * Bewusst KEINE neue Datenquelle/-tabelle: nutzt ausschließlich die
 * bereits vorhandenen Funktionen procurement_suggestions_low_stock(),
 * product_supplier_offers_for_part() und purchase_order_create_draft().
 */
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_drafts') {
        $partIds = array_map('intval', (array)($_POST['part_id'] ?? []));
        $offerChoice = (array)($_POST['offer_id'] ?? []);   // part_id => offer_id
        $qtyChoice = (array)($_POST['qty'] ?? []);           // part_id => qty

        // Nach Lieferant gruppieren – eine Bestellung kann nur genau einen
        // Lieferanten haben, ein Bestellentwurf wird daher je Lieferant
        // separat angelegt (identisches Prinzip wie bereits in
        // public/purchase_orders.php "Bestellvorschläge"-Karte).
        $bySupplier = [];
        $skipped = [];
        foreach ($partIds as $partId) {
            $offerId = (int)($offerChoice[$partId] ?? 0);
            if (!$offerId) { $skipped[] = $partId; continue; }
            $stmt = get_db()->prepare('SELECT id, supplier_id, part_id FROM product_supplier_offers WHERE id = ? AND part_id = ?');
            $stmt->execute([$offerId, $partId]);
            $offer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$offer) { $skipped[] = $partId; continue; }
            $qty = max(1, (int)($qtyChoice[$partId] ?? 1));
            $bySupplier[(int)$offer['supplier_id']][] = [
                'part_id'           => $partId,
                'supplier_offer_id' => $offerId,
                'quantity'          => $qty,
                'assigned_stock'    => 1,
                'notes'             => 'Beschaffungsvorschlag (Mindestbestand unterschritten)',
            ];
        }

        $createdIds = [];
        foreach ($bySupplier as $supplierId => $items) {
            $createdIds[] = purchase_order_create_draft($supplierId, $items, $_SESSION['user_id'] ?? null);
        }

        if ($createdIds) {
            flash('success', count($createdIds) . ' Bestellentwurf/Bestellentwürfe angelegt: #' . implode(', #', $createdIds) . '.');
        } else {
            flash('error', 'Keine gültige Auswahl – bitte mindestens eine Position mit Lieferantenangebot auswählen.');
        }
    }

    header('Location: ' . url('procurement_suggestions.php'));
    exit;
}

$suggestions = procurement_suggestions_low_stock();

// Für jede Vorschlagsposition die vollständige Angebotsliste (nicht nur
// den empfohlenen Lieferanten) laden, damit die Auswahl im Formular frei
// ist (Auftragsanforderung "Lieferantenangebot auswählen").
$offersByPart = [];
foreach ($suggestions as $s) {
    $offersByPart[$s['part_id']] = product_supplier_offers_for_part($s['part_id']);
}

$page_title = 'Beschaffungsvorschläge';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <a href="purchase_orders.php" class="btn btn-outline"><?= svg_icon('arrow-left', 18) ?> Zurück zu Bestellungen</a>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('package', 20) ?> Beschaffungsvorschläge <span class="badge-secondary"><?= count($suggestions) ?></span></h2>
    </div>
    <div class="card-body">
        <?php if (empty($suggestions)): ?>
            <div class="empty-state">
                <?= svg_icon('check', 48) ?>
                <p>Aktuell unterschreitet kein Artikel seinen Mindestbestand.</p>
            </div>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_drafts">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>SKU</th>
                            <th>Artikel</th>
                            <th class="text-right">Bestand</th>
                            <th class="text-right">Reserviert</th>
                            <th class="text-right">Verfügbar</th>
                            <th class="text-right">Mindestbestand</th>
                            <th class="text-right">Fehlend</th>
                            <th class="text-right">Bereits bestellt</th>
                            <th class="text-right">Netto-Bedarf</th>
                            <th>Lieferantenangebot</th>
                            <th class="text-right">Menge</th>
                            <th class="text-right">Gesamtkosten (Angebot)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($suggestions as $s):
                        $offers = $offersByPart[$s['part_id']] ?? [];
                        $recommendedOfferId = $s['recommended_offer']['offer']['id'] ?? null;
                        $rowId = 'row_' . $s['part_id'];
                    ?>
                        <tr>
                            <td><input type="checkbox" name="part_id[]" value="<?= (int)$s['part_id'] ?>" <?= $s['effective_missing'] > 0 && $offers ? 'checked' : '' ?> <?= empty($offers) ? 'disabled' : '' ?>></td>
                            <td class="font-mono"><?= h($s['sku'] ?? '—') ?></td>
                            <td><?= h($s['name']) ?></td>
                            <td class="text-right"><?= (int)$s['stock_quantity'] ?></td>
                            <td class="text-right"><?= (int)$s['reserved_stock'] ?></td>
                            <td class="text-right"><?= (int)$s['available'] ?></td>
                            <td class="text-right"><?= (int)$s['min_stock'] ?></td>
                            <td class="text-right"><?= (int)$s['missing'] ?></td>
                            <td class="text-right"><?= (int)$s['open_order_qty'] ?></td>
                            <td class="text-right"><strong><?= (int)$s['effective_missing'] ?></strong></td>
                            <td>
                                <?php if (empty($offers)): ?>
                                    <span class="text-muted">Kein Lieferantenangebot vorhanden</span>
                                <?php else: ?>
                                <select name="offer_id[<?= (int)$s['part_id'] ?>]" class="search-input" style="width:auto;">
                                    <?php foreach ($offers as $o):
                                        $total = ($o['purchase_price'] !== null ? (float)$o['purchase_price'] : 0) + (float)($o['shipping_cost_estimate'] ?? 0);
                                        $label = h($o['supplier_name']) . ' – ' . h(fmt_money($o['purchase_price'] !== null ? (float)$o['purchase_price'] : null))
                                               . ' + Versand ' . h(fmt_money((float)($o['shipping_cost_estimate'] ?? 0)))
                                               . ' = ' . h(fmt_money($total))
                                               . ' (' . h($o['availability']) . ')';
                                    ?>
                                        <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)$recommendedOfferId ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <input type="number" name="qty[<?= (int)$s['part_id'] ?>]" value="<?= (int)max(1, $s['effective_missing']) ?>" min="1" style="width:70px;">
                            </td>
                            <td class="text-right">
                                <?php if ($s['recommended_offer']): ?>
                                    <?= h(fmt_money((float)$s['recommended_offer']['total_cost_estimate'])) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted" style="margin-top:.5rem;">"Bereits bestellt" zeigt die Summe der noch nicht gelieferten Menge aus offenen Bestellungen für diesen Artikel – der Netto-Bedarf berücksichtigt dies bereits. Es wird ausschließlich ein Bestellentwurf angelegt, niemals automatisch verbindlich bestellt.</p>
            <button type="submit" class="btn btn-primary" style="margin-top:.5rem;"><?= svg_icon('plus', 16) ?> Bestellentwurf/-entwürfe aus Auswahl anlegen</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
