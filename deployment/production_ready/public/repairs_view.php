<?php
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/invoicing.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    flash('error', 'Keine Reparatur-ID angegeben.');
    header('Location: repairs.php');
    exit;
}

// ── Reparatur laden ──────────────────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT r.*,
            c.first_name, c.last_name, c.phone, c.phone2, c.email,
            c.address, c.city, c.zip,
            u.full_name AS technician_name
     FROM repairs r
     LEFT JOIN customers c ON c.id = r.customer_id
     LEFT JOIN users     u ON u.id = r.technician_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$repair = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$repair) {
    flash('error', 'Reparatur nicht gefunden.');
    header('Location: repairs.php');
    exit;
}

// ── Status-History ───────────────────────────────────────────────────────────
$hist_stmt = $db->prepare(
    'SELECT h.*, u.full_name AS user_name
     FROM repair_status_history h
     LEFT JOIN users u ON u.id = h.user_id
     WHERE h.repair_id = ?
     ORDER BY h.created_at ASC'
);
$hist_stmt->execute([$id]);
$status_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fotos ────────────────────────────────────────────────────────────────────
$photos_stmt = $db->prepare(
    'SELECT * FROM repair_photos WHERE repair_id = ? ORDER BY created_at ASC'
);
$photos_stmt->execute([$id]);
$photos = $photos_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Eingesetzte Teile ────────────────────────────────────────────────────────
$parts_stmt = $db->prepare(
    'SELECT rp.*, p.name AS part_name, p.sku AS part_number
     FROM repair_parts rp
     LEFT JOIN parts p ON p.id = rp.part_id
     WHERE rp.repair_id = ?
     ORDER BY rp.id ASC'
);
$parts_stmt->execute([$id]);
$repair_parts = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);
$repair_procurement = user_has_permission('manage_purchase_orders')
    ? procurement_suggestion_for_repair($id)
    : [];

$valid_statuses = repair_valid_statuses();

// ── Statusänderung per POST (non-AJAX Fallback) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    verify_csrf();
    $new_status = repair_status_normalize(trim($_POST['new_status'] ?? ''));
    $old_status = repair_status_normalize($repair['status'] ?? '');
    if (in_array($new_status, $valid_statuses, true)) {
        $now = date('Y-m-d H:i:s');
        $completed_at = $repair['completed_at'];
        $picked_up_at = $repair['picked_up_at'];

        if ($new_status === 'fertig' && $old_status !== 'fertig') {
            $completed_at = $now;
        } elseif ($new_status !== 'fertig') {
            $completed_at = null;
        }
        if ($new_status === 'abgeholt' && $old_status !== 'abgeholt') {
            $picked_up_at = $now;
        } elseif ($new_status !== 'abgeholt') {
            $picked_up_at = null;
        }

        $db->prepare('UPDATE repairs SET status = ?, completed_at = ?, picked_up_at = ?, updated_at = ? WHERE id = ?')
           ->execute([$new_status, $completed_at, $picked_up_at, $now, $id]);

        $db->prepare('INSERT INTO repair_status_history (repair_id, status, user_id, created_at) VALUES (?,?,?,?)')
           ->execute([$id, $new_status, $_SESSION['user_id'] ?? null, $now]);

        log_activity('status_change', 'repairs', $id, $new_status);
        flash('success', 'Status wurde geändert auf: ' . repair_status_label($new_status));
    }
    header('Location: repairs_view.php?id=' . $id);
    exit;
}

// ── Rechnung freigeben (Dokumentenmodul, Abschnitt 2) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_invoice'])) {
    verify_csrf();
    require_permission('release_invoices');
    $result = invoice_release($id, $_SESSION['user_id'] ?? null);
    flash($result['success'] ? 'success' : 'error', $result['message']);
    header('Location: repairs_view.php?id=' . $id);
    exit;
}

