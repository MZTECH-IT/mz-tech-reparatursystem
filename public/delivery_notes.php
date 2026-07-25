<?php
/**
 * MZ Tech – Lieferscheine: Übersicht (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Eigenständige Lieferscheine (siehe private/delivery_notes.php) – gleiche
 * Rechteklammer wie Rechnungsentwürfe (create_invoice_drafts), da
 * Lieferscheine im Dokumentenmodul keine eigene, dedizierte Berechtigung
 * besitzen (siehe PERMISSION_DEFINITIONS in private/permissions.php).
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/permissions.php';
require_once PRIVATE_PATH . '/delivery_notes.php';
require_permission('create_invoice_drafts');

$statusFilter = trim($_GET['status'] ?? '');

$delivery_notes = delivery_notes_list(array_filter([
    'status' => $statusFilter,
]));

$dn_status_labels = [
    'entwurf'    => 'Entwurf',
    'erstellt'   => 'Erstellt',
    'versendet'  => 'Versendet',
    'zugestellt' => 'Zugestellt',
    'storniert'  => 'Storniert',
];
$dn_status_badges = [
    'entwurf'    => 'badge-gray',
    'erstellt'   => 'badge-blue',
    'versendet'  => 'badge-yellow',
    'zugestellt' => 'badge-green',
    'storniert'  => 'badge-red',
];

$page_title = 'Lieferscheine';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" action="delivery_notes.php" style="display:flex;gap:.5rem;align-items:center;">
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <?php foreach ($dn_status_labels as $k => $l): ?>
                <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Filtern</button>
    </form>
    <div class="toolbar-actions">
        <a href="delivery_notes_form.php" class="btn btn-primary"><?= svg_icon('plus', 18) ?> Neuer Lieferschein</a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('package', 20) ?> Lieferscheine <span class="badge badge-gray"><?= count($delivery_notes) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($delivery_notes)): ?>
            <div class="empty-state">
                <?= svg_icon('package', 48) ?>
                <p>Noch keine Lieferscheine angelegt.</p>
                <a href="delivery_notes_form.php" class="btn btn-primary">Ersten Lieferschein anlegen</a>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nummer</th>
                        <th>Kunde/Firma</th>
                        <th>Auftrag</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                        <th class="col-actions">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($delivery_notes as $dn):
                    $customerLabel = $dn['company_name'] ?: trim(($dn['first_name'] ?? '') . ' ' . ($dn['last_name'] ?? ''));
                    $badge = $dn_status_badges[$dn['status']] ?? 'badge-gray';
                    $label = $dn_status_labels[$dn['status']] ?? $dn['status'];
                ?>
                    <tr>
                        <td class="font-mono"><?= h($dn['delivery_note_number'] ?: ('Entwurf #' . $dn['id'])) ?></td>
                        <td><?= $customerLabel !== '' ? h($customerLabel) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if (!empty($dn['repair_id'])): ?>
                                <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$dn['repair_id'] ?>">#<?= (int)$dn['repair_id'] ?></a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
                        <td><?= h(fmt_date($dn['created_at'])) ?></td>
                        <td class="col-actions">
                            <a href="delivery_notes_form.php?id=<?= (int)$dn['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye', 15) ?></a>
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
