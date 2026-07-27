<?php
/**
 * MZ Tech – Buchhaltung: Synchronisation (Phase 7, Abschnitt 8)
 * ----------------------------------------------------------------------
 * Manuelle Übertragung offener, bereits FREIGEGEBENER Belege (Rechnungen
 * über repairs.invoice_number/invoice_status, Gutschriften/Stornos über
 * invoice_corrections, Eingangsbelege über purchase_orders) zum aktiven
 * Anbieter, sowie Wiederholung fehlgeschlagener Übertragungen. Entwürfe
 * (noch nicht freigegeben) werden hier bewusst nicht angezeigt – siehe
 * private/accounting.php für die Datengrundlage-Hinweise.
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/accounting.php';
require_permission('manage_accounting');

$provider = accounting_active_provider();
$providerStatus = $provider ? accounting_adapter_live_write_status($provider) : ['enabled' => false, 'message' => 'Kein Anbieter aktiv.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$provider) {
        flash('error', 'Kein aktiver Anbieter konfiguriert (siehe Einstellungen).');
        header('Location: ' . url('accounting_sync.php'));
        exit;
    }
    if (!$providerStatus['enabled']) {
        flash('error', $providerStatus['message']);
        header('Location: ' . url('accounting_sync.php'));
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'sync_repair') {
        $result = accounting_sync_repair_invoice((int)($_POST['repair_id'] ?? 0), $provider);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'sync_correction') {
        $result = accounting_sync_invoice_correction((int)($_POST['correction_id'] ?? 0), $provider);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'sync_po') {
        $result = accounting_sync_purchase_order_document((int)($_POST['po_id'] ?? 0), $provider);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'retry_failed') {
        $results = accounting_retry_failed_syncs($provider);
        $ok = count(array_filter($results, fn($r) => $r['result']['success']));
        flash('success', count($results) . ' Wiederholungsversuche, davon ' . $ok . ' erfolgreich.');
    }
    header('Location: ' . url('accounting_sync.php'));
    exit;
}

$openRepairs = [];
$openCorrections = [];
$openPOs = [];
if ($provider) {
    $db = get_db();
    $stmt = $db->query(
        "SELECT r.id, r.repair_number, r.price, r.invoice_number, r.invoice_released_at, c.first_name, c.last_name
           FROM repairs r JOIN customers c ON c.id = r.customer_id
          WHERE r.invoice_number IS NOT NULL AND r.invoice_status = 'freigegeben'
          ORDER BY r.invoice_released_at DESC LIMIT 100"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!accounting_document_already_synced($provider, 'invoice_repair', (int)$r['id'])) {
            $openRepairs[] = $r;
        }
    }
    $stmt = $db->query(
        "SELECT ic.id, ic.correction_type, ic.correction_number, ic.amount, ic.released_at, r.invoice_number AS original_invoice_number
           FROM invoice_corrections ic JOIN repairs r ON r.id = ic.repair_id
          WHERE ic.status = 'freigegeben'
          ORDER BY ic.released_at DESC LIMIT 100"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ic) {
        if (!accounting_document_already_synced($provider, 'invoice_correction', (int)$ic['id'])) {
            $openCorrections[] = $ic;
        }
    }
    $stmt = $db->query(
        "SELECT po.id, po.order_number, po.status, po.ordered_at, s.name AS supplier_name
           FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
          WHERE po.status IN ('bestellt','teilweise_geliefert','geliefert')
          ORDER BY po.updated_at DESC LIMIT 100"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $po) {
        if (!accounting_document_already_synced($provider, 'incoming_purchase_order', (int)$po['id'])) {
            $openPOs[] = $po;
        }
    }
}
$failedCount = $provider ? count(accounting_document_sync_list(['provider' => $provider, 'status' => 'fehler'])) : 0;

$page_title = 'Buchhaltung – Synchronisation';
require_once __DIR__ . '/includes/header.php';
?>

<?php if (!$provider): ?>
<div class="card"><div class="card-body">
    <p class="text-muted">Kein aktiver Anbieter konfiguriert. Bitte zuerst unter <a href="accounting_settings.php">Buchhaltung → Einstellungen</a> einen Anbieter aktivieren.</p>
</div></div>
<?php else: ?>

<div class="toolbar">
    <span class="badge-secondary">Aktiver Anbieter: <?= h(ACCOUNTING_PROVIDERS[$provider]) ?></span>
    <?php if ($failedCount > 0): ?>
        <form method="post" style="display:inline;">
            <?= csrf_field() ?><input type="hidden" name="action" value="retry_failed">
            <button type="submit" class="btn btn-outline"><?= svg_icon('refresh-cw', 16) ?> <?= $failedCount ?> fehlgeschlagene erneut versuchen</button>
        </form>
    <?php endif; ?>
</div>
<?php if (!$providerStatus['enabled']): ?>
<div class="alert alert-warning"><?= h($providerStatus['message']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('euro', 20) ?> Offene Rechnungen (freigegeben, noch nicht übertragen) <span class="badge-secondary"><?= count($openRepairs) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($openRepairs)): ?>
            <p class="text-muted">Keine offenen freigegebenen Rechnungen zur Übertragung.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Rechnungsnr.</th><th>Reparatur</th><th>Kunde</th><th class="text-right">Betrag</th><th>Freigegeben</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($openRepairs as $r): ?>
                    <tr>
                        <td class="font-mono"><?= h($r['invoice_number']) ?></td>
                        <td class="font-mono"><?= h($r['repair_number']) ?></td>
                        <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
                        <td class="text-right"><?= h(fmt_money((float)$r['price'])) ?></td>
                        <td><?= $r['invoice_released_at'] ? h(date('d.m.Y H:i', strtotime($r['invoice_released_at']))) : '—' ?></td>
                        <td class="col-actions">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?><input type="hidden" name="action" value="sync_repair">
                                <input type="hidden" name="repair_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Übertragen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('euro', 20) ?> Offene Gutschriften/Stornorechnungen <span class="badge-secondary"><?= count($openCorrections) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($openCorrections)): ?>
            <p class="text-muted">Keine offenen freigegebenen Korrekturbelege zur Übertragung.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Belegnr.</th><th>Typ</th><th>Zu Rechnung</th><th class="text-right">Betrag</th><th>Freigegeben</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($openCorrections as $ic): ?>
                    <tr>
                        <td class="font-mono"><?= h($ic['correction_number']) ?></td>
                        <td><?= $ic['correction_type'] === 'storno' ? 'Stornorechnung' : 'Gutschrift' ?></td>
                        <td class="font-mono"><?= h($ic['original_invoice_number']) ?></td>
                        <td class="text-right"><?= h(fmt_money((float)$ic['amount'])) ?></td>
                        <td><?= $ic['released_at'] ? h(date('d.m.Y H:i', strtotime($ic['released_at']))) : '—' ?></td>
                        <td class="col-actions">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?><input type="hidden" name="action" value="sync_correction">
                                <input type="hidden" name="correction_id" value="<?= (int)$ic['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Übertragen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('package', 20) ?> Offene Eingangsbelege (Bestellungen) <span class="badge-secondary"><?= count($openPOs) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($openPOs)): ?>
            <p class="text-muted">Keine offenen Bestellungen zur Übertragung.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Bestellnummer</th><th>Lieferant</th><th>Status</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($openPOs as $po): ?>
                    <tr>
                        <td class="font-mono"><?= h($po['order_number'] ?? ('#' . $po['id'])) ?></td>
                        <td><?= h($po['supplier_name']) ?></td>
                        <td><?= h($po['status']) ?></td>
                        <td class="col-actions">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?><input type="hidden" name="action" value="sync_po">
                                <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Übertragen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
