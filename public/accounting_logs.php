<?php
/**
 * MZ Tech – Buchhaltung: Protokoll (Phase 7, Abschnitt 8)
 * ----------------------------------------------------------------------
 * Zeigt die Übertragungshistorie (accounting_document_sync) und das
 * Kontakt-Mapping (accounting_contact_mapping), mit Filter nach Anbieter/
 * Status. Reine Leseansicht – Wiederholungen erfolgen über
 * accounting_sync.php.
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/accounting.php';
require_permission('manage_accounting');

$providerFilter = trim($_GET['provider'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$syncs = accounting_document_sync_list(array_filter(['provider' => $providerFilter ?: null, 'status' => $statusFilter ?: null]));
$mappings = accounting_contact_mappings_list($providerFilter);

$page_title = 'Buchhaltung – Protokoll';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <select name="provider" class="search-input" style="width:auto;">
            <option value="">– Alle Anbieter –</option>
            <?php foreach (ACCOUNTING_PROVIDERS as $k => $l): ?>
                <option value="<?= h($k) ?>" <?= $providerFilter === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <option value="erfolgreich" <?= $statusFilter === 'erfolgreich' ? 'selected' : '' ?>>Erfolgreich</option>
            <option value="fehler" <?= $statusFilter === 'fehler' ? 'selected' : '' ?>>Fehler</option>
        </select>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Filtern</button>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('list', 20) ?> Übertragungsprotokoll <span class="badge-secondary"><?= count($syncs) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($syncs)): ?>
            <p class="text-muted">Keine Einträge für diese Filterauswahl.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Datum</th><th>Anbieter</th><th>Typ</th><th>Referenz</th><th>Status</th><th>Externe ID</th><th>Versuche</th><th>Meldung</th></tr></thead>
                <tbody>
                <?php foreach ($syncs as $s): ?>
                    <tr>
                        <td><?= h(date('d.m.Y H:i', strtotime($s['last_attempt_at']))) ?></td>
                        <td><?= h(ACCOUNTING_PROVIDERS[$s['provider']] ?? $s['provider']) ?></td>
                        <td><?= h($s['document_type']) ?></td>
                        <td class="font-mono">#<?= (int)$s['reference_id'] ?></td>
                        <td><span class="badge <?= $s['status'] === 'erfolgreich' ? 'badge-green' : 'badge-red' ?>"><?= h($s['status']) ?></span></td>
                        <td class="font-mono" style="font-size:.8rem;"><?= h($s['external_id'] ?? '—') ?></td>
                        <td class="text-right"><?= (int)$s['attempts'] ?></td>
                        <td class="text-muted" style="font-size:.85rem;max-width:320px;"><?= h($s['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('users', 20) ?> Kontakt-Mapping <span class="badge-secondary"><?= count($mappings) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($mappings)): ?>
            <p class="text-muted">Noch keine Kontakte übertragen.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Anbieter</th><th>Typ</th><th>Interne ID</th><th>Externe ID</th><th>Letzte Sync.</th></tr></thead>
                <tbody>
                <?php foreach ($mappings as $m): ?>
                    <tr>
                        <td><?= h(ACCOUNTING_PROVIDERS[$m['provider']] ?? $m['provider']) ?></td>
                        <td><?= h($m['entity_type']) ?></td>
                        <td class="font-mono">#<?= (int)$m['entity_id'] ?></td>
                        <td class="font-mono" style="font-size:.8rem;"><?= h($m['external_id']) ?></td>
                        <td><?= h(date('d.m.Y H:i', strtotime($m['synced_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
