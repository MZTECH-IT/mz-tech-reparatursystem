<?php
/**
 * MZ Tech – Lieferantenangebote je Produkt: Vergleich + Vorschlag
 * "günstigster sinnvoller Lieferant" (Phase 6, Auftragsabschnitt 7)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_parts');

$partId = (int)($_GET['part_id'] ?? 0);
$part = $partId ? part_find($partId) : null;
if (!$part) {
    flash('error', 'Ersatzteil/Produkt nicht gefunden.');
    header('Location: ' . url('parts.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $offerId = (int)($_POST['offer_id'] ?? 0);
    if ($action === 'set_preferred' && $offerId) {
        $db = get_db();
        $stmt = $db->prepare('SELECT supplier_id FROM product_supplier_offers WHERE id = ? AND part_id = ?');
        $stmt->execute([$offerId, $partId]);
        $supplierId = $stmt->fetchColumn();
        if ($supplierId) {
            $db->prepare('UPDATE product_supplier_offers SET is_preferred = 0 WHERE part_id = ?')->execute([$partId]);
            $db->prepare('UPDATE product_supplier_offers SET is_preferred = 1 WHERE id = ?')->execute([$offerId]);
            $db->prepare('UPDATE parts SET preferred_supplier_id = ? WHERE id = ?')->execute([$supplierId, $partId]);
            flash('success', 'Bevorzugter Lieferant für dieses Produkt aktualisiert.');
        }
    } elseif ($action === 'clear_flag' && $offerId) {
        get_db()->prepare('UPDATE product_supplier_offers SET is_flagged_faulty = 0, flagged_reason = NULL WHERE id = ?')->execute([$offerId]);
        product_recalculate_price_flag($partId);
        flash('success', 'Markierung als fehlerhaft entfernt.');
    }
    header('Location: ' . url('product_offers.php') . '?part_id=' . $partId);
    exit;
}

$offers = product_supplier_offers_for_part($partId);
$suggestion = product_suggest_best_supplier($partId);
$priceHistory = product_price_history_for_part($partId, 20);

$page_title = 'Angebote: ' . $part['name'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <a href="parts.php" class="btn btn-outline"><?= svg_icon('arrow-left', 18) ?> Zurück zu Ersatzteilen</a>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('package', 20) ?> <?= h($part['name']) ?>
            <span class="text-muted font-mono" style="font-size:.8rem;"><?= h($part['sku'] ?? '') ?></span></h2>
    </div>
    <div class="card-body">
        <p class="text-muted">Eigener Lagerbestand: <?= (int)$part['stock_quantity'] ?> ·
           Eigener Verkaufspreis: <?= h(fmt_money($part['selling_price'] !== null ? (float)$part['selling_price'] : null)) ?></p>

        <?php if ($suggestion): ?>
        <div class="alert alert-info" style="margin-bottom:1rem;">
            <strong><?= svg_icon('check', 16) ?> Vorschlag: günstigster sinnvoller Lieferant</strong><br>
            <?= h($suggestion['offer']['supplier_name']) ?> – Gesamtkosten ca. <?= h(fmt_money($suggestion['total_cost_estimate'])) ?>
            (Einkaufspreis <?= h(fmt_money((float)$suggestion['offer']['purchase_price'])) ?>
            <?php if ($suggestion['offer']['shipping_cost_estimate']): ?> + Versand <?= h(fmt_money((float)$suggestion['offer']['shipping_cost_estimate'])) ?><?php endif; ?>,
            Verfügbarkeit: <?= h($suggestion['offer']['availability']) ?>).
            Dies ist ausschließlich ein Vorschlag – es wird niemals automatisch bestellt.
        </div>
        <?php endif; ?>

        <?php if (empty($offers)): ?>
            <div class="empty-state">
                <?= svg_icon('link', 48) ?>
                <p>Noch keine Lieferantenangebote für dieses Produkt (werden über Importe angelegt).</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Lieferant</th><th>Lief.-SKU</th><th class="text-right">EK-Preis</th>
                        <th>Verfügbarkeit</th><th class="text-right">Lieferzeit</th><th class="text-right">Versand (Schätzung)</th>
                        <th>Bevorzugt</th><th class="col-actions">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($offers as $o): ?>
                    <tr <?= $o['is_flagged_faulty'] ? 'style="background:rgba(220,38,38,.06);"' : '' ?>>
                        <td><?= h($o['supplier_name']) ?></td>
                        <td class="font-mono" style="font-size:.8rem;"><?= h($o['supplier_sku'] ?? '—') ?></td>
                        <td class="text-right"><?= h(fmt_money($o['purchase_price'] !== null ? (float)$o['purchase_price'] : null)) ?></td>
                        <td>
                            <?php $availBadge = ['auf_lager'=>'badge-green','bestellbar'=>'badge-orange','nicht_verfuegbar'=>'badge-red','unbekannt'=>'badge-secondary'][$o['availability']] ?? 'badge-secondary'; ?>
                            <span class="badge <?= $availBadge ?>"><?= h($o['availability']) ?></span>
                            <?php if ($o['is_flagged_faulty']): ?><br><span class="badge badge-red" style="font-size:.7rem;" title="<?= h($o['flagged_reason'] ?? '') ?>">Preis auffällig</span><?php endif; ?>
                        </td>
                        <td class="text-right"><?= $o['delivery_time_days'] !== null ? (int)$o['delivery_time_days'] . ' Tage' : '—' ?></td>
                        <td class="text-right"><?= h(fmt_money($o['shipping_cost_estimate'] !== null ? (float)$o['shipping_cost_estimate'] : null)) ?></td>
                        <td><?= $o['is_preferred'] ? '★' : '—' ?></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <?php if (!$o['is_preferred']): ?>
                                <form method="post" class="inline-form"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_preferred">
                                    <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline" title="Als bevorzugt markieren">★</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($o['is_flagged_faulty']): ?>
                                <form method="post" class="inline-form"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="clear_flag">
                                    <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline" title="Markierung entfernen">OK</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('chart', 20) ?> Preisverlauf</h2></div>
    <div class="card-body">
        <?php if (empty($priceHistory)): ?>
            <p class="text-muted">Noch keine Preishistorie erfasst.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Datum</th><th>Typ</th><th class="text-right">Preis</th><th>Quelle</th></tr></thead>
                <tbody>
                <?php foreach ($priceHistory as $h): ?>
                    <tr>
                        <td><?= h(date('d.m.Y H:i', strtotime($h['recorded_at']))) ?></td>
                        <td><?= h($h['price_type']) ?></td>
                        <td class="text-right"><?= h(fmt_money((float)$h['price'])) ?></td>
                        <td><?= h($h['source']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
