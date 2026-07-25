<?php
// Patch E, Version 2 (Phase 3 "vollständig fertigstellen"): editierbare
// Mengenvorschläge je Position + sichtbare Anzeige von Vorschlägen ohne
// automatische Lieferantenzuordnung (nichts wird still verworfen).

function verify_csrf() {}
function procurement_suggestions_low_stock(): array { return $GLOBALS['__suggestions_stub'] ?? []; }
function purchase_order_create_draft(int $s, array $i, $u) { $GLOBALS['__last_draft'] = [$s, $i, $u]; return 42; }
function flash(string $type, string $msg) { $GLOBALS['__flash'][] = [$type, $msg]; }
function supplier_find(int $id): ?array { return ['id' => $id, 'name' => 'Lieferant ' . $id]; }
function csrf_field(): string { return ''; }
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

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
?>
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
                <p class="text-muted">Für diese Artikel liegt kein automatisch auswertbares Lieferantenangebot vor (kein aktives Angebot in <code>product_supplier_offers</code>) — bitte manuell über die Bestellübersicht anlegen.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
