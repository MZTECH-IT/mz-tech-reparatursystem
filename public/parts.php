<?php
require_once __DIR__ . '/init.php';

$db = get_db();

// ── POST: Delete ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        // Check if part is used in any repair
        $stmt = $db->prepare('SELECT COUNT(*) FROM repair_parts WHERE part_id = ?');
        $stmt->execute([$id]);
        $used = (int)$stmt->fetchColumn();
        if ($used > 0) {
            flash('error', 'Ersatzteil kann nicht gelöscht werden – es wird in ' . $used . ' Reparatur(en) verwendet.');
        } else {
            $stmt = $db->prepare('SELECT name FROM parts WHERE id = ?');
            $stmt->execute([$id]);
            $part = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($part) {
                $db->prepare('DELETE FROM parts WHERE id = ?')->execute([$id]);
                log_activity('delete', 'parts', $id);
                flash('success', 'Ersatzteil "' . h($part['name']) . '" wurde gelöscht.');
            } else {
                flash('error', 'Ersatzteil nicht gefunden.');
            }
        }
    }
    header('Location: parts.php');
    exit;
}

// ── POST: Save (Insert / Update) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save') {
    verify_csrf();

    $id           = (int)($_POST['id'] ?? 0);
    $sku          = trim($_POST['sku']          ?? '');
    $name         = trim($_POST['name']         ?? '');
    $category     = trim($_POST['category']     ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $description  = trim($_POST['description']  ?? '');
    $stock_qty    = max(0, (int)($_POST['stock_quantity'] ?? 0));
    $min_stock    = max(0, (int)($_POST['min_stock']      ?? 0));
    $purch_price  = $_POST['purchase_price'] !== '' ? (float)str_replace(',', '.', $_POST['purchase_price']) : null;
    $sell_price   = $_POST['selling_price']  !== '' ? (float)str_replace(',', '.', $_POST['selling_price'])  : null;

    // Phase 6 – zentrale Produkt-/Ersatzteildatenbank: zusätzliche, additive
    // Felder (siehe DATENBANKAENDERUNGEN_PHASE6.sql, Abschnitt 1).
    $ean                 = trim($_POST['ean'] ?? '') ?: null;
    $mpn                 = trim($_POST['mpn'] ?? '') ?: null;
    $brand               = trim($_POST['brand'] ?? '') ?: null;
    $subcategory         = trim($_POST['subcategory'] ?? '') ?: null;
    $device_type         = trim($_POST['device_type'] ?? '') ?: null;
    $model_compatibility = trim($_POST['model_compatibility'] ?? '') ?: null;
    $quality_tier        = in_array($_POST['quality_tier'] ?? '', ['original','oem','aftermarket_a','aftermarket_b','generisch'], true) ? $_POST['quality_tier'] : null;
    $is_discontinued     = !empty($_POST['is_discontinued']) ? 1 : 0;
    $stock_location      = trim($_POST['stock_location'] ?? '') ?: null;
    $preferred_supplier_id = (int)($_POST['preferred_supplier_id'] ?? 0) ?: null;

    $errors = [];
    if ($name === '') $errors[] = 'Name ist erforderlich.';

    if (empty($errors)) {
        if ($id > 0) {
            // Update
            $stmt = $db->prepare('UPDATE parts SET sku=?, ean=?, mpn=?, name=?, category=?, subcategory=?, device_type=?,
                model_compatibility=?, brand=?, manufacturer=?, description=?, quality_tier=?, is_discontinued=?,
                stock_location=?, preferred_supplier_id=?,
                stock_quantity=?, min_stock=?, purchase_price=?, selling_price=? WHERE id=?');
            $stmt->execute([$sku, $ean, $mpn, $name, $category, $subcategory, $device_type,
                $model_compatibility, $brand, $manufacturer, $description, $quality_tier, $is_discontinued,
                $stock_location, $preferred_supplier_id,
                $stock_qty, $min_stock, $purch_price, $sell_price, $id]);
            log_activity('update', 'parts', $id);
            flash('success', 'Ersatzteil "' . h($name) . '" wurde aktualisiert.');
        } else {
            // Insert
            $stmt = $db->prepare('INSERT INTO parts (sku, ean, mpn, name, category, subcategory, device_type,
                model_compatibility, brand, manufacturer, description, quality_tier, is_discontinued,
                stock_location, preferred_supplier_id,
                stock_quantity, min_stock, purchase_price, selling_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$sku, $ean, $mpn, $name, $category, $subcategory, $device_type,
                $model_compatibility, $brand, $manufacturer, $description, $quality_tier, $is_discontinued,
                $stock_location, $preferred_supplier_id,
                $stock_qty, $min_stock, $purch_price, $sell_price]);
            $new_id = (int)$db->lastInsertId();
            log_activity('create', 'parts', $new_id);
            flash('success', 'Ersatzteil "' . h($name) . '" wurde angelegt.');
        }
        header('Location: parts.php');
        exit;
    }
    // On error: fall through and re-open modal with posted data
    $modal_open = true;
    $edit_part  = [
        'id' => $id, 'sku' => $sku, 'ean' => $ean, 'mpn' => $mpn, 'name' => $name, 'category' => $category,
        'subcategory' => $subcategory, 'device_type' => $device_type, 'model_compatibility' => $model_compatibility,
        'brand' => $brand, 'manufacturer' => $manufacturer, 'description' => $description,
        'quality_tier' => $quality_tier, 'is_discontinued' => $is_discontinued, 'stock_location' => $stock_location,
        'preferred_supplier_id' => $preferred_supplier_id,
        'stock_quantity' => $stock_qty, 'min_stock' => $min_stock,
        'purchase_price' => $purch_price, 'selling_price' => $sell_price,
    ];
}

