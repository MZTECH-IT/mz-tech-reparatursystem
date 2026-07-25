<?php
/**
 * MZ Tech – Bestellung anlegen/ansehen (Phase 6, Auftragsabschnitt 10)
 * ----------------------------------------------------------------------
 * Anlegen: Lieferant wählen, Produkte über die integrierte Suche zu einem
 * Bestellentwurf hinzufügen (Zwischenstand in der Session, siehe
 * $_SESSION['po_draft']), jede Position einzeln einem Kunden/einer
 * Firma/einem Projekt/einer Reparatur/dem Lager zuordenbar. Auslösen
 * (purchase_order_confirm()) vergibt erst dann verbindlich eine
 * Bestellnummer aus dem Nummernkreis "BE".
 */
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');

const PO_DRAFT_SESSION_KEY = 'po_draft_v1';

$id = (int)($_GET['id'] ?? 0);
$order = $id ? purchase_order_find($id) : null;
if ($id && !$order) {
    flash('error', 'Bestellung nicht gefunden.');
    header('Location: ' . url('purchase_orders.php'));
    exit;
}

if (!$id) {
    // ── Neuanlage: Entwurf über Session-Warenkorb ────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';
        $draft = $_SESSION[PO_DRAFT_SESSION_KEY] ?? ['supplier_id' => null, 'items' => []];

        if ($action === 'set_supplier') {
            $draft['supplier_id'] = (int)($_POST['supplier_id'] ?? 0) ?: null;
            $draft['items'] = [];
        } elseif ($action === 'add_item') {
            $partId = (int)($_POST['part_id'] ?? 0);
            $part = $partId ? part_find($partId) : null;
            if ($part) {
                $offerStmt = get_db()->prepare('SELECT id FROM product_supplier_offers WHERE part_id = ? AND supplier_id = ? ORDER BY purchase_price ASC LIMIT 1');
                $offerStmt->execute([$partId, $draft['supplier_id']]);
                $draft['items'][] = [
                    'part_id' => $partId,
                    'part_name' => $part['name'],
                    'sku' => $part['sku'],
                    'supplier_offer_id' => $offerStmt->fetchColumn() ?: null,
                    'quantity' => max(1, (int)($_POST['quantity'] ?? 1)),
                    'assigned_repair_id' => (int)($_POST['assigned_repair_id'] ?? 0) ?: null,
                    'assigned_customer_id' => (int)($_POST['assigned_customer_id'] ?? 0) ?: null,
                    'assigned_project_id' => (int)($_POST['assigned_project_id'] ?? 0) ?: null,
                    'assigned_stock' => empty($_POST['assigned_repair_id']) && empty($_POST['assigned_customer_id']) && empty($_POST['assigned_project_id']),
                ];
            }
        } elseif ($action === 'remove_item') {
            $idx = (int)($_POST['idx'] ?? -1);
            unset($draft['items'][$idx]);
            $draft['items'] = array_values($draft['items']);
        } elseif ($action === 'create_draft') {
            if (!empty($draft['supplier_id']) && !empty($draft['items'])) {
                $orderId = purchase_order_create_draft((int)$draft['supplier_id'], $draft['items'], $_SESSION['user_id'] ?? null);
                unset($_SESSION[PO_DRAFT_SESSION_KEY]);
                flash('success', 'Bestellentwurf angelegt.');
                header('Location: ' . url('purchase_order_form.php') . '?id=' . $orderId);
                exit;
            } else {
                flash('error', 'Bitte einen Lieferanten wählen und mindestens ein Produkt hinzufügen.');
            }
        }
        $_SESSION[PO_DRAFT_SESSION_KEY] = $draft;
        header('Location: ' . url('purchase_order_form.php'));
        exit;
    }

    $draft = $_SESSION[PO_DRAFT_SESSION_KEY] ?? ['supplier_id' => null, 'items' => []];
    $suppliers = suppliers_list(['status' => 'aktiv']);
    $searchResults = [];
    $q = trim($_GET['q'] ?? '');
    if ($q !== '' && !empty($draft['supplier_id'])) {
        $searchResults = product_search(['q' => $q], 1, 10)['rows'];
    }

    $page_title = 'Neue Bestellung';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="toolbar"><a href="purchase_orders.php" class="btn btn-outline"><?= svg_icon('arrow-left', 18) ?> Zurück</a></div>

    <div class="card">
        <div class="card-header"><h2 class="card-title"><?= svg_icon('database', 20) ?> Neue Bestellung – Lieferant wählen</h2></div>
        <div class="card-body">
            <form method="post" style="display:flex;gap:.5rem;align-items:center;">
                <?= csrf_field() ?><input type="hidden" name="action" value="set_supplier">
                <select name="supplier_id">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)($draft['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">Übernehmen</button>
            </form>
        </div>
    </div>

    <?php if (!empty($draft['supplier_id'])): ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title"><?= svg_icon('search', 20) ?> Produkte hinzufügen</h2></div>
        <div class="card-body">
            <form method="get" style="display:flex;gap:.5rem;margin-bottom:1rem;">
                <input type="search" name="q" value="<?= h($q) ?>" placeholder="SKU, Name, EAN, MPN …" class="search-input">
                <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Suchen</button>
            </form>
            <?php if (!empty($searchResults)): ?>
            <div class="table-wrap" style="margin-bottom:1.5rem;">
                <table class="table">
                    <thead><tr><th>SKU</th><th>Name</th><th class="text-right">Bestand</th><th>Menge</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($searchResults as $r): ?>
                        <tr>
                            <td class="font-mono"><?= h($r['sku'] ?? '—') ?></td>
                            <td><?= h($r['name']) ?></td>
                            <td class="text-right"><?= (int)$r['stock_quantity'] ?></td>
                            <td>
                                <form method="post" style="display:flex;gap:.4rem;">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="add_item">
                                    <input type="hidden" name="part_id" value="<?= (int)$r['id'] ?>">
                                    <input type="number" name="quantity" value="1" min="1" style="width:70px;">
                                    <button type="submit" class="btn btn-sm btn-primary">Hinzufügen</button>
                                </form>
                            </td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif ($q !== ''): ?>
                <p class="text-muted">Keine Treffer.</p>
            <?php endif; ?>

            <h3 style="font-size:1rem;">Aktueller Bestellentwurf</h3>
            <?php if (empty($draft['items'])): ?>
                <p class="text-muted">Noch keine Positionen hinzugefügt.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>SKU</th><th>Name</th><th class="text-right">Menge</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($draft['items'] as $idx => $item): ?>
                        <tr>
                            <td class="font-mono"><?= h($item['sku'] ?? '—') ?></td>
                            <td><?= h($item['part_name']) ?></td>
                            <td class="text-right"><?= (int)$item['quantity'] ?></td>
                            <td class="col-actions">
                                <form method="post" class="inline-form"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="idx" value="<?= $idx ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><?= svg_icon('trash', 15) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" style="margin-top:1rem;">
                <?= csrf_field() ?><input type="hidden" name="action" value="create_draft">
                <button type="submit" class="btn btn-primary"><?= svg_icon('save', 16) ?> Bestellentwurf anlegen</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <?php
    exit;
}

// ── Ansicht/Bearbeitung einer bestehenden Bestellung ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'release') {
        $result = purchase_order_release($id, $_SESSION['user_id'] ?? null);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'confirm') {
        $result = purchase_order_confirm($id);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'set_status') {
        purchase_order_set_status($id, $_POST['status'] ?? 'entwurf');
        flash('success', 'Status aktualisiert.');
    } elseif ($action === 'receive') {
        purchase_order_item_receive((int)($_POST['item_id'] ?? 0), (int)($_POST['received_quantity'] ?? 0));
        flash('success', 'Wareneingang erfasst.');
    }
    header('Location: ' . url('purchase_order_form.php') . '?id=' . $id);
    exit;
}

