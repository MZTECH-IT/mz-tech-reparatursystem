<?php
/**
 * MZ Tech – Wareneingang (Phase 7)
 * ----------------------------------------------------------------------
 * Eigenständige Wareneingangsseite für eine einzelne Bestellung: zeigt
 * alle offenen Bestellpositionen mit bereits erhaltener/offener Menge und
 * erlaubt die Erfassung eines Wareneingangs (Teil- oder Komplettlieferung)
 * inkl. Lieferscheinnummer, Eingangsdatum und Notiz je Buchung.
 *
 * Nutzt ausschließlich die bereits bestehende, transaktionsgeschützte
 * purchase_order_item_receive() (private/purchase_orders.php) – dieselbe
 * Funktion, die auch das bestehende Schnell-Formular in
 * public/purchase_order_form.php verwendet. Diese Seite übergibt zusätzlich
 * die neuen, optionalen Parameter (Lieferscheinnummer/Datum/Notiz) und
 * protokolliert jede Buchung in purchase_order_receipts (siehe
 * sql/phase7_complete_integrations.sql).
 *
 * Eingabefeld ist bewusst "Menge in diesem Wareneingang" (nicht die
 * kumulierte Gesamtmenge wie im Schnell-Formular) – das entspricht direkt
 * dem, was ein Mitarbeiter beim Auspacken einer (Teil-)Lieferung vor sich
 * hat. Die Funktion selbst erwartet weiterhin die neue GESAMTmenge; diese
 * Seite addiert daher serverseitig bereits erhaltene Menge + neu erfasste
 * Menge, bevor purchase_order_item_receive() aufgerufen wird – die
 * Differenzbildung/Lageraktualisierung selbst bleibt vollständig in der
 * bestehenden, transaktionsgeschützten Funktion.
 *
 * Ohne ?id= zeigt die Seite eine Übersicht aller Bestellungen an, die
 * grundsätzlich für einen Wareneingang infrage kommen (Status "bestellt"
 * oder "teilweise_geliefert") – so ist die Seite auch direkt über das
 * Menü ("Beschaffung > Wareneingang") ohne vorherige Auswahl einer
 * konkreten Bestellung erreichbar.
 */
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');

$id = (int)($_GET['id'] ?? 0);

