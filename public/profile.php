<?php
/**
 * MZ Tech – Mein Profil
 */
require_once __DIR__ . '/init.php';

$db      = get_db();
$user_id = (int)$_SESSION['user_id'];
$errors  = [];

// ── Hilfsfunktion: Aktions-Label (lokale Kopie) ───────────────────────────────
function action_label(string $action): string {
    $map = [
        'login'            => 'Login',
        'logout'           => 'Logout',
        'login_failed'     => 'Login fehlgeschlagen',
        'repair_created'   => 'Reparatur erstellt',
        'repair_updated'   => 'Reparatur aktualisiert',
        'repair_deleted'   => 'Reparatur gelöscht',
        'repair_status'    => 'Status geändert',
        'customer_created' => 'Kunde erstellt',
        'customer_updated' => 'Kunde aktualisiert',
        'customer_deleted' => 'Kunde gelöscht',
        'part_created'     => 'Ersatzteil erstellt',
        'part_updated'     => 'Ersatzteil aktualisiert',
        'part_deleted'     => 'Ersatzteil gelöscht',
        'settings_updated' => 'Einstellungen geändert',
        'backup_created'   => 'Backup erstellt',
        'backup_deleted'   => 'Backup gelöscht',
        'user_created'     => 'Benutzer angelegt',
        'user_updated'     => 'Benutzer bearbeitet',
        'user_toggled'     => 'Benutzer aktiviert/deaktiviert',
        'profile_updated'  => 'Profil aktualisiert',
        'password_changed' => 'Passwort geändert',
        'invoice_generated'=> 'Rechnung erstellt',
        'email_sent'       => 'E-Mail gesendet',
    ];
    return $map[$action] ?? h($action);
}

// ── Aktuellen Benutzer laden ──────────────────────────────────────────────────
$stmt = $db->prepare("SELECT id, username, email, full_name, role, is_active, last_login FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    flash('error', 'Benutzer nicht gefunden.');
    header('Location: ' . url('dashboard.php'));
    exit;
}

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── Profil-Daten speichern ──
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');

        if (!$full_name) {
            $errors[] = 'Vollständiger Name ist erforderlich.';
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        if (empty($errors)) {
            // E-Mail-Eindeutigkeit prüfen (außer eigene)
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Diese E-Mail-Adresse wird bereits von einem anderen Benutzer verwendet.';
            }
        }

        if (empty($errors)) {
            $changed = [];
            if ($user['full_name'] !== $full_name) $changed[] = 'Name';
            if ($user['email']     !== $email)     $changed[] = 'E-Mail';

            $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $user_id]);

            // Session aktualisieren
            $_SESSION['user_name'] = $full_name;

            if (!empty($changed)) {
                log_activity('profile_updated', 'users', $user_id, 'Geändert: ' . implode(', ', $changed));
            }

            // Neu laden
            $user['full_name'] = $full_name;
            $user['email']     = $email;

            flash('success', 'Profil erfolgreich aktualisiert.');
            header('Location: ' . url('profile.php') . '#profil');
            exit;
        }
    }

    // ── Passwort ändern ──
    if ($action === 'change_password') {
        $current_pw = $_POST['current_password'] ?? '';
        $new_pw     = $_POST['new_password']     ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (!$current_pw) {
            $errors[] = 'Aktuelles Passwort ist erforderlich.';
        }
        if (!$new_pw) {
            $errors[] = 'Neues Passwort ist erforderlich.';
        }
        if ($new_pw !== $confirm_pw) {
            $errors[] = 'Neues Passwort und Bestätigung stimmen nicht überein.';
        }

        if (empty($errors)) {
            // Aktuelles Passwort prüfen
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $hash_row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hash_row || !password_verify($current_pw, $hash_row['password_hash'])) {
                $errors[] = 'Das aktuelle Passwort ist falsch.';
            }
        }

        if (empty($errors)) {
            // Neues Passwort validieren
            $pw_error = validate_password($new_pw);
            if ($pw_error !== null) {
                $errors[] = $pw_error;
            }
        }

        if (empty($errors)) {
            $new_hash = password_hash($new_pw, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_hash, $user_id]);

            log_activity('password_changed', 'users', $user_id, 'Passwort geändert');
            flash('success', 'Passwort erfolgreich geändert.');
            header('Location: ' . url('profile.php') . '#passwort');
            exit;
        }
    }
}