// ── Kostenvoranschlag freigeben ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_quote'])) {
    verify_csrf();
    require_permission('manage_repairs');
    $result = quote_of_repair_release($id, $_SESSION['user_id'] ?? null);
    flash($result['success'] ? 'success' : 'error', $result['message']);
    header('Location: repairs_view.php?id=' . $id);
    exit;
}

// WhatsApp-Nachricht
$wa_msg = 'Hallo ' . ($repair['first_name'] ?? '') . ', Ihre Reparatur (' . ($repair['repair_number'] ?? '') . ') steht zum Abholen bereit. Wir freuen uns auf Ihren Besuch!';
$wa_link = ($repair['phone'] ?? '') ? whatsapp_link($repair['phone'], $wa_msg) : '';

$page_title = 'Reparatur ' . h($repair['repair_number'] ?? '#' . $id);
require_once __DIR__ . '/includes/header.php';
?>

<?php show_flash(); ?>

<!-- ── Kopfzeile ──────────────────────────────────────────────────────────── -->
<div class="page-header-bar">
    <div class="page-header-info">
        <a href="repairs.php" class="btn btn-outline btn-sm">
            <?= svg_icon('arrow-left', 16) ?> Zurück
        </a>
        <h1 class="page-title">
            <?= svg_icon('tool', 22) ?>
            <?= h($repair['repair_number'] ?? '#' . $id) ?>
        </h1>
        <?= repair_status_badge($repair['status']) ?>
    </div>
    <div class="page-header-actions">
        <a href="repairs_form.php?id=<?= $id ?>" class="btn btn-outline btn-sm">
            <?= svg_icon('edit', 15) ?> Bearbeiten
        </a>
        <a href="pdf/auftrag.php?id=<?= $id ?>" class="btn btn-outline btn-sm" target="_blank">
            <?= svg_icon('pdf', 15) ?> Auftragsschein
        </a>
        <a href="pdf/kostenvoranschlag.php?id=<?= $id ?>" class="btn btn-outline btn-sm" target="_blank">
            <?= svg_icon('clipboard', 15) ?> Kostenvoranschlag
        </a>
        <a href="pdf/rechnung.php?id=<?= $id ?>" class="btn btn-outline btn-sm" target="_blank">
            <?= svg_icon('pdf', 15) ?> Rechnung
        </a>
        <a href="pdf/abholschein.php?id=<?= $id ?>" class="btn btn-outline btn-sm" target="_blank">
            <?= svg_icon('package', 15) ?> Abholschein
        </a>
        <a href="pdf/reparaturbericht.php?id=<?= $id ?>" class="btn btn-outline btn-sm" target="_blank">
            <?= svg_icon('pdf', 15) ?> Reparaturbericht
        </a>
        <?php if (!empty($repair['email'])): ?>
            <a href="mailto:<?= h($repair['email']) ?>?subject=<?= rawurlencode('Reparatur ' . ($repair['repair_number'] ?? '')) ?>" class="btn btn-outline btn-sm">
                <?= svg_icon('mail', 15) ?> E-Mail
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Rechnung & Kostenvoranschlag: Status, Entwurfshinweis, Freigabe ──────── -->
<?php
$invoice_released = invoice_is_released($repair);
$invoice_check    = $invoice_released ? null : invoice_validate_for_release($id);
$quote_status     = $repair['quote_status'] ?? 'entwurf';
$corr_status      = $repair['invoice_correction_status'] ?? null;
?>
<div class="card" style="margin-bottom:20px;<?= $invoice_released ? '' : 'border-left:4px solid #e0a800;' ?>">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('pdf', 18) ?> Rechnung &amp; Kostenvoranschlag</h3>
    </div>
    <div style="padding:4px 4px 12px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <div style="min-width:220px;">
            <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:2px;">Kostenvoranschlag</div>
            <?php if (!empty($repair['quote_number'])): ?>
                <strong><?= h($repair['quote_number']) ?></strong>
                <span class="badge-outline" style="margin-left:6px;"><?= h(ucfirst($quote_status)) ?></span>
            <?php else: ?>
                <span class="badge-red">ENTWURF</span>
                <span style="color:var(--text-muted);font-size:.82rem;"> – interne Referenz #<?= $id ?>, noch keine KV-Nummer vergeben</span>
            <?php endif; ?>
        </div>
        <div style="min-width:220px;">
            <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:2px;">Rechnung</div>
            <?php if ($invoice_released): ?>
                <strong><?= h($repair['invoice_number']) ?></strong>
                <span class="badge-green" style="margin-left:6px;">FREIGEGEBEN</span>
                <?php if ($corr_status): ?>
                    <span class="badge-outline" style="margin-left:6px;"><?= h($corr_status === 'storniert' ? 'Storniert' : 'Gutschrift erstellt') ?></span>
                <?php endif; ?>
                <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px;">
                    freigegeben am <?= !empty($repair['invoice_released_at']) ? date('d.m.Y H:i', strtotime($repair['invoice_released_at'])) : '–' ?> Uhr
                </div>
            <?php else: ?>
                <span class="badge-red">ENTWURF</span>
                <span style="color:var(--text-muted);font-size:.82rem;"> – Vorläufige Vorschau, keine gültige Rechnung, interne Referenz #<?= $id ?></span>
            <?php endif; ?>
        </div>

        <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
            <?php if (empty($repair['quote_number']) || $quote_status === 'entwurf'): ?>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="release_quote" value="1">
                    <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Kostenvoranschlag jetzt verbindlich freigeben und Nummer vergeben?');">
                        <?= svg_icon('check', 15) ?> Kostenvoranschlag freigeben
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!$invoice_released && user_has_permission('release_invoices')): ?>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="release_invoice" value="1">
                    <button type="submit" class="btn btn-primary btn-sm"
                            <?= !empty($invoice_check['errors']) ? 'disabled title="Freigabe erst nach Behebung der unten genannten Punkte möglich"' : '' ?>
                            onclick="return confirm('Rechnung jetzt verbindlich freigeben? Die Rechnungsnummer wird endgültig vergeben und kann danach nicht mehr geändert werden.');">
                        <?= svg_icon('check', 15) ?> Rechnung freigeben
                    </button>
                </form>
            <?php elseif ($invoice_released): ?>
                <?php if (user_has_permission('create_credit_notes')): ?>
                    <a href="invoice_corrections_form.php?repair_id=<?= $id ?>" class="btn btn-outline btn-sm">
                        <?= svg_icon('edit', 15) ?> Gutschrift/Storno erstellen
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$invoice_released && $invoice_check && (!empty($invoice_check['errors']) || !empty($invoice_check['warnings']))): ?>
        <div style="padding:0 4px 10px;">
            <?php foreach ($invoice_check['errors'] as $err): ?>
                <div style="font-size:.82rem;color:#b42318;">✕ <?= h($err) ?></div>
            <?php endforeach; ?>
            <?php foreach ($invoice_check['warnings'] as $warn): ?>
                <div style="font-size:.82rem;color:#93670a;">⚠ <?= h($warn) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ── Infopanels ─────────────────────────────────────────────────────────── -->
