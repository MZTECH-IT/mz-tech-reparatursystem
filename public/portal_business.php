<?php
/**
 * MZ Tech – Firmenkundenportal (Phase 5)
 *
 * Eigenständiges Portal für Ansprechpartner von Firmenkunden (getrennt
 * vom Privatkundenportal UND vom Admin-Bereich, siehe business_auth.php).
 * Zeigt: mehrere Ansprechpartner, Geräteübersicht, Projekte, Rechnungen,
 * Kostenvoranschläge, Tickets, Dokumente, Verlauf – alles gefiltert auf
 * die EIGENE Firma (IDOR-Schutz: niemals nach Client-Eingaben filtern,
 * immer nach business_current_company_id()).
 */
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/numbering.php';
require_once $private . '/business_auth.php';
require_once $private . '/companies.php';
require_once $private . '/tickets.php';
require_once $private . '/mailer.php';
require_once __DIR__ . '/includes/icons.php';

start_business_session();
$db = get_db();
$company_name_site = get_setting('company_name', 'MZ Tech');

// ── Aktivierung eines eingeladenen Zugangs ─────────────────────────────
$activate_result = null;
if (isset($_GET['activate']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $view = 'activate';
} elseif (isset($_GET['reset'])) {
    $view = 'reset_password';
} elseif (isset($_GET['forgot'])) {
    $view = 'forgot';
} else {
    $view = 'login';
}

$forgot_result = null;
$reset_result  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';

    if ($form === 'login' && business_verify_csrf()) {
        $result = business_attempt_login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
        if ($result['success']) {
            header('Location: ' . url('portal_business.php'));
            exit;
        }
        $login_error = $result['message'];
        $view = 'login';
    }

    if ($form === 'activate' && business_verify_csrf()) {
        $activate_result = company_contact_activate($_POST['token'] ?? '', $_POST['password'] ?? '', $_POST['password2'] ?? '');
        $view = 'activate';
    }

    if ($form === 'forgot' && business_verify_csrf()) {
        $forgot_result = company_contact_request_password_reset(trim($_POST['email'] ?? ''));
        $view = 'forgot';
    }

    if ($form === 'reset' && business_verify_csrf()) {
        $reset_result = company_contact_reset_password($_POST['token'] ?? '', $_POST['password'] ?? '', $_POST['password2'] ?? '');
        $view = 'reset_password';
    }
}

$activate_token = (string)($_GET['activate'] ?? ($_POST['token'] ?? ''));
$reset_token    = (string)($_GET['reset']    ?? ($_POST['token'] ?? ''));

if (isset($_GET['logout'])) {
    business_logout();
    header('Location: ' . url('portal_business.php'));
    exit;
}

