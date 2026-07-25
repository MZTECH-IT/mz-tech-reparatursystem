<?php
/**
 * MZ Tech – Verkaufspreis-/Kalkulationsregeln (Phase 6, Auftragsabschnitt 9)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_pricing_rules');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        pricing_rule_save($_POST, (int)($_POST['id'] ?? 0) ?: null, $_SESSION['user_id'] ?? null);
        flash('success', 'Kalkulationsregel gespeichert.');
    } elseif ($action === 'delete') {
        pricing_rule_delete((int)($_POST['id'] ?? 0));
        flash('success', 'Kalkulationsregel gelöscht.');
    }
    header('Location: ' . url('pricing_rules.php'));
    exit;
}

$rules = pricing_rules_list();
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_rule = $edit_id ? pricing_rule_find($edit_id) : null;

$page_title = 'Kalkulationsregeln';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('euro', 20) ?> Kalkulationsregeln <span class="badge-secondary"><?= count($rules) ?></span></h2></div>
    <div class="card-body">
        <p class="text-muted">Regeln werden in der Reihenfolge Einzelprodukt → Lieferant → Kategorie → Global geprüft; die erste zutreffende, aktive Regel wird angewendet. Mindestmarge/-gewinn/-preis gelten immer zusätzlich als harte Untergrenze.</p>
        <?php if (!empty($rules)): ?>
        <div class="table-wrap" style="margin-bottom:1.5rem;">
            <table class="table">
                <thead><tr><th>Name</th><th>Geltungsbereich</th><th>Berechnung</th><th>Rundung/Endung</th><th>Priorität</th><th>Aktiv</th><th class="col-actions">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td><?= h($r['name']) ?></td>
                        <td><?= h($r['scope_type']) ?><?= $r['scope_value'] ? ' (' . h($r['scope_value']) . ')' : '' ?></td>
                        <td><?= h($r['calculation_type']) ?>: <?= h($r['value']) ?></td>
                        <td><?= h($r['rounding_mode']) ?> / <?= h($r['price_ending']) ?></td>
                        <td><?= (int)$r['priority'] ?></td>
                        <td><?= $r['is_active'] ? '✓' : '—' ?></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <a href="pricing_rules.php?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('edit', 15) ?></a>
                                <form method="post" class="inline-form" onsubmit="return confirm('Regel löschen?');"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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

        <h3 style="font-size:1rem;"><?= $edit_rule ? 'Regel bearbeiten' : 'Neue Regel anlegen' ?></h3>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit_rule['id'] ?? 0) ?>">
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:1rem;">
                <div class="form-group"><label>Name</label><input type="text" name="name" value="<?= h($edit_rule['name'] ?? '') ?>" required></div>
                <div class="form-group"><label>Geltungsbereich</label>
                    <select name="scope_type">
                        <?php foreach (['global'=>'Global','kategorie'=>'Kategorie','lieferant'=>'Lieferant (ID)','einzelprodukt'=>'Einzelprodukt (ID)'] as $k=>$l): ?>
                        <option value="<?= $k ?>" <?= ($edit_rule['scope_type'] ?? 'global') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Geltungsbereich-Wert</label><input type="text" name="scope_value" value="<?= h($edit_rule['scope_value'] ?? '') ?>" placeholder="Kategoriename / Lieferant-ID / Produkt-ID"></div>
                <div class="form-group"><label>Berechnungsart</label>
                    <select name="calculation_type">
                        <?php foreach (['prozent_aufschlag'=>'Prozentualer Aufschlag','fester_aufschlag'=>'Fester Aufschlag (€)','fixer_preis'=>'Fixer Preis (€)'] as $k=>$l): ?>
                        <option value="<?= $k ?>" <?= ($edit_rule['calculation_type'] ?? 'prozent_aufschlag') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Wert</label><input type="number" step="0.0001" name="value" value="<?= h($edit_rule['value'] ?? 0) ?>"></div>
                <div class="form-group"><label>Mindestmarge (%)</label><input type="number" step="0.01" name="min_margin_percent" value="<?= h($edit_rule['min_margin_percent'] ?? '') ?>"></div>
                <div class="form-group"><label>Mindestgewinn (€)</label><input type="number" step="0.01" name="min_profit_amount" value="<?= h($edit_rule['min_profit_amount'] ?? '') ?>"></div>
                <div class="form-group"><label>Mindestpreis (€)</label><input type="number" step="0.01" name="min_price" value="<?= h($edit_rule['min_price'] ?? '') ?>"></div>
                <div class="form-group"><label>Rundung</label>
                    <select name="rounding_mode">
                        <?php foreach (['keine'=>'Keine','auf_0_05'=>'Auf 0,05 €','auf_0_10'=>'Auf 0,10 €','auf_0_50'=>'Auf 0,50 €','auf_1_00'=>'Auf 1,00 €'] as $k=>$l): ?>
                        <option value="<?= $k ?>" <?= ($edit_rule['rounding_mode'] ?? 'keine') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Preisendung</label>
                    <select name="price_ending">
                        <?php foreach (['keine'=>'Keine','_99'=>',99','_95'=>',95','_00'=>',00'] as $k=>$l): ?>
                        <option value="<?= $k ?>" <?= ($edit_rule['price_ending'] ?? 'keine') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Priorität (kleiner = zuerst geprüft)</label><input type="number" name="priority" value="<?= h($edit_rule['priority'] ?? 100) ?>"></div>
                <div class="form-group"><label><input type="checkbox" name="is_active" <?= ($edit_rule['is_active'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Aktiv</label></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('save', 16) ?> Speichern</button>
            <?php if ($edit_rule): ?><a href="pricing_rules.php" class="btn btn-outline">Abbrechen</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
