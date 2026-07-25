<?php
/**
 * MZ Tech – Authentifizierung & Session-Management
 */

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => SESSION_COOKIE_PATH,
        'domain'   => '',
        'secure'   => true,        // nur über HTTPS
        'httponly' => true,        // kein JS-Zugriff
        'samesite' => 'Lax',
    ]);
    session_start();

    // Session-Timeout prüfen
    if (!empty($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Ihre Sitzung ist abgelaufen. Bitte neu anmelden.'];
            header('Location: ' . url('index.php'));
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
}

function require_auth(?string $redirect = null): void {
    start_secure_session();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . ($redirect ?? url('index.php')));
        exit;
    }
}

function require_admin(): void {
    require_auth();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        flash('error', 'Keine Berechtigung für diese Seite.');
        header('Location: ' . url('dashboard.php'));
        exit;
    }
}

function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

// ── Brute-Force-Prüfung ───────────────────────────────
function is_brute_force_locked(string $ip): bool {
    $since = date('Y-m-d H:i:s', time() - (LOGIN_LOCKOUT_MINUTES * 60));
    $stmt  = get_db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND success = 0 AND attempted_at >= ?'
    );
    $stmt->execute([$ip, $since]);
    return (int)$stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

function record_login_attempt(string $ip, string $username, bool $success): void {
    $stmt = get_db()->prepare(
        'INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)'
    );
    $stmt->execute([$ip, $username, $success ? 1 : 0]);

    // Alte Einträge bereinigen (> 7 Tage)
    get_db()->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 7 DAY");
}

// ── Login ──────────────────────────────────────────────
function attempt_login(string $username, string $password): array {
    $ip = get_client_ip();

    if (is_brute_force_locked($ip)) {
        return ['success' => false, 'message' => 'Zu viele Fehlversuche. Bitte ' . LOGIN_LOCKOUT_MINUTES . ' Minuten warten.'];
    }

    $stmt = get_db()->prepare(
        'SELECT id, username, password_hash, full_name, role, is_active
         FROM users WHERE username = ? OR email = ? LIMIT 1'
    );
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($ip, $username, false);
        return ['success' => false, 'message' => 'Benutzername oder Passwort falsch.'];
    }

    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Ihr Konto ist deaktiviert.'];
    }

    // Session erneuern → Session-Fixation verhindern
    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['full_name'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']); // Neues CSRF-Token erzeugen

    record_login_attempt($ip, $username, true);

    // last_login aktualisieren
    get_db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    log_activity('login', 'users', $user['id']);

    return ['success' => true];
}

// ── Logout ─────────────────────────────────────────────
function do_logout(): void {
    log_activity('logout');
    session_unset();
    session_destroy();
    // Session-Cookie löschen
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            SESSION_NAME, '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
}

// ── Passwort-Regeln ───────────────────────────────────
function validate_password(string $pw): ?string {
    if (strlen($pw) < 8) return 'Mindestens 8 Zeichen.';
    if (!preg_match('/[A-Z]/', $pw)) return 'Mindestens ein Großbuchstabe.';
    if (!preg_match('/[0-9]/', $pw)) return 'Mindestens eine Zahl.';
    return null;
}

// ── Passwort-Reset für Mitarbeiter (Phase 4) ──────────
// Mirrors das bewährte Muster aus customer_auth.php (Kundenportal):
// zeit-limitierter Token, keine Konto-Enumeration möglich, E-Mail über
// die zentrale Vorlage "mitarbeiter_passwort_reset".
function staff_request_password_reset(string $email): array {
    $email = trim($email);
    $generic = ['success' => true, 'message' => 'Falls zu dieser E-Mail-Adresse ein Mitarbeiterkonto besteht, wurde soeben ein Link zum Zurücksetzen des Passworts versendet.'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $generic;
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT id, username, full_name, email, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && (int)$user['is_active']) {
        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 60 MINUTE) WHERE id = ?')
           ->execute([$token, $user['id']]);

        try {
            $name_parts = preg_split('/\s+/', trim((string)$user['full_name']), 2);
            $reset_link = base_app_url() . '/index.php?reset=' . $token;
            send_generic_template_email('mitarbeiter_passwort_reset', [
                'first_name' => $name_parts[0] ?? $user['username'],
                'last_name'  => $name_parts[1] ?? '',
                'email'      => $user['email'],
            ], ['reset_link' => $reset_link]);
        } catch (Throwable $e) {
            error_log('staff_request_password_reset: Mail fehlgeschlagen: ' . $e->getMessage());
        }

        log_activity('staff_password_reset_request', 'users', (int)$user['id'], 'Passwort-Reset angefordert');
    }

    return $generic;
}

function staff_reset_password(string $token, string $password, string $password2): array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Link.'];
    }
    if ($password !== $password2) {
        return ['success' => false, 'message' => 'Die Passwörter stimmen nicht überein.'];
    }
    $pw_error = validate_password($password);
    if ($pw_error !== null) {
        return ['success' => false, 'message' => $pw_error];
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE reset_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user || empty($user['reset_expires']) || strtotime($user['reset_expires']) < time()) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Link. Bitte fordern Sie einen neuen an.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $db->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
       ->execute([$hash, $user['id']]);

    log_activity('staff_password_reset', 'users', (int)$user['id'], 'Passwort erfolgreich zurückgesetzt');

    return ['success' => true, 'message' => 'Ihr Passwort wurde erfolgreich geändert. Sie können sich jetzt anmelden.'];
}
