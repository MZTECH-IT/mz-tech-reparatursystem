<?php
/**
 * MZ Tech – Lieferantenangebote: Gesamtübersicht über alle Artikel (Phase 7)
 * ----------------------------------------------------------------------
 * Bewusst KEINE neue Datenquelle: liest direkt aus der bereits
 * bestehenden Tabelle product_supplier_offers (wie
 * public/product_offers.php, dort aber je EINEM Artikel zugeordnet).
 * Diese Seite ist die artikelübergreifende Liste mit Filtermöglichkeit
 * nach Lieferant/Verfügbarkeit/"nur bevorzugt" und verlinkt für Details
 * bzw. Aktionen (bevorzugt setzen, Preisverlauf) weiterhin auf die
 * bestehende product_offers.php – keine doppelte Aktionslogik.
 */
require_once __DIR__ . '/init.php';
require_permission('manage_parts');

$supplierId = (int)($_GET['supplier_id'] ?? 0);
$availability = trim($_GET['availability'] ?? '');
$preferredOnly = !empty($_GET['preferred_only']);
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($supplierId)     { $where[] = 'o.supplier_id = ?'; $params[] = $supplierId; }
if ($availability)   { $where[] = 'o.availability = ?'; $params[] = $availability; }
if ($preferredOnly)  { $where[] = 'o.is_preferred = 1'; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(p.sku LIKE ? OR p.name LIKE ? OR o.supplier_sku LIKE ?)';
    array_push($params, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = get_db()->prepare(
    "SELECT o.*, p.sku AS part_sku, p.name AS part_name, s.name AS supplier_name
       FROM product_supplier_offers o
       JOIN parts p ON p.id = o.part_id
       JOIN suppliers s ON s.id = o.supplier_id
       $whereSql
       ORDER BY o.last_seen_at DESC
       LIMIT 500"
);
$stmt->execute($params);
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$suppliers = suppliers_list();

$page_title = 'Lieferantenangebote';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="SKU, Name, Lief.-SKU …" class="search-input">
        <select name="supplier_id" class="search-input" style="width:auto;">
            <option value="">– Alle Lieferanten –</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="availability" class="search-input" style="width:auto;">
            <option value="">– Alle Verfügbarkeiten –</option>
            <?php foreach (['auf_lager'=>'Auf Lager','bestellbar'=>'Bestellbar','nicht_verfuegbar'=>'Nicht verfügbar','unbekannt'=>'Unbekannt'] as $k=>$l): ?>
                <option value="<?= $k ?>" <?= $availability === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <label style="display:flex;gap:.3rem;align-items:center;"><input type="checkbox" name="preferred_only" value="1" <?= $preferredOnly ? 'checked' : '' ?>> nur bevorzugte</label>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Filtern</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('package', 20) ?> Lieferantenangebote <span class="badge-secondary"><?= count($offers) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($offers)): ?>
            <p class="text-muted">Keine Angebote für diese Filterauswahl.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>SKU</th><th>Artikel</th><th>Lieferant</th><th>Lief.-SKU</th>
                        <th class="text-right">EK-Preis</th><th>Verfügbarkeit</th><th class="text-right">Menge b. Lief.</th>
                        <th>Bevorzugt</th><th>Letzte Sync.</th><th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($offers as $o): ?>
                    <tr>
                        <td class="font-mono"><?= h($o['part_sku'] ?? '—') ?></td>
                        <td><?= h($o['part_name']) ?></td>
                        <td><?= h($o['supplier_name']) ?></td>
                        <td class="font-mono" style="font-size:.8rem;"><?= h($o['supplier_sku'] ?? '—') ?></td>
                        <td class="text-right"><?= h(fmt_money($o['purchase_price'] !== null ? (float)$o['purchase_price'] : null)) ?> <?= h($o['currency'] ?: 'EUR') ?></td>
                        <td>
                            <?php $availBadge = ['auf_lager'=>'badge-green','bestellbar'=>'badge-orange','nicht_verfuegbar'=>'badge-red','unbekannt'=>'badge-secondary'][$o['availability']] ?? 'badge-secondary'; ?>
                            <span class="badge <?= $availBadge ?>"><?= h($o['availability']) ?></span>
                        </td>
                        <td class="text-right"><?= $o['stock_quantity_at_supplier'] !== null ? (int)$o['stock_quantity_at_supplier'] : '—' ?></td>
                        <td><?= $o['is_preferred'] ? '★' : '—' ?></td>
                        <td><?= $o['last_seen_at'] ? h(date('d.m.Y H:i', strtotime($o['last_seen_at']))) : '<span class="text-muted">nie</span>' ?></td>
                        <td class="col-actions"><a href="product_offers.php?part_id=<?= (int)$o['part_id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye', 15) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($offers) === 500): ?><p class="text-muted">Es werden maximal 500 Angebote gleichzeitig angezeigt – bitte die Filter eingrenzen.</p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
