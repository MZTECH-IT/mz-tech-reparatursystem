<?php
/**
 * MZ Tech – Ticketsystem: Detailansicht / Neues Ticket (Phase 5)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_tickets');

$db = get_db();
$is_new = isset($_GET['new']);
$id = (int)($_GET['id'] ?? 0);

if (!$is_new && !$id) {
    header('Location: ' . url('tickets.php'));
    exit;
}

// ── Neues Ticket anlegen ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ticket') {
    verify_csrf();

    $data = $_POST;
    // Optionale Auftragsnummer -> repair_id auflösen
    $repair_number = trim($_POST['repair_number'] ?? '');
    if ($repair_number !== '') {
        $rstmt = $db->prepare('SELECT id FROM repairs WHERE repair_number = ? LIMIT 1');
        $rstmt->execute([$repair_number]);
        $rid = $rstmt->fetchColumn();
        if ($rid) $data['repair_id'] = (int)$rid;
        else { flash('error', 'Auftragsnummer "' . h($repair_number) . '" nicht gefunden.'); header('Location: ' . url('ticket_view.php') . '?new=1'); exit; }
    }

    $result = ticket_create($data, ['type' => 'staff', 'ref' => $_SESSION['user_id'] ?? null]);
    if ($result['success']) {
        flash('success', $result['message']);
        header('Location: ' . url('ticket_view.php') . '?id=' . $result['id']);
    } else {
        flash('error', $result['message']);
        header('Location: ' . url('ticket_view.php') . '?new=1');
    }
    exit;
}

if ($is_new) {
    $customers_all = $db->query("SELECT id, first_name, last_name FROM customers ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
    $contacts_all  = $db->query("SELECT cc.id, cc.first_name, cc.last_name, c.company_name FROM company_contacts cc JOIN companies c ON c.id = cc.company_id ORDER BY c.company_name, cc.last_name")->fetchAll(PDO::FETCH_ASSOC);
    $projects_all  = $db->query("SELECT p.id, p.project_number, p.name, c.company_name FROM projects p JOIN companies c ON c.id = p.company_id WHERE p.status='aktiv' ORDER BY c.company_name, p.name")->fetchAll(PDO::FETCH_ASSOC);
    $technicians   = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    $page_title = 'Neues Ticket';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="page-header">
      <div>
        <h1 class="page-title">Neues Ticket</h1>
        <p class="page-subtitle"><a href="<?= url('tickets.php') ?>"><?= svg_icon('arrow-left', 14) ?> Zurück zur Übersicht</a></p>
      </div>
    </div>
    <?php show_flash(); ?>
    <div class="card">
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_ticket">
          <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1;">
              <label>Betreff *</label>
              <input type="text" name="subject" class="form-control" required>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
              <label>Beschreibung</label>
              <textarea name="description" class="form-control" rows="4"></textarea>
            </div>
            <div class="form-group">
              <label>Priorität</label>
              <select name="priority" class="form-control">
                <option value="normal">Normal</option>
                <option value="niedrig">Niedrig</option>
                <option value="hoch">Hoch</option>
                <option value="dringend">Dringend</option>
              </select>
            </div>
            <div class="form-group">
              <label>Kategorie</label>
              <select name="category" class="form-control">
                <option value="support">Support</option>
                <option value="reparatur">Reparatur</option>
                <option value="rechnung">Rechnung</option>
                <option value="sonstiges">Sonstiges</option>
              </select>
            </div>
            <div class="form-group">
              <label>Zugewiesener Techniker</label>
              <select name="assigned_technician_id" class="form-control">
                <option value="">– keiner –</option>
                <?php foreach ($technicians as $t): ?>
                  <option value="<?= (int)$t['id'] ?>"><?= h($t['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Auftragsnummer (optional)</label>
              <input type="text" name="repair_number" class="form-control" placeholder="z. B. MZ20260123">
              <p class="form-hint">Verknüpft das Ticket mit einem bestehenden Reparaturauftrag. Leer lassen für ein eigenständiges Ticket.</p>
            </div>
            <div class="form-group">
              <label>Privatkunde (optional)</label>
              <select name="customer_id" class="form-control">
                <option value="">– keiner –</option>
                <?php foreach ($customers_all as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['first_name'] . ' ' . $c['last_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Firmenkontakt (optional)</label>
              <select name="company_contact_id" class="form-control">
                <option value="">– keiner –</option>
                <?php foreach ($contacts_all as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['company_name'] . ' – ' . $c['first_name'] . ' ' . $c['last_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Projekt (optional)</label>
              <select name="project_id" class="form-control">
                <option value="">– keines –</option>
                <?php foreach ($projects_all as $project): ?>
                  <option value="<?= (int)$project['id'] ?>"><?= h($project['company_name'].' – '.$project['project_number'].' – '.$project['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Team</label><input type="text" name="team" class="form-control"></div>
            <div class="form-group"><label>Fälligkeit</label><input type="datetime-local" name="due_at" class="form-control"></div>
            <div class="form-group"><label>Standort</label><input type="text" name="location" class="form-control"></div>
            <div class="form-group"><label>Kundenreferenz</label><input type="text" name="customer_reference" class="form-control"></div>
          </div>
          <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"><?= svg_icon('plus') ?> Ticket anlegen</button>
          </div>
        </form>
      </div>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <?php
    exit;
}

// ── Detailansicht ──────────────────────────────────────────────────────
$ticket = ticket_find($id);
if (!$ticket) {
    flash('error', 'Ticket nicht gefunden.');
    header('Location: ' . url('tickets.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment') {
        $body = trim($_POST['body'] ?? '');
        $internal = !empty($_POST['is_internal']);
        if ($body !== '') {
            $commentId = ticket_add_comment($id, $body, ['type' => 'staff', 'ref' => $_SESSION['user_id'] ?? null], $internal);
            if (!empty($_FILES['attachment']['name'])) {
                $saved = ticket_attachment_save($_FILES['attachment'], $id);
                if ($saved) ticket_add_attachment($id, $commentId, $saved);
            }
            flash('success', 'Antwort hinzugefügt.');
        }
        header('Location: ' . url('ticket_view.php') . '?id=' . $id);
        exit;
    }

    if ($action === 'update_status') {
        ticket_update_status($id, $_POST['status'] ?? '');
        flash('success', 'Status aktualisiert.');
        header('Location: ' . url('ticket_view.php') . '?id=' . $id);
        exit;
    }

    if ($action === 'assign_technician') {
        ticket_assign_technician($id, (int)($_POST['technician_id'] ?? 0) ?: null);
        flash('success', 'Techniker zugewiesen.');
        header('Location: ' . url('ticket_view.php') . '?id=' . $id);
        exit;
    }

    if ($action === 'add_link') {
        $ok = ticket_link_add(
            $id,
            (string)($_POST['link_type'] ?? ''),
            (int)($_POST['link_id'] ?? 0),
            !empty($_POST['is_portal_visible']),
            (int)($_SESSION['user_id'] ?? 0)
        );
        flash($ok ? 'success' : 'error', $ok ? 'Verknüpfung gespeichert.' : 'Ungültige Verknüpfung.');
        header('Location: ' . url('ticket_view.php') . '?id=' . $id);
        exit;
    }
}

$comments = ticket_comments($id, true);
$history = ticket_history($id, true);
$links = ticket_links($id, false);
$attachments_by_comment = [];
foreach (ticket_attachments($id) as $a) {
    $attachments_by_comment[(int)($a['comment_id'] ?? 0)][] = $a;
}
$technicians = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Ticket ' . $ticket['ticket_number'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= h($ticket['ticket_number']) ?> – <?= h($ticket['subject']) ?></h1>
    <p class="page-subtitle"><a href="<?= url('tickets.php') ?>"><?= svg_icon('arrow-left', 14) ?> Zurück zur Übersicht</a></p>
  </div>
  <div style="display:flex;gap:8px;">
    <?= ticket_priority_badge($ticket['priority']) ?>
    <?= ticket_status_badge($ticket['status']) ?>
  </div>
</div>

<?php show_flash(); ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
  <div>
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><h2 class="card-title">Verlauf</h2></div>
      <div class="card-body">
        <?php if (empty($comments)): ?>
          <p class="text-muted">Noch keine Nachrichten.</p>
        <?php else: ?>
          <?php foreach ($comments as $c): ?>
            <div style="border-bottom:1px solid var(--border, #e5e7eb);padding:12px 0;">
              <div style="display:flex;justify-content:space-between;font-size:.82rem;color:var(--gray-500,#6b7280);margin-bottom:4px;">
                <span>
                  <strong>
                    <?= match($c['author_type']) {
                        'staff' => h($c['staff_name'] ?? 'Mitarbeiter'),
                        'customer' => 'Kunde',
                        'company_contact' => 'Firmenansprechpartner',
                        default => 'Unbekannt',
                    } ?>
                  </strong>
                  <?php if ($c['is_internal']): ?><span class="badge badge-yellow">Interne Notiz</span><?php endif; ?>
                </span>
                <span><?= fmt_date($c['created_at'], true) ?></span>
              </div>
              <div style="white-space:pre-line;"><?= h($c['body']) ?></div>
              <?php if (!empty($attachments_by_comment[(int)$c['id']])): ?>
                <div style="margin-top:6px;">
                  <?php foreach ($attachments_by_comment[(int)$c['id']] as $a): ?>
                    <a href="<?= url('ticket_attachment.php') ?>?id=<?= (int)$a['id'] ?>" style="font-size:.82rem;"><?= svg_icon('download', 14) ?> <?= h($a['original_name']) ?></a><br>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_comment">
          <div class="form-group">
            <label>Antwort / Notiz</label>
            <textarea name="body" class="form-control" rows="3" required></textarea>
          </div>
          <div class="form-group">
            <label>Anhang (optional)</label>
            <input type="file" name="attachment">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="is_internal" name="is_internal" value="1">
            <label for="is_internal" style="margin:0;">Interne Notiz (für Kunde nicht sichtbar)</label>
          </div>
          <button type="submit" class="btn btn-primary"><?= svg_icon('mail') ?> Absenden</button>
        </form>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><h2 class="card-title">Details</h2></div>
      <div class="card-body">
        <p><strong>Kunde:</strong><br>
          <?php if ($ticket['company_name']): ?>
            <?= h($ticket['company_name']) ?> (<?= h($ticket['contact_first_name'] . ' ' . $ticket['contact_last_name']) ?>)
          <?php elseif ($ticket['customer_first_name']): ?>
            <?= h($ticket['customer_first_name'] . ' ' . $ticket['customer_last_name']) ?>
          <?php else: ?>
            <span class="text-muted">Kein Kunde zugeordnet</span>
          <?php endif; ?>
        </p>
        <?php if ($ticket['repair_id']): ?>
          <p><strong>Auftrag:</strong><br><a href="<?= url('repairs_view.php') ?>?id=<?= (int)$ticket['repair_id'] ?>"><?= h($ticket['repair_number']) ?></a></p>
        <?php elseif (!empty($ticket['customer_id'])): ?>
          <p><a class="btn btn-outline btn-sm" href="<?= url('repairs_form.php') ?>?ticket_id=<?= (int)$ticket['id'] ?>&amp;customer_id=<?= (int)$ticket['customer_id'] ?>">
            <?= svg_icon('wrench', 14) ?> In Reparatur überführen
          </a></p>
        <?php endif; ?>
        <?php if (!empty($ticket['project_name'])): ?><p><strong>Projekt:</strong><br><?= h($ticket['project_number'].' – '.$ticket['project_name']) ?></p><?php endif; ?>
        <?php if (!empty($ticket['category'])): ?><p><strong>Kategorie:</strong><br><?= h($ticket['category']) ?></p><?php endif; ?>
        <?php if (!empty($ticket['customer_reference'])): ?><p><strong>Kundenreferenz:</strong><br><?= h($ticket['customer_reference']) ?></p><?php endif; ?>
        <p><strong>Erstellt:</strong><br><?= fmt_date($ticket['created_at'], true) ?></p>

        <form method="post" style="margin-top:12px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_status">
          <label>Status</label>
          <select name="status" class="form-control" onchange="this.form.submit()">
            <?php foreach (ticket_valid_statuses() as $s): ?>
              <option value="<?= h($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= h(ticket_status_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <hr style="margin:18px 0;">
        <h3>Verknüpfungen</h3>
        <?php foreach ($links as $link): ?>
          <div><?= h($link['link_type']) ?> #<?= (int)$link['link_id'] ?> <?= $link['is_portal_visible'] ? '(im Portal sichtbar)' : '(intern)' ?></div>
        <?php endforeach; ?>
        <form method="post" style="margin-top:12px;">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_link">
          <div class="form-group"><label>Typ</label><select name="link_type" class="form-control">
            <option value="project">Projekt</option><option value="purchase_order">Bestellung</option>
            <option value="document">Dokument</option><option value="repair">Reparatur</option>
          </select></div>
          <div class="form-group"><label>ID</label><input type="number" min="1" name="link_id" class="form-control" required></div>
          <label><input type="checkbox" name="is_portal_visible" value="1"> Im Portal sichtbar</label>
          <button type="submit" class="btn btn-outline btn-sm">Verknüpfen</button>
        </form>

        <form method="post" style="margin-top:12px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign_technician">
          <label>Zugewiesener Techniker</label>
          <select name="technician_id" class="form-control" onchange="this.form.submit()">
            <option value="">– keiner –</option>
            <?php foreach ($technicians as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= (int)$ticket['assigned_technician_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Aktivitätsverlauf</h2></div>
      <div class="card-body">
        <?php if (!$history): ?><p class="text-muted">Noch keine protokollierten Ereignisse.</p><?php endif; ?>
        <?php foreach ($history as $event): ?>
          <div style="border-bottom:1px solid #e5e7eb;padding:8px 0;">
            <strong><?= h($event['event_type']) ?></strong>
            <small><?= h(fmt_date($event['created_at'], true)) ?></small>
            <?php if ($event['old_value'] !== null || $event['new_value'] !== null): ?>
              <div><?= h((string)$event['old_value']) ?> → <?= h((string)$event['new_value']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