// ── Nicht angemeldet: Login/Aktivierung/Reset-Ansichten ────────────────
if (!business_is_logged_in()) {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Firmenkundenportal – <?= h($company_name_site) ?></title>
      <link rel="icon" type="image/x-icon" href="<?= h(company_favicon_url()) ?>">
      <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
      <?= company_color_css_override() ?>
    </head>
    <body style="background:#f5f7fa;">
    <div style="max-width:480px;margin:60px auto;padding:0 20px;">
      <div style="text-align:center;margin-bottom:24px;">
        <img src="<?= h(company_logo_url()) ?>" alt="<?= h($company_name_site) ?>" style="max-height:60px;">
        <p style="color:#6B7280;margin-top:8px;">Firmenkundenportal</p>
      </div>
      <div class="card">
        <div class="card-body">
          <?php if ($view === 'login'): ?>
            <?php if (!empty($login_error)): ?>
              <div class="alert alert-danger" style="margin-bottom:16px;"><?= h($login_error) ?></div>
            <?php endif; ?>
            <?php show_flash(); ?>
            <form method="post">
              <input type="hidden" name="form" value="login">
              <?= business_csrf_field() ?>
              <div class="form-group" style="margin-bottom:16px;">
                <label>E-Mail-Adresse</label>
                <input type="email" name="email" class="form-control" required autofocus>
              </div>
              <div class="form-group" style="margin-bottom:12px;">
                <label>Passwort</label>
                <input type="password" name="password" class="form-control" required>
              </div>
              <div style="text-align:right;margin-bottom:16px;">
                <a href="?forgot=1" style="font-size:.82rem;color:var(--gray-500);">Passwort vergessen?</a>
              </div>
              <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Anmelden</button>
            </form>

          <?php elseif ($view === 'activate'): ?>
            <?php if ($activate_result): ?>
              <div class="alert <?= $activate_result['success'] ? 'alert-success' : 'alert-danger' ?>" style="margin-bottom:16px;"><?= h($activate_result['message']) ?></div>
            <?php endif; ?>
            <?php if (!$activate_result || !$activate_result['success']): ?>
              <p class="text-muted" style="margin-bottom:16px;">Bitte legen Sie Ihr persönliches Passwort für den Zugang zum Firmenkundenportal fest.</p>
              <form method="post">
                <input type="hidden" name="form" value="activate">
                <input type="hidden" name="token" value="<?= h($activate_token) ?>">
                <?= business_csrf_field() ?>
                <div class="form-group" style="margin-bottom:16px;">
                  <label>Passwort</label>
                  <input type="password" name="password" class="form-control" required autocomplete="new-password">
                </div>
                <div class="form-group" style="margin-bottom:16px;">
                  <label>Passwort bestätigen</label>
                  <input type="password" name="password2" class="form-control" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Zugang aktivieren</button>
              </form>
            <?php else: ?>
              <a href="<?= url('portal_business.php') ?>" class="btn btn-outline w-full" style="justify-content:center;">Zur Anmeldung</a>
            <?php endif; ?>

          <?php elseif ($view === 'forgot'): ?>
            <?php if ($forgot_result): ?>
              <div class="alert alert-success" style="margin-bottom:16px;"><?= h($forgot_result['message']) ?></div>
              <a href="<?= url('portal_business.php') ?>" class="btn btn-outline w-full" style="justify-content:center;">Zurück zur Anmeldung</a>
            <?php else: ?>
              <form method="post">
                <input type="hidden" name="form" value="forgot">
                <?= business_csrf_field() ?>
                <div class="form-group" style="margin-bottom:16px;">
                  <label>E-Mail-Adresse</label>
                  <input type="email" name="email" class="form-control" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Link anfordern</button>
              </form>
            <?php endif; ?>

          <?php elseif ($view === 'reset_password'): ?>
            <?php if ($reset_result && $reset_result['success']): ?>
              <div class="alert alert-success" style="margin-bottom:16px;"><?= h($reset_result['message']) ?></div>
              <a href="<?= url('portal_business.php') ?>" class="btn btn-outline w-full" style="justify-content:center;">Zur Anmeldung</a>
            <?php else: ?>
              <?php if ($reset_result): ?><div class="alert alert-danger" style="margin-bottom:16px;"><?= h($reset_result['message']) ?></div><?php endif; ?>
              <form method="post">
                <input type="hidden" name="form" value="reset">
                <input type="hidden" name="token" value="<?= h($reset_token) ?>">
                <?= business_csrf_field() ?>
                <div class="form-group" style="margin-bottom:16px;">
                  <label>Neues Passwort</label>
                  <input type="password" name="password" class="form-control" required autocomplete="new-password">
                </div>
                <div class="form-group" style="margin-bottom:16px;">
                  <label>Neues Passwort bestätigen</label>
                  <input type="password" name="password2" class="form-control" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Passwort setzen</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Angemeldet: Dashboard ───────────────────────────────────────────────
$companyId = business_current_company_id();
$contactId = business_current_contact_id();
$company   = company_find($companyId);
$contact   = company_contact_find($contactId);

// Aktionen (Ticket erstellen/kommentieren) – Firmenkontakt als Akteur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && business_verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $result = ticket_create($_POST, ['type' => 'company_contact', 'ref' => $contactId]);
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('portal_business.php') . '?view=tickets' . (!empty($result['id']) ? '&ticket=' . $result['id'] : ''));
        exit;
    }

    if ($action === 'ticket_comment') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $ticket = ticket_find($ticketId);
        if ($ticket && (int)($ticket['company_id'] ?? 0) === $companyId) {
            ticket_add_comment($ticketId, trim($_POST['body'] ?? ''), ['type' => 'company_contact', 'ref' => $contactId]);
            flash('success', 'Nachricht gesendet.');
        }
        header('Location: ' . url('portal_business.php') . '?view=tickets&ticket=' . $ticketId);
        exit;
    }
}

$section = $_GET['view'] ?? 'dashboard';
if (!in_array($section, ['dashboard', 'contacts', 'devices', 'projects', 'documents', 'tickets'], true)) {
    $section = 'dashboard';
}

$all_contacts = company_contacts_list($companyId);
$all_customers = company_customers($companyId);
$all_repairs   = company_repairs($companyId);
$all_projects  = projects_list_for_company($companyId);
$all_documents = company_documents_list($companyId);
$all_tickets   = tickets_list_for_company($companyId);