// ── Letzte Aktivitäten dieses Benutzers ──────────────────────────────────────
$stmt = $db->prepare(
    "SELECT action, entity_type, entity_id, details, ip_address, created_at
     FROM activity_log WHERE user_id = ?
     ORDER BY id DESC LIMIT 10"
);
$stmt->execute([$user_id]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Rollen-Label
function role_label_profile(string $role): string {
    return match($role) {
        'admin'     => 'Administrator',
        'techniker' => 'Techniker',
        'empfang'   => 'Empfang',
        default     => $role,
    };
}

$page_title = 'Mein Profil';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Mein Profil</h1>
    <p class="page-subtitle">Persönliche Daten und Passwort verwalten</p>
  </div>
</div>

<?php show_flash(); ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger" style="margin-bottom:24px;">
    <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start;">

  <!-- ── Linke Spalte: Formulare ── -->
  <div style="display:flex;flex-direction:column;gap:24px;">

    <!-- ── Profil-Daten ── -->
    <div class="card" id="profil">
      <div class="card-header">
        <h2 class="card-title"><?= svg_icon('user') ?> Profildaten</h2>
      </div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1;">
              <label for="full_name">Vollständiger Name *</label>
              <input type="text" id="full_name" name="full_name" class="form-control"
                     value="<?= h($user['full_name']) ?>" required>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
              <label for="email">E-Mail-Adresse *</label>
              <input type="email" id="email" name="email" class="form-control"
                     value="<?= h($user['email']) ?>" required>
            </div>
            <div class="form-group">
              <label>Benutzername</label>
              <input type="text" class="form-control" value="<?= h($user['username']) ?>" disabled
                     title="Benutzername kann nicht geändert werden" style="background:#F9FAFB;color:#6B7280;">
              <small style="color:#9CA3AF;font-size:.8rem;">Benutzername kann nicht geändert werden.</small>
            </div>
            <div class="form-group">
              <label>Rolle</label>
              <input type="text" class="form-control" value="<?= h(role_label_profile($user['role'])) ?>" disabled
                     style="background:#F9FAFB;color:#6B7280;">
            </div>
          </div>
          <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Profil speichern</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Passwort ändern ── -->
    <div class="card" id="passwort">
      <div class="card-header">
        <h2 class="card-title"><?= svg_icon('lock') ?> Passwort ändern</h2>
      </div>
      <div class="card-body">
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1;">
              <label for="current_password">Aktuelles Passwort *</label>
              <input type="password" id="current_password" name="current_password" class="form-control"
                     required autocomplete="current-password">
            </div>
            <div class="form-group">
              <label for="new_password">Neues Passwort *</label>
              <input type="password" id="new_password" name="new_password" class="form-control"
                     required autocomplete="new-password"
                     oninput="checkPasswordStrength(this.value)">
              <!-- Passwort-Stärke-Anzeige -->
              <div id="pw-strength-bar" style="margin-top:6px;height:4px;border-radius:2px;background:#E5E7EB;overflow:hidden;">
                <div id="pw-strength-fill" style="height:100%;width:0%;transition:width .3s,background .3s;"></div>
              </div>
              <div id="pw-strength-text" style="font-size:.8rem;color:#9CA3AF;margin-top:4px;"></div>
            </div>
            <div class="form-group">
              <label for="confirm_password">Neues Passwort bestätigen *</label>
              <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                     required autocomplete="new-password">
            </div>
          </div>
          <p style="font-size:.8rem;color:#9CA3AF;margin:8px 0 16px;">
            Das Passwort muss mindestens 8 Zeichen lang sein und Groß-/Kleinbuchstaben sowie Ziffern enthalten.
          </p>
          <button type="submit" class="btn btn-primary"><?= svg_icon('lock') ?> Passwort ändern</button>
        </form>
      </div>
    </div>

  </div><!-- /linke Spalte -->

  <!-- ── Rechte Spalte: Info + Letzte Aktivitäten ── -->
  <div style="display:flex;flex-direction:column;gap:24px;">

    <!-- ── Account-Info ── -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title"><?= svg_icon('info') ?> Account-Info</h2>
      </div>
      <div class="card-body">
        <!-- Avatar -->
        <div style="text-align:center;margin-bottom:20px;">
          <?php
            $initials = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1));
            if (strpos($user['full_name'], ' ') !== false) {
                $parts = explode(' ', $user['full_name'], 2);
                $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
            }
          ?>
          <div style="width:72px;height:72px;border-radius:50%;background:#0057B8;color:#fff;
                      display:flex;align-items:center;justify-content:center;
                      font-size:1.5rem;font-weight:700;margin:0 auto 12px;">
            <?= h($initials) ?>
          </div>
          <div style="font-weight:600;font-size:1.05rem;"><?= h($user['full_name'] ?: $user['username']) ?></div>
          <div style="color:#6B7280;font-size:.875rem;">@<?= h($user['username']) ?></div>
          <div style="margin-top:8px;">
            <span class="badge badge-blue"><?= h(role_label_profile($user['role'])) ?></span>
            <?php if ($user['is_active']): ?>
              <span class="badge badge-green" style="margin-left:4px;">Aktiv</span>
            <?php endif; ?>
          </div>
        </div>
        <hr style="border:none;border-top:1px solid #F3F4F6;margin:16px 0;">
        <dl style="margin:0;display:grid;gap:10px;">
          <div>
            <dt style="font-size:.8rem;color:#9CA3AF;font-weight:500;text-transform:uppercase;letter-spacing:.05em;">E-Mail</dt>
            <dd style="margin:2px 0 0;color:#374151;"><?= h($user['email'] ?: '–') ?></dd>
          </div>
          <div>
            <dt style="font-size:.8rem;color:#9CA3AF;font-weight:500;text-transform:uppercase;letter-spacing:.05em;">Letzter Login</dt>
            <dd style="margin:2px 0 0;color:#374151;"><?= $user['last_login'] ? fmt_date($user['last_login'], true) : '–' ?></dd>
          </div>
        </dl>
      </div>
    </div>

    <!-- ── Letzte Aktivitäten ── -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title"><?= svg_icon('list') ?> Meine Aktivitäten</h2>
        <a href="<?= url('activity.php') ?>?user_id=<?= $user_id ?>" class="btn btn-sm btn-outline">Alle</a>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($recent_activities)): ?>
          <div class="empty-state" style="padding:32px 16px;">
            <?= svg_icon('list') ?>
            <p>Noch keine Aktivitäten.</p>
          </div>
        <?php else: ?>
          <ul style="margin:0;padding:0;list-style:none;">
            <?php foreach ($recent_activities as $act): ?>
            <li style="padding:10px 16px;border-bottom:1px solid #F3F4F6;display:flex;gap:10px;align-items:flex-start;">
              <div style="flex:1;min-width:0;">
                <div style="font-size:.875rem;font-weight:500;color:#374151;">
                  <?= h(action_label($act['action'])) ?>
                  <?php if ($act['entity_type']): ?>
                    <span style="color:#9CA3AF;font-weight:400;"> – <?= h($act['entity_type']) ?>
                      <?= $act['entity_id'] ? '#' . (int)$act['entity_id'] : '' ?>
                    </span>
                  <?php endif; ?>
                </div>
                <?php if ($act['details']): ?>
                  <div style="font-size:.8rem;color:#6B7280;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= h(mb_strimwidth($act['details'], 0, 60, '…')) ?>
                  </div>
                <?php endif; ?>
                <div style="font-size:.75rem;color:#9CA3AF;margin-top:3px;">
                  <?= fmt_date($act['created_at'], true) ?>
                </div>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /rechte Spalte -->

