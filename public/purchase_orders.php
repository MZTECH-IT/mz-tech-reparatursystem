<?php
/**
 * MZ Tech – Bestellungen: Übersicht (Phase 6, Auftragsabschnitt 10)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');

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
