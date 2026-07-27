<?php
/**
 * MZ Tech – Privatkundenportal: Tickets (Phase 5)
 *
 * Eigene Seite statt Erweiterung des bereits sehr umfangreichen
 * portal.php – nutzt dieselbe Kundenportal-Session (portal_auth.php).
 * IDOR-Schutz: alle Abfragen filtern zwingend nach
 * portal_current_customer_id(), niemals nach Client-Eingaben.
 */
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/portal_security.php';
require_once $private . '/portal_auth.php';
require_once $private . '/tickets.php';
require_once $private . '/mailer.php';
require_once __DIR__ . '/includes/icons.php';

start_portal_session();
portal_require_login();

$customerId = portal_current_customer_id();
$company_name = get_setting('company_name', 'MZ Tech');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf()) {
        flash('error', 'Ungültige Anfrage.');
        header('Location: ' . url('portal_tickets.php'));
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $data = $_POST;
        $data['customer_id'] = $customerId;
        $result = ticket_create($data, ['type' => 'customer', 'ref' => $customerId]);
        if ($result['success'] && !empty($_FILES['attachment']['name'])) {
            $saved = ticket_attachment_save($_FILES['attachment'], (int)$result['id']);
            if ($saved) {
                ticket_add_attachment((int)$result['id'], null, $saved);
            } else {
                $result['message'] .= ' Der Anhang wurde wegen Dateityp oder Größe nicht übernommen.';
            }
        }
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('portal_tickets.php') . (!empty($result['id']) ? '?id=' . $result['id'] : ''));
        exit;
    }

    if ($action === 'add_comment') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $ticket = ticket_find($ticketId);
        if ($ticket && (int)($ticket['customer_id'] ?? 0) === $customerId) {
            $commentId = ticket_add_comment($ticketId, trim($_POST['body'] ?? ''), ['type' => 'customer', 'ref' => $customerId]);
            if ($commentId && !empty($_FILES['attachment']['name'])) {
                $saved = ticket_attachment_save($_FILES['attachment'], $ticketId);
                if ($saved) ticket_add_attachment($ticketId, $commentId, $saved);
            }
            flash('success', 'Nachricht gesendet.');
        }
        header('Location: ' . url('portal_tickets.php') . '?id=' . $ticketId);
        exit;
    }
}

$tickets = tickets_list_for_customer($customerId);

$openId = (int)($_GET['id'] ?? 0);
$openTicket = null;
$openComments = [];
$openAttachments = [];
$openLinks = [];
if ($openId) {
    $t = ticket_find($openId);
    if ($t && (int)($t['customer_id'] ?? 0) === $customerId) {
        $openTicket = $t;
        $openComments = ticket_comments($openId, false);
        $openLinks = ticket_links($openId, true);
        foreach (ticket_attachments($openId) as $attachment) {
            $openAttachments[(int)($attachment['comment_id'] ?? 0)][] = $attachment;
        }
    }
}

$page_title = 'Meine Tickets';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Meine Tickets – <?= h($company_name) ?></title>
  <link rel="icon" type="image/x-icon" href="<?= h(company_favicon_url()) ?>">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
  <?= company_color_css_override() ?>