<div class="detail-grid">

    <!-- Kundeninfos -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= svg_icon('user', 18) ?> Kundeninfo</h3>
            <a href="customers_form.php?id=<?= (int)$repair['customer_id'] ?>" class="btn btn-sm btn-outline">
                <?= svg_icon('edit', 13) ?> Kunde bearbeiten
            </a>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Name</dt>
                <dd>
                    <a href="repairs.php?customer_id=<?= (int)$repair['customer_id'] ?>">
                        <?= h(trim($repair['first_name'] . ' ' . $repair['last_name'])) ?>
                    </a>
                </dd>

                <?php if ($repair['phone']): ?>
                    <dt>Telefon</dt>
                    <dd>
                        <a href="tel:<?= h($repair['phone']) ?>"><?= h($repair['phone']) ?></a>
                        <?php if ($wa_link): ?>
                            <a href="<?= h($wa_link) ?>" target="_blank" class="btn btn-xs btn-green" title="WhatsApp" style="margin-left:.5rem;">
                                <?= svg_icon('message-circle', 14) ?> WhatsApp
                            </a>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($repair['phone2']): ?>
                    <dt>Telefon 2</dt>
                    <dd><a href="tel:<?= h($repair['phone2']) ?>"><?= h($repair['phone2']) ?></a></dd>
                <?php endif; ?>

                <?php if ($repair['email']): ?>
                    <dt>E-Mail</dt>
                    <dd><a href="mailto:<?= h($repair['email']) ?>"><?= h($repair['email']) ?></a></dd>
                <?php endif; ?>

                <?php if ($repair['address'] || $repair['city']): ?>
                    <dt>Adresse</dt>
                    <dd>
                        <?= h($repair['address'] ?? '') ?>
                        <?php if ($repair['zip'] || $repair['city']): ?>
                            <br><?= h(trim($repair['zip'] . ' ' . $repair['city'])) ?>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- Geräteinfos -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= svg_icon('smartphone', 18) ?> Geräteinfo</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Geräteart</dt>
                <dd><?= h($repair['device_type'] ?? '—') ?></dd>

                <?php if ($repair['manufacturer']): ?>
                    <dt>Hersteller</dt>
                    <dd><?= h($repair['manufacturer']) ?></dd>
                <?php endif; ?>

                <?php if ($repair['model']): ?>
                    <dt>Modell</dt>
                    <dd><?= h($repair['model']) ?></dd>
                <?php endif; ?>

                <?php if ($repair['color']): ?>
                    <dt>Farbe</dt>
                    <dd><?= h($repair['color']) ?></dd>
                <?php endif; ?>

                <?php if ($repair['imei']): ?>
                    <dt>IMEI</dt>
                    <dd><code><?= h($repair['imei']) ?></code></dd>
                <?php endif; ?>

                <?php if ($repair['serial_number']): ?>
                    <dt>Seriennummer</dt>
                    <dd><code><?= h($repair['serial_number']) ?></code></dd>
                <?php endif; ?>
            </dl>

            <!-- Gerätecode (nur Admin) -->
            <div class="passcode-section" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border-color,#e5e7eb);">
                <strong><?= svg_icon('lock', 15) ?> Gerätecode:</strong>
                <?php if (!empty($repair['passcode_encrypted'])): ?>
                    <?php if (is_admin()): ?>
                        <span id="passcode_display" style="margin-left:.5rem;font-family:monospace;">••••••</span>
                        <button type="button" id="btn_show_passcode" class="btn btn-xs btn-outline" style="margin-left:.5rem;">
                            <?= svg_icon('eye', 14) ?> Code anzeigen
                        </button>
                    <?php else: ?>
                        <span class="text-muted" style="margin-left:.5rem;">Nur mit Admin-Rechten sichtbar</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-muted" style="margin-left:.5rem;">—</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reparaturinfos -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= svg_icon('clipboard', 18) ?> Reparaturdetails</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <?php if ($repair['problem_type']): ?>
                    <dt>Fehlertyp</dt>
                    <dd><?= h($repair['problem_type']) ?></dd>
                <?php endif; ?>

                <dt>Fehlerbeschreibung</dt>
                <dd style="white-space:pre-wrap;"><?= h($repair['problem_description'] ?? '—') ?></dd>

                <?php if ($repair['internal_notes']): ?>
                    <dt>Interne Notizen</dt>
                    <dd style="white-space:pre-wrap;"><?= h($repair['internal_notes']) ?></dd>
                <?php endif; ?>

                <dt>Preis</dt>
                <dd><?= $repair['price'] !== null ? h(fmt_money((float)$repair['price'])) : '<span class="text-muted">—</span>' ?></dd>

                <?php if ($repair['advance_payment'] !== null): ?>
                    <dt>Anzahlung</dt>
                    <dd><?= h(fmt_money((float)$repair['advance_payment'])) ?></dd>
                <?php endif; ?>

                <dt>Garantie</dt>
                <dd><?= $repair['warranty_months'] > 0 ? (int)$repair['warranty_months'] . ' Monate' : 'Keine' ?></dd>

                <?php if ($repair['technician_name']): ?>
                    <dt>Techniker</dt>
                    <dd><?= h($repair['technician_name']) ?></dd>
                <?php endif; ?>

                <?php if ($repair['estimated_ready']): ?>
                    <dt>Voraussichtlich fertig</dt>
                    <dd><?= h(fmt_date($repair['estimated_ready'])) ?></dd>
                <?php endif; ?>

                <?php if ($repair['completed_at']): ?>
                    <dt>Repariert am</dt>
                    <dd><?= h(fmt_date($repair['completed_at'], true)) ?></dd>
                <?php endif; ?>

                <?php if ($repair['picked_up_at']): ?>
                    <dt>Abgeholt am</dt>
                    <dd><?= h(fmt_date($repair['picked_up_at'], true)) ?></dd>
                <?php endif; ?>

                <dt>Angelegt am</dt>
                <dd><?= h(fmt_date($repair['created_at'], true)) ?></dd>
            </dl>
        </div>
    </div>

