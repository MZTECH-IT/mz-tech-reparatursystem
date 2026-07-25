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
        'valid_until' => '',
        'tax_rate'    => get_setting('tax_rate', '0'),
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
                'valid_until' => $quote['valid_until'] ?? '',
                'tax_rate'    => $quote['tax_rate'] ?? get_setting('tax_rate', '0'),
                'items'       => array_map(static fn(array $it): array => [
                    'part_id'     => $it['part_id'],
                    'description' => $it['description'],
                    'quantity'    => (int)$it['quantity'],
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
        $draft['valid_until'] = trim($_POST['valid_until'] ?? '') ?: null;
        $draft['tax_rate']    = trim($_POST['tax_rate'] ?? '') !== '' ? $_POST['tax_rate'] : '0';
        $_SESSION[QUOTE_DRAFT_SESSION_KEY] = $draft;
        flash('success', 'Kopfdaten übernommen.');

    } elseif ($isDraft && $action === 'add_item') {
        $desc = trim($_POST['description'] ?? '');
        if ($desc === '') {
            flash('error', 'Bitte eine Beschreibung für die Position angeben.');
        } else {
            $draft['items'][] = [
                'part_id'     => (int)($_POST['part_id'] ?? 0) ?: null,
                'description' => $desc,
                'quantity'    => max(1, (int)($_POST['quantity'] ?? 1)),
                'unit_price'  => (float)str_replace(',', '.', (string)($_POST['unit_price'] ?? 0)),
            ];
            $_SESSION[QUOTE_DRAFT_SESSION_KEY] = $draft;
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
        $subtotal += (float)$it['unit_price'] * (int)$it['quantity'];
    }
    $taxRate = (float)str_replace(',', '.', (string)$draft['tax_rate']);
    $ustg    = $taxRate <= 0;
    $tax     = $ustg ? 0.0 : round($subtotal * $taxRate / 100, 2);
    $total   = round($subtotal + $tax, 2);
} else {
    $items    = quote_items_list($id);
    $subtotal = (float)$quote['subtotal'];
    $tax      = (float)$quote['tax_amount'];
    $total    = (float)$quote['total'];
    $taxRate  = (float)($quote['tax_rate'] ?? 0);
    $ustg     = $tax <= 0.0;
}

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
                        <label for="tax_rate">MwSt-Satz (%)</label>
                        <input type="number" id="tax_rate" name="tax_rate" min="0" max="100" step="0.1" value="<?= h($draft['tax_rate']) ?>">
                    </div>
                    <div class="form-group full">
                        <label for="title">Titel</label>
                        <input type="text" id="title" name="title" value="<?= h($draft['title']) ?>" placeholder="z. B. Display-Austausch iPhone 15">
                    </div>
                    <div class="form-group full">
                        <label for="notes">Notizen</label>
                        <textarea id="notes" name="notes" rows="3"><?= h($draft['notes']) ?></textarea>
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
                    <?php foreach ($items as $idx => $it): $lineTotal = (float)$it['unit_price'] * (int)$it['quantity']; ?>
                        <tr>
                            <td><?= h($it['description']) ?><?= $it['part_id'] ? ' <span class="text-muted font-mono">(Teil #' . (int)$it['part_id'] . ')</span>' : '' ?></td>
                            <td class="text-right"><?= (int)$it['quantity'] ?></td>
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
                <div class="form-group" style="margin:0;flex:2;min-width:220px;">
                    <label style="font-size:.8rem;">Beschreibung</label>
                    <input type="text" name="description" placeholder="z. B. Display-Reparatur" required>
                </div>
                <div class="form-group" style="margin:0;width:90px;">
                    <label style="font-size:.8rem;">Menge</label>
                    <input type="number" name="quantity" value="1" min="1">
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
                <?php foreach ($items as $i => $it): $lineTotal = (float)$it['unit_price'] * (int)$it['quantity']; ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($it['description']) ?></td>
                        <td class="text-right"><?= (int)$it['quantity'] ?></td>
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
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
