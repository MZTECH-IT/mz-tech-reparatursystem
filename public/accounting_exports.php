<?php
/**
 * MZ Tech – Buchhaltung: DATEV-Export (Phase 7, Abschnitt 8)
 * ----------------------------------------------------------------------
 * Erzeugt eine DATEV-EXTF-CSV (Buchungsstapel) für einen wählbaren
 * Zeitraum, siehe accounting_datev_export() in private/accounting.php.
 * KEINE Live-API – reine Datei zur manuellen Übergabe an den
 * Steuerberater/dessen DATEV-Software.
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/accounting.php';
require_permission('manage_accounting');

$from = trim($_POST['from'] ?? $_GET['from'] ?? date('Y-m-01'));
$to   = trim($_POST['to'] ?? $_GET['to'] ?? date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
    verify_csrf();
    $export = accounting_datev_export($from, $to);
    header('Content-Type: text/csv; charset=windows-1252');
    header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
    header('Content-Length: ' . strlen($export['csv']));
    echo $export['csv'];
    exit;
}

$preview = null;
if (($_GET['preview'] ?? $_POST['preview'] ?? null) !== null) {
    $preview = accounting_datev_export($from, $to);
}

$page_title = 'Buchhaltung – Exporte';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('download', 20) ?> DATEV-Export (Buchungsstapel)</h2></div>
    <div class="card-body">
        <form method="post" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <div class="form-group"><label>Von</label><input type="date" name="from" value="<?= h($from) ?>"></div>
            <div class="form-group"><label>Bis</label><input type="date" name="to" value="<?= h($to) ?>"></div>
            <button type="submit" name="preview" value="1" class="btn btn-outline"><?= svg_icon('eye', 16) ?> Vorschau</button>
            <button type="submit" name="action" value="download" class="btn btn-primary"><?= svg_icon('download', 16) ?> CSV herunterladen</button>
        </form>
        <p class="text-muted" style="margin-top:.5rem;">Kontonummern und Berater-/Mandanten-Nummer werden aus den <a href="accounting_settings.php">Buchhaltungseinstellungen</a> übernommen.</p>
    </div>
</div>

<?php if ($preview !== null): ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Vorschau – <?= (int)$preview['booking_count'] ?> Buchungen</h2></div>
    <div class="card-body">
        <?php if ($preview['booking_count'] === 0): ?>
            <p class="text-muted">Keine Buchungen im gewählten Zeitraum.</p>
        <?php else: ?>
            <pre style="max-height:400px;overflow:auto;background:#f7f7f7;padding:1rem;border-radius:6px;font-size:.8rem;"><?= h($preview['csv']) ?></pre>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
