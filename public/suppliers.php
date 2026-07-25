<?php
/**
 * MZ Tech – Lieferanten: Übersicht (Phase 6)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'sync_now' && $id) {
        $result = supplier_sync_now($id, $_SESSION['user_id'] ?? null);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'test_connection' && $id) {
        // Profil-ID statt Lieferanten-ID bei diesem Aktionstyp.
        $result = supplier_interface_test($id);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'set_status' && $id) {
        supplier_set_status($id, $_POST['status'] ?? 'inaktiv');
        flash('success', 'Status aktualisiert.');
    } elseif ($action === 'sync_all') {
        $count = 0; $errors = 0;
        foreach (suppliers_list(['status' => 'aktiv']) as $s) {
            $r = supplier_sync_now((int)$s['id'], $_SESSION['user_id'] ?? null);
            if ($r['success']) $count++; else $errors++;
        }
        flash($errors === 0 ? 'success' : 'error', "Synchronisation abgeschlossen: $count erfolgreich, $errors mit Fehler.");
    }
    header('Location: ' . url('suppliers.php'));
    exit;
}

$q = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$suppliers = suppliers_list(array_filter(['q' => $q, 'status' => $statusFilter]));

$counts = ['aktiv' => 0, 'inaktiv' => 0, 'archiviert' => 0];
foreach (suppliers_list() as $s) { $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1; }

$page_title = 'Lieferanten';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" action="suppliers.php" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Name, Kürzel, E-Mail …" class="search-input">
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <option value="aktiv"      <?= $statusFilter === 'aktiv' ? 'selected' : '' ?>>Aktiv (<?= $counts['aktiv'] ?>)</option>
            <option value="inaktiv"    <?= $statusFilter === 'inaktiv' ? 'selected' : '' ?>>Inaktiv (<?= $counts['inaktiv'] ?>)</option>
            <option value="archiviert" <?= $statusFilter === 'archiviert' ? 'selected' : '' ?>>Archiviert (<?= $counts['archiviert'] ?>)</option>
        </select>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Suchen</button>
        <?php if ($q !== '' || $statusFilter !== ''): ?>
            <a href="suppliers.php" class="btn btn-outline">Zurücksetzen</a>
        <?php endif; ?>
    </form>
    <div class="toolbar-actions">
        <form method="post" action="suppliers.php" class="inline-form" onsubmit="return confirm('Alle aktiven Lieferanten jetzt synchronisieren? Es werden dabei nur Vorschau-Importe erzeugt, nichts wird automatisch übernommen.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_all">
            <button type="submit" class="btn btn-outline"><?= svg_icon('refresh-cw', 18) ?> Alle synchronisieren</button>
        </form>
        <a href="suppliers_form.php" class="btn btn-primary"><?= svg_icon('plus', 18) ?> Neuer Lieferant</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('package', 20) ?> Lieferanten <span class="badge-secondary"><?= count($suppliers) ?></span></h2>
    </div>
    <div class="card-body">
        <?php if (empty($suppliers)): ?>
            <div class="empty-state">
                <?= svg_icon('package', 48) ?>
                <p>Noch keine Lieferanten angelegt.</p>
                <a href="suppliers_form.php" class="btn btn-primary">Ersten Lieferanten anlegen</a>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Schnittstellen</th>
                        <th>Angebote</th>
                        <th>Letzte Sync.</th>
                        <th>Sync.-Status</th>
                        <th>Auto-Sync</th>
                        <th class="col-actions">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td><strong><?= h($s['name']) ?></strong><?= $s['short_code'] ? ' <span class="text-muted font-mono" style="font-size:.8rem;">(' . h($s['short_code']) . ')</span>' : '' ?></td>
                        <td>
                            <?php $badgeClass = ['aktiv' => 'badge-green', 'inaktiv' => 'badge-secondary', 'archiviert' => 'badge-red'][$s['status']] ?? 'badge-secondary'; ?>
                            <span class="badge <?= $badgeClass ?>"><?= ucfirst($s['status']) ?></span>
                        </td>
                        <td><?= (int)$s['active_profile_count'] ?> aktiv</td>
                        <td><?= (int)$s['offer_count'] ?></td>
                        <td><?= $s['last_sync_at'] ? h(date('d.m.Y H:i', strtotime($s['last_sync_at']))) : '<span class="text-muted">nie</span>' ?></td>
                        <td>
                            <?php $syncBadge = ['nie' => 'badge-secondary', 'erfolgreich' => 'badge-green', 'fehler' => 'badge-red', 'teilweise' => 'badge-orange'][$s['last_sync_status']] ?? 'badge-secondary'; ?>
                            <span class="badge <?= $syncBadge ?>" title="<?= h($s['last_sync_message'] ?? '') ?>"><?= ucfirst($s['last_sync_status']) ?></span>
                        </td>
                        <td><?= ucfirst($s['auto_sync_mode']) ?></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <a href="suppliers_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline" title="Öffnen/Bearbeiten"><?= svg_icon('edit', 15) ?></a>
                                <form method="post" action="suppliers.php" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="sync_now">
                                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline" title="Jetzt synchronisieren"><?= svg_icon('refresh-cw', 15) ?></button>
                                </form>
                                <a href="supplier_import.php?supplier_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline" title="Import-Datei hochladen"><?= svg_icon('upload', 15) ?></a>
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
