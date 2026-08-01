<?php
/**
 * MZ Tech – Angebot anlegen/ansehen/bearbeiten (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Anlegen/Bearbeiten eines Angebotsentwurfs: Kopfdaten (Kunde ODER Firma,
 * Titel, Notizen, Gültigkeit, Steuersatz, optionale Reparaturzuordnung)
 * und Positionen werden – analog zum bereits etablierten Bestellentwurf-
 * Muster in public/purchase_order_form.php – zunächst in der Session
 * gesammelt ($_SESSION[QUOTE_DRAFT_SESSION_KEY]) und erst über die
 * Aktion "save" tatsächlich per quote_create_draft()/quote_update() in
 * die Datenbank geschrieben. Solange sich das Angebot im Status
 * "entwurf" befindet, wird bei jedem Aufruf dieser Seite aus der DB
 * (bzw. bei Neuanlage leer) in die Session geladen, falls dort noch kein
 * zu diesem Angebot passender Entwurf vorliegt.
 *
 * Nach der Freigabe (quote_release()) sind die Positionen unveränderlich
 * (nur noch Status-Übergänge über quote_set_status()/quote_decision_record()
 * bzw. die Übernahme in einen Reparaturauftrag über quote_convert_to_repair()).
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/permissions.php';
require_once PRIVATE_PATH . '/quotes.php';
require_once PRIVATE_PATH . '/companies.php';
require_permission('create_invoice_drafts');

const QUOTE_DRAFT_SESSION_KEY = 'quote_draft_v1';

function quote_draft_empty(): array {
    return [
        'quote_id'    => null,
        'customer_id' => null,
        'company_id'  => null,
        'repair_id'   => null,
        'title'       => '',
        'notes'       => '',
        'planned_work'=> '',
        'internal_notes' => '',
        'valid_until' => '',
        'tax_rate'    => billing_tax_rate(),
        'items'       => [],
    ];
}

$id    = (int)($_GET['id'] ?? 0);
$quote = $id ? quote_find($id) : null;
if ($id && !$quote) {
    flash('error', 'Angebot nicht gefunden.');
    header('Location: ' . url('quotes.php'));
    exit;
}

$isDraft = !$quote || $quote['status'] === 'entwurf';

// ── Session-Arbeitskopie (nur solange das Angebot ein Entwurf ist) ─────────
$draft = null;
if ($isDraft) {
    $draft = $_SESSION[QUOTE_DRAFT_SESSION_KEY] ?? null;
    if (!$draft || (int)($draft['quote_id'] ?? 0) !== $id) {
        if ($quote) {
            $draft = [
                'quote_id'    => $id,
                'customer_id' => $quote['customer_id'],
                'company_id'  => $quote['company_id'],
                'repair_id'   => $quote['repair_id'],
                'title'       => $quote['title'] ?? '',
                'notes'       => $quote['notes'] ?? '',
                'planned_work'=> $quote['planned_work'] ?? '',
                'internal_notes' => $quote['internal_notes'] ?? '',
                'valid_until' => $quote['valid_until'] ?? '',
                'tax_rate'    => $quote['tax_rate'] ?? get_setting('tax_rate', '0'),
                'items'       => array_map(static fn(array $it): array => [
                    'part_id'     => $it['part_id'],
                    'description' => $it['description'],
                    'quantity'    => (float)$it['quantity'],
                    'unit_price'  => (float)$it['unit_price'],
                ], quote_items_list($id)),
            ];
        } else {
            $draft = quote_draft_empty();
        }
        $_SESSION[QUOTE_DRAFT_SESSION_KEY] = $draft;
    }
}

// ── POST-Aktionen ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($isDraft && $action === 'set_header') {
        $draft['customer_id'] = (int)($_POST['customer_id'] ?? 0) ?: null;
        $draft['company_id']  = (int)($_POST['company_id']  ?? 0) ?: null;
        $draft['repair_id']   = (int)($_POST['repair_id']   ?? 0) ?: null;
        $draft['title']       = trim($_POST['title'] ?? '');
        $draft['notes']       = trim($_POST['notes'] ?? '');
        $draft['planned_work']= trim($_POST['planned_work'] ?? '');
        $draft['internal_notes'] = trim($_POST['internal_notes'] ?? '');
        $draft['valid_until'] = trim($_POST['valid_until'] ?? '') ?: null;
        $draft['tax_rate']    = billing_tax_rate();
        $_SESSION[QUOTE_DRAFT_SESSION_KEY] = $draft;
        flash('success', 'Kopfdaten übernommen.');

    } elseif ($isDraft && $action === 'add_item') {
        $desc = trim($_POST['description'] ?? '');
        if ($desc === '') {
            flash('error', 'Bitte eine Beschreibung für die Position angeben.');
        } else {
            try {
                $draft['items'][] = [
                    'part_id'     => (int)($_POST['part_id'] ?? 0) ?: null,
                    'description' => $desc,
                    'item_type'   => in_array($_POST['item_type'] ?? '', ['part', 'labor', 'service'], true) ? $_POST['item_type'] : 'service',
                    'quantity'    => repair_decimal_input($_POST['quantity'] ?? '1', 'Menge', '999999.99'),
                    'unit_price'  => repair_decimal_input($_POST['unit_price'] ?? '0', 'Einzelpreis', '9999999.99'),
                ];
                $_SESSION[QUOTE_DRAFT_SESSION_KEY] = $draft;
            } catch (InvalidArgumentException $e) {
                flash('error', $e->getMessage());
            }
        }

    } elseif ($isDraft && $action === 'remove_item') {
        $idx = (int)($_POST['idx'] ?? -1);
        unset($draft['items'][$idx]);
        $draft['items'] = array_values($draft['items']);
        $_SESSION[QUOTE_DRAFT_SESSION_KEY] = $draft;

    } elseif ($isDraft && $action === 'save') {
        if (empty($draft['customer_id']) && empty($draft['company_id'])) {
            flash('error', 'Bitte zuerst einen Kunden oder eine Firma auswählen und die Kopfdaten übernehmen.');
        } else {
            $data = [
                'repair_id'   => $draft['repair_id'],
                'customer_id' => $draft['customer_id'],
                'company_id'  => $draft['company_id'],
                'title'       => $draft['title'],
                'notes'       => $draft['notes'],
                'planned_work'=> $draft['planned_work'],
                'internal_notes' => $draft['internal_notes'],
                'valid_until' => $draft['valid_until'],
                'currency'    => 'EUR',
                'tax_rate'    => $draft['tax_rate'],
            ];
            if ($id) {
                quote_update($id, $data, $draft['items']);
                flash('success', 'Angebot wurde aktualisiert.');
                $targetId = $id;
            } else {
                $targetId = quote_create_draft($data, $draft['items'], $_SESSION['user_id'] ?? null);
                flash('success', 'Angebot wurde als Entwurf angelegt.');
            }
            unset($_SESSION[QUOTE_DRAFT_SESSION_KEY]);
            header('Location: ' . url('quotes_form.php') . '?id=' . $targetId);
            exit;
        }

    } elseif ($id && $action === 'release') {
        $result = quote_release($id, $_SESSION['user_id'] ?? null);
        flash($result['success'] ? 'success' : 'error', $result['message']);

    } elseif ($id && $action === 'set_status') {
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['gesendet', 'abgelaufen', 'storniert'], true)) {
            quote_set_status($id, $status);
            flash('success', 'Status wurde aktualisiert.');
        }

    } elseif ($id && $action === 'record_decision') {
        $decision = $_POST['decision'] ?? '';
        if (in_array($decision, ['angenommen', 'abgelehnt'], true)) {
            quote_decision_record(
                'angebot',
                null,
                $id,
                $decision,
                'staff',
                $_SESSION['user_id'] ?? null,
                trim($_POST['comment'] ?? '') ?: null,
                $quote['quote_number'] ?? null,
                null
            );
            flash('success', 'Kundenentscheidung wurde erfasst.');
        }

    } elseif ($id && $action === 'convert') {
        $repairId = (int)($_POST['repair_id'] ?? 0);
        $repairExists = false;
        if ($repairId) {
            $rs = get_db()->prepare('SELECT id FROM repairs WHERE id = ?');
            $rs->execute([$repairId]);
            $repairExists = (bool)$rs->fetchColumn();
        }
        if ($repairExists) {
            quote_convert_to_repair($id, $repairId);
            flash('success', 'Positionen wurden in den Reparaturauftrag #' . $repairId . ' übernommen.');
        } else {
            flash('error', 'Reparaturauftrag mit dieser ID wurde nicht gefunden.');
        }
    } elseif ($id && $action === 'convert_invoice_preview') {
        $repairId = (int)($_POST['repair_id'] ?? ($quote['repair_id'] ?? 0));
        $selected = [];
        foreach ((array)($_POST['selected'] ?? []) as $itemId => $enabled) {
            if ((string)$enabled !== '1') continue;
            $selected[(int)$itemId] = $_POST['quantity'][$itemId] ?? '0';
        }
        $result = quote_convert_to_invoice_draft(
            $id,
            $repairId,
            $selected,
            trim($_POST['service_date'] ?? '') ?: null,
            $_SESSION['user_id'] ?? null
        );
        if ($result['success']) {
            flash('success', 'Das Angebot wurde als Rechnungsentwurf in den Reparaturauftrag übernommen. Eine Rechnungsnummer wurde noch nicht verbraucht.');
            header('Location: ' . url('repairs_view.php') . '?id=' . (int)$result['repair_id']);
            exit;
        }
        flash('error', $result['message'] ?? 'Die Übernahme ist fehlgeschlagen.');
    }

    header('Location: ' . url('quotes_form.php') . ($id ? '?id=' . $id : ''));
    exit;
}

// ── Daten für die Ansicht ───────────────────────────────────────────────
$customers = get_db()->query('SELECT id, first_name, last_name FROM customers ORDER BY last_name, first_name')->fetchAll(PDO::FETCH_ASSOC);
$companies = companies_list();

if ($isDraft) {
    $items    = $draft['items'];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal += (float)$it['unit_price'] * (float)$it['quantity'];
    }
    $amounts = billing_amounts($subtotal);
    $taxRate = (float)$amounts['tax_rate'];
    $ustg    = billing_is_small_business();
    $tax     = (float)$amounts['tax_amount'];
    $total   = (float)$amounts['total'];
} else {
    $items    = quote_items_list($id);
    $subtotal = (float)$quote['subtotal'];
    $tax      = (float)$quote['tax_amount'];
    $total    = (float)$quote['total'];
    $taxRate  = (float)($quote['tax_rate'] ?? 0);
    $ustg     = billing_is_small_business($quote['billing_mode'] ?? null);
}

$quote_status_labels = [
    'entwurf'     => 'Entwurf',
    'zur_pruefung'=> 'Zur Prüfung',
    'freigegeben' => 'Freigegeben',
    'gesendet'    => 'Gesendet',
    'angenommen'  => 'Angenommen',
    'abgelehnt'   => 'Abgelehnt',
    'abgelaufen'  => 'Abgelaufen',
    'storniert'   => 'Storniert',
    'in_rechnung_umgewandelt' => 'In Rechnung umgewandelt',
];
$quote_status_badges = [
    'entwurf'     => 'badge-gray',
    'zur_pruefung'=> 'badge-yellow',
    'freigegeben' => 'badge-blue',
    'gesendet'    => 'badge-yellow',
    'angenommen'  => 'badge-green',
    'abgelehnt'   => 'badge-red',
    'abgelaufen'  => 'badge-orange',
    'storniert'   => 'badge-red',
    'in_rechnung_umgewandelt' => 'badge-green',
];

$page_title = $id ? ('Angebot ' . ($quote['quote_number'] ?? ('Entwurf #' . $id))) : 'Neues Angebot';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-nav">
    <a href="quotes.php" class="btn btn-outline btn-sm"><?= svg_icon('arrow-left', 16) ?> Zurück zu Angeboten</a>
</div>

<?php show_flash(); ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('euro', 20) ?>
            <?= $id ? h($quote['quote_number'] ?? ('Entwurf #' . $id)) : 'Neues Angebot' ?>
        </h2>
        <?php if ($id): ?>
            <?php $badge = $quote_status_badges[$quote['status']] ?? 'badge-gray'; $label = $quote_status_labels[$quote['status']] ?? $quote['status']; ?>
            <span class="badge <?= $badge ?>"><?= h($label) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <?php if ($id): ?>
        <p>
            <a href="<?= url('pdf/angebot.php') ?>?id=<?= (int)$id ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= svg_icon('pdf', 15) ?> PDF ansehen
            </a>
            <?php if (!empty($quote['converted_to_repair_id'])): ?>
                <span class="text-muted" style="margin-left:.75rem;">
                    Bereits übernommen in
                    <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$quote['converted_to_repair_id'] ?>">Reparaturauftrag #<?= (int)$quote['converted_to_repair_id'] ?></a>
                </span>
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if ($isDraft): ?>
        <!-- ═══ KOPFDATEN (bearbeitbar, solange Entwurf) ═══════════════════ -->
        <fieldset class="form-section">
            <legend><?= svg_icon('user', 16) ?> Kopfdaten</legend>
            <form method="post" action="quotes_form.php<?= $id ? '?id=' . $id : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_header">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="customer_id">Kunde</label>
                        <select id="customer_id" name="customer_id">
                            <option value="">– kein Privatkunde –</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (int)($draft['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= h($c['last_name'] . ', ' . $c['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Kunde ODER Firma auswählen (mind. eines erforderlich).</p>
                    </div>
                    <div class="form-group">
                        <label for="company_id">Firma</label>
                        <select id="company_id" name="company_id">
                            <option value="">– keine Firma –</option>
                            <?php foreach ($companies as $co): ?>
                                <option value="<?= (int)$co['id'] ?>" <?= (int)($draft['company_id'] ?? 0) === (int)$co['id'] ? 'selected' : '' ?>>
                                    <?= h($co['company_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="repair_id">Reparaturauftrag (optional)</label>
                        <input type="number" id="repair_id" name="repair_id" min="1" value="<?= h($draft['repair_id'] ?? '') ?>" placeholder="z. B. 123">
                        <p class="form-hint">Nur ausfüllen, wenn das Angebot bereits einem bestehenden Auftrag zugeordnet ist.</p>
                    </div>
                    <div class="form-group">
                        <label for="valid_until">Gültig bis</label>
                        <input type="date" id="valid_until" name="valid_until" value="<?= h($draft['valid_until'] ? substr((string)$draft['valid_until'], 0, 10) : '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Abrechnungsart</label>
                        <input type="text" readonly value="Kleinunternehmer gemäß § 19 UStG">
                        <p class="form-hint">Keine Umsatzsteuer; Positionspreise sind Endbeträge.</p>
                    </div>
                    <div class="form-group full">
                        <label for="title">Titel</label>
                        <input type="text" id="title" name="title" value="<?= h($draft['title']) ?>" placeholder="z. B. Display-Austausch iPhone 15">
                    </div>
                    <div class="form-group full">
                        <label for="notes">Kundenhinweis</label>
                        <textarea id="notes" name="notes" rows="3"><?= h($draft['notes']) ?></textarea>
                    </div>
                    <div class="form-group full">
                        <label for="planned_work">Geplante Arbeiten</label>
                        <textarea id="planned_work" name="planned_work" rows="4"><?= h($draft['planned_work']) ?></textarea>
                    </div>
                    <div class="form-group full">
                        <label for="internal_notes">Interne Notizen – nicht für Kunden sichtbar</label>
                        <textarea id="internal_notes" name="internal_notes" rows="3"><?= h($draft['internal_notes']) ?></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-outline"><?= svg_icon('save', 16) ?> Kopfdaten übernehmen</button>
                </div>
            </form>
        </fieldset>

        <!-- ═══ POSITIONEN (bearbeitbar, solange Entwurf) ══════════════════ -->
        <fieldset class="form-section">
            <legend><?= svg_icon('package', 16) ?> Positionen</legend>

            <?php if (!empty($items)): ?>
            <div class="table-wrap" style="margin-bottom:1rem;">
                <table class="table">
                    <thead><tr><th>Beschreibung</th><th class="text-right">Menge</th><th class="text-right">Einzelpreis</th><th class="text-right">Gesamt</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $idx => $it): $lineTotal = (float)$it['unit_price'] * (float)$it['quantity']; ?>
                        <tr>
                            <td><?= h($it['description']) ?><?= $it['part_id'] ? ' <span class="text-muted font-mono">(Teil #' . (int)$it['part_id'] . ')</span>' : '' ?></td>
                            <td class="text-right"><?= h(rtrim(rtrim(number_format((float)$it['quantity'], 2, ',', '.'), '0'), ',')) ?></td>
                            <td class="text-right"><?= h(fmt_money((float)$it['unit_price'])) ?></td>
                            <td class="text-right"><?= h(fmt_money($lineTotal)) ?></td>
                            <td class="col-actions">
                                <form method="post" action="quotes_form.php<?= $id ? '?id=' . $id : '' ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="idx" value="<?= (int)$idx ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><?= svg_icon('trash', 15) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-muted">Noch keine Positionen hinzugefügt.</p>
            <?php endif; ?>

            <form method="post" action="quotes_form.php<?= $id ? '?id=' . $id : '' ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_item">
                <div class="form-group" style="margin:0;width:125px;">
                    <label style="font-size:.8rem;">Art</label>
                    <select name="item_type"><option value="service">Leistung</option><option value="labor">Arbeitsleistung</option><option value="part">Ersatzteil</option></select>
                </div>
                <div class="form-group" style="margin:0;flex:2;min-width:220px;">
                    <label style="font-size:.8rem;">Beschreibung</label>
                    <input type="text" name="description" placeholder="z. B. Display-Reparatur" required>
                </div>
                <div class="form-group" style="margin:0;width:90px;">
                    <label style="font-size:.8rem;">Menge</label>
                    <input type="number" name="quantity" value="1" min="0.01" step="0.01">
                </div>
                <div class="form-group" style="margin:0;width:120px;">
                    <label style="font-size:.8rem;">Einzelpreis (€)</label>
                    <input type="number" name="unit_price" value="0" min="0" step="0.01">
                </div>
                <div class="form-group" style="margin:0;width:120px;">
                    <label style="font-size:.8rem;">Teil-ID (optional)</label>
                    <input type="number" name="part_id" min="1">
                </div>
                <button type="submit" class="btn btn-outline"><?= svg_icon('plus', 16) ?> Position hinzufügen</button>
            </form>
        </fieldset>

        <div class="form-actions">
            <form method="post" action="quotes_form.php<?= $id ? '?id=' . $id : '' ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <button type="submit" class="btn btn-primary"><?= svg_icon('save', 18) ?> <?= $id ? 'Änderungen speichern' : 'Angebotsentwurf anlegen' ?></button>
            </form>
            <?php if ($id): ?>
            <form method="post" action="quotes_form.php?id=<?= $id ?>" class="inline-form" onsubmit="return confirm('Angebot jetzt freigeben? Danach wird die Angebotsnummer verbindlich vergeben und die Positionen können nicht mehr geändert werden.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="release">
                <button type="submit" class="btn btn-outline"><?= svg_icon('check', 16) ?> Freigeben</button>
            </form>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ═══ ANSICHT (freigegeben – Positionen unveränderlich) ══════════ -->
        <p>
            <strong>Kunde/Firma:</strong>
            <?= h($quote['company_name'] ?: trim(($quote['first_name'] ?? '') . ' ' . ($quote['last_name'] ?? '')) ?: '—') ?>
            <?php if (!empty($quote['title'])): ?> · <strong>Titel:</strong> <?= h($quote['title']) ?><?php endif; ?>
            <?php if (!empty($quote['valid_until'])): ?> · <strong>Gültig bis:</strong> <?= h(fmt_date($quote['valid_until'])) ?><?php endif; ?>
        </p>
        <?php if (!empty($quote['notes'])): ?><p class="text-muted"><?= nl2br(h($quote['notes'])) ?></p><?php endif; ?>

        <div class="table-wrap" style="margin-top:1rem;">
            <table class="table">
                <thead><tr><th>Pos.</th><th>Beschreibung</th><th class="text-right">Menge</th><th class="text-right">Einzelpreis</th><th class="text-right">Gesamt</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i => $it): $lineTotal = (float)$it['unit_price'] * (float)$it['quantity']; ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($it['description']) ?></td>
                        <td class="text-right"><?= h(rtrim(rtrim(number_format((float)$it['quantity'], 2, ',', '.'), '0'), ',')) ?></td>
                        <td class="text-right"><?= h(fmt_money((float)$it['unit_price'])) ?></td>
                        <td class="text-right"><?= h(fmt_money($lineTotal)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

        <!-- ═══ SUMMEN ══════════════════════════════════════════════════ -->
        <div style="max-width:320px;margin-left:auto;margin-top:1rem;">
            <?php if (!$ustg): ?>
            <p style="display:flex;justify-content:space-between;"><span>Zwischensumme (netto):</span> <strong><?= h(fmt_money($subtotal)) ?></strong></p>
            <p style="display:flex;justify-content:space-between;"><span>MwSt. (<?= h(rtrim(rtrim(number_format($taxRate, 1, ',', '.'), '0'), ',')) ?> %):</span> <strong><?= h(fmt_money($tax)) ?></strong></p>
            <?php endif; ?>
            <p style="display:flex;justify-content:space-between;font-size:1.1rem;"><span>Gesamt<?= $ustg ? '' : ' (brutto)' ?>:</span> <strong><?= h(fmt_money($total)) ?></strong></p>
        </div>

        <?php if (!$isDraft): ?>
        <!-- ═══ STATUS-AKTIONEN (nach Freigabe) ════════════════════════════ -->
        <fieldset class="form-section" style="margin-top:1.5rem;">
            <legend><?= svg_icon('refresh-cw', 16) ?> Status</legend>

            <?php if (!in_array($quote['status'], ['angenommen', 'abgelehnt', 'storniert'], true)): ?>
            <form method="post" action="quotes_form.php?id=<?= $id ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_status">
                <select name="status">
                    <option value="gesendet">Gesendet</option>
                    <option value="abgelaufen">Abgelaufen</option>
                    <option value="storniert">Storniert</option>
                </select>
                <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('check', 15) ?> Status setzen</button>
            </form>

            <form method="post" action="quotes_form.php?id=<?= $id ?>" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="record_decision">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:.8rem;">Kundenentscheidung</label>
                    <select name="decision">
                        <option value="angenommen">Angenommen</option>
                        <option value="abgelehnt">Abgelehnt</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;flex:1;min-width:220px;">
                    <label style="font-size:.8rem;">Kommentar (optional)</label>
                    <input type="text" name="comment" placeholder="z. B. telefonisch am ...">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><?= svg_icon('save', 15) ?> Entscheidung erfassen</button>
            </form>
            <?php else: ?>
                <p class="text-muted">Dieses Angebot befindet sich in einem abschließenden Status (<?= h($quote_status_labels[$quote['status']] ?? $quote['status']) ?>) und kann nicht mehr verändert werden.</p>
            <?php endif; ?>
        </fieldset>

        <?php if ($quote['status'] === 'angenommen' && empty($quote['converted_to_repair_id'])): ?>
        <fieldset class="form-section">
            <legend><?= svg_icon('link', 16) ?> In Reparaturauftrag übernehmen</legend>
            <form method="post" action="quotes_form.php?id=<?= $id ?>" style="display:flex;gap:.5rem;align-items:flex-end;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="convert">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:.8rem;">Bestehende Reparaturauftrag-ID</label>
                    <input type="number" name="repair_id" min="1" required>
                </div>
                <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('link', 15) ?> Positionen übernehmen</button>
            </form>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= svg_icon('euro', 16) ?> In Rechnung übernehmen</legend>
            <p class="form-hint">Vorschau: Positionen und tatsächliche Mengen auswählen. Das Angebot bleibt unverändert; die Rechnung bleibt zunächst Entwurf und verbraucht noch keine Rechnungsnummer.</p>
            <form method="post" action="quotes_form.php?id=<?= $id ?>" onsubmit="return confirm('Ausgewählte Positionen als Rechnungsentwurf übernehmen?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="convert_invoice_preview">
                <div class="form-grid">
                    <div class="form-group"><label>Reparaturauftrag-ID</label><input type="number" name="repair_id" min="1" required value="<?= h($quote['repair_id'] ?? '') ?>"></div>
                    <div class="form-group"><label>Leistungsdatum</label><input type="date" name="service_date" value="<?= date('Y-m-d') ?>" required></div>
                </div>
                <div class="table-wrap"><table class="table"><thead><tr><th>Übernehmen</th><th>Position</th><th>Menge</th><th>Einzelpreis</th></tr></thead><tbody>
                <?php foreach ($items as $it): ?>
                    <tr><td><input type="checkbox" name="selected[<?= (int)$it['id'] ?>]" value="1" checked></td><td><?= h($it['description']) ?></td><td><input type="number" name="quantity[<?= (int)$it['id'] ?>]" min="0.01" step="0.01" value="<?= h($it['quantity']) ?>" style="max-width:110px"></td><td><?= h(fmt_money((float)$it['unit_price'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <button type="submit" class="btn btn-primary"><?= svg_icon('euro', 15) ?> In Rechnung übernehmen</button>
            </form>
        </fieldset>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