// ── Filters & Search ─────────────────────────────────────────────────────────
$q               = trim($_GET['q']        ?? '');
$cat_filter      = trim($_GET['category'] ?? '');
$low_stock_only  = isset($_GET['low_stock']);
$page            = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 25;

$where_parts = [];
$params      = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    // Phase 6: Suche zusätzlich über EAN/MPN/Marke (zentrale Produkt-/
    // Ersatzteildatenbank, Auftragsabschnitt 6 "durchsuchbar nach vielen Feldern").
    $where_parts[] = '(sku LIKE ? OR name LIKE ? OR manufacturer LIKE ? OR ean LIKE ? OR mpn LIKE ? OR brand LIKE ? OR model_compatibility LIKE ?)';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
}
if ($cat_filter !== '') {
    $where_parts[] = 'category = ?';
    $params[]      = $cat_filter;
}
if ($low_stock_only) {
    $where_parts[] = 'min_stock > 0 AND stock_quantity <= min_stock';
}
$price_flagged_only = isset($_GET['price_flagged']);
if ($price_flagged_only) {
    $where_parts[] = 'is_price_flagged = 1';
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM parts $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Fetch
$stmt = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM product_supplier_offers o WHERE o.part_id = p.id) AS offer_count
                       FROM parts p $where_sql ORDER BY name ASC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$per_page, $offset]));
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Categories for filter dropdown
$cat_stmt = $db->query("SELECT DISTINCT category FROM parts WHERE category != '' ORDER BY category");
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// Phase 6: aktive Lieferanten für "Bevorzugter Lieferant"-Auswahl im Modal
$active_suppliers = function_exists('suppliers_list') ? suppliers_list(['status' => 'aktiv']) : [];

// Critical stock items (for alert banner, max 5)
$low_stmt = $db->query('SELECT * FROM parts WHERE min_stock > 0 AND stock_quantity <= min_stock ORDER BY stock_quantity ASC LIMIT 5');
$low_stock_parts = $low_stmt->fetchAll(PDO::FETCH_ASSOC);
$low_count_stmt = $db->query('SELECT COUNT(*) FROM parts WHERE min_stock > 0 AND stock_quantity <= min_stock');
$low_total = (int)$low_count_stmt->fetchColumn();

