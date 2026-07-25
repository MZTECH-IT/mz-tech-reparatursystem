<?php
/**
 * MZ Tech – Gutschrift/Storno anlegen/ansehen/bearbeiten (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Zwei Einstiege:
 *   - `?repair_id=X` (kein `id`): kommt typischerweise vom Link
 *     "Gutschrift/Storno erstellen" auf repairs_view.php – zeigt ein
 *     kompaktes Anlage-Formular (Typ, Betrag, Grund) für genau diesen
 *     Auftrag. invoice_correction_create_draft() wirft eine
 *     RuntimeException, falls zu diesem Auftrag noch keine freigegebene
 *     Rechnung vorliegt; das wird hier abgefangen (statt die Seite mit
 *     einem Fatal Error abzubrechen) und als Flash-Fehler zurück auf
 *     repairs_view.php gemeldet.
 *   - `?id=X`: zeigt eine bestehende Korrektur an. Solange status =
 *     "entwurf" sind Betrag/Grund bearbeitbar (einfaches, vorbereitetes
 *     UPDATE – es gibt bewusst keine dedizierte
 *     invoice_correction_update()-Hilfsfunktion in
 *     private/invoice_corrections.php) und die Korrektur kann freigegeben
 *     werden (invoice_correction_release() – vergibt die GS/STO-Nummer
 *     EINMALIG und danach unveränderlich). Nach der Freigabe ist die
 *     Ansicht rein lesend.
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/permissions.php';
require_once PRIVATE_PATH . '/invoice_corrections.php';
require_permission('create_credit_notes');

$id       = (int)($_GET['id'] ?? 0);
$repairId = (int)($_GET['repair_id'] ?? 0);

$correction = $id ? invoice_correction_find($id) : null;
if ($id && !$correction) {
    flash('error', 'Korrekturbeleg nicht gefunden.');
    header('Location: ' . url('invoice_corrections.php'));
    exit;
}

// Ohne `id` und ohne `repair_id` fehlt jeder Bezugspunkt – eine Korrektur
// kann nicht "frei schwebend" angelegt werden.
if (!$id && !$repairId) {
    flash('error', 'Bitte eine Korrektur über die Reparaturansicht oder die Übersicht anlegen.');
    header('Location: ' . url('invoice_corrections.php'));
    exit;
}

$isNew = !$id;

// ── POST-Aktionen ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($isNew && $action === 'create') {
        $type   = $_POST['correction_type'] ?? '';
        $amount = (float)str_replace(',', '.', (string)($_POST['amount'] ?? 0));
        $reason = trim($_POST['reason'] ?? '');

        if (!in_array($type, ['gutschrift', 'storno'], true)) {
            flash('error', 'Bitte einen gültigen Korrekturtyp wählen.');
            header('Location: ' . url('invoice_corrections_form.php') . '?repair_id=' . $repairId);
            exit;
        }

        try {
            $newId = invoice_correction_create_draft($type, $repairId, $amount, $reason, $_SESSION['user_id'] ?? null);
            flash('success', 'Korrektur wurde als Entwurf angelegt.');
            header('Location: ' . url('invoice_corrections_form.php') . '?id=' . $newId);
            exit;
        } catch (RuntimeException $e) {
            // Kein Fatal Error: z. B. weil die Rechnung zu diesem Auftrag
            // noch nicht freigegeben ist – zurück zur Reparaturansicht,
            // dort ist der aktuelle Rechnungsstatus sichtbar.
            flash('error', $e->getMessage());
            header('Location: ' . url('repairs_view.php') . '?id=' . $repairId);
            exit;
        }

    } elseif ($id && $correction['status'] === 'entwurf' && $action === 'update') {
        $amount = (float)str_replace(',', '.', (string)($_POST['amount'] ?? 0));
        $reason = trim($_POST['reason'] ?? '');
        get_db()->prepare('UPDATE invoice_corrections SET amount = ?, reason = ? WHERE id = ?')
                 ->execute([$amount, $reason !== '' ? $reason : null, $id]);
        flash('success', 'Änderungen wurden gespeichert.');

    } elseif ($id && $action === 'release') {
        $result = invoice_correction_release($id, $_SESSION['user_id'] ?? null);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    }

    header('Location: ' . url('invoice_corrections_form.php') . ($id ? '?id=' . $id : '?repair_id=' . $repairId));
    exit;
}

// ── Daten für die Ansicht ───────────────────────────────────────────────
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

if (!$isNew) {
    // Reparatur-/Auftragskontext für die Anzeige (Reparaturnummer, Auftrags-ID) laden.
    $repairId = (int)$correction['repair_id'];
}

$page_title = $isNew
    ? 'Neue Korrektur zu Auftrag #' . $repairId
    : ($correction['correction_number'] ?? ('Entwurf #' . $id));
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-nav">
    <a href="invoice_corrections.php" class="btn btn-outline btn-sm"><?= svg_icon('arrow-left', 16) ?> Zurück zu Gutschriften &amp; Stornorechnungen</a>
    <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$repairId ?>" class="btn btn-outline btn-sm"><?= svg_icon('wrench', 16) ?> Zum Reparaturauftrag #<?= (int)$repairId ?></a>
</div>

<?php show_flash(); ?>

<?php if ($isNew): ?>
<!-- ═══ NEUE KORREKTUR ANLEGEN ══════════════════════════════════════════ -->
<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('euro', 20) ?> Neue Korrektur zu Auftrag #<?= (int)$repairId ?></h2></div>
    <div class="card-body">
        <p class="form-hint" style="margin-top:0;">
            Korrekturen sind nur zu Aufträgen mit bereits freigegebener (finalisierter) Rechnung möglich.
            Befindet sich die Rechnung noch im Entwurf, bearbeiten Sie stattdessen direkt die Rechnung selbst.
        </p>
        <form method="post" action="invoice_corrections_form.php?repair_id=<?= (int)$repairId ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="form-group">
                    <label for="correction_type">Typ</label>
                    <select id="correction_type" name="correction_type">
                        <option value="gutschrift">Gutschrift</option>
                        <option value="storno">Stornorechnung</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="amount">Betrag (€)</label>
                    <input type="number" id="amount" name="amount" value="0" min="0" step="0.01">
                </div>
                <div class="form-group full">
                    <label for="reason">Grund</label>
                    <textarea id="reason" name="reason" rows="3" placeholder="z. B. Reklamation, fehlerhafte Rechnungsposition"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= svg_icon('save', 18) ?> Als Entwurf anlegen</button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ═══ BESTEHENDE KORREKTUR ════════════════════════════════════════════ -->
<?php
$typeBadge   = $correction_type_badges[$correction['correction_type']] ?? 'badge-gray';
$typeLabel   = $correction_type_labels[$correction['correction_type']] ?? $correction['correction_type'];
$statusBadge = $correction_status_badges[$correction['status']] ?? 'badge-gray';
$statusLabel = $correction_status_labels[$correction['status']] ?? $correction['status'];
$isDraft     = $correction['status'] === 'entwurf';
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('euro', 20) ?>
            <?= h($correction['correction_number'] ?? ('Entwurf #' . $id)) ?>
        </h2>
        <span class="badge <?= $typeBadge ?>"><?= h($typeLabel) ?></span>
        <span class="badge <?= $statusBadge ?>"><?= h($statusLabel) ?></span>
    </div>
    <div class="card-body">

        <p>
            <a href="<?= url('pdf/gutschrift.php') ?>?id=<?= (int)$id ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= svg_icon('pdf', 15) ?> PDF ansehen
            </a>
        </p>

        <p>
            <strong>Ursprüngliche Rechnung:</strong>
            <?php if (!empty($correction['original_invoice_number'])): ?>
                <?= h($correction['original_invoice_number']) ?>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
            · <strong>Reparaturauftrag:</strong>
            <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$correction['repair_id'] ?>"><?= h($correction['repair_number'] ?? ('#' . $correction['repair_id'])) ?></a>
            · <strong>Erstellt:</strong> <?= h(fmt_date($correction['created_at'])) ?>
        </p>

        <?php if ($isDraft): ?>
        <!-- ═══ BEARBEITBAR (solange Entwurf) ══════════════════════════════ -->
        <fieldset class="form-section">
            <legend><?= svg_icon('edit', 16) ?> Betrag &amp; Grund</legend>
            <form method="post" action="invoice_corrections_form.php?id=<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="amount">Betrag (€)</label>
                        <input type="number" id="amount" name="amount" value="<?= h((string)$correction['amount']) ?>" min="0" step="0.01">
                    </div>
                    <div class="form-group full">
                        <label for="reason">Grund</label>
                        <textarea id="reason" name="reason" rows="3"><?= h($correction['reason'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-outline"><?= svg_icon('save', 16) ?> Änderungen speichern</button>
                </div>
            </form>
        </fieldset>

        <div class="form-actions">
            <form method="post" action="invoice_corrections_form.php?id=<?= $id ?>" class="inline-form"
                  onsubmit="return confirm('<?= $correction['correction_type'] === 'storno' ? 'Stornorechnung' : 'Gutschrift' ?> jetzt freigeben? Danach wird die Belegnummer verbindlich vergeben und das Dokument kann nicht mehr geändert werden.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="release">
                <button type="submit" class="btn btn-primary"><?= svg_icon('check', 16) ?> Freigeben</button>
            </form>
        </div>

        <?php else: ?>
        <!-- ═══ ANSICHT (freigegeben – unveränderlich) ══════════════════════ -->
        <div style="max-width:420px;">
            <p style="display:flex;justify-content:space-between;"><span>Belegnummer:</span> <strong class="font-mono"><?= h($correction['correction_number']) ?></strong></p>
            <p style="display:flex;justify-content:space-between;"><span>Betrag:</span> <strong><?= h(fmt_money((float)$correction['amount'])) ?></strong></p>
            <p style="display:flex;justify-content:space-between;"><span>Freigegeben am:</span> <strong><?= h(fmt_date($correction['released_at'], true)) ?></strong></p>
        </div>
        <?php if (!empty($correction['reason'])): ?>
            <p><strong>Grund:</strong></p>
            <p class="text-muted"><?= nl2br(h($correction['reason'])) ?></p>
        <?php endif; ?>
        <p class="text-muted">Diese Korrektur wurde freigegeben und kann nicht mehr geändert werden.</p>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
