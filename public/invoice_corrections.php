<?php
/**
 * MZ Tech – Gutschriften & Stornorechnungen: Übersicht (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Zeigt ALLE Korrekturen (Gutschriften und Stornorechnungen, siehe
 * private/invoice_corrections.php – eine Tabelle für beide Arten,
 * unterschieden über correction_type) auftragsübergreifend an.
 *
 * Anders als Angebote/Lieferscheine ist eine Korrektur nie "frei
 * schwebend": sie bezieht sich immer auf einen konkreten, bereits
 * freigegebenen Rechnung. Der übliche Weg, eine Korrektur anzulegen,
 * führt daher über den Link "Gutschrift/Storno erstellen" auf
 * repairs_view.php (siehe dort, Abschnitt "Rechnung & Kostenvoranschlag").
 * Damit Mitarbeiter dennoch nicht zwingend über einen konkreten Auftrag
 * einsteigen müssen, bietet diese Übersicht zusätzlich ein kompaktes
 * Inline-Formular an, das direkt per Auftrags-ID anlegt.
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/permissions.php';
require_once PRIVATE_PATH . '/invoice_corrections.php';
require_permission('create_credit_notes');

// ── POST: Inline-Neuanlage per Auftrags-ID ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $repairId = (int)($_POST['repair_id'] ?? 0);
        $type     = $_POST['correction_type'] ?? '';
        $amount   = (float)str_replace(',', '.', (string)($_POST['amount'] ?? 0));
        $reason   = trim($_POST['reason'] ?? '');

        if (!$repairId || !in_array($type, ['gutschrift', 'storno'], true)) {
            flash('error', 'Bitte eine gültige Auftrags-ID und einen Korrekturtyp angeben.');
            header('Location: ' . url('invoice_corrections.php'));
            exit;
        }

        try {
            $newId = invoice_correction_create_draft($type, $repairId, $amount, $reason, $_SESSION['user_id'] ?? null);
            flash('success', 'Korrektur wurde als Entwurf angelegt.');
            header('Location: ' . url('invoice_corrections_form.php') . '?id=' . $newId);
            exit;
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            header('Location: ' . url('invoice_corrections.php'));
            exit;
        }
    }
}

// ── Filter ───────────────────────────────────────────────────────────────
$repairFilter = (int)($_GET['repair_id'] ?? 0);
$corrections  = invoice_corrections_list($repairFilter ?: null);

$correction_type_labels = [
    'gutschrift' => 'Gutschrift',
    'storno'     => 'Stornorechnung',
];
$correction_type_badges = [
    'gutschrift' => 'badge-blue',
    'storno'     => 'badge-red',
];
$correction_status_labels = [
    'entwurf'     => 'Entwurf',
    'freigegeben' => 'Freigegeben',
];
$correction_status_badges = [
    'entwurf'     => 'badge-gray',
    'freigegeben' => 'badge-green',
];

$page_title = 'Gutschriften & Stornorechnungen';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($repairFilter): ?>
<div class="page-nav">
    <a href="invoice_corrections.php" class="btn btn-outline btn-sm"><?= svg_icon('arrow-left', 16) ?> Alle Korrekturen anzeigen</a>
</div>
<?php endif; ?>

<!-- ═══ Neue Korrektur per Auftrags-ID anlegen ══════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('plus', 18) ?> Neue Korrektur anlegen</h2></div>
    <div class="card-body">
        <p class="form-hint" style="margin-top:0;">
            Eine Gutschrift oder Stornorechnung korrigiert immer eine bereits freigegebene Rechnung zu einem
            konkreten Reparaturauftrag. Bequemer geht die Anlage über die Reparaturansicht
            ("Rechnung &amp; Kostenvoranschlag" &rarr; "Gutschrift/Storno erstellen"); alternativ direkt hier per Auftrags-ID.
        </p>
        <form method="post" action="invoice_corrections.php" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group" style="margin:0;width:140px;">
                <label style="font-size:.8rem;">Auftrag-ID</label>
                <input type="number" name="repair_id" min="1" required placeholder="z. B. 123" value="<?= $repairFilter ? h((string)$repairFilter) : '' ?>">
            </div>
            <div class="form-group" style="margin:0;width:170px;">
                <label style="font-size:.8rem;">Typ</label>
                <select name="correction_type">
                    <option value="gutschrift">Gutschrift</option>
                    <option value="storno">Stornorechnung</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;width:140px;">
                <label style="font-size:.8rem;">Betrag (€)</label>
                <input type="number" name="amount" value="0" min="0" step="0.01">
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:220px;">
                <label style="font-size:.8rem;">Grund</label>
                <input type="text" name="reason" placeholder="z. B. Reklamation, Rechnungskorrektur">
            </div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('plus', 16) ?> Anlegen</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('euro', 20) ?> Gutschriften &amp; Stornorechnungen <span class="badge badge-gray"><?= count($corrections) ?></span></h2>
    </div>
    <div class="card-body">
        <?php if (empty($corrections)): ?>
            <div class="empty-state">
                <?= svg_icon('euro', 48) ?>
                <p>Noch keine Gutschriften oder Stornorechnungen angelegt.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nummer</th>
                        <th>Typ</th>
                        <th>Ursprüngliche Rechnung</th>
                        <th class="text-right">Betrag</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                        <th class="col-actions">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($corrections as $c):
                    $typeBadge   = $correction_type_badges[$c['correction_type']] ?? 'badge-gray';
                    $typeLabel   = $correction_type_labels[$c['correction_type']] ?? $c['correction_type'];
                    $statusBadge = $correction_status_badges[$c['status']] ?? 'badge-gray';
                    $statusLabel = $correction_status_labels[$c['status']] ?? $c['status'];
                ?>
                    <tr>
                        <td class="font-mono"><?= h($c['correction_number'] ?: ('Entwurf #' . $c['id'])) ?></td>
                        <td><span class="badge <?= $typeBadge ?>"><?= h($typeLabel) ?></span></td>
                        <td>
                            <?php if (!empty($c['original_invoice_number'])): ?>
                                <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$c['repair_id'] ?>"><?= h($c['original_invoice_number']) ?></a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?= h(fmt_money((float)$c['amount'])) ?></td>
                        <td><span class="badge <?= $statusBadge ?>"><?= h($statusLabel) ?></span></td>
                        <td><?= h(fmt_date($c['created_at'])) ?></td>
                        <td class="col-actions">
                            <a href="invoice_corrections_form.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye', 15) ?></a>
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
