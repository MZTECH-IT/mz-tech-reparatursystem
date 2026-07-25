<?php
require_once __DIR__ . '/init.php';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    verify_csrf();

    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $db = get_db();

        // Check if customer has repairs
        $stmt = $db->prepare('SELECT COUNT(*) FROM repairs WHERE customer_id = ?');
        $stmt->execute([$id]);
        $repair_count = (int)$stmt->fetchColumn();

        if ($repair_count > 0) {
            flash('error', 'Kunde kann nicht gelöscht werden, da noch ' . $repair_count . ' Reparatur(en) vorhanden sind.');
        } else {
            // Get customer name for log
            $stmt = $db->prepare('SELECT first_name, last_name FROM customers WHERE id = ?');
            $stmt->execute([$id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customer) {
                $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
                log_activity('delete', 'customers', $id);
                flash('success', 'Kunde "' . h($customer['first_name']) . ' ' . h($customer['last_name']) . '" wurde gelöscht.');
            } else {
                flash('error', 'Kunde nicht gefunden.');
            }
        }
    }

    header('Location: customers.php');
    exit;
}

$page_title = 'Kunden';
require_once __DIR__ . '/includes/header.php';

$db = get_db();

// Search
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$where = '';
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where = 'WHERE (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR phone2 LIKE ? OR email LIKE ?)';
    $params = [$like, $like, $like, $like, $like];
}

// Total count
$count_sql = 'SELECT COUNT(*) FROM customers ' . $where;
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$pagination = paginate($total, $per_page, $page);
$offset = ($page - 1) * $per_page;

// Fetch customers
$sql = 'SELECT * FROM customers ' . $where . ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
$stmt = $db->prepare($sql);
$exec_params = array_merge($params, [$per_page, $offset]);
$stmt->execute($exec_params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="toolbar">
    <form method="get" action="customers.php" class="toolbar-search">
        <input
            type="search"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Name, Telefon oder E-Mail suchen …"
            class="search-input"
        >
        <button type="submit" class="btn btn-outline">
            <?= svg_icon('search', 18) ?> Suchen
        </button>
        <?php if ($q !== ''): ?>
            <a href="customers.php" class="btn btn-outline">Zurücksetzen</a>
        <?php endif; ?>
    </form>

    <div class="toolbar-actions">
        <a href="customers_form.php" class="btn btn-primary">
            <?= svg_icon('plus', 18) ?> Neuen Kunden anlegen
        </a>
    </div>
</div>

<?php if ($q !== ''): ?>
    <p class="search-info">
        <?= $total ?> Ergebnis<?= $total !== 1 ? 'se' : '' ?> für „<?= h($q) ?>"
    </p>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('users', 20) ?> Kunden
            <span class="badge-secondary"><?= $total ?></span>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($customers)): ?>
            <div class="empty-state">
                <?= svg_icon('user-x', 48) ?>
                <p><?= $q !== '' ? 'Keine Kunden für diese Suche gefunden.' : 'Noch keine Kunden angelegt.' ?></p>
                <?php if ($q === ''): ?>
                    <a href="customers_form.php" class="btn btn-primary">Ersten Kunden anlegen</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Vorname</th>
                            <th>Nachname</th>
                            <th>Telefon</th>
                            <th>E-Mail</th>
                            <th>Ort</th>
                            <th>DSGVO</th>
                            <th>Angelegt am</th>
                            <th class="col-actions">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td><?= h($c['first_name']) ?></td>
                                <td><?= h($c['last_name']) ?></td>
                                <td>
                                    <?php if ($c['phone']): ?>
                                        <a href="tel:<?= h($c['phone']) ?>"><?= h($c['phone']) ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['email']): ?>
                                        <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['city']): ?>
                                        <?= h($c['city']) ?>
                                        <?php if ($c['zip']): ?>
                                            <span class="text-muted">(<?= h($c['zip']) ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['gdpr_consent']): ?>
                                        <span class="badge-success" title="Einwilligung am <?= h(fmt_date($c['gdpr_date'])) ?>">
                                            <?= svg_icon('check', 14) ?> Ja
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-danger"><?= svg_icon('x', 14) ?> Nein</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(fmt_date($c['created_at'])) ?></td>
                                <td class="col-actions">
                                    <div class="action-group">
                                        <a
                                            href="customers_form.php?id=<?= (int)$c['id'] ?>"
                                            class="btn btn-sm btn-outline"
                                            title="Bearbeiten"
                                        >
                                            <?= svg_icon('edit', 15) ?> Bearbeiten
                                        </a>
                                        <a
                                            href="repairs_form.php?customer_id=<?= (int)$c['id'] ?>"
                                            class="btn btn-sm btn-outline"
                                            title="Neue Reparatur"
                                        >
                                            <?= svg_icon('tool', 15) ?> Reparatur
                                        </a>
                                        <form
                                            method="post"
                                            action="customers.php?action=delete&id=<?= (int)$c['id'] ?>"
                                            onsubmit="return confirm('Kunden «<?= h(addslashes($c['first_name'] . ' ' . $c['last_name'])) ?>» wirklich löschen?')"
                                            class="inline-form"
                                        >
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger" title="Löschen">
                                                <?= svg_icon('trash', 15) ?> Löschen
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="pagination">
                    <?php if ($pagination['has_prev']): ?>
                        <a
                            href="customers.php?page=<?= $page - 1 ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                            class="page-link"
                        ><?= svg_icon('chevron-left', 16) ?> Zurück</a>
                    <?php endif; ?>

                    <span class="page-info">
                        Seite <?= $page ?> von <?= $pagination['total_pages'] ?>
                        (<?= $total ?> Einträge)
                    </span>

                    <?php if ($pagination['has_next']): ?>
                        <a
                            href="customers.php?page=<?= $page + 1 ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                            class="page-link"
                        >Weiter <?= svg_icon('chevron-right', 16) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
