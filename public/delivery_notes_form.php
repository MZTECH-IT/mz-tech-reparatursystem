<?php
/**
 * MZ Tech – Lieferschein anlegen/ansehen/bearbeiten (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * Anlegen/Bearbeiten eines Lieferschein-Entwurfs: Kopfdaten (Kunde ODER
 * Firma, optional Projekt/Reparaturauftrag/Bestellung, Notizen) und
 * Positionen werden – analog zum bereits etablierten Angebots-Entwurf-
 * Muster in public/quotes_form.php (das seinerseits public/purchase_order_form.php
 * folgt) – zunächst in der Session gesammelt
 * ($_SESSION[DELIVERY_NOTE_DRAFT_SESSION_KEY]) und erst über die Aktion
 * "save" tatsächlich per delivery_note_create_draft()/einem lokalen
 * UPDATE (es gibt bewusst keine delivery_note_update()-Hilfsfunktion in
 * private/delivery_notes.php, siehe dort) in die Datenbank geschrieben.
 *
 * Nach der Erstellung (delivery_note_finalize()) sind die Positionen
 * unveränderlich (nur noch Status-Übergänge über delivery_note_set_status()) –
 * "erstellt" wird dabei AUSSCHLIESSLICH von delivery_note_finalize()
 * vergeben, die UI darf diesen Status niemals manuell setzen.
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/permissions.php';
require_once PRIVATE_PATH . '/delivery_notes.php';
require_once PRIVATE_PATH . '/companies.php';
require_permission('create_invoice_drafts');

const DELIVERY_NOTE_DRAFT_SESSION_KEY = 'delivery_note_draft_v1';

function delivery_note_draft_empty(): array {
    return [
        'delivery_note_id'  => null,
        'customer_id'       => null,
        'company_id'        => null,
        'project_id'        => null,
        'repair_id'         => null,
        'purchase_order_id' => null,
        'notes'             => '',
        'items'             => [],
    ];
}

$id = (int)($_GET['id'] ?? 0);
$dn = $id ? delivery_note_find($id) : null;
if ($id && !$dn) {
    flash('error', 'Lieferschein nicht gefunden.');
    header('Location: ' . url('delivery_notes.php'));
    exit;
}

$isDraft = !$dn || $dn['status'] === 'entwurf';

// ── Session-Arbeitskopie (nur solange der Lieferschein ein Entwurf ist) ────
$draft = null;
if ($isDraft) {
    $draft = $_SESSION[DELIVERY_NOTE_DRAFT_SESSION_KEY] ?? null;
    if (!$draft || (int)($draft['delivery_note_id'] ?? 0) !== $id) {
        if ($dn) {
            $draft = [
                'delivery_note_id'  => $id,
                'customer_id'       => $dn['customer_id'],
                'company_id'        => $dn['company_id'],
                'project_id'        => $dn['project_id'],
                'repair_id'         => $dn['repair_id'],
                'purchase_order_id' => $dn['purchase_order_id'],
                'notes'             => $dn['notes'] ?? '',
                'items'             => array_map(static fn(array $it): array => [
                    'part_id'     => $it['part_id'],
                    'description' => $it['description'],
                    'quantity'    => (int)$it['quantity'],
                ], delivery_note_items_list($id)),
            ];
        } else {
            $draft = delivery_note_draft_empty();
        }
        $_SESSION[DELIVERY_NOTE_DRAFT_SESSION_KEY] = $draft;
    }
}

// ── POST-Aktionen ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($isDraft && $action === 'set_header') {
        $draft['customer_id']       = (int)($_POST['customer_id'] ?? 0) ?: null;
        $draft['company_id']        = (int)($_POST['company_id'] ?? 0) ?: null;
        $draft['project_id']        = (int)($_POST['project_id'] ?? 0) ?: null;
        $draft['repair_id']         = (int)($_POST['repair_id'] ?? 0) ?: null;
        $draft['purchase_order_id'] = (int)($_POST['purchase_order_id'] ?? 0) ?: null;
        $draft['notes']             = trim($_POST['notes'] ?? '');
        $_SESSION[DELIVERY_NOTE_DRAFT_SESSION_KEY] = $draft;
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
            ];
            $_SESSION[DELIVERY_NOTE_DRAFT_SESSION_KEY] = $draft;
        }

    } elseif ($isDraft && $action === 'remove_item') {
        $idx = (int)($_POST['idx'] ?? -1);
        unset($draft['items'][$idx]);
        $draft['items'] = array_values($draft['items']);
        $_SESSION[DELIVERY_NOTE_DRAFT_SESSION_KEY] = $draft;

    } elseif ($isDraft && $action === 'save') {
        if (empty($draft['customer_id']) && empty($draft['company_id'])) {
            flash('error', 'Bitte zuerst einen Kunden oder eine Firma auswählen und die Kopfdaten übernehmen.');
        } else {
            $data = [
                'customer_id'       => $draft['customer_id'],
                'company_id'        => $draft['company_id'],
                'project_id'        => $draft['project_id'],
                'repair_id'         => $draft['repair_id'],
                'purchase_order_id' => $draft['purchase_order_id'],
                'notes'             => $draft['notes'],
            ];
            if ($id) {
                // Es gibt bewusst keine delivery_note_update()-Hilfsfunktion in
                // private/delivery_notes.php (delivery_note_create_draft() ist
                // reine Anlage) – die Kopfdaten eines bestehenden Entwurfs
                // werden daher hier direkt per vorbereitetem UPDATE geschrieben.
                get_db()->prepare(
                    'UPDATE delivery_notes
                        SET customer_id = ?, company_id = ?, project_id = ?, repair_id = ?, purchase_order_id = ?, notes = ?
                      WHERE id = ?'
                )->execute([
                    $data['customer_id'],
                    $data['company_id'],
                    $data['project_id'],
                    $data['repair_id'],
                    $data['purchase_order_id'],
                    $data['notes'] !== '' ? $data['notes'] : null,
                    $id,
                ]);
                delivery_note_items_replace($id, $draft['items']);
                flash('success', 'Lieferschein wurde aktualisiert.');
                $targetId = $id;
            } else {
                $targetId = delivery_note_create_draft($data, $draft['items'], $_SESSION['user_id'] ?? null);
                flash('success', 'Lieferschein wurde als Entwurf angelegt.');
            }
            unset($_SESSION[DELIVERY_NOTE_DRAFT_SESSION_KEY]);
            header('Location: ' . url('delivery_notes_form.php') . '?id=' . $targetId);
            exit;
        }

    } elseif ($id && $action === 'finalize') {
        $result = delivery_note_finalize($id, $_SESSION['user_id'] ?? null);
        flash($result['success'] ? 'success' : 'error', $result['message']);

    } elseif ($id && $action === 'set_status') {
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['versendet', 'zugestellt', 'storniert'], true)) {
            delivery_note_set_status($id, $status);
            flash('success', 'Status wurde aktualisiert.');
        }
    }

    header('Location: ' . url('delivery_notes_form.php') . ($id ? '?id=' . $id : ''));
    exit;
}

// ── Daten für die Ansicht ───────────────────────────────────────────────
$customers = get_db()->query('SELECT id, first_name, last_name FROM customers ORDER BY last_name, first_name')->fetchAll(PDO::FETCH_ASSOC);
$companies = companies_list();

$items = $isDraft ? $draft['items'] : delivery_note_items_list($id);

$dn_status_labels = [
    'entwurf'    => 'Entwurf',
    'erstellt'   => 'Erstellt',
    'versendet'  => 'Versendet',
    'zugestellt' => 'Zugestellt',
    'storniert'  => 'Storniert',
];
$dn_status_badges = [
    'entwurf'    => 'badge-gray',
    'erstellt'   => 'badge-blue',
    'versendet'  => 'badge-yellow',
    'zugestellt' => 'badge-green',
    'storniert'  => 'badge-red',
];

$page_title = $id ? ('Lieferschein ' . ($dn['delivery_note_number'] ?? ('Entwurf #' . $id))) : 'Neuer Lieferschein';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-nav">
    <a href="delivery_notes.php" class="btn btn-outline btn-sm"><?= svg_icon('arrow-left', 16) ?> Zurück zu Lieferscheinen</a>
</div>

<?php show_flash(); ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('package', 20) ?>
            <?= $id ? h($dn['delivery_note_number'] ?? ('Entwurf #' . $id)) : 'Neuer Lieferschein' ?>
        </h2>
        <?php if ($id): ?>
            <?php $badge = $dn_status_badges[$dn['status']] ?? 'badge-gray'; $label = $dn_status_labels[$dn['status']] ?? $dn['status']; ?>
            <span class="badge <?= $badge ?>"><?= h($label) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <?php if ($id): ?>
        <p>
            <a href="<?= url('pdf/lieferschein.php') ?>?id=<?= (int)$id ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= svg_icon('pdf', 15) ?> PDF ansehen
            </a>
        </p>
        <?php endif; ?>

        <?php if ($isDraft): ?>
        <!-- ═══ KOPFDATEN (bearbeitbar, solange Entwurf) ═══════════════════ -->
        <fieldset class="form-section">
            <legend><?= svg_icon('user', 16) ?> Kopfdaten</legend>
            <form method="post" action="delivery_notes_form.php<?= $id ? '?id=' . $id : '' ?>">
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
                        <label for="project_id">Projekt (optional)</label>
                        <input type="number" id="project_id" name="project_id" min="1" value="<?= h($draft['project_id'] ?? '') ?>" placeholder="z. B. 12">
                    </div>
                    <div class="form-group">
                        <label for="repair_id">Reparaturauftrag (optional)</label>
                        <input type="number" id="repair_id" name="repair_id" min="1" value="<?= h($draft['repair_id'] ?? '') ?>" placeholder="z. B. 123">
                    </div>
                    <div class="form-group">
                        <label for="purchase_order_id">Bestellung (optional)</label>
                        <input type="number" id="purchase_order_id" name="purchase_order_id" min="1" value="<?= h($draft['purchase_order_id'] ?? '') ?>" placeholder="z. B. 45">
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
                    <thead><tr><th>Beschreibung</th><th class="text-right">Menge</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $idx => $it): ?>
                        <tr>
                            <td><?= h($it['description']) ?><?= $it['part_id'] ? ' <span class="text-muted font-mono">(Teil #' . (int)$it['part_id'] . ')</span>' : '' ?></td>
                            <td class="text-right"><?= (int)$it['quantity'] ?></td>
                            <td class="col-actions">
                                <form method="post" action="delivery_notes_form.php<?= $id ? '?id=' . $id : '' ?>" class="inline-form">
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

            <form method="post" action="delivery_notes_form.php<?= $id ? '?id=' . $id : '' ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_item">
                <div class="form-group" style="margin:0;flex:2;min-width:220px;">
                    <label style="font-size:.8rem;">Beschreibung</label>
                    <input type="text" name="description" placeholder="z. B. Ersatzteil / Zubehör" required>
                </div>
                <div class="form-group" style="margin:0;width:90px;">
                    <label style="font-size:.8rem;">Menge</label>
                    <input type="number" name="quantity" value="1" min="1">
                </div>
                <div class="form-group" style="margin:0;width:120px;">
                    <label style="font-size:.8rem;">Teil-ID (optional)</label>
                    <input type="number" name="part_id" min="1">
                </div>
                <button type="submit" class="btn btn-outline"><?= svg_icon('plus', 16) ?> Position hinzufügen</button>
            </form>
        </fieldset>

        <div class="form-actions">
            <form method="post" action="delivery_notes_form.php<?= $id ? '?id=' . $id : '' ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <button type="submit" class="btn btn-primary"><?= svg_icon('save', 18) ?> <?= $id ? 'Änderungen speichern' : 'Lieferscheinentwurf anlegen' ?></button>
            </form>
            <?php if ($id): ?>
            <form method="post" action="delivery_notes_form.php?id=<?= $id ?>" class="inline-form" onsubmit="return confirm('Lieferschein jetzt erstellen? Danach wird die Lieferscheinnummer verbindlich vergeben und die Positionen können nicht mehr geändert werden.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="finalize">
                <button type="submit" class="btn btn-outline"><?= svg_icon('check', 16) ?> Lieferschein erstellen</button>
            </form>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ═══ ANSICHT (erstellt – Positionen unveränderlich) ═════════════ -->
        <p>
            <strong>Kunde/Firma:</strong>
            <?= h($dn['company_name'] ?: trim(($dn['first_name'] ?? '') . ' ' . ($dn['last_name'] ?? '')) ?: '—') ?>
            <?php if (!empty($dn['project_id'])): ?> · <strong>Projekt:</strong> #<?= (int)$dn['project_id'] ?><?php endif; ?>
            <?php if (!empty($dn['repair_id'])): ?> · <strong>Auftrag:</strong> <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$dn['repair_id'] ?>">#<?= (int)$dn['repair_id'] ?></a><?php endif; ?>
            <?php if (!empty($dn['purchase_order_id'])): ?> · <strong>Bestellung:</strong> #<?= (int)$dn['purchase_order_id'] ?><?php endif; ?>
        </p>
        <?php if (!empty($dn['notes'])): ?><p class="text-muted"><?= nl2br(h($dn['notes'])) ?></p><?php endif; ?>

        <div class="table-wrap" style="margin-top:1rem;">
            <table class="table">
                <thead><tr><th>Pos.</th><th>Beschreibung</th><th class="text-right">Menge</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i => $it): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($it['description']) ?></td>
                        <td class="text-right"><?= (int)$it['quantity'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ═══ STATUS-AKTIONEN (nach Erstellung) ═══════════════════════════ -->
        <fieldset class="form-section" style="margin-top:1.5rem;">
            <legend><?= svg_icon('refresh-cw', 16) ?> Status</legend>

            <?php if ($dn['status'] !== 'storniert'): ?>
            <form method="post" action="delivery_notes_form.php?id=<?= $id ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_status">
                <select name="status">
                    <option value="versendet">Versendet</option>
                    <option value="zugestellt">Zugestellt</option>
                    <option value="storniert">Storniert</option>
                </select>
                <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('check', 15) ?> Status setzen</button>
            </form>
            <?php else: ?>
                <p class="text-muted">Dieser Lieferschein wurde storniert und kann nicht mehr verändert werden.</p>
            <?php endif; ?>
        </fieldset>

        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