// Edit-modal pre-fill from ?edit=ID
$edit_part  = $edit_part  ?? null;
$modal_open = $modal_open ?? false;
if (!$modal_open && isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    if ($eid > 0) {
        $es = $db->prepare('SELECT * FROM parts WHERE id = ?');
        $es->execute([$eid]);
        $ep = $es->fetch(PDO::FETCH_ASSOC);
        if ($ep) {
            $edit_part  = $ep;
            $modal_open = true;
        }
    }
}
if (!$modal_open && isset($_GET['new'])) {
    $edit_part  = null;
    $modal_open = true;
}

$page_title = 'Ersatzteile';
require_once __DIR__ . '/includes/header.php';

function build_parts_url(array $extra = [], array $remove = []): string {
    $p = $_GET;
    unset($p['page'], $p['edit'], $p['new']);
    foreach ($remove as $k) unset($p[$k]);
    $p = array_merge($p, $extra);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== false && $v !== null);
    return 'parts.php?' . http_build_query($p);
}
?>

<!-- ── Kritischer Bestand ────────────────────────────────────────────────── -->
<?php if ($low_total > 0 && !$low_stock_only): ?>
<div class="alert alert-warning" style="margin-bottom:1.25rem;display:flex;flex-direction:column;gap:.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <strong><?= svg_icon('alert-triangle', 18) ?> Kritischer Bestand: <?= $low_total ?> Artikel</strong>
        <a href="<?= h(build_parts_url(['low_stock' => '1'])) ?>" class="btn btn-sm btn-outline">
            Alle anzeigen
        </a>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
        <?php foreach ($low_stock_parts as $lp): ?>
            <span class="badge badge-red" style="font-size:.78rem;">
                <?= h($lp['name']) ?> (<?= (int)$lp['stock_quantity'] ?>/<?= (int)$lp['min_stock'] ?>)
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($low_stock_only): ?>
<div class="alert alert-info" style="margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;">
    <span><?= svg_icon('filter', 16) ?> Zeige nur Artikel mit kritischem Bestand (<?= $low_total ?>)</span>
    <a href="parts.php" class="btn btn-sm btn-outline">Filter aufheben</a>
</div>
<?php endif; ?>

<!-- ── Toolbar ───────────────────────────────────────────────────────────── -->
<div class="toolbar">
    <form method="get" action="parts.php" class="toolbar-search" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="SKU, Name, Hersteller …" class="search-input">
        <select name="category" class="search-input" style="width:auto;">
            <option value="">– Alle Kategorien –</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= h($cat) ?>" <?= $cat_filter === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($low_stock_only): ?>
            <input type="hidden" name="low_stock" value="1">
        <?php endif; ?>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Suchen</button>
        <?php if ($q !== '' || $cat_filter !== '' || $low_stock_only): ?>
            <a href="parts.php" class="btn btn-outline">Zurücksetzen</a>
        <?php endif; ?>
    </form>
    <div class="toolbar-actions">
        <a href="parts.php?new=1<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-primary">
            <?= svg_icon('plus', 18) ?> Neu
        </a>
    </div>
</div>

