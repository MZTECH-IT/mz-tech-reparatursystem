<?php
/**
 * MZ Tech – Bestellungen: Übersicht (Phase 6, Auftragsabschnitt 10)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');

// Integrationsphase / Phase 3: Bestell-Entwurf aus Nachbestell-Vorschlaegen
// anlegen. Rein additiv - bestehende GET-Listenansicht dieser Seite bleibt
// unveraendert.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_draft_from_suggestion') {
    verify_csrf();
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $partIds = array_map('intval', (array)($_POST['part_id'] ?? []));
    $qtyOverrides = (array)($_POST['qty'] ?? []);
    if ($supplierId > 0 && !empty($partIds)) {
        $items = [];
        foreach (procurement_suggestions_low_stock() as $s) {
            if (!in_array($s['part_id'], $partIds, true)) continue;
            $offer = $s['recommended_offer']['offer'] ?? null;
            if (!$offer || (int)($offer['supplier_id'] ?? 0) !== $supplierId) continue;
            $qty = isset($qtyOverrides[$s['part_id']]) ? max(1, (int)$qtyOverrides[$s['part_id']]) : $s['missing'];
            $items[] = [
                'part_id'           => $s['part_id'],
                'supplier_offer_id' => $offer['id'],
                'quantity'          => $qty,
                'assigned_stock'    => 1,
                'notes'             => 'Automatischer Bestellvorschlag (Mindestbestand unterschritten)',
            ];
        }
        if ($items) {
            $orderId = purchase_order_create_draft($supplierId, $items, $_SESSION['user_id'] ?? null);
            flash('success', count($items) . ' Position(en) als Bestell-Entwurf #' . $orderId . ' angelegt.');
        } else {
            flash('error', 'Keine passenden Vorschlaege fuer diesen Lieferanten gefunden.');
        }
    }
    header('Location: purchase_orders.php');
    exit;
}

$statusFilter = trim($_GET['status'] ?? '');
$orders = purchase_orders_list(array_filter(['status' => $statusFilter]));

$page_title = 'Bestellungen';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" action="purchase_orders.php" style="display:flex;gap:.5rem;align-items:center;">
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <?php foreach (['entwurf'=>'Entwurf','freigegeben'=>'Freigegeben','bestellt'=>'Bestellt','teilweise_geliefert'=>'Teilweise geliefert','geliefert'=>'Geliefert','storniert'=>'Storniert'] as $k=>$l): ?>
                <option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Filtern</button>
    </form>
    <div class="toolbar-actions">
        <a href="purchase_order_form.php" class="btn btn-primary"><?= svg_icon('plus', 18) ?> Neue Bestellung</a>
    </div>
</div>

<?php $lowStockSuggestions = procurement_suggestions_low_stock(); ?>
<?php if (!empty($lowStockSuggestions)): ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h2 class="card-title">Bestellvorschläge (Mindestbestand unterschritten) <span class="badge-secondary"><?= count($lowStockSuggestions) ?></span></h2></div>
    <div class="card-body">
        <?php
        $bySupplier = [];
        $withoutOffer = [];
        foreach ($lowStockSuggestions as $s) {
            $offer = $s['recommended_offer']['offer'] ?? null;
            if (!$offer) { $withoutOffer[] = $s; continue; }
            $bySupplier[(int)$offer['supplier_id']][] = $s;
        }
        ?>
        <?php foreach ($bySupplier as $supplierId => $items): ?>
            <?php $supplier = supplier_find($supplierId); ?>
            <form method="post" style="margin-bottom:1rem;border-bottom:1px solid #e2e2e2;padding-bottom:.75rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_draft_from_suggestion">
                <input type="hidden" name="supplier_id" value="<?= (int)$supplierId ?>">
                <strong><?= htmlspecialchars($supplier['name'] ?? ('Lieferant #' . $supplierId)) ?></strong>
                <table class="table" style="margin-top:.5rem;">
                    <thead><tr><th></th><th>SKU</th><th>Artikel</th><th>Verfügbar</th><th>Mindestbestand</th><th>Menge</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><input type="checkbox" name="part_id[]" value="<?= (int)$it['part_id'] ?>" checked></td>
                            <td><?= htmlspecialchars((string)$it['sku']) ?></td>
                            <td><?= htmlspecialchars((string)$it['name']) ?></td>
                            <td><?= (int)$it['available'] ?></td>
                            <td><?= (int)$it['min_stock'] ?></td>
                            <td><input type="number" name="qty[<?= (int)$it['part_id'] ?>]" value="<?= (int)$it['missing'] ?>" min="1" style="width:70px;"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-sm btn-primary">Entwurf für diesen Lieferanten anlegen</button>
            </form>
        <?php endforeach; ?>

        <?php if (!empty($withoutOffer)): ?>
            <div style="margin-top:1rem;">
                <strong>Ohne automatische Lieferantenzuordnung (<?= count($withoutOffer) ?>):</strong>
                <table class="table" style="margin-top:.5rem;">
                    <thead><tr><th>SKU</th><th>Artikel</th><th>Verfügbar</th><th>Mindestbestand</th><th>Fehlend</th></tr></thead>
                    <tbody>
                    <?php foreach ($withoutOffer as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$it['sku']) ?></td>
                            <td><?= htmlspecialchars((string)$it['name']) ?></td>
                            <td><?= (int)$it['available'] ?></td>
                            <td><?= (int)$it['min_stock'] ?></td>
                            <td><?= (int)$it['missing'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="text-muted">Für diese Artikel liegt kein automatisch auswertbares Lieferantenangebot vor — bitte manuell über die Bestellübersicht anlegen.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('database', 20) ?> Bestellungen <span class="badge-secondary"><?= count($orders) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <?= svg_icon('database', 48) ?>
                <p>Noch keine Bestellungen angelegt.</p>
                <a href="purchase_order_form.php" class="btn btn-primary">Erste Bestellung anlegen</a>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Nr.</th><th>Lieferant</th><th>Status</th><th>Positionen</th><th>Erstellt</th><th class="col-actions">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="font-mono"><?= h($o['order_number'] ?? 'Entwurf #' . $o['id']) ?></td>
                        <td><?= h($o['supplier_name']) ?></td>
                        <td>
                            <?php $stBadge = ['entwurf'=>'badge-gray','freigegeben'=>'badge-yellow','bestellt'=>'badge-blue','teilweise_geliefert'=>'badge-orange','geliefert'=>'badge-green','storniert'=>'badge-red'][$o['status']] ?? 'badge-gray'; ?>
                            <span class="badge <?= $stBadge ?>"><?= h($o['status']) ?></span>
                        </td>
                        <td><?= (int)$o['item_count'] ?></td>
                        <td><?= h(date('d.m.Y', strtotime($o['created_at']))) ?></td>
                        <td class="col-actions">
                            <a href="purchase_order_form.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye', 15) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
