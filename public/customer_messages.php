<?php
/**
 * MZ Tech – Admin: Kundennachrichten (Übersicht)
 *
 * Zeigt alle Konversationen aus dem Kundenportal (Tabelle
 * `customer_messages`) gruppiert nach Kunde, mit ungelesenen
 * Nachrichten zuerst. Details/Antworten siehe customer_message_view.php.
 */
require_once __DIR__ . '/init.php';

$page_title = 'Kundennachrichten';
require_once __DIR__ . '/includes/header.php';

$db = get_db();

$q = trim($_GET['q'] ?? '');

$where_sql = '';
$params    = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where_sql = 'WHERE (CONCAT(c.first_name," ",c.last_name) LIKE ? OR c.email LIKE ?)';
    $params    = [$like, $like];
}

// Eine Zeile pro Kunde: letzte Nachricht + Anzahl ungelesener (vom Kunden gesendeter) Nachrichten.
$sql = "
    SELECT
        c.id AS customer_id,
        c.first_name, c.last_name, c.email,
        (SELECT m2.message    FROM customer_messages m2 WHERE m2.customer_id = c.id ORDER BY m2.created_at DESC LIMIT 1) AS last_message,
        (SELECT m2.sender     FROM customer_messages m2 WHERE m2.customer_id = c.id ORDER BY m2.created_at DESC LIMIT 1) AS last_sender,
        (SELECT m2.created_at FROM customer_messages m2 WHERE m2.customer_id = c.id ORDER BY m2.created_at DESC LIMIT 1) AS last_at,
        (SELECT COUNT(*) FROM customer_messages m3 WHERE m3.customer_id = c.id AND m3.sender = 'customer' AND m3.is_read = 0) AS unread_count
    FROM customers c
    WHERE c.id IN (SELECT DISTINCT customer_id FROM customer_messages)
    " . ($q !== '' ? 'AND (CONCAT(c.first_name," ",c.last_name) LIKE ? OR c.email LIKE ?)' : '') . "
    ORDER BY unread_count DESC, last_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($q !== '' ? [$like, $like] : []);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_unread = 0;
foreach ($conversations as $c) { $total_unread += (int)$c['unread_count']; }
?>

<?php show_flash(); ?>

<div class="toolbar">
    <form method="get" action="customer_messages.php" class="toolbar-search" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input
            type="search"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Kundenname oder E-Mail …"
            class="search-input"
        >
        <button type="submit" class="btn btn-outline">
            <?= svg_icon('search', 18) ?> Suchen
        </button>
        <?php if ($q !== ''): ?>
            <a href="customer_messages.php" class="btn btn-outline">Zurücksetzen</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('message-circle', 20) ?> Kundennachrichten
            <span class="badge-secondary"><?= count($conversations) ?></span>
            <?php if ($total_unread > 0): ?>
                <span class="badge-red"><?= $total_unread ?> ungelesen</span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($conversations)): ?>
            <div class="empty-state">
                <?= svg_icon('inbox', 48) ?>
                <p>Noch keine Nachrichten von Kunden im Kundenportal eingegangen.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kunde</th>
                            <th>Letzte Nachricht</th>
                            <th>Von</th>
                            <th>Datum</th>
                            <th class="col-actions">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conversations as $c): ?>
                            <tr<?= (int)$c['unread_count'] > 0 ? ' style="font-weight:600;"' : '' ?>>
                                <td>
                                    <?= h(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
                                    <?php if ((int)$c['unread_count'] > 0): ?>
                                        <span class="badge-red"><?= (int)$c['unread_count'] ?></span>
                                    <?php endif; ?>
                                    <br><small class="text-muted"><?= h($c['email'] ?? '') ?></small>
                                </td>
                                <td>
                                    <?php
                                        $snippet = mb_substr((string)$c['last_message'], 0, 80);
                                        if (mb_strlen((string)$c['last_message']) > 80) $snippet .= '…';
                                    ?>
                                    <?= h($snippet) ?>
                                </td>
                                <td>
                                    <?= $c['last_sender'] === 'staff' ? 'MZ Tech' : 'Kunde' ?>
                                </td>
                                <td>
                                    <span title="<?= h(fmt_date($c['last_at'], true)) ?>">
                                        <?= h(fmt_date($c['last_at'])) ?>
                                    </span>
                                </td>
                                <td class="col-actions">
                                    <div class="action-group">
                                        <a href="customer_message_view.php?customer_id=<?= (int)$c['customer_id'] ?>"
                                           class="btn btn-sm btn-outline"
                                           title="Konversation öffnen">
                                            <?= svg_icon('eye', 15) ?> Öffnen
                                        </a>
                                    </div>
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
