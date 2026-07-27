<?php
require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

$suppliers = suppliers_list();
$rows = [];
foreach ($suppliers as $supplier) {
    foreach (supplier_interface_profiles_list((int)$supplier['id']) as $profile) {
        $profile['supplier_name'] = $supplier['name'];
        $rows[] = $profile;
    }
}
$labels = supplier_interface_type_labels();
$page_title = 'Lieferanten-API & Adapter';
require_once __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <a href="<?= url('suppliers.php') ?>" class="btn btn-outline"><?= svg_icon('arrow-left', 16) ?> Lieferanten &amp; Großhändler</a>
</div>
<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('settings', 20) ?> Konfigurierte Schnittstellen <span class="badge-secondary"><?= count($rows) ?></span></h2></div>
    <div class="card-body">
        <p class="text-muted">Zugangsdaten werden verschlüsselt gespeichert und hier niemals ausgegeben. Ein fehlendes oder deaktiviertes Profil stoppt nur den jeweiligen Lieferantenlauf.</p>
        <?php if (!$rows): ?>
            <div class="empty-state"><p>Noch keine Schnittstelle konfiguriert. Öffnen Sie einen Lieferanten oder Großhändler und legen Sie dort ein Schnittstellenprofil an.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Lieferant / Großhändler</th><th>Profil</th><th>Typ</th><th>Status</th><th>Letzter Abruf</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['supplier_name']) ?></td>
                    <td><?= h($row['label']) ?></td>
                    <td><?= h($labels[$row['interface_type']] ?? $row['interface_type']) ?></td>
                    <td><span class="badge <?= $row['is_active'] ? 'badge-green' : 'badge-secondary' ?>"><?= $row['is_active'] ? 'Aktiv' : 'Deaktiviert' ?></span></td>
                    <td><?= !empty($row['last_fetch_at']) ? h(date('d.m.Y H:i', strtotime($row['last_fetch_at']))) : '—' ?></td>
                    <td><a class="btn btn-sm btn-outline" href="<?= url('suppliers_form.php?id=' . (int)$row['supplier_id'] . '#schnittstellen') ?>">Konfigurieren</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
