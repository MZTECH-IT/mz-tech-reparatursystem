<?php
/**
 * MZ Tech – Kundenportal
 *
 * Passwortloser Zugang für Kunden zu ihren eigenen Reparaturaufträgen:
 * per Zugangslink (?t=TOKEN, z. B. aus einer Status-E-Mail oder einem
 * QR-Code) oder alternativ manuell per Auftragsnummer + PIN.
 *
 * WICHTIG (Datenisolation): Jede Datenbankabfrage in dieser Datei filtert
 * zwingend nach portal_current_customer_id() – niemals nach einer vom
 * Client übergebenen ID. So kann ein Kunde ausschließlich seine eigenen
 * Daten sehen (Schutz vor IDOR / Datenlecks zwischen Kunden).
 */

$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/portal_security.php';
require_once $private . '/portal_auth.php';
require_once $private . '/customer_auth.php';
require_once $private . '/mailer.php';
require_once __DIR__ . '/includes/icons.php';

start_portal_session();
$db = get_db();

$company_name = get_setting('company_name', 'MZ Tech');

// ── Ansicht des Login-/Registrierungsbereichs (nur relevant, solange
//    nicht angemeldet) – per ?view= steuerbar, Whitelist gegen beliebige
//    Werte (kein offener Redirect/Reflected-XSS möglich). ──────────
$view = $_GET['view'] ?? 'guest';
if (!in_array($view, ['guest', 'account_login', 'register', 'forgot', 'reset_password', 'activate'], true)) {
    $view = 'guest';
}
$reset_token   = '';
$register_result = null;
$forgot_result    = null;
$reset_result     = null;
$activation_result = null;
$change_pw_result = null;