$items = purchase_order_items_list($id);

$page_title = 'Bestellung ' . ($order['order_number'] ?? '#' . $id);
require_once __DIR__ . '/includes/header.php';
?>
<div class="toolbar"><a href="purchase_orders.php" class="btn btn-outline"><?= svg_icon('arrow-left', 18) ?> Zurück</a></div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('database', 20) ?> <?= h($order['order_number'] ?? 'Entwurf #' . $id) ?> – <?= h($order['supplier_name']) ?></h2>
    </div>
    <div class="card-body">
        <p>Status: <span class="badge badge-secondary"><?= h($order['status']) ?></span>
           · Versandkosten: <?= h(fmt_money($order['shipping_cost'] !== null ? (float)$order['shipping_cost'] : null)) ?>
           <?= $order['shipping_cost_is_estimate'] ? ' (Schätzung)' : ' (genau)' ?></p>

        <?php if ($order['status'] === 'entwurf'):
            $poErrors = purchase_order_validate_for_release($id);
        ?>
        <?php if (!empty($poErrors)): ?>
            <div class="alert alert-danger" style="margin-top:.5rem;">
                <?php foreach ($poErrors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="inline-form" onsubmit="return confirm('Bestellung jetzt intern freigeben? Danach kann sie ausgelöst werden.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="release">
            <button type="submit" class="btn btn-primary" <?= !empty($poErrors) ? 'disabled title="Bitte zuerst die oben genannten Punkte beheben."' : '' ?>><?= svg_icon('check', 16) ?> Bestellung freigeben</button>
        </form>
        <?php elseif ($order['status'] === 'freigegeben'): ?>
        <form method="post" class="inline-form" onsubmit="return confirm('Bestellung jetzt verbindlich auslösen? Es wird eine Bestellnummer vergeben und Preise/Mengen werden eingefroren.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-primary"><?= svg_icon('check', 16) ?> Bestellung jetzt auslösen</button>
        </form>
        <?php endif; ?>

        <div class="table-wrap" style="margin-top:1rem;">
            <table class="table">
                <thead><tr><th>SKU</th><th>Name</th><th class="text-right">Menge</th><th class="text-right">Preis (eingefroren)</th><th>Zuordnung</th><th class="text-right">Erhalten</th><?php if ($order['status'] !== 'entwurf'): ?><th class="col-actions">Wareneingang</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="font-mono"><?= h($it['sku'] ?? '—') ?></td>
                        <td><?= h($it['part_name']) ?></td>
                        <td class="text-right"><?= (int)$it['quantity'] ?></td>
                        <td class="text-right"><?= h(fmt_money($it['purchase_price_at_time'] !== null ? (float)$it['purchase_price_at_time'] : null)) ?></td>
                        <td>
                            <?= $it['assigned_customer_name'] ? 'Kunde: ' . h($it['assigned_customer_name']) : '' ?>
                            <?= $it['assigned_company_name'] ? 'Firma: ' . h($it['assigned_company_name']) : '' ?>
                            <?= $it['assigned_project_name'] ? 'Projekt: ' . h($it['assigned_project_name']) : '' ?>
                            <?= (!$it['assigned_customer_name'] && !$it['assigned_company_name'] && !$it['assigned_project_name']) ? '<span class="text-muted">Lager</span>' : '' ?>
                        </td>
                        <td class="text-right"><?= (int)$it['quantity_received'] ?> / <?= (int)$it['quantity'] ?></td>
                        <?php if ($order['status'] !== 'entwurf'): ?>
                        <td class="col-actions">
                            <form method="post" style="display:flex;gap:.4rem;">
                                <?= csrf_field() ?><input type="hidden" name="action" value="receive">
                                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                <input type="number" name="received_quantity" value="<?= (int)$it['quantity_received'] ?>" min="0" max="<?= (int)$it['quantity'] ?>" style="width:70px;">
                                <button type="submit" class="btn btn-sm btn-outline">Speichern</button>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
