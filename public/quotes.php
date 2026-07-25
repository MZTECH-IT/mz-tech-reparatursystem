<?php
/**
 * MZ Tech – Angebote: Übersicht (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Eigenständige Angebote (siehe private/quotes.php) – anders als der
 * Kostenvoranschlag (KV), der weiterhin fest an repairs.quote_number
 * hängt. Gleiche Rechteklammer wie Rechnungsentwürfe (create_invoice_drafts),
 * da Angebote im Dokumentenmodul keine eigene, dedizierte Berechtigung
 * besitzen (siehe PERMISSION_DEFINITIONS in private/permissions.php).
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/permissions.php';
require_once PRIVATE_PATH . '/quotes.php';
require_permission('create_invoice_drafts');

$statusFilter   = trim($_GET['status'] ?? '');
$customerFilter = (int)($_GET['customer_id'] ?? 0);
$companyFilter  = (int)($_GET['company_id'] ?? 0);

$quotes = quotes_list(array_filter([
    'status'      => $statusFilter,
    'customer_id' => $customerFilter ?: null,
    'company_id'  => $companyFilter ?: null,
]));

$quote_status_labels = [
    'entwurf'     => 'Entwurf',
    'freigegeben' => 'Freigegeben',
    'gesendet'    => 'Gesendet',
    'angenommen'  => 'Angenommen',
    'abgelehnt'   => 'Abgelehnt',
    'abgelaufen'  => 'Abgelaufen',
    'storniert'   => 'Storniert',
];
$quote_status_badges = [
    'entwurf'     => 'badge-gray',
    'freigegeben' => 'badge-blue',
    'gesendet'    => 'badge-yellow',
    'angenommen'  => 'badge-green',
    'abgelehnt'   => 'badge-red',
    'abgelaufen'  => 'badge-orange',
    'storniert'   => 'badge-red',
];

$page_title = 'Angebote';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" action="quotes.php" style="display:flex;gap:.5rem;align-items:center;">
        <select name="status" class="search-input" style="width:auto;">
            <option value="">– Alle Status –</option>
            <?php foreach ($quote_status_labels as $k => $l): ?>
                <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Filtern</button>
    </form>
    <div class="toolbar-actions">
        <a href="quotes_form.php" class="btn btn-primary"><?= svg_icon('plus', 18) ?> Neues Angebot</a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('euro', 20) ?> Angebote <span class="badge badge-gray"><?= count($quotes) ?></span></h2></div>
    <div class="card-body">
        <?php if (empty($quotes)): ?>
            <div class="empty-state">
                <?= svg_icon('euro', 48) ?>
                <p>Noch keine Angebote angelegt.</p>
                <a href="quotes_form.php" class="btn btn-primary">Erstes Angebot anlegen</a>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nummer</th>
                        <th>Kunde/Firma</th>
                        <th>Titel</th>
                        <th>Status</th>
                        <th class="text-right">Gesamt</th>
                        <th>Erstellt</th>
                        <th class="col-actions">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($quotes as $q):
                    $customerLabel = $q['company_name'] ?: trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? ''));
                    $badge = $quote_status_badges[$q['status']] ?? 'badge-gray';
                    $label = $quote_status_labels[$q['status']] ?? $q['status'];
                ?>
                    <tr>
                        <td class="font-mono"><?= h($q['quote_number'] ?: ('Entwurf #' . $q['id'])) ?></td>
                        <td><?= $customerLabel !== '' ? h($customerLabel) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h($q['title'] ?: '—') ?></td>
                        <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
                        <td class="text-right"><?= h(fmt_money((float)$q['total'])) ?></td>
                        <td><?= h(fmt_date($q['created_at'])) ?></td>
                        <td class="col-actions">
                            <a href="quotes_form.php?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye', 15) ?></a>
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
