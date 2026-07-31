<?php
require_once __DIR__ . '/init.php';

$page_title = 'Reparaturen';
require_once __DIR__ . '/includes/header.php';

$db = get_db();

// ── Filter & Suche ──────────────────────────────────────────────────────────
$q           = trim($_GET['q']           ?? '');
$status_filter = trim($_GET['status']   ?? '');
$device_filter = trim($_GET['device_type'] ?? '');
$customer_id   = (int)($_GET['customer_id'] ?? 0);
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 25;

$where_parts = [];
$params      = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where_parts[] = '(r.repair_number LIKE ? OR CONCAT(c.first_name," ",c.last_name) LIKE ? OR r.manufacturer LIKE ? OR r.model LIKE ? OR r.device_type LIKE ?)';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$valid_statuses = repair_valid_statuses();
if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
    // Filtert sowohl auf den neuen Statuswert als auch auf einen eventuell
    // noch vorhandenen alten (nicht migrierten) Statuswert derselben Stufe.
    $legacy_equivalents = array_keys(array_filter(
        ['eingegangen' => 'angenommen', 'in_arbeit' => 'in_reparatur', 'warte_auf_teile' => 'ersatzteil_bestellt', 'repariert' => 'fertig'],
        fn($v) => $v === $status_filter
    ));
    $status_values = array_merge([$status_filter], $legacy_equivalents);
    $placeholders  = implode(',', array_fill(0, count($status_values), '?'));
    $where_parts[] = "r.status IN ($placeholders)";
    $params        = array_merge($params, $status_values);
}

if ($customer_id > 0) {
    $where_parts[] = 'r.customer_id = ?';
    $params[]      = $customer_id;
}

$device_options = device_type_options();
if ($device_filter !== '' && array_key_exists($device_filter, $device_options)) {
    $where_parts[] = '(r.device_type = ? OR r.device_type_id = (
        SELECT id FROM device_types WHERE technical_key = ? LIMIT 1
    ))';
    $params[] = $device_filter;
    $params[] = $device_filter;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Kundennamen bei customer_id-Filter
$customer_name = '';
if ($customer_id > 0) {
    $cs = $db->prepare('SELECT first_name, last_name FROM customers WHERE id = ?');
    $cs->execute([$customer_id]);
    $cf = $cs->fetch();
    if ($cf) $customer_name = $cf['first_name'] . ' ' . $cf['last_name'];
}

// ── Anzahl ──────────────────────────────────────────────────────────────────
$count_sql = "SELECT COUNT(*) FROM repairs r
              LEFT JOIN customers c ON c.id = r.customer_id
              $where_sql";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$pagination = paginate($total, $per_page, $page);
$offset     = ($page - 1) * $per_page;

// ── Datensätze ───────────────────────────────────────────────────────────────
$sql = "SELECT r.*,
               c.first_name, c.last_name,
               u.full_name AS technician_name
        FROM repairs r
        LEFT JOIN customers c ON c.id = r.customer_id
        LEFT JOIN users     u ON u.id = r.technician_id
        $where_sql
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge($params, [$per_page, $offset]));
$repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── URL-Helper ───────────────────────────────────────────────────────────────
function build_url(array $extra = [], array $remove = []): string {
    $p = $_GET;
    unset($p['page']);
    foreach ($remove as $k) unset($p[$k]);
    $p = array_merge($p, $extra);
    $p = array_filter($p, fn($v) => $v !== '');
    return 'repairs.php?' . http_build_query($p);
}
?>