</div><!-- /.detail-grid -->

<!-- ── Status ändern ─────────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('refresh-cw', 18) ?> Status ändern</h3>
        <div style="display:flex;gap:.5rem;align-items:center;">
            <span class="text-muted" style="font-size:.875rem;">Aktuell: <?= repair_status_badge($repair['status']) ?></span>
        </div>
    </div>
    <div class="card-body">
        <!-- AJAX-Status-Änderung -->
        <form id="status_change_form" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="repair_id" value="<?= $id ?>">
            <select id="status_select" name="new_status" style="min-width:200px;">
                <?php foreach ($valid_statuses as $s): ?>
                    <option value="<?= $s ?>" <?= repair_status_normalize($repair['status']) === $s ? 'selected' : '' ?>>
                        <?= h(repair_status_label($s)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm" id="btn_status_change">
                <?= svg_icon('check', 16) ?> Status setzen
            </button>
            <span id="status_change_msg" style="font-size:.875rem;"></span>
        </form>

        <!-- Fallback ohne JS -->
        <noscript>
            <form method="post" action="repairs_view.php?id=<?= $id ?>" style="margin-top:.75rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="change_status" value="1">
                <select name="new_status">
                    <?php foreach ($valid_statuses as $s): ?>
                        <option value="<?= $s ?>" <?= repair_status_normalize($repair['status']) === $s ? 'selected' : '' ?>>
                            <?= h(repair_status_label($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Status setzen</button>
            </form>
        </noscript>
    </div>
</div>

<!-- ── Status-Timeline ───────────────────────────────────────────────────── -->
<?php if (!empty($status_history)): ?>
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('clock', 18) ?> Statusverlauf</h3>
    </div>
    <div class="card-body">
        <ul class="timeline">
            <?php foreach ($status_history as $i => $h_entry): ?>
                <li class="timeline-item <?= $i === count($status_history) - 1 ? 'timeline-item--current' : '' ?>">
                    <div class="timeline-dot"></div>
                    <div class="timeline-content">
                        <?= repair_status_badge($h_entry['status']) ?>
                        <span class="text-muted" style="font-size:.8rem;margin-left:.5rem;">
                            <?= h(fmt_date($h_entry['created_at'], true)) ?>
                            <?php if ($h_entry['user_name']): ?>
                                &middot; <?= h($h_entry['user_name']) ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($h_entry['note'] ?? ''): ?>
                            <div class="timeline-note"><?= h($h_entry['note']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- ── Verwendete Teile ───────────────────────────────────────────────────── -->
<?php if (!empty($repair_parts)): ?>
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('package', 18) ?> Verwendete Ersatzteile</h3>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Teil</th>
                        <th>Artikelnr.</th>
                        <th class="text-right">Menge</th>
                        <th class="text-right">Einzelpreis</th>
                        <th class="text-right">Gesamt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $parts_total = 0;
                    foreach ($repair_parts as $rp):
                        $line_total = (float)($rp['selling_price_at_time'] ?? 0) * (int)($rp['quantity'] ?? 1);
                        $parts_total += $line_total;
                    ?>
                        <tr>
                            <td><?= h($rp['part_name'] ?? '—') ?></td>
                            <td><code><?= h($rp['part_number'] ?? '—') ?></code></td>
                            <td class="text-right"><?= (int)($rp['quantity'] ?? 1) ?></td>
                            <td class="text-right"><?= h(fmt_money((float)($rp['selling_price_at_time'] ?? 0))) ?></td>
                            <td class="text-right"><?= h(fmt_money($line_total)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-right"><strong>Teile-Summe</strong></td>
                        <td class="text-right"><strong><?= h(fmt_money($parts_total)) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Fotos ─────────────────────────────────────────────────────────────── -->
<?php
$missing_procurement = array_values(array_filter(
    $repair_procurement,
    static fn(array $item): bool => (int)$item['missing'] > 0
));
if ($missing_procurement):
?>
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('inbox', 18) ?> Beschaffungsbedarf dieser Reparatur</h3>
        <a href="<?= url('procurement_suggestions.php') ?>" class="btn btn-sm btn-outline">Alle Vorschläge</a>
    </div>
    <div class="card-body">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Artikel</th><th class="text-right">Bedarf</th><th class="text-right">Verfügbar</th><th class="text-right">Fehlend</th><th>Empfehlung</th></tr></thead>
            <tbody>
            <?php foreach ($missing_procurement as $item): ?>
                <tr>
                    <td><?= h($item['name']) ?> <span class="text-muted"><?= h($item['sku'] ?? '') ?></span></td>
                    <td class="text-right"><?= (int)$item['needed'] ?></td>
                    <td class="text-right"><?= (int)$item['available'] ?></td>
                    <td class="text-right"><strong><?= (int)$item['missing'] ?></strong></td>
                    <td><?= $item['recommended_offer'] ? h($item['recommended_offer']['offer']['supplier_name'] ?? 'Angebot vorhanden') : '<span class="text-muted">Kein aktives Angebot</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('image', 18) ?> Fotos</h3>
        <button type="button" id="btn_upload_toggle" class="btn btn-sm btn-outline">
            <?= svg_icon('upload', 15) ?> Foto hochladen
        </button>
    </div>
    <div class="card-body">

        <!-- Upload-Formular (eingeklappt) -->
        <div id="photo_upload_area" style="display:none;margin-bottom:1.25rem;padding:1rem;background:var(--bg-muted,#f9fafb);border-radius:8px;border:1px dashed var(--border-color,#ddd);">
            <form id="photo_upload_form" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="repair_id" value="<?= $id ?>">
                <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
                    <div class="form-group" style="margin:0;flex:1;min-width:150px;">
                        <label for="photo_type" style="font-size:.85rem;">Fotoart</label>
                        <select id="photo_type" name="photo_type">
                            <option value="vorher">Vorher</option>
                            <option value="nachher">Nachher</option>
                            <option value="sonstiges">Sonstiges</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;flex:2;min-width:200px;">
                        <label for="photo_file" style="font-size:.85rem;">Datei auswählen</label>
                        <input type="file" id="photo_file" name="photo" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <?= svg_icon('upload', 15) ?> Hochladen
                    </button>
                </div>
                <div id="photo_upload_msg" style="margin-top:.5rem;font-size:.875rem;"></div>
            </form>
        </div>

        <?php
        $photo_groups = ['vorher' => [], 'nachher' => [], 'sonstiges' => []];
        foreach ($photos as $photo) {
            $type = $photo['photo_type'] ?? 'sonstiges';
            $photo_groups[$type][] = $photo;
        }
        $type_labels = ['vorher' => 'Vorher', 'nachher' => 'Nachher', 'sonstiges' => 'Sonstiges'];
        $any_photos = !empty($photos);
        ?>

        <?php if (!$any_photos): ?>
            <div class="empty-state" style="padding:2rem 0;">
                <?= svg_icon('image', 36) ?>
                <p class="text-muted">Noch keine Fotos hochgeladen.</p>
            </div>
        <?php else: ?>
            <?php foreach ($photo_groups as $ptype => $plist): ?>
                <?php if (empty($plist)) continue; ?>
                <div class="photo-group" style="margin-bottom:1.25rem;">
                    <h4 style="font-size:.9rem;font-weight:600;margin-bottom:.75rem;color:var(--text-muted,#666);">
                        <?= h($type_labels[$ptype]) ?>
                        <span class="badge-secondary" style="font-weight:400;"><?= count($plist) ?></span>
                    </h4>
                    <div class="photo-grid">
                        <?php foreach ($plist as $photo): ?>
                            <div class="photo-thumb">
                                <img
                                    src="<?= url('api/repairs.php') ?>?action=get_photo&id=<?= (int)$photo['id'] ?>"
                                    alt="<?= h($photo['original_name'] ?? 'Foto') ?>"
                                    loading="lazy"
                                    onclick="openLightbox('<?= url('api/repairs.php') ?>?action=get_photo&id=<?= (int)$photo['id'] ?>')"
                                    title="<?= h($photo['original_name'] ?? '') ?>"
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── QR-Code & Zusatz ───────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('grid', 18) ?> QR-Code</h3>
    </div>
    <div class="card-body" style="text-align:center;">
        <img src="<?= url('api/qrcode.php') ?>?id=<?= $id ?>" alt="QR-Code für Reparatur <?= h($repair['repair_number'] ?? $id) ?>"
             style="max-width:200px;border:1px solid var(--border-color,#ddd);border-radius:6px;padding:.5rem;">
        <p class="text-muted" style="font-size:.8rem;margin-top:.5rem;"><?= h($repair['repair_number'] ?? '#' . $id) ?></p>
    </div>
</div>

<?php
$api_repairs_url = url('api/repairs.php');
$extra_js = <<<JS
<script>
// ── Upload-Toggle ─────────────────────────────────────────────────────────
document.getElementById('btn_upload_toggle').addEventListener('click', function() {
    const area = document.getElementById('photo_upload_area');
    area.style.display = area.style.display === 'none' ? 'block' : 'none';
});

// ── Foto-Upload per AJAX ──────────────────────────────────────────────────
document.getElementById('photo_upload_form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg     = document.getElementById('photo_upload_msg');
    const fileIn  = document.getElementById('photo_file');
    const btn     = this.querySelector('button[type=submit]');

    if (!fileIn.files.length) {
        msg.textContent = 'Bitte zuerst eine Datei auswählen.';
        msg.style.color = 'var(--danger, #dc2626)';
        return;
    }

    const formData = new FormData(this);
    formData.append('action', 'upload_photo');
    btn.disabled = true;
    msg.textContent = 'Wird hochgeladen …';
    msg.style.color = '';

    try {
        const res  = await fetch('{$api_repairs_url}', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            msg.textContent = 'Foto hochgeladen. Seite wird neu geladen …';
            msg.style.color = 'var(--success, #16a34a)';
            setTimeout(() => location.reload(), 900);
        } else {
            msg.textContent = data.message || 'Fehler beim Hochladen.';
            msg.style.color = 'var(--danger, #dc2626)';
            btn.disabled = false;
        }
    } catch(err) {
        msg.textContent = 'Netzwerkfehler: ' + err.message;
        msg.style.color = 'var(--danger, #dc2626)';
        btn.disabled = false;
    }
});

// ── Status-Änderung per AJAX ──────────────────────────────────────────────
document.getElementById('status_change_form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg    = document.getElementById('status_change_msg');
    const btn    = document.getElementById('btn_status_change');
    const select = document.getElementById('status_select');
    const formData = new FormData(this);
    formData.append('action', 'change_status');
    formData.append('status', select.value);

    btn.disabled = true;
    msg.textContent = 'Wird gespeichert …';
    msg.style.color = '';

    try {
        const res  = await fetch('{$api_repairs_url}', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            msg.textContent = 'Status erfolgreich geändert.';
            msg.style.color = 'var(--success, #16a34a)';
            // Badge im Header aktualisieren (einfaches Reload)
            setTimeout(() => location.reload(), 800);
        } else {
            msg.textContent = data.message || 'Fehler beim Speichern.';
            msg.style.color = 'var(--danger, #dc2626)';
            btn.disabled = false;
        }
    } catch(err) {
        msg.textContent = 'Netzwerkfehler: ' + err.message;
        msg.style.color = 'var(--danger, #dc2626)';
        btn.disabled = false;
    }
});

// ── Passcode anzeigen ─────────────────────────────────────────────────────
const btnPasscode = document.getElementById('btn_show_passcode');
if (btnPasscode) {
    let revealed = false;
    const display = document.getElementById('passcode_display');

    btnPasscode.addEventListener('click', async function() {
        if (revealed) {
            display.textContent = '••••••';
            btnPasscode.textContent = 'Code anzeigen';
            revealed = false;
            return;
        }

        this.disabled = true;
        this.textContent = 'Lade …';

        try {
            const csrfToken = document.querySelector('input[name=csrf_token]')?.value || '';
            const res = await fetch('{$api_repairs_url}?action=get_passcode&id=' + {$id}, {
                headers: { 'X-CSRF-Token': csrfToken }
            });
            const data = await res.json();
            if (data.success && data.passcode !== undefined) {
                display.textContent = data.passcode || '(leer)';
                this.textContent = 'Code verbergen';
                revealed = true;
            } else {
                display.textContent = 'Fehler: ' + (data.message || 'Unbekannt');
            }
        } catch(err) {
            display.textContent = 'Netzwerkfehler';
        } finally {
            this.disabled = false;
        }
    });
}
</script>
<style>
.page-header-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .75rem;
    margin-bottom: 1.25rem;
}
.page-header-info {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}
.page-header-actions {
    display: flex;
    gap: .4rem;
    flex-wrap: wrap;
}
.page-title {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.25rem;
}
.detail-list {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: .35rem .75rem;
    font-size: .9rem;
}
.detail-list dt {
    font-weight: 600;
    color: var(--text-muted, #666);
    white-space: nowrap;
}
.detail-list dd { margin: 0; }
.timeline { list-style: none; padding: 0; margin: 0; }
.timeline-item {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .45rem 0;
    position: relative;
}
.timeline-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 7px;
    top: 26px;
    bottom: -6px;
    width: 2px;
    background: var(--border-color, #e5e7eb);
}
.timeline-dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--border-color, #e5e7eb);
    flex-shrink: 0;
    margin-top: 3px;
    border: 2px solid var(--bg-card, #fff);
    box-shadow: 0 0 0 2px var(--border-color, #e5e7eb);
}
.timeline-item--current .timeline-dot {
    background: var(--primary, #2563eb);
    box-shadow: 0 0 0 2px var(--primary-200, #bfdbfe);
}
.timeline-content {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .35rem;
}
.timeline-note {
    width: 100%;
    font-size: .8rem;
    color: var(--text-muted, #888);
    margin-top: .2rem;
}
.photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: .5rem;
}
.photo-thumb img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 6px;
    cursor: zoom-in;
    border: 1px solid var(--border-color, #e5e7eb);
    transition: opacity .15s;
}
.photo-thumb img:hover { opacity: .85; }
.btn-xs {
    font-size: .75rem;
    padding: .2rem .5rem;
}
.btn-green {
    background: #dcfce7;
    color: #15803d;
    border-color: #86efac;
}
.btn-green:hover { background: #bbf7d0; }
</style>
JS;
// Hinweis: {$api_repairs_url} und {$id} wurden bereits durch das Heredoc
// (kein Nowdoc) oben direkt interpoliert, daher ist hier keine weitere
// str_replace()-Ersetzung mehr nötig.

require_once __DIR__ . '/includes/footer.php';
?>
