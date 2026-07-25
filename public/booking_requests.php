<?php
require_once __DIR__ . '/init.php';

$page_title = 'Terminanfragen';
require_once __DIR__ . '/includes/header.php';

$db = get_db();

// ── Filter & Suche ──────────────────────────────────────────────────────────
$q             = trim($_GET['q']      ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 25;

$where_parts = [];
$params      = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where_parts[] = '(booking_number LIKE ? OR CONCAT(first_name," ",last_name) LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$valid_statuses = booking_valid_statuses();
if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
    $where_parts[] = 'status = ?';
    $params[]      = $status_filter;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ── Anzahl ──────────────────────────────────────────────────────────────────
$count_stmt = $db->prepare("SELECT COUNT(*) FROM booking_requests $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Offene Anfragen (angefragt) zuerst zählen für Alert-Banner
$open_stmt = $db->query("SELECT COUNT(*) FROM booking_requests WHERE status = 'angefragt'");
$open_count = (int)$open_stmt->fetchColumn();

// ── Datensätze ────────────────────────────────────────────────────────────────
$sql = "SELECT * FROM booking_requests
        $where_sql
        ORDER BY
            CASE status WHEN 'angefragt' THEN 0 ELSE 1 END,
            created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge($params, [$per_page, $offset]));
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

function build_booking_url(array $extra = [], array $remove = []): string {
    $p = $_GET;
    unset($p['page']);
    foreach ($remove as $k) unset($p[$k]);
    $p = array_merge($p, $extra);
    $p = array_filter($p, fn($v) => $v !== '');
    return 'booking_requests.php?' . http_build_query($p);
}
?>

<?php if ($open_count > 0 && $status_filter === '' && $q === ''): ?>
<div class="alert alert-info" style="margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <span><?= svg_icon('clock', 16) ?> <?= $open_count ?> neue Terminanfrage<?= $open_count === 1 ? '' : 'n' ?> warten auf Bearbeitung.</span>
    <a href="<?= h(build_booking_url(['status' => 'angefragt'])) ?>" class="btn btn-sm btn-outline">Anzeigen</a>
</div>
<?php endif; ?>

<?php show_flash(); ?>

<!-- ── Toolbar ────────────────────────────────────────────────────────────── -->
<div class="toolbar">
    <form method="get" action="booking_requests.php" class="toolbar-search" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input
            type="search"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Buchungsnr., Name, E-Mail, Telefon …"
            class="search-input"
        >
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <?php foreach ($valid_statuses as $s): ?>
                <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>>
                    <?= h(booking_status_label($s)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">
            <?= svg_icon('search', 18) ?> Suchen
        </button>
        <?php if ($q !== '' || $status_filter !== ''): ?>
            <a href="booking_requests.php" class="btn btn-outline">Zurücksetzen</a>
        <?php endif; ?>
    </form>

    <div class="toolbar-actions">
        <a href="<?= url('termin.php') ?>" target="_blank" class="btn btn-outline">
            <?= svg_icon('link', 16) ?> Buchungsseite ansehen
        </a>
    </div>
</div>

<!-- ── Tabelle ───────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('clock', 20) ?> Terminanfragen
            <span class="badge-secondary"><?= $total ?></span>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($bookings)): ?>
            <div class="empty-state">
                <?= svg_icon('clock', 48) ?>
                <p><?= ($q !== '' || $status_filter !== '') ? 'Keine Terminanfragen für diese Suche/Filter gefunden.' : 'Noch keine Terminanfragen eingegangen.' ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Buchungsnr.</th>
                            <th>Gerät</th>
                            <th>Kunde</th>
                            <th>Wunschtermin</th>
                            <th>Status</th>
                            <th>Eingegangen</th>
                            <th class="col-actions">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b): ?>
                            <?php
                                $geraet = trim(($b['manufacturer'] ?? '') . ' ' . ($b['model'] ?? '')) ?: device_type_label($b['device_type']);
                                $wunschzeit = fmt_date($b['preferred_date']) . ', ' . substr((string)$b['preferred_time'], 0, 5) . ' Uhr';
                            ?>
                            <tr>
                                <td>
                                    <a href="booking_view.php?id=<?= (int)$b['id'] ?>" class="font-mono text-primary">
                                        <?= h($b['booking_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="text-strong"><?= h(device_type_label($b['device_type'])) ?></span>
                                    <?php if ($geraet !== device_type_label($b['device_type'])): ?>
                                        <br><small class="text-muted"><?= h($geraet) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= h(trim($b['first_name'] . ' ' . $b['last_name'])) ?>
                                    <br><small class="text-muted"><?= h($b['email']) ?></small>
                                </td>
                                <td>
                                    <?php if (in_array($b['status'], ['bestaetigt', 'umgeplant'], true) && $b['confirmed_datetime']): ?>
                                        <span title="Bestätigter Termin"><?= h(fmt_date($b['confirmed_datetime'], true)) ?></span>
                                    <?php else: ?>
                                        <?= h($wunschzeit) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= booking_status_badge($b['status']) ?></td>
                                <td>
                                    <span title="<?= h(fmt_date($b['created_at'], true)) ?>">
                                        <?= h(fmt_date($b['created_at'])) ?>
                                    </span>
                                </td>
                                <td class="col-actions">
                                    <div class="action-group">
                                        <a href="booking_view.php?id=<?= (int)$b['id'] ?>"
                                           class="btn btn-sm btn-outline"
                                           title="Details / Bearbeiten">
                                            <?= svg_icon('eye', 15) ?>
                                        </a>
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
                        <a href="<?= h(build_booking_url(['page' => $page - 1])) ?>" class="page-link">
                            <?= svg_icon('chevron-left', 16) ?> Zurück
                        </a>
                    <?php endif; ?>

                    <span class="page-info">
                        Seite <?= $page ?> von <?= $total_pages ?>
                        (<?= $total ?> Einträge)
                    </span>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?= h(build_booking_url(['page' => $page + 1])) ?>" class="page-link">
                            Weiter <?= svg_icon('chevron-right', 16) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