<!-- ── Toolbar ────────────────────────────────────────────────────────────── -->
<div class="toolbar">
    <form method="get" action="repairs.php" class="toolbar-search" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <?php if ($customer_id > 0): ?>
            <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
        <?php endif; ?>
        <input
            type="search"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Auftragsnr., Kunde, Gerät …"
            class="search-input"
        >
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <?php foreach ($valid_statuses as $s): ?>
                <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>>
                    <?= h(repair_status_label($s)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="device_type" class="search-input" style="width:auto;">
            <option value="">– Alle Gerätearten –</option>
            <?php foreach ($device_options as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= $device_filter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">
            <?= svg_icon('search', 18) ?> Suchen
        </button>
        <?php if ($q !== '' || $status_filter !== '' || $device_filter !== ''): ?>
            <a href="repairs.php<?= $customer_id > 0 ? '?customer_id=' . $customer_id : '' ?>" class="btn btn-outline">Zurücksetzen</a>
        <?php endif; ?>
    </form>

    <div class="toolbar-actions">
        <a href="repairs_form.php<?= $customer_id > 0 ? '?customer_id=' . $customer_id : '' ?>" class="btn btn-primary">
            <?= svg_icon('plus', 18) ?> Neue Reparatur
        </a>
    </div>
</div>

<?php if ($customer_name !== ''): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;">
        <?= svg_icon('user', 16) ?>
        Reparaturen für <strong><?= h($customer_name) ?></strong>
        <a href="repairs.php" style="margin-left:.75rem;">Alle Reparaturen anzeigen</a>
    </div>
<?php endif; ?>

<?php show_flash(); ?>

<!-- ── Tabelle ───────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('wrench', 20) ?> Reparaturen
            <span class="badge-secondary"><?= $total ?></span>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($repairs)): ?>
            <div class="empty-state">
                <?= svg_icon('tool', 48) ?>
                <p><?= ($q !== '' || $status_filter !== '') ? 'Keine Reparaturen für diese Suche/Filter gefunden.' : 'Noch keine Reparaturen angelegt.' ?></p>
                <?php if ($q === '' && $status_filter === ''): ?>
                    <a href="repairs_form.php" class="btn btn-primary">Erste Reparatur anlegen</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Auftragsnr.</th>
                            <th>Gerät</th>
                            <th>Kunde</th>
                            <th>Techniker</th>
                            <th>Status</th>
                            <th class="text-right">Preis</th>
                            <th>Datum</th>
                            <th class="col-actions">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repairs as $r): ?>
                            <tr>
                                <td>
                                    <a href="repairs_view.php?id=<?= (int)$r['id'] ?>" class="font-mono text-primary">
                                        <?= h($r['repair_number'] ?? '#' . $r['id']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="text-strong"><?= h($r['manufacturer'] ?? '') ?></span>
                                    <?php if ($r['model']): ?>
                                        <span class="text-muted"> <?= h($r['model']) ?></span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted"><?= h(device_type_label((string)($r['device_type'] ?? ''))) ?></small>
                                </td>
                                <td>
                                    <?php if ($r['first_name'] || $r['last_name']): ?>
                                        <a href="repairs.php?customer_id=<?= (int)$r['customer_id'] ?>">
                                            <?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['technician_name']): ?>
                                        <?= h($r['technician_name']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= repair_status_badge($r['status']) ?></td>
                                <td class="text-right">
                                    <?= h(fmt_money((float)($r['price'] ?? 0) + (float)($r['labor_cost'] ?? 0))) ?>
                                </td>
                                <td>
                                    <span title="<?= h(fmt_date($r['created_at'], true)) ?>">
                                        <?= h(fmt_date($r['created_at'])) ?>
                                    </span>
                                </td>
                                <td class="col-actions">
                                    <div class="action-group">
                                        <a href="repairs_view.php?id=<?= (int)$r['id'] ?>"
                                           class="btn btn-sm btn-outline"
                                           title="Ansehen">
                                            <?= svg_icon('eye', 15) ?>
                                        </a>
                                        <a href="repairs_form.php?id=<?= (int)$r['id'] ?>"
                                           class="btn btn-sm btn-outline"
                                           title="Bearbeiten">
                                            <?= svg_icon('edit', 15) ?>
                                        </a>
                                        <a href="pdf/auftrag.php?id=<?= (int)$r['id'] ?>"
                                           class="btn btn-sm btn-outline"
                                           title="Auftragsschein PDF"
                                           target="_blank">
                                            <?= svg_icon('pdf', 15) ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="pagination">
                    <?php if ($pagination['current_page'] > 1): ?>
                        <a href="<?= h(build_url(['page' => $page - 1])) ?>" class="page-link">
                            <?= svg_icon('arrow-left', 16) ?> Zurück
                        </a>
                    <?php endif; ?>

                    <span class="page-info">
                        Seite <?= $pagination['current_page'] ?> von <?= $pagination['total_pages'] ?>
                        (<?= $total ?> Einträge)
                    </span>

                    <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                        <a href="<?= h(build_url(['page' => $page + 1])) ?>" class="page-link">
                            Weiter <?= svg_icon('chevron-right', 16) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
