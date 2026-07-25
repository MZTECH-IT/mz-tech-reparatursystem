<?php
/**
 * MZ Tech Repair System – Login
 */

// Pfad zu den privaten Dateien (AUSSERHALB des Webroots)
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
require_once $private . '/mailer.php';

start_secure_session();

// Bereits eingeloggt?
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error   = '';
$success = '';

// ── Ansicht: Login (Standard) / Passwort vergessen / Passwort zurücksetzen ──
// (Phase 4: Mitarbeiter-Passwort-Reset, mirrors das bewährte Muster aus dem
// Kundenportal, siehe private/customer_auth.php.)
$view = 'login';
if (isset($_GET['reset']))  $view = 'reset_password';
elseif (isset($_GET['forgot'])) $view = 'forgot';

// POST: Login-Versuch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? 'login') === 'login') {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Ungültige Anfrage.';
    } else {
        $result = attempt_login(
            trim($_POST['username'] ?? ''),
            $_POST['password'] ?? ''
        );

        if ($result['success']) {
            header('Location: ' . url('dashboard.php'));
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

// POST: Passwort-Reset anfordern
$forgot_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'forgot') {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $forgot_result = staff_request_password_reset(trim($_POST['email'] ?? ''));
    }
    $view = 'forgot';
}

// POST: Neues Passwort setzen
$reset_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'reset') {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $reset_result = staff_reset_password(
            $_POST['token'] ?? '',
            $_POST['password'] ?? '',
            $_POST['password2'] ?? ''
        );
    }
    $view = 'reset_password';
}

$reset_token = (string)($_GET['reset'] ?? ($_POST['token'] ?? ''));

// Flash-Nachrichten aus anderer Seite
$flash = get_flash();
if ($flash) {
    if ($flash['type'] === 'warning') $success = $flash['msg'];
    else $error = $flash['msg'];
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Anmelden – MZ Tech Admin</title>
  <link rel="icon" type="image/x-icon" href="<?= h(company_favicon_url()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
  <?= company_color_css_override() ?>
  <script>window.APP_URL_BASE = <?= json_encode(APP_URL_BASE, JSON_UNESCAPED_SLASHES) ?>;</script>
  <style>
    .input-icon  { position:relative; }
    .input-icon input { padding-left:40px; }
    .input-icon svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--gray-400); width:16px; height:16px; }
  </style>
</head>
<body>
<div class="login-page">
  <div class="login-card">

    <div class="login-logo">
      <img src="<?= h(company_logo_url()) ?>" alt="<?= h(get_setting('company_name', 'MZ Tech')) ?>">
      <p>Anmeldung · Werkstatt- und Verwaltungssystem</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:16px;">
      <strong>Fehler:</strong> <?= h($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-warning" style="margin-bottom:16px;">
      <?= h($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($view === 'login'): ?>
      <form method="POST" action="<?= url('index.php') ?>" autocomplete="on">
        <input type="hidden" name="form" value="login">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <div class="form-group" style="margin-bottom:16px;">
          <label for="username">Benutzername oder E-Mail</label>
          <div class="input-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" id="username" name="username"
                   value="<?= h($_POST['username'] ?? '') ?>"
                   placeholder="Benutzername"
                   autocomplete="username" required autofocus>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:12px;">
          <label for="password">Passwort</label>
          <div class="input-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="password" name="password"
                   placeholder="Passwort"
                   autocomplete="current-password" required>
          </div>
        </div>

        <div style="text-align:right;margin-bottom:20px;">
          <a href="<?= url('index.php') ?>?forgot=1" style="font-size:.82rem;color:var(--gray-500);text-decoration:none;">Passwort vergessen?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" style="justify-content:center;">
          Anmelden
        </button>
      </form>

    <?php elseif ($view === 'forgot'): ?>
      <?php if ($forgot_result): ?>
        <div class="alert alert-success" style="margin-bottom:16px;"><?= h($forgot_result['message']) ?></div>
        <a href="<?= url('index.php') ?>" class="btn btn-outline w-full" style="justify-content:center;">Zurück zur Anmeldung</a>
      <?php else: ?>
        <p class="text-muted" style="margin-bottom:16px;font-size:.86rem;">Geben Sie Ihre E-Mail-Adresse ein – Sie erhalten einen Link zum Zurücksetzen Ihres Passworts.</p>
        <form method="POST" action="<?= url('index.php') ?>?forgot=1">
          <input type="hidden" name="form" value="forgot">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <div class="form-group" style="margin-bottom:20px;">
            <label for="forgot_email">E-Mail-Adresse</label>
            <input type="email" id="forgot_email" name="email" class="form-control" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary btn-lg w-full" style="justify-content:center;">Link anfordern</button>
        </form>
        <div style="text-align:center;margin-top:16px;">
          <a href="<?= url('index.php') ?>" style="font-size:.82rem;color:var(--gray-500);text-decoration:none;">Zurück zur Anmeldung</a>
        </div>
      <?php endif; ?>

    <?php elseif ($view === 'reset_password'): ?>
      <?php if ($reset_result && $reset_result['success']): ?>
        <div class="alert alert-success" style="margin-bottom:16px;"><?= h($reset_result['message']) ?></div>
        <a href="<?= url('index.php') ?>" class="btn btn-outline w-full" style="justify-content:center;">Zur Anmeldung</a>
      <?php else: ?>
        <?php if ($reset_result && !$reset_result['success']): ?>
          <div class="alert alert-danger" style="margin-bottom:16px;"><?= h($reset_result['message']) ?></div>
        <?php endif; ?>
        <form method="POST" action="<?= url('index.php') ?>">
          <input type="hidden" name="form" value="reset">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="token" value="<?= h($reset_token) ?>">
          <div class="form-group" style="margin-bottom:16px;">
            <label for="reset_password">Neues Passwort</label>
            <input type="password" id="reset_password" name="password" class="form-control" required autocomplete="new-password" autofocus>
          </div>
          <div class="form-group" style="margin-bottom:20px;">
            <label for="reset_password2">Neues Passwort bestätigen</label>
            <input type="password" id="reset_password2" name="password2" class="form-control" required autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-primary btn-lg w-full" style="justify-content:center;">Passwort setzen</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <div style="text-align:center;margin-top:20px;font-size:.78rem;color:#9CA3AF;">
      &copy; <?= date('Y') ?> <?= h(get_setting('company_name', 'MZ Tech')) ?> · Leopoldshöhe
    </div>
  </div>
</div>
</body>
</html>
