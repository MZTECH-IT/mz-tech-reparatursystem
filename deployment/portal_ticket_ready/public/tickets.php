<?php
/**
 * MZ Tech – Ticketsystem: Übersicht (Phase 5)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_tickets');

$filters = [
    'status'   => $_GET['status']   ?? '',
    'priority' => $_GET['priority'] ?? '',
    'project_id' => (int)($_GET['project_id'] ?? 0),
    'company_contact_id' => (int)($_GET['company_contact_id'] ?? 0),
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'q'        => trim($_GET['q'] ?? ''),
];
$tickets = tickets_list(array_filter($filters));
$projects = get_db()->query("SELECT id, project_number, name FROM projects ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$contacts = get_db()->query("SELECT id, first_name, last_name FROM company_contacts ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Tickets';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Tickets</h1>
    <p class="page-subtitle">Support-Anfragen von Privat- und Firmenkunden sowie interne Tickets</p>
  </div>
  <a href="<?= url('ticket_view.php') ?>?new=1" class="btn btn-primary"><?= svg_icon('plus') ?> Neues Ticket</a>
</div>

<?php show_flash(); ?>

<div class="card" style="margin-bottom:16px;">
  <div class="card-body">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <div class="form-group" style="margin:0;">
        <label>Status</label>
        <select name="status" class="form-control">
          <option value="">Alle</option>
          <?php foreach (ticket_valid_statuses() as $s): ?>
            <option value="<?= h($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= h(ticket_status_label($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Priorität</label>
        <select name="priority" class="form-control">
          <option value="">Alle</option>
          <?php foreach (['niedrig','normal','hoch','dringend'] as $p): ?>
            <option value="<?= h($p) ?>" <?= $filters['priority'] === $p ? 'selected' : '' ?>><?= h(ticket_priority_label($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:200px;">
        <label>Suche</label>
        <input type="text" name="q" class="form-control" value="<?= h($filters['q']) ?>" placeholder="Ticketnummer oder Betreff">
      </div>
      <div class="form-group" style="margin:0;">
        <label>Projekt</label><select name="project_id" class="form-control"><option value="">Alle</option>
          <?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= $filters['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= h($project['project_number'].' – '.$project['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Ansprechpartner</label><select name="company_contact_id" class="form-control"><option value="">Alle</option>
          <?php foreach ($contacts as $contact): ?><option value="<?= (int)$contact['id'] ?>" <?= $filters['company_contact_id'] === (int)$contact['id'] ? 'selected' : '' ?>><?= h($contact['first_name'].' '.$contact['last_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;"><label>Von</label><input type="date" name="date_from" class="form-control" value="<?= h($filters['date_from']) ?>"></div>
      <div class="form-group" style="margin:0;"><label>Bis</label><input type="date" name="date_to" class="form-control" value="<?= h($filters['date_to']) ?>"></div>
      <button type="submit" class="btn btn-outline"><?= svg_icon('search') ?> Filtern</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;">
    <?php if (empty($tickets)): ?>
      <div class="empty-state"><?= svg_icon('inbox') ?><p>Keine Tickets gefunden.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Nr.</th><th>Betreff</th><th>Kunde</th><th>Auftrag</th><th>Priorität</th><th>Status</th><th>Techniker</th><th>Erstellt</th></tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
            <tr style="cursor:pointer;" onclick="window.location='<?= url('ticket_view.php') ?>?id=<?= (int)$t['id'] ?>'">
              <td><code><?= h($t['ticket_number']) ?></code></td>
              <td style="font-weight:600;"><?= h($t['subject']) ?></td>
              <td>
                <?php if ($t['company_name']): ?>
                  <?= h($t['company_name']) ?> <span class="text-muted">(<?= h($t['contact_first_name'] . ' ' . $t['contact_last_name']) ?>)</span>
                <?php elseif ($t['customer_first_name']): ?>
                  <?= h($t['customer_first_name'] . ' ' . $t['customer_last_name']) ?>
                <?php else: ?>
                  <span class="text-muted">–</span>
                <?php endif; ?>
              </td>
              <td><?= $t['repair_number'] ? h($t['repair_number']) : '<span class="text-muted">–</span>' ?></td>
              <td><?= ticket_priority_badge($t['priority']) ?></td>
              <td><?= ticket_status_badge($t['status']) ?></td>
              <td><?= h($t['technician_name'] ?? '–') ?></td>
              <td><?= fmt_date($t['created_at'], true) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
