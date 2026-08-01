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
require_once $private . '/portal_security.php';
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
    $incomingActivationToken = trim((string)$_GET['activate']);
    if (preg_match('/^[a-f0-9]{64}$/', $incomingActivationToken)) {
        $_SESSION['business_activation_token'] = $incomingActivationToken;
    } else {
        unset($_SESSION['business_activation_token']);
    }
    header('Location: ' . url('portal_business.php') . '?activate_form=1');
    exit;
} elseif (isset($_GET['activate_form'])) {
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
        $activate_result = company_contact_activate(
            (string)($_SESSION['business_activation_token'] ?? ''),
            $_POST['password'] ?? '',
            $_POST['password2'] ?? ''
        );
        if ($activate_result['success']) unset($_SESSION['business_activation_token']);
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
              <?php if (empty($_SESSION['business_activation_token'])): ?>
                <div class="alert alert-danger">Dieser Aktivierungslink ist nicht mehr gültig.</div>
              <?php else: ?>
              <p class="text-muted" style="margin-bottom:16px;">Bitte legen Sie Ihr persönliches Passwort für den Zugang zum Firmenkundenportal fest.</p>
              <form method="post">
                <input type="hidden" name="form" value="activate">
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
              <?php endif; ?>
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
business_require_login();
$companyId = business_current_company_id();
$contactId = business_current_contact_id();
$company   = company_find($companyId);
$contact   = company_contact_find($contactId);

// Aktionen (Ticket erstellen/kommentieren) – Firmenkontakt als Akteur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && business_verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket' && business_can('create_ticket')) {
        $ticketData = $_POST;
        $ticketData['company_id'] = $companyId;
        $ticketData['company_contact_id'] = $contactId;
        $result = ticket_create($ticketData, ['type' => 'company_contact', 'ref' => $contactId]);
        if ($result['success'] && !empty($_FILES['attachment']['name'])) {
            $saved = ticket_attachment_save($_FILES['attachment'], (int)$result['id']);
            if ($saved) {
                ticket_add_attachment((int)$result['id'], null, $saved);
            } else {
                $result['message'] .= ' Der Anhang wurde wegen Dateityp oder Größe nicht übernommen.';
            }
        }
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('portal_business.php') . '?view=tickets' . (!empty($result['id']) ? '&ticket=' . $result['id'] : ''));
        exit;
    }

    if ($action === 'ticket_comment' && business_can('reply_ticket')) {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $ticket = ticket_find($ticketId);
        if ($ticket && (int)($ticket['company_id'] ?? 0) === $companyId) {
            $commentId = ticket_add_comment($ticketId, trim($_POST['body'] ?? ''), ['type' => 'company_contact', 'ref' => $contactId]);
            if ($commentId && !empty($_FILES['attachment']['name'])) {
                $saved = ticket_attachment_save($_FILES['attachment'], $ticketId);
                if ($saved) ticket_add_attachment($ticketId, $commentId, $saved);
            }
            flash('success', 'Nachricht gesendet.');
        }
        header('Location: ' . url('portal_business.php') . '?view=tickets&ticket=' . $ticketId);
        exit;
    }

    if ($action === 'invite_contact' && business_can('manage_users')) {
        $_POST['is_primary'] = 0;
        $result = company_contact_create($companyId, $_POST);
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('portal_business.php') . '?view=contacts');
        exit;
    }

    if ($action === 'update_contact' && business_can('manage_users')) {
        $targetId = (int)($_POST['contact_id'] ?? 0);
        $target = company_contact_find($targetId);
        if ($target && (int)$target['company_id'] === $companyId && $targetId !== $contactId) {
            $role = in_array(($_POST['portal_role'] ?? ''), ['admin', 'employee', 'read_only'], true)
                ? $_POST['portal_role'] : 'employee';
            get_db()->prepare(
                'UPDATE company_contacts SET portal_role = ?, is_active = ? WHERE id = ? AND company_id = ?'
            )->execute([$role, !empty($_POST['is_active']) ? 1 : 0, $targetId, $companyId]);
            portal_audit('company_user_updated', 'company_contact', $contactId, 'company_contacts', $targetId, [
                'portal_role' => $role,
                'is_active' => !empty($_POST['is_active']),
            ]);
            flash('success', 'Firmenbenutzer wurde aktualisiert.');
        }
        header('Location: ' . url('portal_business.php') . '?view=contacts');
        exit;
    }

    if ($action === 'update_own_profile') {
        get_db()->prepare(
            'UPDATE company_contacts SET phone = ?, role_title = ? WHERE id = ? AND company_id = ?'
        )->execute([
            mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 50) ?: null,
            mb_substr(trim((string)($_POST['role_title'] ?? '')), 0, 120) ?: null,
            $contactId,
            $companyId,
        ]);
        portal_audit('business_profile_updated', 'company_contact', $contactId, 'company_contacts', $contactId);
        flash('success', 'Profil wurde aktualisiert.');
        header('Location: ' . url('portal_business.php') . '?view=profile');
        exit;
    }

    if ($action === 'change_own_password') {
        $result = company_contact_change_password(
            $contactId,
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['new_password2'] ?? '')
        );
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('portal_business.php') . '?view=profile');
        exit;
    }
}