$open_ticket_id = (int)($_GET['ticket'] ?? 0);
$open_ticket = null;
$open_ticket_comments = [];
if ($section === 'tickets' && $open_ticket_id) {
    $t = ticket_find($open_ticket_id);
    if ($t && (int)($t['company_id'] ?? 0) === $companyId) {
        $open_ticket = $t;
        $open_ticket_comments = ticket_comments($open_ticket_id, false);
    }
}

$page_title = 'Firmenkundenportal';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($company['company_name'] ?? '') ?> – Firmenkundenportal</title>
  <link rel="icon" type="image/x-icon" href="<?= h(company_favicon_url()) ?>">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
  <?= company_color_css_override() ?>
</head>
<body>
<div style="max-width:1100px;margin:0 auto;padding:24px 20px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
    <div>
      <img src="<?= h(company_logo_url()) ?>" alt="<?= h($company_name_site) ?>" style="max-height:40px;">
    </div>
    <div style="text-align:right;">
      <div style="font-weight:600;"><?= h($company['company_name'] ?? '') ?></div>
      <div style="font-size:.82rem;color:#6B7280;">
        <?= h($contact['first_name'] . ' ' . $contact['last_name']) ?> ·
        <a href="?logout=1" style="color:#6B7280;">Abmelden</a>
      </div>
    </div>
  </div>

  <?php show_flash(); ?>

  <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php
    $tabs = [
        'dashboard' => 'Übersicht',
        'devices'   => 'Geräte & Aufträge',
        'projects'  => 'Projekte',
        'tickets'   => 'Tickets',
        'documents' => 'Dokumente',
        'contacts'  => 'Ansprechpartner',
    ];
    foreach ($tabs as $key => $label):
    ?>
      <a href="?view=<?= $key ?>" class="btn btn-sm <?= $section === $key ? 'btn-primary' : 'btn-outline' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($section === 'dashboard'): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
      <div class="card"><div class="card-body"><div style="font-size:1.8rem;font-weight:700;"><?= count($all_repairs) ?></div><div class="text-muted">Aufträge gesamt</div></div></div>
      <div class="card"><div class="card-body"><div style="font-size:1.8rem;font-weight:700;"><?= count(array_filter($all_repairs, fn($r) => repair_status_is_open($r['status']))) ?></div><div class="text-muted">Offene Aufträge</div></div></div>
      <div class="card"><div class="card-body"><div style="font-size:1.8rem;font-weight:700;"><?= count($all_projects) ?></div><div class="text-muted">Projekte</div></div></div>
      <div class="card"><div class="card-body"><div style="font-size:1.8rem;font-weight:700;"><?= count(array_filter($all_tickets, fn($t) => !in_array($t['status'], ['geloest','geschlossen'], true))) ?></div><div class="text-muted">Offene Tickets</div></div></div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Letzte Aufträge</h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Auftrag</th><th>Kunde</th><th>Status</th><th>Rechnung</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($all_repairs, 0, 8) as $r): ?>
              <tr>
                <td><code><?= h($r['repair_number']) ?></code></td>
                <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= repair_status_badge($r['status']) ?></td>
                <td><?= invoice_status_badge($r['invoice_status'] ?? 'entwurf') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <?php elseif ($section === 'devices'): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Geräte & Aufträge</h2></div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($all_repairs)): ?>
          <div class="empty-state"><p>Noch keine Aufträge vorhanden.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Auftrag</th><th>Kunde</th><th>Gerät</th><th>Status</th><th>Kostenvoranschlag</th><th>Rechnung</th></tr></thead>
              <tbody>
                <?php foreach ($all_repairs as $r): ?>
                <tr>
                  <td><code><?= h($r['repair_number']) ?></code></td>
                  <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
                  <td><?= h(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? '')) ?: h($r['device_type'] ?? '') ?></td>
                  <td><?= repair_status_badge($r['status']) ?></td>
                  <td><a href="<?= url('pdf/kostenvoranschlag.php') ?>?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline"><?= svg_icon('pdf', 14) ?> Ansehen</a></td>
                  <td>
                    <?php if (($r['invoice_status'] ?? 'entwurf') !== 'entwurf'): ?>
                      <a href="<?= url('pdf/rechnung.php') ?>?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline"><?= svg_icon('pdf', 14) ?> Ansehen</a>
                    <?php else: ?>
                      <span class="text-muted">–</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($section === 'projects'): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Projekte</h2></div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($all_projects)): ?>
          <div class="empty-state"><p>Noch keine Projekte vorhanden.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Nr.</th><th>Name</th><th>Status</th><th>Aufträge</th></tr></thead>
              <tbody>
                <?php foreach ($all_projects as $p): ?>
                <tr>
                  <td><code><?= h($p['project_number']) ?></code></td>
                  <td><?= h($p['name']) ?></td>
                  <td><?= h(ucfirst($p['status'])) ?></td>
                  <td><?= count(project_repairs((int)$p['id'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($section === 'documents'): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Dokumente</h2></div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($all_documents)): ?>
          <div class="empty-state"><p>Noch keine Dokumente hinterlegt.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Datei</th><th>Beschreibung</th><th>Datum</th></tr></thead>
              <tbody>
                <?php foreach ($all_documents as $d): ?>
                <tr>
                  <td><a href="<?= url('portal_business_document.php') ?>?id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['original_name']) ?></a></td>
                  <td><?= h($d['description'] ?? '') ?></td>
                  <td><?= fmt_date($d['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($section === 'contacts'): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Ansprechpartner Ihrer Firma</h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Funktion</th><th>E-Mail</th></tr></thead>
            <tbody>
              <?php foreach ($all_contacts as $c): ?>
              <tr>
                <td><?= h($c['first_name'] . ' ' . $c['last_name']) ?> <?= $c['is_primary'] ? '<span class="badge badge-blue">Primär</span>' : '' ?></td>
                <td><?= h($c['role_title'] ?? '') ?></td>
                <td><?= h($c['email']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="form-hint" style="padding:12px 16px;">Neue Ansprechpartner können über MZ Tech eingerichtet werden – bitte kontaktieren Sie uns.</p>
      </div>
    </div>

  <?php elseif ($section === 'tickets'): ?>
    <?php if ($open_ticket): ?>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header">
          <h2 class="card-title"><?= h($open_ticket['ticket_number']) ?> – <?= h($open_ticket['subject']) ?></h2>
          <a href="?view=tickets" class="btn btn-sm btn-outline"><?= svg_icon('arrow-left', 14) ?> Zurück</a>
        </div>
        <div class="card-body">
          <div style="margin-bottom:12px;"><?= ticket_status_badge($open_ticket['status']) ?> <?= ticket_priority_badge($open_ticket['priority']) ?></div>
          <?php foreach ($open_ticket_comments as $c): ?>
            <div style="border-bottom:1px solid var(--border,#e5e7eb);padding:10px 0;">
              <div style="font-size:.8rem;color:#6B7280;"><?= $c['author_type'] === 'staff' ? 'MZ Tech Support' : h($contact['first_name']) ?> · <?= fmt_date($c['created_at'], true) ?></div>
              <div style="white-space:pre-line;"><?= h($c['body']) ?></div>
            </div>
          <?php endforeach; ?>
          <form method="post" style="margin-top:16px;">
            <?= business_csrf_field() ?>
            <input type="hidden" name="action" value="ticket_comment">
            <input type="hidden" name="ticket_id" value="<?= (int)$open_ticket['id'] ?>">
            <div class="form-group"><textarea name="body" class="form-control" rows="3" required placeholder="Ihre Nachricht..."></textarea></div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('mail') ?> Senden</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2 class="card-title">Neues Ticket erstellen</h2></div>
        <div class="card-body">
          <form method="post">
            <?= business_csrf_field() ?>
            <input type="hidden" name="action" value="create_ticket">
            <div class="form-group"><label>Betreff *</label><input type="text" name="subject" class="form-control" required></div>
            <div class="form-group"><label>Beschreibung</label><textarea name="description" class="form-control" rows="3"></textarea></div>
            <div class="form-group">
              <label>Priorität</label>
              <select name="priority" class="form-control">
                <option value="normal">Normal</option><option value="niedrig">Niedrig</option>
                <option value="hoch">Hoch</option><option value="dringend">Dringend</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('plus') ?> Ticket erstellen</button>
          </form>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><h2 class="card-title">Ihre Tickets</h2></div>
        <div class="card-body" style="padding:0;">
          <?php if (empty($all_tickets)): ?>
            <div class="empty-state"><p>Noch keine Tickets vorhanden.</p></div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Nr.</th><th>Betreff</th><th>Status</th><th>Priorität</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($all_tickets as $t): ?>
                  <tr>
                    <td><code><?= h($t['ticket_number']) ?></code></td>
                    <td><?= h($t['subject']) ?></td>
                    <td><?= ticket_status_badge($t['status']) ?></td>
                    <td><?= ticket_priority_badge($t['priority']) ?></td>
                    <td><a href="?view=tickets&ticket=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye', 14) ?></a></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>
</body>
</html>