<!-- ── Tabelle ───────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('package', 20) ?> Ersatzteile
            <span class="badge-secondary"><?= $total ?></span>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($parts)): ?>
            <div class="empty-state">
                <?= svg_icon('package', 48) ?>
                <p><?= ($q !== '' || $cat_filter !== '' || $low_stock_only) ? 'Keine Ersatzteile für diesen Filter gefunden.' : 'Noch keine Ersatzteile angelegt.' ?></p>
                <?php if ($q === '' && $cat_filter === '' && !$low_stock_only): ?>
                    <a href="parts.php?new=1" class="btn btn-primary">Erstes Ersatzteil anlegen</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Kategorie</th>
                            <th>Hersteller</th>
                            <th class="text-right">Bestand</th>
                            <th class="text-right">Mindestbestand</th>
                            <th class="text-right">EK-Preis</th>
                            <th class="text-right">VK-Preis</th>
                            <th class="col-actions">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parts as $p): ?>
                            <?php $low = $p['min_stock'] > 0 && $p['stock_quantity'] <= $p['min_stock']; ?>
                            <tr>
                                <td><span class="font-mono text-muted" style="font-size:.82rem;"><?= h($p['sku'] ?? '—') ?></span></td>
                                <td><strong><?= h($p['name']) ?></strong>
                                    <?php if (!empty($p['is_price_flagged'])): ?>
                                        <span class="badge badge-orange" style="font-size:.7rem;" title="<?= h($p['price_flag_reason'] ?? '') ?>">Preis prüfen</span>
                                    <?php endif; ?>
                                    <?php if (!empty($p['is_discontinued'])): ?>
                                        <span class="badge badge-gray" style="font-size:.7rem;">Auslaufartikel</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $p['category'] ? h($p['category']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= $p['manufacturer'] ? h($p['manufacturer']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-right">
                                    <span style="<?= $low ? 'color:var(--red);font-weight:700;' : '' ?>">
                                        <?= (int)$p['stock_quantity'] ?>
                                    </span>
                                    <span class="stock-adj" style="display:inline-flex;gap:2px;margin-left:4px;">
                                        <button class="btn btn-sm btn-outline adj-btn" data-id="<?= (int)$p['id'] ?>" data-delta="-1" title="Bestand verringern" style="padding:2px 6px;">−</button>
                                        <button class="btn btn-sm btn-outline adj-btn" data-id="<?= (int)$p['id'] ?>" data-delta="1"  title="Bestand erhöhen"   style="padding:2px 6px;">+</button>
                                    </span>
                                </td>
                                <td class="text-right"><?= (int)$p['min_stock'] ?></td>
                                <td class="text-right"><?= $p['purchase_price'] !== null ? h(fmt_money((float)$p['purchase_price'])) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-right"><?= $p['selling_price']  !== null ? h(fmt_money((float)$p['selling_price']))  : '<span class="text-muted">—</span>' ?></td>
                                <td class="col-actions">
                                    <div class="action-group">
                                        <a href="product_offers.php?part_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline" title="Lieferantenangebote (<?= (int)$p['offer_count'] ?>)">
                                            <?= svg_icon('link', 15) ?> <?= (int)$p['offer_count'] ?>
                                        </a>
                                        <a href="parts.php?edit=<?= (int)$p['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $cat_filter !== '' ? '&category=' . urlencode($cat_filter) : '' ?>"
                                           class="btn btn-sm btn-outline" title="Bearbeiten">
                                            <?= svg_icon('edit', 15) ?>
                                        </a>
                                        <form method="post" action="parts.php?action=delete&id=<?= (int)$p['id'] ?>"
                                              onsubmit="return confirm('Ersatzteil «<?= h(addslashes($p['name'])) ?>» wirklich löschen?')"
                                              class="inline-form">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger" title="Löschen">
                                                <?= svg_icon('trash', 15) ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= h(build_parts_url(['page' => $page - 1])) ?>" class="page-link">
                            <?= svg_icon('chevron-left', 16) ?> Zurück
                        </a>
                    <?php endif; ?>
                    <span class="page-info">Seite <?= $page ?> von <?= $total_pages ?> (<?= $total ?> Einträge)</span>
                    <?php if ($page < $total_pages): ?>
                        <a href="<?= h(build_parts_url(['page' => $page + 1])) ?>" class="page-link">
                            Weiter <?= svg_icon('chevron-right', 16) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Add / Edit Modal ──────────────────────────────────────────────────── -->
<div class="modal-overlay <?= $modal_open ? 'active' : '' ?>" id="part-modal-overlay">
    <div class="modal" style="max-width:640px;width:100%;" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <span class="modal-title" id="modal-title">
                <?= ($edit_part && ($edit_part['id'] ?? 0) > 0) ? 'Ersatzteil bearbeiten' : 'Neues Ersatzteil' ?>
            </span>
            <button class="modal-close" onclick="closePartModal()" aria-label="Schließen">✕</button>
        </div>
        <form method="post" action="parts.php?action=save">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)($edit_part['id'] ?? 0) ?>">
            <div class="modal-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" style="margin-bottom:1rem;">
                        <?php foreach ($errors as $e): ?>
                            <div><?= h($e) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="p-sku">SKU</label>
                        <input type="text" id="p-sku" name="sku" value="<?= h($edit_part['sku'] ?? '') ?>" placeholder="z.B. BAT-IP12-001">
                    </div>
                    <div class="form-group">
                        <label for="p-name">Name <span style="color:var(--red)">*</span></label>
                        <input type="text" id="p-name" name="name" value="<?= h($edit_part['name'] ?? '') ?>" required placeholder="z.B. Akku iPhone 12">
                    </div>
                    <div class="form-group">
                        <label for="p-category">Kategorie</label>
                        <input type="text" id="p-category" name="category" value="<?= h($edit_part['category'] ?? '') ?>" list="cat-list" placeholder="z.B. Akkus">
                        <datalist id="cat-list">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="p-manufacturer">Hersteller</label>
                        <input type="text" id="p-manufacturer" name="manufacturer" value="<?= h($edit_part['manufacturer'] ?? '') ?>" placeholder="z.B. Apple">
                    </div>
                    <div class="form-group full" style="grid-column:1/-1;">
                        <label for="p-description">Beschreibung</label>
                        <textarea id="p-description" name="description" rows="3" placeholder="Optionale Beschreibung …"><?= h($edit_part['description'] ?? '') ?></textarea>
                    </div>
                    <!-- Phase 6: zentrale Produkt-/Ersatzteildatenbank – zusätzliche Felder -->
                    <div class="form-group">
                        <label for="p-ean">EAN/GTIN</label>
                        <input type="text" id="p-ean" name="ean" value="<?= h($edit_part['ean'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="p-mpn">Herstellerteilenummer (MPN)</label>
                        <input type="text" id="p-mpn" name="mpn" value="<?= h($edit_part['mpn'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="p-brand">Marke</label>
                        <input type="text" id="p-brand" name="brand" value="<?= h($edit_part['brand'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="p-subcategory">Unterkategorie</label>
                        <input type="text" id="p-subcategory" name="subcategory" value="<?= h($edit_part['subcategory'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="p-devicetype">Gerätetyp</label>
                        <input type="text" id="p-devicetype" name="device_type" value="<?= h($edit_part['device_type'] ?? '') ?>" placeholder="z.B. Smartphone">
                    </div>
                    <div class="form-group">
                        <label for="p-modelcompat">Modellkompatibilität</label>
                        <input type="text" id="p-modelcompat" name="model_compatibility" value="<?= h($edit_part['model_compatibility'] ?? '') ?>" placeholder="z.B. iPhone 12/12 Pro">
                    </div>
                    <div class="form-group">
                        <label for="p-qualitytier">Qualitätsstufe</label>
                        <select id="p-qualitytier" name="quality_tier">
                            <option value="">– keine Angabe –</option>
                            <?php foreach (['original' => 'Original', 'oem' => 'OEM', 'aftermarket_a' => 'Zubehör (Klasse A)', 'aftermarket_b' => 'Zubehör (Klasse B)', 'generisch' => 'Generisch'] as $qk => $ql): ?>
                                <option value="<?= $qk ?>" <?= ($edit_part['quality_tier'] ?? '') === $qk ? 'selected' : '' ?>><?= $ql ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="p-stocklocation">Lagerort</label>
                        <input type="text" id="p-stocklocation" name="stock_location" value="<?= h($edit_part['stock_location'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="p-preferredsupplier">Bevorzugter Lieferant</label>
                        <select id="p-preferredsupplier" name="preferred_supplier_id">
                            <option value="0">– keiner –</option>
                            <?php foreach ($active_suppliers as $sup): ?>
                                <option value="<?= (int)$sup['id'] ?>" <?= (int)($edit_part['preferred_supplier_id'] ?? 0) === (int)$sup['id'] ? 'selected' : '' ?>><?= h($sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="is_discontinued" <?= !empty($edit_part['is_discontinued']) ? 'checked' : '' ?> style="width:auto;"> Auslaufartikel</label>
                    </div>
                    <div class="form-group">
                        <label for="p-stock">Bestand <span style="color:var(--red)">*</span></label>
                        <input type="number" id="p-stock" name="stock_quantity" value="<?= (int)($edit_part['stock_quantity'] ?? 0) ?>" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="p-minstock">Mindestbestand</label>
                        <input type="number" id="p-minstock" name="min_stock" value="<?= (int)($edit_part['min_stock'] ?? 0) ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label for="p-ekpreis">EK-Preis (€)</label>
                        <input type="number" id="p-ekpreis" name="purchase_price" value="<?= ($edit_part['purchase_price'] ?? null) !== null ? number_format((float)$edit_part['purchase_price'], 2, '.', '') : '' ?>" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="p-vkpreis">VK-Preis (€)</label>
                        <input type="number" id="p-vkpreis" name="selling_price" value="<?= ($edit_part['selling_price'] ?? null) !== null ? number_format((float)$edit_part['selling_price'], 2, '.', '') : '' ?>" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePartModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary"><?= svg_icon('save', 16) ?> Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────────────────────
function openPartModal(data) {
    var overlay = document.getElementById('part-modal-overlay');
    var title   = document.getElementById('modal-title');
    var form    = overlay.querySelector('form');
    var isEdit  = data && data.id > 0;

    title.textContent = isEdit ? 'Ersatzteil bearbeiten' : 'Neues Ersatzteil';
    form.querySelector('[name="id"]').value              = data ? (data.id || 0) : 0;
    form.querySelector('[name="sku"]').value             = data ? (data.sku || '') : '';
    form.querySelector('[name="name"]').value            = data ? (data.name || '') : '';
    form.querySelector('[name="category"]').value        = data ? (data.category || '') : '';
    form.querySelector('[name="manufacturer"]').value    = data ? (data.manufacturer || '') : '';
    form.querySelector('[name="description"]').value     = data ? (data.description || '') : '';
    form.querySelector('[name="stock_quantity"]').value  = data ? (data.stock_quantity || 0) : 0;
    form.querySelector('[name="min_stock"]').value       = data ? (data.min_stock || 0) : 0;
    form.querySelector('[name="purchase_price"]').value  = data ? (data.purchase_price || '') : '';
    form.querySelector('[name="selling_price"]').value   = data ? (data.selling_price || '') : '';

    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    form.querySelector('[name="name"]').focus();
}
function closePartModal() {
    document.getElementById('part-modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
    // Remove ?new or ?edit from URL without reload
    var url = new URL(window.location.href);
    url.searchParams.delete('new');
    url.searchParams.delete('edit');
    window.history.replaceState({}, '', url.toString());
}

// Close on overlay click
document.getElementById('part-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closePartModal();
});

// ESC closes modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePartModal();
});

// ── Stock adjustment (AJAX) ───────────────────────────────────────────────
document.querySelectorAll('.adj-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var partId = this.dataset.id;
        var delta  = parseInt(this.dataset.delta, 10);
        var row    = this.closest('tr');
        var csrf   = <?= json_encode(csrf_token()) ?>;

        fetch((window.APP_URL_BASE || '') + '/api/parts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=adjust&id=' + partId + '&delta=' + delta + '&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var stockSpan = row.querySelector('td:nth-child(5) > span:first-child');
                stockSpan.textContent = data.new_quantity;
                if (data.is_low) {
                    stockSpan.style.color = 'var(--red)';
                    stockSpan.style.fontWeight = '700';
                } else {
                    stockSpan.style.color = '';
                    stockSpan.style.fontWeight = '';
                }
            } else {
                alert(data.message || 'Fehler beim Aktualisieren des Bestands.');
            }
        })
        .catch(function() { alert('Netzwerkfehler.'); });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