$section = $_GET['view'] ?? 'dashboard';
if (!in_array($section, ['dashboard', 'contacts', 'devices', 'projects', 'documents', 'orders', 'tickets', 'profile'], true)) {
    $section = 'dashboard';
}

$all_contacts = company_contacts_list($companyId);
$all_customers = company_customers($companyId);
$all_repairs   = company_repairs($companyId);
$all_projects  = projects_list_for_company($companyId);
$all_documents = company_documents_list($companyId);
$all_quotes = [];
try {
    $stmt=$db->prepare('SELECT id,quote_number,status,title,valid_until,total,currency FROM quotes WHERE company_id=? AND status<>"entwurf" ORDER BY created_at DESC');
    $stmt->execute([$companyId]);
    $all_quotes=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $all_quotes=[];
}
$all_tickets   = tickets_list_for_company($companyId);
$all_orders = [];
try {
    $stmt = $db->prepare(
        'SELECT DISTINCT po.id, po.order_number, po.status, po.ordered_at, po.created_at, s.name AS supplier_name
         FROM purchase_orders po
         JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
         LEFT JOIN suppliers s ON s.id = po.supplier_id
         LEFT JOIN projects p ON p.id = poi.assigned_project_id
         WHERE poi.assigned_company_id = ? OR p.company_id = ?
         ORDER BY po.created_at DESC'
    );
    $stmt->execute([$companyId, $companyId]);
    $all_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $all_orders = [];
}

