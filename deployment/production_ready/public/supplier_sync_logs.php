<?php
/**
 * MZ Tech – Synchronisationsprotokoll: lieferantenübergreifend (Phase 7)
 * ----------------------------------------------------------------------
 * Zeigt alle Import-/Synchronisationsaufträge (import_jobs) über ALLE
 * Lieferanten hinweg, mit Filter nach Lieferant/Status, sowie den
 * aktuellen Sync-Status jedes Lieferanten (suppliers.last_sync_*).
 * Ergänzt die bereits bestehende, je Lieferant eingebettete
 * "Import-Historie"-Karte in public/suppliers_form.php um eine
 * zentrale Übersicht – nutzt identische Backend-Funktionen
 * (import_jobs_list(), import_job_apply(), import_job_rollback()),
 * keine neue Protokollquelle.
 */
require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($action === 'apply_import' && $jobId && user_has_permission('approve_imports')) {
        $result = import_job_apply($jobId, $_SESSION['user_id'] ?? 0);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'rollback_import' && $jobId && user_has_permission('approve_imports')) {
        $result = import_job_rollback($jobId, $_SESSION['user_id'] ?? 0);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    }
    header('Location: ' . url('supplier_sync_logs.php') . '?' . http_build_query($_GET));
    exit;
}

$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$jobs = import_jobs_list(array_filter(['supplier_id' => $supplierFilter ?: null, 'status' => $statusFilter ?: null]));
$suppliers = suppliers_list();

$page_title = 'Synchronisationsprotokoll';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <select name="supplier_id" class="search-input" style="width:auto;">
            <option value="">– Alle Lieferanten –</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $supplierFilter === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <?php foreach (['vorschau'=>'Vorschau','wartet_auf_freigabe'=>'Wartet auf Freigabe','importiert'=>'Importiert','zurueckgerollt'=>'Zurückgerollt','fehlgeschlagen'=>'Fehlgeschlagen'] as $k=>$l): ?>
                <option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Filtern</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('list', 20) ?> Lieferanten-Sync-Status</h2></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Lieferant</th><th>Auto-Sync</th><th>Letzte Sync.</th><th>Status</th><th>Meldung</th></tr></thead>
                <tbody>
                <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td><a href="suppliers_form.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a></td>
                        <td><?= h(ucfirst($s['auto_sync_mode'])) ?></td>
                        <td><?= $s['last_sync_at'] ? h(date('d.m.Y H:i', strtotime($s['last_sync_at']))) : '<span class="text-muted">nie</span>' ?></td>
                        <td>
                            <?php $syncBadge = ['nie' => 'badge-secondary', 'erfolgreich' => 'badge-green', 'fehler' => 'badge-red', 'teilweise' => 'badge-orange'][$s['last_sync_status']] ?? 'badge-secondary'; ?>
                            <span class="badge <?= $syncBadge ?>"><?= h(ucfirst($s['last_sync_status'] ?: 'nie')) ?></span>
                        </td>
                        <td class="text-muted" style="font-size:.85rem;"><?= h($s['last_sync_message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('database', 20) ?> Import-/Sync-Aufträge <span class="badge-secondary"><?= count($jobs) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($jobs)): ?>
            <p class="text-muted">Keine Einträge für diese Filterauswahl.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Datum</th><th>Lieferant</th><th>Quelle</th><th>Status</th><th>Neu/Aktualisiert/Unverändert/Fehler</th><th class="col-actions">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($jobs as $job):
                    $jobSupplier = $job['supplier_id'] ? supplier_find((int)$job['supplier_id']) : null;
                ?>
                    <tr>
                        <td><?= h(date('d.m.Y H:i', strtotime($job['created_at']))) ?></td>
                        <td><?= $jobSupplier ? '<a href="suppliers_form.php?id=' . (int)$jobSupplier['id'] . '">' . h($jobSupplier['name']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h($job['source_filename'] ?? $job['source_type']) ?></td>
                        <td>
                            <?php $jobBadge = ['vorschau'=>'badge-secondary','wartet_auf_freigabe'=>'badge-orange','importiert'=>'badge-green','zurueckgerollt'=>'badge-gray','fehlgeschlagen'=>'badge-red'][$job['status']] ?? 'badge-secondary'; ?>
                            <span class="badge <?= $jobBadge ?>"><?= h($job['status']) ?></span>
                        </td>
                        <td><?= (int)$job['rows_new'] ?> / <?= (int)$job['rows_updated'] ?> / <?= (int)$job['rows_unchanged'] ?> / <?= (int)$job['rows_error'] ?></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <?php if ($job['status'] === 'wartet_auf_freigabe' && user_has_permission('approve_imports')): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Diesen Import jetzt verbindlich anwenden?');"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="apply_import">
                                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Freigeben &amp; importieren</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($job['status'] === 'importiert' && user_has_permission('approve_imports')): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Diesen Import wirklich zurückrollen?');"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rollback_import">
                                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline">Rückgängig machen</button>
                                </form>
                                <?php endif; ?>
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