// ── Portal global deaktiviert? ─────────────────────────
if (!portal_enabled()) {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Kundenportal – <?= h($company_name) ?></title>
        <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    </head>
    <body style="background:#f5f7fa;">
        <div style="max-width:520px;margin:80px auto;text-align:center;padding:0 20px;">
            <h1 style="color:#0057B8;"><?= h($company_name) ?></h1>
            <div class="alert alert-warning" style="margin-top:24px;">
                Das Kundenportal ist derzeit leider nicht verfügbar. Bitte kontaktieren Sie uns direkt.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$login_error = null;

// ── Zugangslink (Magic-Link) verarbeiten ───────────────
$token = trim($_GET['t'] ?? '');
if ($token !== '') {
    if (portal_is_logged_in()) {
        // Bereits angemeldet – Token aus der URL entfernen und Dashboard zeigen
        header('Location: ' . url('portal.php'));
        exit;
    }
    $result = portal_attempt_token_login($token);
    if ($result['success']) {
        header('Location: ' . url('portal.php'));
        exit;
    }
    $login_error = $result['message'];
}

// ── E-Mail-Bestätigung für ein registriertes Kundenkonto ───────
if (isset($_GET['verify']) && $_GET['verify'] !== '') {
    $activationToken = trim((string)$_GET['verify']);
    if (preg_match('/^[a-f0-9]{64}$/', $activationToken)) {
        $_SESSION['portal_activation_token'] = $activationToken;
    } else {
        unset($_SESSION['portal_activation_token']);
    }
    header('Location: ' . url('portal.php') . '?view=activate');
    exit;
}

// ── Passwort-Reset-Link geöffnet: Formular für neues Passwort ──
if (isset($_GET['reset']) && $_GET['reset'] !== '') {
    $reset_token = trim($_GET['reset']);
    $view = 'reset_password';
}

// ── Logout ──────────────────────────────────────────────
if (isset($_GET['logout'])) {
    portal_logout();
    header('Location: ' . url('portal.php'));
    exit;
}

// ── POST-Aktionen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'activate_account') {
        $view = 'activate';
        if (!portal_verify_csrf()) {
            $activation_result = ['success' => false, 'message' => 'Ungültige Anfrage. Bitte öffnen Sie den Aktivierungslink erneut.'];
        } else {
            $activationToken = (string)($_SESSION['portal_activation_token'] ?? '');
            unset($_SESSION['portal_activation_token']);
            $activation_result = customer_verify_email($activationToken);
        }
    } elseif ($post_action === 'login') {
        if (!portal_verify_csrf()) {
            $login_error = 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte erneut versuchen.';
        } else {
            $result = portal_attempt_login($_POST['repair_number'] ?? '', $_POST['pin'] ?? '');
            if ($result['success']) {
                header('Location: ' . url('portal.php'));
                exit;
            }
            $login_error = $result['message'];
        }

    } elseif ($post_action === 'register') {
        $view = 'register';
        if (!portal_verify_csrf()) {
            $register_result = ['success' => false, 'message' => 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte erneut versuchen.'];
        } else {
            $register_result = customer_register($_POST);
        }

    } elseif ($post_action === 'account_login') {
        $view = 'account_login';
        if (!portal_verify_csrf()) {
            $login_error = 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte erneut versuchen.';
        } else {
            $result = customer_attempt_login($_POST['email'] ?? '', $_POST['password'] ?? '');
            if ($result['success']) {
                header('Location: ' . url('portal.php'));
                exit;
            }
            $login_error = $result['message'];
        }

    } elseif ($post_action === 'request_reset') {
        $view = 'forgot';
        if (!portal_verify_csrf()) {
            $forgot_result = ['success' => false, 'message' => 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte erneut versuchen.'];
        } else {
            $forgot_result = customer_request_password_reset($_POST['email'] ?? '');
        }

    } elseif ($post_action === 'reset_password') {
        $view = 'reset_password';
        $reset_token = trim($_POST['token'] ?? '');
        if (!portal_verify_csrf()) {
            $reset_result = ['success' => false, 'message' => 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte erneut versuchen.'];
        } else {
            $reset_result = customer_reset_password($reset_token, $_POST['password'] ?? '', $_POST['password2'] ?? '');
            if ($reset_result['success']) {
                header('Location: ' . url('portal.php') . '?view=account_login&ok=password_reset');
                exit;
            }
        }

    } elseif ($post_action === 'change_password' && portal_is_logged_in() && portal_is_registered_account()) {
        if (!portal_verify_csrf()) {
            $change_pw_result = ['success' => false, 'message' => 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte erneut versuchen.'];
        } else {
            $change_pw_result = customer_change_password(
                (int)portal_current_account_id(),
                $_POST['current_password'] ?? '',
                $_POST['new_password'] ?? '',
                $_POST['new_password2'] ?? ''
            );
        }

    } elseif ($post_action === 'update_profile' && portal_is_logged_in() && portal_is_registered_account()) {
        if (portal_verify_csrf()) {
            $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 50);
            $address = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255);
            $zip = mb_substr(trim((string)($_POST['zip'] ?? '')), 0, 20);
            $city = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 100);
            $db->prepare(
                'UPDATE customers SET phone = ?, address = ?, zip = ?, city = ? WHERE id = ?'
            )->execute([
                $phone ?: null, $address ?: null, $zip ?: null, $city ?: null,
                portal_current_customer_id(),
            ]);
            portal_audit('customer_profile_updated', 'customer', portal_current_customer_id(), 'customers', portal_current_customer_id());
            flash('success', 'Profil wurde aktualisiert.');
        }
        header('Location: ' . url('portal.php') . '#profile');
        exit;

    } elseif ($post_action === 'send_message' && portal_is_logged_in() && portal_is_registered_account()) {
        if (portal_verify_csrf()) {
            $msg_text = trim($_POST['message'] ?? '');
            $msg_repair_id = intval($_POST['repair_id'] ?? 0);
            if ($msg_text !== '' && mb_strlen($msg_text) <= 2000) {
                // Falls eine Reparatur angegeben wurde: zwingend Eigentümerprüfung
                // gegen den angemeldeten Kunden (IDOR-Schutz).
                $safe_repair_id = null;
                if ($msg_repair_id > 0) {
                    $chk = $db->prepare('SELECT id FROM repairs WHERE id = ? AND customer_id = ? LIMIT 1');
                    $chk->execute([$msg_repair_id, portal_current_customer_id()]);
                    if ($chk->fetch()) {
                        $safe_repair_id = $msg_repair_id;
                    }
                }
                $db->prepare(
                    'INSERT INTO customer_messages (customer_id, repair_id, sender, message) VALUES (?, ?, ?, ?)'
                )->execute([portal_current_customer_id(), $safe_repair_id, 'customer', $msg_text]);
                log_activity('portal_send_message', 'customer_messages', portal_current_customer_id(), 'Neue Nachricht im Kundenportal gesendet');
            }
        }
        header('Location: ' . url('portal.php') . '?ok=message_sent#messages');
        exit;

    } elseif ($post_action === 'gdpr_consent' && portal_is_logged_in()) {
        if (portal_verify_csrf()) {
            $db->prepare('UPDATE customers SET gdpr_consent = 1, gdpr_date = NOW() WHERE id = ?')
               ->execute([portal_current_customer_id()]);
            log_activity('portal_gdpr_consent', 'customers', portal_current_customer_id(), 'Datenschutz-Einwilligung im Kundenportal erteilt');
        }
        header('Location: ' . url('portal.php'));
        exit;

    } elseif (in_array($post_action, ['approve_quote', 'reject_quote'], true) && portal_is_logged_in()) {
        if (!portal_verify_csrf()) {
            header('Location: ' . url('portal.php') . '?err=csrf');
            exit;
        }

        $repair_id = intval($_POST['repair_id'] ?? 0);

        // Zwingend zusätzlich nach customer_id filtern – so kann ein Kunde
        // niemals über eine fremde repair_id einen fremden Auftrag ändern.
        $stmt = $db->prepare(
            'SELECT r.*, c.first_name, c.last_name, c.email
             FROM repairs r JOIN customers c ON r.customer_id = c.id
             WHERE r.id = ? AND r.customer_id = ? LIMIT 1'
        );
        $stmt->execute([$repair_id, portal_current_customer_id()]);
        $repair_row = $stmt->fetch();

        if (!$repair_row) {
            header('Location: ' . url('portal.php') . '?err=notfound');
            exit;
        }

        $current_status = repair_status_normalize($repair_row['status']);
        if (!in_array($current_status, ['kostenvoranschlag', 'freigabe_ausstehend'], true)) {
            header('Location: ' . url('portal.php') . '?err=state');
            exit;
        }

        $approve   = $post_action === 'approve_quote';
        $new_status = $approve ? 'ersatzteil_bestellt' : 'storniert';
        $note = $approve
            ? 'Kostenvoranschlag durch Kunde im Kundenportal freigegeben.'
            : 'Kostenvoranschlag durch Kunde im Kundenportal abgelehnt.';

        $db->prepare('UPDATE repairs SET status = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$new_status, $repair_id]);

        try {
            $db->prepare(
                'INSERT INTO repair_status_history (repair_id, status, note, user_id, created_at)
                 VALUES (?, ?, ?, NULL, NOW())'
            )->execute([$repair_id, $new_status, $note]);
        } catch (PDOException $e) {
            error_log('portal.php: repair_status_history insert failed: ' . $e->getMessage());
        }

        try {
            if (!empty($repair_row['email'])) {
                send_repair_status_email($repair_row, [
                    'first_name' => $repair_row['first_name'],
                    'last_name'  => $repair_row['last_name'],
                    'email'      => $repair_row['email'],
                ], $new_status);
            }
        } catch (Throwable $e) {
            error_log('portal.php: Statusmail fehlgeschlagen: ' . $e->getMessage());
        }

        log_activity('portal_' . $post_action, 'repairs', $repair_id, $note);

        header('Location: ' . url('portal.php') . '?ok=' . ($approve ? 'approved' : 'rejected'));
        exit;

    } elseif ($post_action === 'confirm_invoice' && portal_is_logged_in()) {
        // Phase 2: Rechnungsfreigabe – Kunden-Bestätigung einer bereits durch
        // einen Mitarbeiter freigegebenen Rechnung (siehe repairs_view.php).
        if (!portal_verify_csrf()) {
            header('Location: ' . url('portal.php') . '?err=csrf');
            exit;
        }

        $repair_id = intval($_POST['repair_id'] ?? 0);

        // Zwingend zusätzlich nach customer_id filtern (IDOR-Schutz).
        $stmt = $db->prepare(
            'SELECT r.* FROM repairs r WHERE r.id = ? AND r.customer_id = ? LIMIT 1'
        );
        $stmt->execute([$repair_id, portal_current_customer_id()]);
        $inv_repair = $stmt->fetch();

        if (!$inv_repair) {
            header('Location: ' . url('portal.php') . '?err=notfound');
            exit;
        }

        if (($inv_repair['invoice_status'] ?? 'entwurf') !== 'freigegeben') {
            header('Location: ' . url('portal.php') . '?err=state');
            exit;
        }

        $db->prepare('UPDATE repairs SET invoice_status = ?, invoice_customer_confirmed_at = NOW() WHERE id = ?')
           ->execute(['kunde_bestaetigt', $repair_id]);

        log_activity('portal_confirm_invoice', 'repairs', $repair_id, 'Rechnung ' . ($inv_repair['invoice_number'] ?? '') . ' vom Kunden im Kundenportal bestätigt.');

        header('Location: ' . url('portal.php') . '?ok=invoice_confirmed');
        exit;

    } elseif ($post_action === 'upload_photo' && portal_is_logged_in()) {
        if (!portal_verify_csrf()) {
            header('Location: ' . url('portal.php') . '?err=csrf#repairs');
            exit;
        }

        $repair_id = intval($_POST['repair_id'] ?? 0);

        // Zwingend zusätzlich nach customer_id filtern (IDOR-Schutz) – ein
        // Kunde darf niemals über eine fremde repair_id Fotos zu einem
        // fremden Auftrag hochladen.
        $own_stmt = $db->prepare('SELECT id FROM repairs WHERE id = ? AND customer_id = ? LIMIT 1');
        $own_stmt->execute([$repair_id, portal_current_customer_id()]);
        if (!$own_stmt->fetch()) {
            header('Location: ' . url('portal.php') . '?err=notfound');
            exit;
        }

        $uploaded = normalize_multi_upload('photos');
        if (empty($uploaded)) {
            header('Location: ' . url('portal.php') . '?err=nofile#repair-' . $repair_id);
            exit;
        }
        if (count($uploaded) > MAX_PHOTOS_PER_UPLOAD) {
            header('Location: ' . url('portal.php') . '?err=toomany#repair-' . $repair_id);
            exit;
        }

        // Alle Dateien zunächst validieren – erst bei vollständig gültiger
        // Auswahl wird überhaupt etwas gespeichert.
        foreach ($uploaded as $pf) {
            if (validate_photo_upload($pf) !== null) {
                header('Location: ' . url('portal.php') . '?err=invalidfile#repair-' . $repair_id);
                exit;
            }
        }

        $photo_stmt = $db->prepare(
            'INSERT INTO repair_photos (repair_id, filename, original_name, photo_type, description, file_size, uploaded_by, source, created_at)
             VALUES (?, ?, ?, "sonstiges", "Vom Kunden im Kundenportal hochgeladen", ?, NULL, "customer", NOW())'
        );
        $saved_any = false;
        foreach ($uploaded as $pf) {
            $result = save_upload($pf, $repair_id);
            if ($result !== false) {
                $photo_stmt->execute([$repair_id, $result['filename'], $result['original_name'], $result['file_size']]);
                $saved_any = true;
            }
        }

        if ($saved_any) {
            log_activity('portal_upload_photo', 'repairs', $repair_id, 'Kunde hat im Kundenportal Foto(s) zum Auftrag hochgeladen.');
        }

        header('Location: ' . url('portal.php') . '?ok=' . ($saved_any ? 'photo_uploaded' : 'photo_failed') . '#repair-' . $repair_id);
        exit;
    }
}

// ── Daten für den Dashboard-Bereich laden ──────────────
$customer   = null;
$repairs    = [];
$appointments = [];
$messages   = [];

if (portal_is_logged_in()) {
    $customer_id = portal_current_customer_id();

    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        // Kunde wurde zwischenzeitlich gelöscht – Sitzung beenden
        portal_logout();
        header('Location: ' . url('portal.php'));
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM repairs WHERE customer_id = ? ORDER BY created_at DESC');
    $stmt->execute([$customer_id]);
    $repairs = $stmt->fetchAll();

    $hist_stmt  = $db->prepare('SELECT * FROM repair_status_history WHERE repair_id = ? ORDER BY created_at ASC');
    $photo_stmt = $db->prepare('SELECT * FROM repair_photos WHERE repair_id = ? ORDER BY created_at ASC');
    foreach ($repairs as &$r) {
        $hist_stmt->execute([$r['id']]);
        $r['status_history'] = $hist_stmt->fetchAll();

        $photo_stmt->execute([$r['id']]);
        $r['photos'] = $photo_stmt->fetchAll();
    }
    unset($r);

    $stmt = $db->prepare(
        'SELECT * FROM appointments
         WHERE customer_id = ? AND start_datetime >= NOW()
         ORDER BY start_datetime ASC'
    );
    $stmt->execute([$customer_id]);
    $appointments = $stmt->fetchAll();

    // Nachrichten (nur für registrierte Konten sichtbar/nutzbar) –
    // Zugriff ausschließlich über portal_current_customer_id() (IDOR-Schutz).
    if (portal_is_registered_account()) {
        $stmt = $db->prepare(
            'SELECT m.*, r.repair_number FROM customer_messages m
             LEFT JOIN repairs r ON r.id = m.repair_id
             WHERE m.customer_id = ? ORDER BY m.created_at ASC'
        );
        $stmt->execute([$customer_id]);
        $messages = $stmt->fetchAll();

        // Ungelesene Nachrichten vom Mitarbeiter als gelesen markieren,
        // sobald der Kunde das Portal aufruft.
        $db->prepare("UPDATE customer_messages SET is_read = 1 WHERE customer_id = ? AND sender = 'staff' AND is_read = 0")
           ->execute([$customer_id]);
    }
}

$csrf = portal_is_logged_in() ? portal_csrf_token() : portal_csrf_token();
$ok_msg  = $_GET['ok']  ?? '';
$err_msg = $_GET['err'] ?? '';

$appointment_types = [
    'eingang'   => 'Geräteannahme',
    'reparatur' => 'Reparaturtermin',
    'abholung'  => 'Abholung',
    'sonstiges' => 'Termin',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kundenportal – <?= h($company_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
  <script>window.APP_URL_BASE = <?= json_encode(APP_URL_BASE, JSON_UNESCAPED_SLASHES) ?>;</script>
  <style>
    body { background: var(--bg-body, #f5f7fa); }
    .public-page { max-width: 860px; margin: 0 auto; padding: 32px 20px 60px; }
    .public-header { text-align:center; margin-bottom: 28px; position:relative; }
    .public-header .brand { font-size:1.6rem; font-weight:800; color:#0057B8; letter-spacing:-.02em; }
    .public-header .sub { color:#6B7280; font-size:.9rem; margin-top:6px; }
    .logout-link { position:absolute; right:0; top:6px; font-size:.82rem; color:#6B7280; text-decoration:none; display:flex; align-items:center; gap:4px; }
    .logout-link:hover { color:#0057B8; }
    .req { color:#DC2626; }
    fieldset.form-section { border:1px solid var(--border-color,#e5e7eb); border-radius:8px; padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
    fieldset.form-section legend { font-weight:600; padding:0 .5rem; font-size:.95rem; }
    .privacy-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:14px 16px; font-size:.82rem; color:#4B5563; margin-bottom:16px; max-height:160px; overflow-y:auto; }
    .greeting { font-size:1.15rem; font-weight:700; margin-bottom:4px; }
    .repair-card { margin-bottom: 1.25rem; }
    .repair-head { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px; }
    .repair-title { font-weight:700; font-size:1.02rem; }
    .repair-sub { color:#6B7280; font-size:.85rem; margin-top:2px; }
    .repair-meta { display:flex; flex-wrap:wrap; gap:16px; margin: 12px 0; font-size:.85rem; }
    .repair-meta dt { color:#6B7280; font-weight:600; }
    .repair-meta dd { margin:0; }
    .quote-box { background:#FFF7ED; border:1px solid #FDBA74; border-radius:8px; padding:14px 16px; margin-top:14px; }
    .quote-actions { display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }
    .timeline { list-style: none; padding: 0; margin: 14px 0 0; }
    .timeline-item { display:flex; align-items:flex-start; gap:.75rem; padding:.45rem 0; position:relative; }
    .timeline-item:not(:last-child)::before { content:''; position:absolute; left:7px; top:26px; bottom:-6px; width:2px; background:var(--border-color,#e5e7eb); }
    .timeline-dot { width:16px; height:16px; border-radius:50%; background:var(--border-color,#e5e7eb); flex-shrink:0; margin-top:3px; border:2px solid var(--bg-card,#fff); box-shadow:0 0 0 2px var(--border-color,#e5e7eb); }
    .timeline-item--current .timeline-dot { background:#0057B8; box-shadow:0 0 0 2px #bfdbfe; }
    .timeline-content { display:flex; align-items:center; flex-wrap:wrap; gap:.35rem; }
    .timeline-note { width:100%; font-size:.8rem; color:#6B7280; margin-top:.2rem; }
    .access-box { display:flex; align-items:center; gap:10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 14px; font-size:.82rem; color:#4B5563; }
    .access-box input { flex:1; border:none; background:transparent; font-size:.82rem; color:#374151; }
    .empty-state { text-align:center; padding:40px 20px; color:#6B7280; }
    .appt-item { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #f0f2f5; font-size:.88rem; }
    .appt-item:last-child { border-bottom:none; }
    .portal-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; justify-content:center; }
    .portal-tabs a { padding:7px 14px; border-radius:20px; font-size:.83rem; text-decoration:none; color:#4B5563; background:#f0f2f5; }
    .portal-tabs a.active { background:#0057B8; color:#fff; font-weight:600; }
    .form-help-link { font-size:.82rem; text-align:right; margin-top:-6px; }
    .form-help-link a { color:#0057B8; text-decoration:none; }
    .msg-thread { max-height:340px; overflow-y:auto; display:flex; flex-direction:column; gap:10px; padding:4px 2px; }
    .msg-bubble { max-width:78%; padding:9px 13px; border-radius:12px; font-size:.87rem; line-height:1.4; }
    .msg-bubble.customer { align-self:flex-end; background:#0057B8; color:#fff; border-bottom-right-radius:3px; }
    .msg-bubble.staff { align-self:flex-start; background:#f0f2f5; color:#1f2937; border-bottom-left-radius:3px; }
    .msg-meta { font-size:.7rem; opacity:.75; margin-top:4px; }
    .msg-empty { text-align:center; color:#9CA3AF; font-size:.85rem; padding:20px 0; }
  </style>
</head>
<body>
<div class="public-page">

  <div class="public-header">
    <?php if (portal_is_logged_in()): ?>
      <a href="<?= url('portal_tickets.php') ?>" class="logout-link" style="right:110px;"><?= svg_icon('message-circle', 15) ?> Tickets</a>
      <a href="<?= url('portal.php') ?>?logout=1" class="logout-link"><?= svg_icon('logout', 15) ?> Abmelden</a>
    <?php endif; ?>
    <svg xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 60 60" style="margin:0 auto 10px;">
      <rect width="60" height="60" rx="14" fill="#0057B8"/>
      <text x="30" y="42" font-family="Arial" font-weight="bold" font-size="28" fill="white" text-anchor="middle">MZ</text>
    </svg>
    <div class="brand"><?= h($company_name) ?></div>
    <div class="sub">Kundenportal – Ihre Reparaturaufträge im Überblick</div>
  </div>

  <?php if (!portal_is_logged_in()): ?>

    <?php if ($ok_msg === 'verified'): ?>
      <div class="alert alert-success">Ihre E-Mail-Adresse wurde bestätigt. Sie können sich jetzt mit Ihrem Konto anmelden.</div>
    <?php elseif ($ok_msg === 'password_reset'): ?>
      <div class="alert alert-success">Ihr Passwort wurde erfolgreich geändert. Sie können sich jetzt anmelden.</div>
    <?php elseif ($err_msg === 'verify_failed'): ?>
      <div class="alert alert-danger">Der Bestätigungslink ist ungültig oder abgelaufen.</div>
    <?php endif; ?>

    <?php if ($login_error): ?>
      <div class="alert alert-danger"><?= h($login_error) ?></div>
    <?php endif; ?>

    <?php if (!in_array($view, ['reset_password', 'activate'], true)): ?>
    <div class="portal-tabs">
      <a href="<?= url('portal.php') ?>?view=guest" class="<?= $view === 'guest' ? 'active' : '' ?>"><?= svg_icon('key', 14) ?> Gast-Zugang</a>
      <a href="<?= url('portal.php') ?>?view=account_login" class="<?= $view === 'account_login' ? 'active' : '' ?>"><?= svg_icon('lock', 14) ?> Anmelden</a>
      <?php if (customer_accounts_enabled()): ?>
        <a href="<?= url('portal.php') ?>?view=register" class="<?= $view === 'register' ? 'active' : '' ?>"><?= svg_icon('user', 14) ?> Konto erstellen</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($view === 'activate'): ?>

      <div class="card">
        <div class="card-body">
          <h2 style="margin-top:0;font-size:1.1rem;">Zugang bestätigen</h2>
          <?php if ($activation_result): ?>
            <div class="alert <?= $activation_result['success'] ? 'alert-success' : 'alert-danger' ?>"><?= h($activation_result['message']) ?></div>
            <?php if ($activation_result['success']): ?>
              <p><a class="btn btn-primary" href="<?= url('portal.php') ?>?view=account_login">Zur Anmeldung</a></p>
            <?php endif; ?>
          <?php elseif (empty($_SESSION['portal_activation_token'])): ?>
            <div class="alert alert-danger">Dieser Aktivierungslink ist nicht mehr gültig.</div>
          <?php else: ?>
            <p>Bitte bestätigen Sie Ihren Zugang zu MZ Tech. Nach erfolgreicher Aktivierung können Sie sich mit Ihrer hinterlegten E-Mail-Adresse anmelden.</p>
            <form method="post" action="<?= url('portal.php') ?>">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="activate_account">
              <button type="submit" class="btn btn-primary btn-lg">Zugang aktivieren</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($view === 'guest'): ?>

      <div class="card">
        <div class="card-body">
          <h2 style="margin-top:0;font-size:1.1rem;"><?= svg_icon('lock', 20) ?> Gast-Zugang zum Kundenportal</h2>
          <p style="color:#6B7280;font-size:.9rem;">
            Sie haben einen persönlichen Zugangslink per E-Mail erhalten oder einen QR-Code von uns bekommen?
            Öffnen Sie den Link bzw. scannen Sie den Code – Sie werden automatisch angemeldet.
          </p>
          <p style="color:#6B7280;font-size:.9rem;">
            Alternativ können Sie sich mit Ihrer Auftragsnummer und der zugehörigen PIN anmelden,
            die Sie bei der Geräteabgabe von uns erhalten haben:
          </p>

          <form method="post" action="<?= url('portal.php') ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="login">
            <div class="form-grid">
              <div class="form-group">
                <label for="repair_number">Auftragsnummer <span class="req">*</span></label>
                <input type="text" id="repair_number" name="repair_number" placeholder="z. B. MZ20260001" required autocomplete="off">
              </div>
              <div class="form-group">
                <label for="pin">PIN <span class="req">*</span></label>
                <input type="text" id="pin" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6-stellige PIN" required autocomplete="off">
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary btn-lg"><?= svg_icon('key', 18) ?> Anmelden</button>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($view === 'account_login'): ?>

      <div class="card">
        <div class="card-body">
          <h2 style="margin-top:0;font-size:1.1rem;"><?= svg_icon('lock', 20) ?> Anmeldung mit Kundenkonto</h2>
          <form method="post" action="<?= url('portal.php') ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="account_login">
            <div class="form-group">
              <label for="email">E-Mail-Adresse <span class="req">*</span></label>
              <input type="email" id="email" name="email" required autocomplete="username" maxlength="190">
            </div>
            <div class="form-group">
              <label for="password">Passwort <span class="req">*</span></label>
              <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <div class="form-help-link"><a href="<?= url('portal.php') ?>?view=forgot">Passwort vergessen?</a></div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary btn-lg"><?= svg_icon('key', 18) ?> Anmelden</button>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($view === 'register' && !customer_accounts_enabled()): ?>

      <div class="card">
        <div class="card-body">
          <div class="alert alert-info" style="margin:0;">Die Registrierung neuer Kundenkonten ist derzeit nicht verfügbar. Sie können den Gast-Zugang weiterhin uneingeschränkt nutzen.</div>
        </div>
      </div>

    <?php elseif ($view === 'register' && customer_accounts_enabled()): ?>

      <div class="card">
        <div class="card-body">
          <h2 style="margin-top:0;font-size:1.1rem;"><?= svg_icon('user', 20) ?> Kundenkonto erstellen</h2>

          <?php if ($register_result && $register_result['success']): ?>
            <div class="alert alert-success"><?= h($register_result['message']) ?></div>
          <?php else: ?>
            <?php if ($register_result && !$register_result['success']): ?>
              <div class="alert alert-danger"><?= h($register_result['message']) ?></div>
            <?php endif; ?>

            <p style="color:#6B7280;font-size:.9rem;">
              Mit einem kostenlosen Kundenkonto behalten Sie alle Ihre Reparaturaufträge, Rechnungen und
              Nachrichten dauerhaft im Überblick. Ein Konto ist optional – Sie können den Gast-Zugang
              jederzeit weiter nutzen.
            </p>
            <form method="post" action="<?= url('portal.php') ?>" novalidate>
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="register">
              <div class="form-grid">
                <div class="form-group">
                  <label for="first_name">Vorname <span class="req">*</span></label>
                  <input type="text" id="first_name" name="first_name" required maxlength="100" value="<?= h($_POST['first_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                  <label for="last_name">Nachname <span class="req">*</span></label>
                  <input type="text" id="last_name" name="last_name" required maxlength="100" value="<?= h($_POST['last_name'] ?? '') ?>">
                </div>
              </div>
              <div class="form-grid">
                <div class="form-group">
                  <label for="reg_email">E-Mail-Adresse <span class="req">*</span></label>
                  <input type="email" id="reg_email" name="email" required maxlength="190" autocomplete="username" value="<?= h($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                  <label for="phone">Telefon</label>
                  <input type="tel" id="phone" name="phone" maxlength="40" value="<?= h($_POST['phone'] ?? '') ?>">
                </div>
              </div>
              <div class="form-grid">
                <div class="form-group">
                  <label for="reg_password">Passwort <span class="req">*</span></label>
                  <input type="password" id="reg_password" name="password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                  <label for="reg_password2">Passwort wiederholen <span class="req">*</span></label>
                  <input type="password" id="reg_password2" name="password2" required minlength="8" autocomplete="new-password">
                </div>
              </div>

              <div class="privacy-box"><?= render_privacy_notice() ?></div>
              <div class="form-group form-check">
                <label style="display:flex;align-items:flex-start;gap:8px;font-weight:400;">
                  <input type="checkbox" name="gdpr_consent" value="1" required style="margin-top:3px;">
                  <span>Ich habe die Datenschutzhinweise zur Kenntnis genommen und bin mit der Verarbeitung
                    meiner Daten zur Kontoführung einverstanden. <span class="req">*</span></span>
                </label>
              </div>

              <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg"><?= svg_icon('user', 18) ?> Konto erstellen</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($view === 'forgot'): ?>

      <div class="card">
        <div class="card-body">
          <h2 style="margin-top:0;font-size:1.1rem;"><?= svg_icon('key', 20) ?> Passwort vergessen</h2>
          <?php if ($forgot_result): ?>
            <div class="alert alert-info"><?= h($forgot_result['message']) ?></div>
          <?php else: ?>
            <p style="color:#6B7280;font-size:.9rem;">
              Bitte geben Sie die E-Mail-Adresse Ihres Kundenkontos ein. Falls ein Konto dazu existiert,
              senden wir Ihnen einen Link zum Zurücksetzen des Passworts.
            </p>
            <form method="post" action="<?= url('portal.php') ?>" novalidate>
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="request_reset">
              <div class="form-group">
                <label for="forgot_email">E-Mail-Adresse <span class="req">*</span></label>
                <input type="email" id="forgot_email" name="email" required maxlength="190" autocomplete="username">
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg"><?= svg_icon('key', 18) ?> Link anfordern</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($view === 'reset_password'): ?>

      <div class="card">
        <div class="card-body">
          <h2 style="margin-top:0;font-size:1.1rem;"><?= svg_icon('key', 20) ?> Neues Passwort festlegen</h2>
          <?php if ($reset_result && !$reset_result['success']): ?>
            <div class="alert alert-danger"><?= h($reset_result['message']) ?></div>
          <?php endif; ?>
          <?php if (!$reset_token || !preg_match('/^[a-f0-9]{64}$/', $reset_token)): ?>
            <div class="alert alert-danger">Ungültiger oder abgelaufener Link. Bitte fordern Sie einen neuen an.</div>
            <p style="margin-top:14px;"><a href="<?= url('portal.php') ?>?view=forgot">Neuen Link anfordern</a></p>
          <?php else: ?>
            <form method="post" action="<?= url('portal.php') ?>" novalidate>
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="token" value="<?= h($reset_token) ?>">
              <div class="form-group">
                <label for="new_pw">Neues Passwort <span class="req">*</span></label>
                <input type="password" id="new_pw" name="password" required minlength="8" autocomplete="new-password">
              </div>
              <div class="form-group">
                <label for="new_pw2">Passwort wiederholen <span class="req">*</span></label>
                <input type="password" id="new_pw2" name="password2" required minlength="8" autocomplete="new-password">
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg"><?= svg_icon('check', 18) ?> Passwort speichern</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

    <?php endif; ?>

  <?php else: ?>

    <?php if ($ok_msg === 'approved'): ?>
      <div class="alert alert-success">Vielen Dank! Der Kostenvoranschlag wurde freigegeben – wir setzen die Reparatur nun fort.</div>
    <?php elseif ($ok_msg === 'rejected'): ?>
      <div class="alert alert-info">Der Kostenvoranschlag wurde abgelehnt. Der Auftrag wurde storniert.</div>
    <?php elseif ($ok_msg === 'invoice_confirmed'): ?>
      <div class="alert alert-success">Vielen Dank! Sie haben die Rechnung bestätigt.</div>
    <?php elseif ($err_msg === 'notfound'): ?>
      <div class="alert alert-danger">Auftrag nicht gefunden.</div>
    <?php elseif ($err_msg === 'state'): ?>
      <div class="alert alert-danger">Für diesen Auftrag steht aktuell keine Freigabe aus.</div>
    <?php elseif ($err_msg === 'csrf'): ?>
      <div class="alert alert-danger">Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte erneut versuchen.</div>
    <?php elseif ($ok_msg === 'message_sent'): ?>
      <div class="alert alert-success">Ihre Nachricht wurde gesendet. Wir melden uns so schnell wie möglich.</div>
    <?php elseif ($ok_msg === 'photo_uploaded'): ?>
      <div class="alert alert-success">Vielen Dank! Ihr(e) Foto(s) wurde(n) erfolgreich hochgeladen.</div>
    <?php elseif ($ok_msg === 'photo_failed' || $err_msg === 'invalidfile'): ?>
      <div class="alert alert-danger">Die Datei(en) konnten nicht hochgeladen werden. Bitte nur JPG, PNG, GIF oder WebP (max. 10 MB) verwenden.</div>
    <?php elseif ($err_msg === 'toomany'): ?>
      <div class="alert alert-danger">Sie können maximal <?= (int)MAX_PHOTOS_PER_UPLOAD ?> Fotos gleichzeitig hochladen.</div>
    <?php elseif ($err_msg === 'nofile'): ?>
      <div class="alert alert-danger">Bitte wählen Sie mindestens eine Datei aus.</div>
    <?php endif; ?>

    <?php if ($change_pw_result): ?>
      <div class="alert <?= $change_pw_result['success'] ? 'alert-success' : 'alert-danger' ?>"><?= h($change_pw_result['message']) ?></div>
    <?php endif; ?>

    <div class="greeting">Hallo, <?= h($customer['first_name'] . ' ' . $customer['last_name']) ?>!</div>
    <p style="color:#6B7280;font-size:.88rem;margin-top:0;">Hier finden Sie den aktuellen Stand Ihrer Reparaturaufträge.</p>

    <?php if (empty($customer['gdpr_consent'])): ?>
      <div class="alert alert-info" style="margin-top:16px;">
        <div class="privacy-box" style="background:transparent;border:none;padding:0;margin-bottom:10px;"><?= render_privacy_notice() ?></div>
        <form method="post" action="<?= url('portal.php') ?>" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="gdpr_consent">
          <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('check', 14) ?> Datenschutzhinweise zur Kenntnis genommen</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (empty($repairs)): ?>
      <div class="card" style="margin-top:20px;" id="profile">
        <div class="card-header">
          <h3 class="card-title"><?= svg_icon('user', 18) ?> Profil</h3>
        </div>
        <div class="card-body">
          <form method="post" action="<?= url('portal.php') ?>#profile">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
              <div class="form-group"><label>Telefon</label><input type="text" name="phone" value="<?= h($customer['phone'] ?? '') ?>"></div>
              <div class="form-group"><label>Adresse</label><input type="text" name="address" value="<?= h($customer['address'] ?? '') ?>"></div>
              <div class="form-group"><label>PLZ</label><input type="text" name="zip" value="<?= h($customer['zip'] ?? '') ?>"></div>
              <div class="form-group"><label>Ort</label><input type="text" name="city" value="<?= h($customer['city'] ?? '') ?>"></div>
            </div>
            <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('check', 14) ?> Profil speichern</button>
          </form>
        </div>
      </div>

      <div class="card" style="margin-top:20px;">
        <div class="card-body empty-state">
          <?= svg_icon('inbox', 40) ?>
          <p style="margin-top:12px;">Aktuell liegen keine Reparaturaufträge zu Ihrem Zugang vor.</p>
        </div>
      </div>
    <?php else: ?>

      <?php foreach ($repairs as $repair):
        $geraet = trim(($repair['manufacturer'] ?? '') . ' ' . ($repair['model'] ?? '')) ?: device_type_label($repair['device_type']);
        $status_norm = repair_status_normalize($repair['status']);
        $needs_approval = in_array($status_norm, ['kostenvoranschlag', 'freigabe_ausstehend'], true);
        // Phase 2: eine Rechnung ist für den Kunden erst sichtbar/verlinkt,
        // sobald sie von einem Mitarbeiter freigegeben wurde (vorher wäre es
        // nur eine unverbindliche Entwurfsvorschau ohne gültige Nummer).
        $inv_status = (string)($repair['invoice_status'] ?? 'entwurf');
        $invoice_released  = in_array($inv_status, ['freigegeben', 'kunde_bestaetigt'], true);
        $needs_invoice_confirm = $inv_status === 'freigegeben';
      ?>
        <div class="card repair-card" id="repair-<?= (int)$repair['id'] ?>">
          <div class="card-body">
            <div class="repair-head">
              <div>
                <div class="repair-title"><?= svg_icon('wrench', 16) ?> <?= h($geraet) ?></div>
                <div class="repair-sub">Auftragsnummer: <strong><?= h($repair['repair_number']) ?></strong></div>
              </div>
              <div><?= repair_status_badge($repair['status']) ?></div>
            </div>

            <dl class="repair-meta">
              <div><dt>Geräteart</dt><dd><?= h(device_type_label($repair['device_type'])) ?></dd></div>
              <?php if (!empty($repair['price']) || !empty($repair['labor_cost'])): ?>
                <div><dt>Service netto</dt><dd><?= h(fmt_money((float)($repair['price'] ?? 0) + (float)($repair['labor_cost'] ?? 0))) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($repair['labor_cost'])): ?>
                <div><dt>Arbeitsleistung netto</dt><dd><?= h(fmt_money((float)$repair['labor_cost'])) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($repair['estimated_ready'])): ?>
                <div><dt>Voraussichtlich fertig</dt><dd><?= h(fmt_date($repair['estimated_ready'], true)) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($repair['warranty_months'])): ?>
                <div><dt>Garantie</dt><dd><?= (int)$repair['warranty_months'] ?> Monate</dd></div>
              <?php endif; ?>
            </dl>

            <?php if (!empty($repair['performed_work'])): ?>
              <div style="margin:12px 0;">
                <strong>Durchgeführte Arbeiten</strong>
                <div style="white-space:pre-wrap;margin-top:6px;"><?= h($repair['performed_work']) ?></div>
              </div>
            <?php endif; ?>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
              <a href="<?= url('pdf/kostenvoranschlag.php') ?>?id=<?= (int)$repair['id'] ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                <?= svg_icon('clipboard', 14) ?> Kostenvoranschlag
              </a>
              <?php if ($invoice_released): ?>
                <a href="<?= url('pdf/rechnung.php') ?>?id=<?= (int)$repair['id'] ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                  <?= svg_icon('pdf', 14) ?> Rechnung <?= h($repair['invoice_number'] ?? '') ?>
                </a>
              <?php endif; ?>
            </div>

            <?php if ($needs_invoice_confirm): ?>
              <div class="quote-box">
                <strong><?= svg_icon('alert-circle', 16) ?> Ihre Bestätigung wird benötigt</strong>
                <p style="margin:6px 0 0;font-size:.86rem;">
                  Für diesen Auftrag liegt die Rechnung <strong><?= h($repair['invoice_number'] ?? '') ?></strong> vor. Bitte bestätigen Sie den Erhalt.
                </p>
                <div class="quote-actions">
                  <form method="post" action="<?= url('portal.php') ?>" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="confirm_invoice">
                    <input type="hidden" name="repair_id" value="<?= (int)$repair['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm"><?= svg_icon('check', 14) ?> Rechnung bestätigen</button>
                  </form>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($needs_approval): ?>
              <div class="quote-box">
                <strong><?= svg_icon('alert-circle', 16) ?> Ihre Freigabe wird benötigt</strong>
                <p style="margin:6px 0 0;font-size:.86rem;">
                  Für diesen Auftrag liegt ein Kostenvoranschlag
                  <?php if (!empty($repair['price']) || !empty($repair['labor_cost'])): ?>
                    über <strong><?= h(fmt_money((float)($repair['price'] ?? 0) + (float)($repair['labor_cost'] ?? 0))) ?></strong>
                  <?php endif; ?>
                  vor. Bitte prüfen Sie diesen und geben Sie die Reparatur frei oder lehnen Sie sie ab.
                </p>
                <div class="quote-actions">
                  <form method="post" action="<?= url('portal.php') ?>" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="approve_quote">
                    <input type="hidden" name="repair_id" value="<?= (int)$repair['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm"><?= svg_icon('check', 14) ?> Freigeben</button>
                  </form>
                  <form method="post" action="<?= url('portal.php') ?>" style="margin:0;"
                        onsubmit="return confirm('Kostenvoranschlag wirklich ablehnen? Der Auftrag wird storniert.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="reject_quote">
                    <input type="hidden" name="repair_id" value="<?= (int)$repair['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('x', 14) ?> Ablehnen</button>
                  </form>
                </div>
              </div>
            <?php endif; ?>

            <?php if (!empty($repair['status_history'])): ?>
              <ul class="timeline">
                <?php foreach ($repair['status_history'] as $i => $hentry): ?>
                  <li class="timeline-item <?= $i === count($repair['status_history']) - 1 ? 'timeline-item--current' : '' ?>">
                    <div class="timeline-dot"></div>
                    <div class="timeline-content">
                      <?= repair_status_badge($hentry['status']) ?>
                      <span style="font-size:.78rem;color:#9CA3AF;"><?= h(fmt_date($hentry['created_at'], true)) ?></span>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <!-- ── Fotos zu diesem Auftrag ─────────────────────────────────────── -->
            <div class="repair-photos" style="margin-top:14px;border-top:1px solid #E5E7EB;padding-top:12px;">
              <strong style="font-size:.86rem;"><?= svg_icon('image', 15) ?> Fotos</strong>

              <?php if (!empty($repair['photos'])): ?>
                <div class="photo-grid" style="margin-top:8px;">
                  <?php foreach ($repair['photos'] as $photo): ?>
                    <?php $photo_url = url('portal_photo.php') . '?id=' . (int)$photo['id']; ?>
                    <div class="photo-thumb">
                      <a href="<?= h($photo_url) ?>" target="_blank" rel="noopener">
                        <img src="<?= h($photo_url) ?>" alt="<?= h($photo['original_name'] ?? 'Foto') ?>" loading="lazy" title="<?= h($photo['original_name'] ?? '') ?>">
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p style="font-size:.82rem;color:#9CA3AF;margin:6px 0 0;">Noch keine Fotos vorhanden.</p>
              <?php endif; ?>

              <form method="post" action="<?= url('portal.php') ?>#repair-<?= (int)$repair['id'] ?>" enctype="multipart/form-data" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="upload_photo">
                <input type="hidden" name="repair_id" value="<?= (int)$repair['id'] ?>">
                <input type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="font-size:.8rem;max-width:260px;">
                <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('upload', 14) ?> Foto(s) hochladen</button>
              </form>
              <small style="color:#9CA3AF;display:block;margin-top:4px;">Bis zu <?= (int)MAX_PHOTOS_PER_UPLOAD ?> Fotos, je max. 10 MB (JPG, PNG, GIF, WebP).</small>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

    <?php endif; ?>

    <?php if (!empty($appointments)): ?>
      <div class="card" style="margin-top:20px;">
        <div class="card-header">
          <h3 class="card-title"><?= svg_icon('calendar', 18) ?> Anstehende Termine</h3>
        </div>
        <div class="card-body">
          <?php foreach ($appointments as $appt): ?>
            <div class="appt-item">
              <span><?= h($appt['title']) ?> <span style="color:#9CA3AF;">(<?= h($appointment_types[$appt['type']] ?? $appt['type']) ?>)</span></span>
              <strong><?= h(fmt_date($appt['start_datetime'], true)) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (portal_is_registered_account()): ?>

      <div class="card" style="margin-top:20px;" id="messages">
        <div class="card-header">
          <h3 class="card-title"><?= svg_icon('message-circle', 18) ?> Nachrichten</h3>
        </div>
        <div class="card-body">
          <?php if (empty($messages)): ?>
            <p class="msg-empty">Noch keine Nachrichten vorhanden. Schreiben Sie uns bei Fragen zu einem Auftrag.</p>
          <?php else: ?>
            <div class="msg-thread">
              <?php foreach ($messages as $m): ?>
                <div class="msg-bubble <?= h($m['sender']) ?>">
                  <?= nl2br(h($m['message'])) ?>
                  <div class="msg-meta">
                    <?= h(fmt_date($m['created_at'], true)) ?>
                    <?php if (!empty($m['repair_number'])): ?> · Auftrag <?= h($m['repair_number']) ?><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" action="<?= url('portal.php') ?>#messages" style="margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="send_message">
            <?php if (!empty($repairs)): ?>
              <div class="form-group">
                <label for="msg_repair_id">Bezug zu Auftrag (optional)</label>
                <select id="msg_repair_id" name="repair_id">
                  <option value="0">– Allgemeine Nachricht –</option>
                  <?php foreach ($repairs as $r_opt): ?>
                    <option value="<?= (int)$r_opt['id'] ?>"><?= h($r_opt['repair_number']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <div class="form-group">
              <label for="msg_text">Ihre Nachricht <span class="req">*</span></label>
              <textarea id="msg_text" name="message" rows="3" maxlength="2000" required></textarea>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary btn-sm"><?= svg_icon('mail', 14) ?> Senden</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card" style="margin-top:20px;">
        <div class="card-header">
          <h3 class="card-title"><?= svg_icon('lock', 18) ?> Passwort ändern</h3>
        </div>
        <div class="card-body">
          <form method="post" action="<?= url('portal.php') ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid">
              <div class="form-group">
                <label for="current_password">Aktuelles Passwort <span class="req">*</span></label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
              </div>
              <div></div>
              <div class="form-group">
                <label for="new_password">Neues Passwort <span class="req">*</span></label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
              </div>
              <div class="form-group">
                <label for="new_password2">Neues Passwort wiederholen <span class="req">*</span></label>
                <input type="password" id="new_password2" name="new_password2" required minlength="8" autocomplete="new-password">
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-outline btn-sm"><?= svg_icon('check', 14) ?> Passwort ändern</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

  <?php endif; ?>

  <p style="text-align:center;margin-top:28px;font-size:.78rem;color:#9CA3AF;">
    &copy; <?= date('Y') ?> <?= h($company_name) ?>
  </p>
</div>
</body>
</html>