$open_ticket_id = (int)($_GET['ticket'] ?? 0);
$open_ticket = null;
$open_ticket_comments = [];
$open_ticket_attachments = [];
$open_ticket_links = [];
if ($section === 'tickets' && $open_ticket_id) {
    $t = ticket_find($open_ticket_id);
    if ($t && (int)($t['company_id'] ?? 0) === $companyId) {
        $open_ticket = $t;
        $open_ticket_comments = ticket_comments($open_ticket_id, false);
        $open_ticket_links = ticket_links($open_ticket_id, true);
        foreach (ticket_attachments($open_ticket_id) as $attachment) {
            $open_ticket_attachments[(int)($attachment['comment_id'] ?? 0)][] = $attachment;
        }
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
  <style>
    .business-portal-shell .table-wrap { -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
    .business-portal-shell .table-wrap table { min-width:680px; }
    @media (max-width:600px) {
      .business-portal-shell { padding:16px 12px 40px !important; }
      .business-portal-shell .table-wrap::before { content:'Tabelle seitlich wischen, um alle Angaben zu sehen.'; display:block; position:sticky; left:0; width:max-content; max-width:calc(100vw - 48px); padding:7px 10px; color:#6B7280; font-size:.75rem; background:#F9FAFB; }
      .business-portal-shell .table-wrap table { min-width:720px; }
    }
  </style>
</head>
<body>
<div class="business-portal-shell" style="max-width:1100px;margin:0 auto;padding:24px 20px;">
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
        'orders'    => 'Bestellungen',
        'contacts'  => 'Ansprechpartner',
        'profile'   => 'Profil',
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
                  <td><?= h(trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? '')) ?: device_type_label((string)($r['device_type'] ?? ''))) ?></td>
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
    <?php if ($all_quotes): ?><div class="card" style="margin-top:1rem"><div class="card-header"><h2 class="card-title">Angebote</h2></div><div class="card-body" style="padding:0"><div class="table-wrap"><table><thead><tr><th>Nummer</th><th>Titel</th><th>Status</th><th>Gesamt</th><th></th></tr></thead><tbody><?php foreach($all_quotes as $offer): ?><tr><td><?= h($offer['quote_number'] ?? ('#'.$offer['id'])) ?></td><td><?= h($offer['title'] ?? 'Angebot') ?></td><td><?= h($offer['status']) ?></td><td><?= h(fmt_money((float)$offer['total'])) ?></td><td><a class="btn btn-sm btn-outline" target="_blank" href="<?= url('pdf/angebot.php') ?>?id=<?= (int)$offer['id'] ?>"><?= svg_icon('pdf',14) ?> PDF</a></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>

  <?php elseif ($section === 'orders'): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Relevante Bestellungen</h2></div>
      <div class="card-body" style="padding:0;">
        <?php if (!$all_orders): ?><div class="empty-state"><p>Keine zugeordneten Bestellungen vorhanden.</p></div>
        <?php else: ?><div class="table-wrap"><table>
          <thead><tr><th>Nummer</th><th>Lieferant</th><th>Status</th><th>Bestellt am</th></tr></thead>
          <tbody><?php foreach ($all_orders as $order): ?><tr>
            <td><?= h($order['order_number'] ?: 'Entwurf #'.$order['id']) ?></td>
            <td><?= h($order['supplier_name'] ?? '–') ?></td>
            <td><?= h($order['status']) ?></td>
            <td><?= h($order['ordered_at'] ? fmt_date($order['ordered_at'], true) : '–') ?></td>
          </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
      </div>
    </div>

  <?php elseif ($section === 'contacts'): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Ansprechpartner Ihrer Firma</h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Funktion</th><th>E-Mail</th><th>Portalrolle</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($all_contacts as $c): ?>
              <tr>
                <td><?= h($c['first_name'] . ' ' . $c['last_name']) ?> <?= $c['is_primary'] ? '<span class="badge badge-blue">Primär</span>' : '' ?></td>
                <td><?= h($c['role_title'] ?? '') ?></td>
                <td><?= h($c['email']) ?></td>
                <td><?= h(['admin'=>'Firmenadministrator','employee'=>'Firmenmitarbeiter','read_only'=>'Nur Lesen'][$c['portal_role'] ?? 'employee'] ?? 'Firmenmitarbeiter') ?></td>
                <td>
                  <?php if (business_can('manage_users') && (int)$c['id'] !== $contactId): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;">
                      <?= business_csrf_field() ?>
                      <input type="hidden" name="action" value="update_contact">
                      <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                      <select name="portal_role" class="form-control">
                        <option value="admin" <?= ($c['portal_role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="employee" <?= ($c['portal_role'] ?? 'employee') === 'employee' ? 'selected' : '' ?>>Mitarbeiter</option>
                        <option value="read_only" <?= ($c['portal_role'] ?? '') === 'read_only' ? 'selected' : '' ?>>Nur Lesen</option>
                      </select>
                      <label><input type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>> aktiv</label>
                      <button class="btn btn-sm btn-outline" type="submit">Speichern</button>
                    </form>
                  <?php else: ?>
                    <?= $c['is_active'] ? 'Aktiv' : 'Deaktiviert' ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (business_can('manage_users')): ?>
          <form method="post" style="padding:16px;border-top:1px solid #e5e7eb;">
            <?= business_csrf_field() ?>
            <input type="hidden" name="action" value="invite_contact">
            <h3>Firmenbenutzer einladen</h3>
            <div class="form-grid">
              <div class="form-group"><label>Vorname *</label><input name="first_name" class="form-control" required></div>
              <div class="form-group"><label>Nachname *</label><input name="last_name" class="form-control" required></div>
              <div class="form-group"><label>E-Mail *</label><input type="email" name="email" class="form-control" required></div>
              <div class="form-group"><label>Funktion</label><input name="role_title" class="form-control"></div>
              <div class="form-group"><label>Portalrolle</label>
                <select name="portal_role" class="form-control">
                  <option value="employee">Firmenmitarbeiter</option>
                  <option value="read_only">Nur Lesen</option>
                  <option value="admin">Firmenadministrator</option>
                </select>
              </div>
            </div>
            <button type="submit" class="btn btn-primary">Einladung anlegen</button>
          </form>
        <?php else: ?>
          <p class="form-hint" style="padding:12px 16px;">Nur Firmenadministratoren können Zugänge verwalten.</p>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($section === 'profile'): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Mein Profil</h2></div>
      <div class="card-body">
        <form method="post">
          <?= business_csrf_field() ?><input type="hidden" name="action" value="update_own_profile">
          <div class="form-grid">
            <div class="form-group"><label>Name</label><input class="form-control" readonly value="<?= h($contact['first_name'].' '.$contact['last_name']) ?>"></div>
            <div class="form-group"><label>E-Mail</label><input class="form-control" readonly value="<?= h($contact['email']) ?>"></div>
            <div class="form-group"><label>Telefon</label><input name="phone" class="form-control" value="<?= h($contact['phone'] ?? '') ?>"></div>
            <div class="form-group"><label>Funktion</label><input name="role_title" class="form-control" value="<?= h($contact['role_title'] ?? '') ?>"></div>
          </div>
          <button class="btn btn-primary" type="submit">Profil speichern</button>
        </form>
        <hr style="margin:24px 0;">
        <form method="post">
          <?= business_csrf_field() ?><input type="hidden" name="action" value="change_own_password">
          <h3>Passwort ändern</h3>
          <div class="form-grid">
            <div class="form-group"><label>Aktuelles Passwort</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
            <div class="form-group"><label>Neues Passwort</label><input type="password" name="new_password" class="form-control" minlength="8" required autocomplete="new-password"></div>
            <div class="form-group"><label>Neues Passwort wiederholen</label><input type="password" name="new_password2" class="form-control" minlength="8" required autocomplete="new-password"></div>
          </div>
          <button class="btn btn-outline" type="submit">Passwort ändern</button>
        </form>
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
          <?php if (!empty($open_ticket['project_id'])): ?>
            <p><strong>Projekt:</strong>
              <?= h(trim(($open_ticket['project_number'] ?? '') . ' – ' . ($open_ticket['project_name'] ?? ''), " –")) ?>
            </p>
          <?php endif; ?>
          <?php foreach ($open_ticket_links as $link): ?>
            <span class="badge badge-gray"><?= h($link['link_type']) ?> #<?= (int)$link['link_id'] ?></span>
          <?php endforeach; ?>
          <?php foreach ($open_ticket_attachments[0] ?? [] as $attachment): ?>
            <p><a href="<?= url('portal_ticket_attachment.php') ?>?portal=business&amp;id=<?= (int)$attachment['id'] ?>">
              <?= svg_icon('download', 14) ?> <?= h($attachment['original_name']) ?>
            </a></p>
          <?php endforeach; ?>
          <?php foreach ($open_ticket_comments as $c): ?>
            <div style="border-bottom:1px solid var(--border,#e5e7eb);padding:10px 0;">
              <div style="font-size:.8rem;color:#6B7280;"><?= $c['author_type'] === 'staff' ? 'MZ Tech Support' : h($contact['first_name']) ?> · <?= fmt_date($c['created_at'], true) ?></div>
              <div style="white-space:pre-line;"><?= h($c['body']) ?></div>
              <?php foreach ($open_ticket_attachments[(int)$c['id']] ?? [] as $attachment): ?>
                <a href="<?= url('portal_ticket_attachment.php') ?>?portal=business&amp;id=<?= (int)$attachment['id'] ?>">
                  <?= svg_icon('download', 14) ?> <?= h($attachment['original_name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <?php if (business_can('reply_ticket')): ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
            <?= business_csrf_field() ?>
            <input type="hidden" name="action" value="ticket_comment">
            <input type="hidden" name="ticket_id" value="<?= (int)$open_ticket['id'] ?>">
            <div class="form-group"><textarea name="body" class="form-control" rows="3" required placeholder="Ihre Nachricht..."></textarea></div>
            <div class="form-group"><label>Anhang (optional)</label><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx"></div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('mail') ?> Senden</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <?php if (business_can('create_ticket')): ?>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2 class="card-title">Neues Ticket erstellen</h2></div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
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
            <div class="form-grid">
              <div class="form-group"><label>Kategorie</label>
                <select name="category" class="form-control">
                  <option value="support">Support</option><option value="reparatur">Reparatur</option>
                  <option value="rechnung">Rechnung</option><option value="sonstiges">Sonstiges</option>
                </select>
              </div>
              <div class="form-group"><label>Projekt</label>
                <select name="project_id" class="form-control">
                  <option value="">Ohne Projekt</option>
                  <?php foreach ($all_projects as $project): if (($project['status'] ?? '') !== 'aktiv') continue; ?>
                    <option value="<?= (int)$project['id'] ?>"><?= h($project['project_number'] . ' – ' . $project['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group"><label>Bevorzugter Kontaktweg</label>
                <select name="preferred_contact" class="form-control">
                  <option value="">Keine Angabe</option><option value="email">E-Mail</option><option value="telefon">Telefon</option>
                </select>
              </div>
              <div class="form-group"><label>Wunschtermin</label><input type="datetime-local" name="preferred_date" class="form-control"></div>
              <div class="form-group"><label>Standort</label><input type="text" name="location" class="form-control" maxlength="255"></div>
              <div class="form-group"><label>Interne Referenznummer</label><input type="text" name="customer_reference" class="form-control" maxlength="100"></div>
            </div>
            <div class="form-group"><label>Neues Projekt anfragen (optional)</label><textarea name="project_request_text" class="form-control" rows="2"></textarea></div>
            <div class="form-group"><label>Anhang (optional)</label><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx"></div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('plus') ?> Ticket erstellen</button>
          </form>
        </div>
      </div>
      <?php endif; ?>
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
