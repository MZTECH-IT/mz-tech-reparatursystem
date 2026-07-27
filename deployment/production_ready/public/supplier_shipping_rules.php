<?php
/**
 * MZ Tech – Versandkostenregeln: Übersicht über alle Lieferanten (Phase 7)
 * ----------------------------------------------------------------------
 * Eigenständige Verwaltungsseite, ergänzend zur bereits bestehenden
 * Versandregel-Karte in public/suppliers_form.php (dort je Lieferant
 * eingebettet). Nutzt exakt dieselben Backend-Funktionen
 * (supplier_shipping_rule_save/_delete/_set_active/_find,
 * supplier_shipping_rules_list_all – siehe private/purchase_orders.php)
 * und dieselben Regeltypen – keine parallele Struktur, nur eine
 * zusätzliche, lieferantenübergreifende Sicht mit Bearbeitungsmöglichkeit.
 */
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');

$RULE_TYPE_LABELS = [
    'fest' => 'Fest', 'kostenlos_ab' => 'Kostenlos ab Betrag', 'express_zuschlag' => 'Express-Zuschlag',
    'sperrgut_zuschlag' => 'Sperrgut-Zuschlag', 'gefahrgut_zuschlag' => 'Gefahrgut-Zuschlag',
    'pro_versandklasse' => 'Pro Versandklasse', 'pro_land' => 'Pro Land',
];

$supplierId = (int)($_GET['supplier_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $postSupplierId = (int)($_POST['supplier_id'] ?? 0);

    if ($action === 'save_rule' && $postSupplierId) {
        supplier_shipping_rule_save($postSupplierId, $_POST, (int)($_POST['rule_id'] ?? 0) ?: null);
        flash('success', 'Versandregel gespeichert.');
    } elseif ($action === 'delete_rule') {
        supplier_shipping_rule_delete((int)($_POST['rule_id'] ?? 0));
        flash('success', 'Versandregel gelöscht.');
    } elseif ($action === 'toggle_rule') {
        $rule = supplier_shipping_rule_find((int)($_POST['rule_id'] ?? 0));
        if ($rule) { supplier_shipping_rule_set_active((int)$rule['id'], !$rule['is_active']); }
        flash('success', 'Status aktualisiert.');
    }
    header('Location: ' . url('supplier_shipping_rules.php') . '?supplier_id=' . $postSupplierId);
    exit;
}

$suppliers = suppliers_list();
$rules = $supplierId ? supplier_shipping_rules_list_all($supplierId) : [];
$editId = (int)($_GET['edit'] ?? 0);
$editRule = $editId ? supplier_shipping_rule_find($editId) : null;

$page_title = 'Versandkostenregeln';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" style="display:flex;gap:.5rem;align-items:center;">
        <select name="supplier_id" onchange="this.form.submit()">
            <option value="">– Lieferant wählen –</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$supplierId): ?>
<div class="card"><div class="card-body">
    <p class="text-muted">Bitte einen Lieferanten auswählen, um dessen Versandkostenregeln zu verwalten.</p>
</div></div>
<?php else: ?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('package', 20) ?> Versandkostenregeln <span class="badge-secondary"><?= count($rules) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($rules)): ?>
            <p class="text-muted">Noch keine Versandkostenregeln für diesen Lieferanten.</p>
        <?php else: ?>
        <div class="table-wrap" style="margin-bottom:1.5rem;">
            <table class="table">
                <thead><tr><th>Typ</th><th>Schwellwert/Bedingung</th><th class="text-right">Betrag</th><th class="text-right">Priorität</th><th>Status</th><th class="col-actions">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td><?= h($RULE_TYPE_LABELS[$r['rule_type']] ?? $r['rule_type']) ?></td>
                        <td><?= h($r['condition_value'] !== null ? fmt_money((float)$r['condition_value']) : ($r['condition_text'] ?? '—')) ?></td>
                        <td class="text-right"><?= h(fmt_money((float)$r['amount'])) ?></td>
                        <td class="text-right"><?= (int)$r['priority'] ?></td>
                        <td><span class="badge <?= $r['is_active'] ? 'badge-green' : 'badge-secondary' ?>"><?= $r['is_active'] ? 'Aktiv' : 'Inaktiv' ?></span></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <a href="supplier_shipping_rules.php?supplier_id=<?= $supplierId ?>&edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline" title="Bearbeiten"><?= svg_icon('edit', 15) ?></a>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle_rule">
                                    <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
                                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                </form>
                                <form method="post" class="inline-form" onsubmit="return confirm('Regel löschen?');">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_rule">
                                    <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
                                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><?= svg_icon('trash', 15) ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h3 style="font-size:1rem;"><?= $editRule ? 'Regel bearbeiten' : 'Neue Regel hinzufügen' ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rule">
            <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
            <?php if ($editRule): ?><input type="hidden" name="rule_id" value="<?= (int)$editRule['id'] ?>"><?php endif; ?>
            <div class="form-grid" style="grid-template-columns:repeat(3,1fr);gap:.75rem;">
                <div class="form-group"><label>Typ</label>
                    <select name="rule_type">
                        <?php foreach ($RULE_TYPE_LABELS as $k => $l): ?>
                        <option value="<?= $k ?>" <?= ($editRule['rule_type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Schwellwert (€, z. B. für "Kostenlos ab")</label>
                    <input type="number" step="0.01" name="condition_value" value="<?= h($editRule['condition_value'] ?? '') ?>">
                </div>
                <div class="form-group"><label>Text (Versandklasse/Land, z. B. für "Pro Land")</label>
                    <input type="text" name="condition_text" value="<?= h($editRule['condition_text'] ?? '') ?>">
                </div>
                <div class="form-group"><label>Betrag (€)</label>
                    <input type="number" step="0.01" name="amount" value="<?= h($editRule['amount'] ?? '0') ?>">
                </div>
                <div class="form-group"><label>Priorität (niedriger = zuerst geprüft)</label>
                    <input type="number" name="priority" value="<?= h($editRule['priority'] ?? '100') ?>">
                </div>
                <div class="form-group"><label><input type="checkbox" name="is_active" value="1" style="width:auto;" <?= ($editRule['is_active'] ?? 1) ? 'checked' : '' ?>> Aktiv</label></div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:.75rem;"><?= svg_icon('save', 16) ?> <?= $editRule ? 'Speichern' : 'Regel hinzufügen' ?></button>
            <?php if ($editRule): ?><a href="supplier_shipping_rules.php?supplier_id=<?= $supplierId ?>" class="btn btn-outline" style="margin-top:.75rem;">Abbrechen</a><?php endif; ?>
        </form>
        <p class="text-muted" style="margin-top:1rem;">Hinweis: liegt für eine Bestellposition ein exakter, vom Lieferanten per API gelieferter Versandkostenwert vor, hat dieser stets Vorrang vor den hier konfigurierten Regeln (siehe purchase_order_calculate_shipping()).</p>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