if ($id === 0) {
    $openOrders = array_filter(
        purchase_orders_list(),
        fn($po) => in_array($po['status'], ['bestellt', 'teilweise_geliefert'], true)
    );
    $page_title = 'Wareneingang';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title"><?= svg_icon('upload', 20) ?> Bestellungen für Wareneingang <span class="badge-secondary"><?= count($openOrders) ?></span></h2></div>
        <div class="card-body">
            <?php if (empty($openOrders)): ?>
                <p class="text-muted">Aktuell keine Bestellungen mit offenem Wareneingang.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Bestellnummer</th><th>Lieferant</th><th>Status</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($openOrders as $po): ?>
                        <tr>
                            <td class="font-mono"><?= h($po['order_number'] ?? ('#' . $po['id'])) ?></td>
                            <td><?= h($po['supplier_name']) ?></td>
                            <td><span class="badge badge-secondary"><?= h($po['status']) ?></span></td>
                            <td class="col-actions"><a href="purchase_order_receive.php?id=<?= (int)$po['id'] ?>" class="btn btn-sm btn-primary"><?= svg_icon('upload', 15) ?> Wareneingang erfassen</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$order = purchase_order_find($id);
if (!$order) {
    flash('error', 'Bestellung nicht gefunden.');
    header('Location: ' . url('purchase_orders.php'));
    exit;
}
if (!in_array($order['status'], ['bestellt', 'teilweise_geliefert'], true)) {
    flash('error', 'Wareneingang ist nur für ausgelöste, noch offene Bestellungen möglich.');
    header('Location: ' . url('purchase_order_form.php') . '?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $itemId = (int)($_POST['item_id'] ?? 0);
    $newQty = max(0, (int)($_POST['new_quantity'] ?? 0));
    $deliveryNoteNumber = trim($_POST['delivery_note_number'] ?? '');
    $receivedDate = trim($_POST['received_date'] ?? '') ?: date('Y-m-d');
    $note = trim($_POST['note'] ?? '');

    if ($newQty <= 0) {
        flash('error', 'Bitte eine Menge größer 0 für den Wareneingang angeben.');
    } else {
        $itemsNow = purchase_order_items_list($id);
        $target = null;
        foreach ($itemsNow as $i) { if ((int)$i['id'] === $itemId) { $target = $i; break; } }
        if (!$target) {
            flash('error', 'Position nicht gefunden.');
        } else {
            $alreadyReceived = (int)$target['quantity_received'];
            $ordered = (int)$target['quantity'];
            $totalAfter = min($ordered, $alreadyReceived + $newQty);
            if ($alreadyReceived + $newQty > $ordered) {
                flash('error', 'Hinweis: die erfasste Menge überschreitet die offene Bestellmenge – nur die noch offene Menge (' . ($ordered - $alreadyReceived) . ' Stück) wurde als Wareneingang gebucht.');
            }
            try {
                purchase_order_item_receive(
                    $itemId,
                    $totalAfter,
                    $deliveryNoteNumber ?: null,
                    $receivedDate,
                    $note ?: null,
                    $_SESSION['user_id'] ?? null
                );
                flash('success', 'Wareneingang erfasst: ' . min($newQty, $ordered - $alreadyReceived) . ' Stück.');
            } catch (DomainException $e) {
                flash('error', $e->getMessage());
            }
        }
    }
    header('Location: ' . url('purchase_order_receive.php') . '?id=' . $id);
    exit;
}

$items = purchase_order_items_list($id);

$page_title = 'Wareneingang – ' . ($order['order_number'] ?? 'Entwurf #' . $id);
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <a href="<?= url('purchase_order_form.php') ?>?id=<?= (int)$id ?>" class="btn btn-outline"><?= svg_icon('arrow-left', 18) ?> Zurück zur Bestellung</a>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('upload', 20) ?> Wareneingang – <?= h($order['order_number'] ?? 'Entwurf #' . $id) ?> (<?= h($order['supplier_name']) ?>)</h2>
    </div>
    <div class="card-body">
        <p>Status: <span class="badge badge-secondary"><?= h($order['status']) ?></span></p>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>SKU</th><th>Artikel</th>
                        <th class="text-right">Bestellt</th><th class="text-right">Bereits erhalten</th><th class="text-right">Offen</th>
                        <th>Menge in diesem Wareneingang</th><th>Lieferscheinnummer</th><th>Eingangsdatum</th><th>Notiz</th><th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it):
                    $open = (int)$it['quantity'] - (int)$it['quantity_received'];
                ?>
                    <tr>
                        <td class="font-mono"><?= h($it['sku'] ?? '—') ?></td>
                        <td><?= h($it['part_name']) ?></td>
                        <td class="text-right"><?= (int)$it['quantity'] ?></td>
                        <td class="text-right"><?= (int)$it['quantity_received'] ?></td>
                        <td class="text-right"><strong><?= $open ?></strong></td>
                        <?php if ($open <= 0): ?>
                        <td colspan="5" class="text-muted">Vollständig erhalten</td>
                        <?php else: ?>
                        <td colspan="5">
                            <form method="post" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                <input type="number" name="new_quantity" value="<?= $open ?>" min="1" max="<?= $open ?>" style="width:70px;" required title="Menge in diesem Wareneingang">
                                <input type="text" name="delivery_note_number" placeholder="Lieferscheinnummer" style="width:120px;">
                                <input type="date" name="received_date" value="<?= date('Y-m-d') ?>">
                                <input type="text" name="note" placeholder="Notiz (optional)" style="width:140px;">
                                <button type="submit" class="btn btn-sm btn-primary"><?= svg_icon('check', 15) ?> Buchen</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('list', 20) ?> Bisherige Wareneingangsbuchungen</h2></div>
    <div class="card-body">
        <?php
        $allReceipts = [];
        foreach ($items as $it) {
            foreach (purchase_order_receipts_for_item((int)$it['id']) as $r) {
                $r['sku'] = $it['sku'];
                $r['part_name'] = $it['part_name'];
                $allReceipts[] = $r;
            }
        }
        usort($allReceipts, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
        ?>
        <?php if (empty($allReceipts)): ?>
            <p class="text-muted">Noch keine Wareneingangsbuchungen erfasst.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Gebucht am</th><th>SKU</th><th>Artikel</th><th class="text-right">Menge</th><th>Lieferschein</th><th>Eingangsdatum</th><th>Notiz</th></tr></thead>
                <tbody>
                <?php foreach ($allReceipts as $r): ?>
                    <tr>
                        <td><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
                        <td class="font-mono"><?= h($r['sku'] ?? '—') ?></td>
                        <td><?= h($r['part_name']) ?></td>
                        <td class="text-right">+<?= (int)$r['quantity_delta'] ?></td>
                        <td><?= h($r['delivery_note_number'] ?? '—') ?></td>
                        <td><?= $r['received_date'] ? h(date('d.m.Y', strtotime($r['received_date']))) : '—' ?></td>
                        <td><?= h($r['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