</div><!-- /grid -->

<script>
function checkPasswordStrength(pw) {
  var bar  = document.getElementById('pw-strength-fill');
  var text = document.getElementById('pw-strength-text');
  if (!pw) { bar.style.width = '0%'; text.textContent = ''; return; }

  var score = 0;
  if (pw.length >= 8)                        score++;
  if (pw.length >= 12)                       score++;
  if (/[A-Z]/.test(pw))                     score++;
  if (/[a-z]/.test(pw))                     score++;
  if (/[0-9]/.test(pw))                     score++;
  if (/[^A-Za-z0-9]/.test(pw))             score++;

  var levels = [
    { pct: '0%',   color: '#E5E7EB', label: '' },
    { pct: '20%',  color: '#EF4444', label: 'Sehr schwach' },
    { pct: '40%',  color: '#F59E0B', label: 'Schwach' },
    { pct: '60%',  color: '#F59E0B', label: 'Mittel' },
    { pct: '80%',  color: '#10B981', label: 'Stark' },
    { pct: '100%', color: '#10B981', label: 'Sehr stark' },
  ];
  var lvl = levels[Math.min(score, 5)];
  bar.style.width      = lvl.pct;
  bar.style.background = lvl.color;
  text.textContent     = lvl.label;
  text.style.color     = lvl.color;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