</head>
<body style="background:#f5f7fa;">
<div style="max-width:760px;margin:0 auto;padding:24px 20px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <h1 style="font-size:1.3rem;color:var(--blue,#0057B8);">Meine Tickets</h1>
    <a href="<?= url('portal.php') ?>" style="font-size:.85rem;color:#6B7280;"><?= svg_icon('arrow-left', 14) ?> Zurück zum Portal</a>
  </div>

  <?php show_flash(); ?>

  <?php if ($openTicket): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <h2 class="card-title"><?= h($openTicket['ticket_number']) ?> – <?= h($openTicket['subject']) ?></h2>
        <a href="<?= url('portal_tickets.php') ?>" class="btn btn-sm btn-outline"><?= svg_icon('arrow-left', 14) ?> Zurück</a>
      </div>
      <div class="card-body">
        <div style="margin-bottom:12px;"><?= ticket_status_badge($openTicket['status']) ?> <?= ticket_priority_badge($openTicket['priority']) ?></div>
        <?php foreach ($openLinks as $link): ?>
          <span class="badge badge-gray"><?= h($link['link_type']) ?> #<?= (int)$link['link_id'] ?></span>
        <?php endforeach; ?>
        <?php foreach ($openAttachments[0] ?? [] as $attachment): ?>
          <p><a href="<?= url('portal_ticket_attachment.php') ?>?portal=customer&amp;id=<?= (int)$attachment['id'] ?>">
            <?= svg_icon('download', 14) ?> <?= h($attachment['original_name']) ?>
          </a></p>
        <?php endforeach; ?>
        <?php foreach ($openComments as $c): ?>
          <div style="border-bottom:1px solid #e5e7eb;padding:10px 0;">
            <div style="font-size:.8rem;color:#6B7280;"><?= $c['author_type'] === 'staff' ? 'MZ Tech Support' : 'Sie' ?> · <?= fmt_date($c['created_at'], true) ?></div>
            <div style="white-space:pre-line;"><?= h($c['body']) ?></div>
            <?php foreach ($openAttachments[(int)$c['id']] ?? [] as $attachment): ?>
              <a href="<?= url('portal_ticket_attachment.php') ?>?portal=customer&amp;id=<?= (int)$attachment['id'] ?>">
                <?= svg_icon('download', 14) ?> <?= h($attachment['original_name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
          <?= portal_csrf_field() ?>
          <input type="hidden" name="action" value="add_comment">
          <input type="hidden" name="ticket_id" value="<?= (int)$openTicket['id'] ?>">
          <div class="form-group"><textarea name="body" class="form-control" rows="3" required placeholder="Ihre Nachricht..."></textarea></div>
          <div class="form-group"><label>Anhang (optional)</label><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx"></div>
          <button type="submit" class="btn btn-primary"><?= svg_icon('mail') ?> Senden</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h2 class="card-title">Neues Ticket erstellen</h2></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= portal_csrf_field() ?>
          <input type="hidden" name="action" value="create_ticket">
          <div class="form-group"><label>Betreff *</label><input type="text" name="subject" class="form-control" required></div>
          <div class="form-group"><label>Beschreibung</label><textarea name="description" class="form-control" rows="3"></textarea></div>
          <div class="form-grid">
            <div class="form-group"><label>Priorität</label>
              <select name="priority" class="form-control">
                <option value="normal">Normal</option><option value="niedrig">Niedrig</option>
                <option value="hoch">Hoch</option><option value="dringend">Dringend</option>
              </select>
            </div>
            <div class="form-group"><label>Kategorie</label>
              <select name="category" class="form-control">
                <option value="support">Support</option><option value="reparatur">Reparatur</option>
                <option value="rechnung">Rechnung</option><option value="sonstiges">Sonstiges</option>
              </select>
            </div>
            <div class="form-group"><label>Bevorzugter Kontaktweg</label>
              <select name="preferred_contact" class="form-control">
                <option value="">Keine Angabe</option><option value="email">E-Mail</option>
                <option value="telefon">Telefon</option>
              </select>
            </div>
            <div class="form-group"><label>Wunschtermin</label><input type="datetime-local" name="preferred_date" class="form-control"></div>
            <div class="form-group"><label>Standort</label><input type="text" name="location" class="form-control" maxlength="255"></div>
            <div class="form-group"><label>Ihre Referenznummer</label><input type="text" name="customer_reference" class="form-control" maxlength="100"></div>
          </div>
          <div class="form-group"><label>Anhang (optional)</label><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx"></div>
          <button type="submit" class="btn btn-primary"><?= svg_icon('plus') ?> Ticket erstellen</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2 class="card-title">Meine Tickets</h2></div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($tickets)): ?>
          <div class="empty-state"><p>Noch keine Tickets vorhanden.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Nr.</th><th>Betreff</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($tickets as $t): ?>
                <tr>
                  <td><code><?= h($t['ticket_number']) ?></code></td>
                  <td><?= h($t['subject']) ?></td>
                  <td><?= ticket_status_badge($t['status']) ?></td>
                  <td><a href="?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye', 14) ?></a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
