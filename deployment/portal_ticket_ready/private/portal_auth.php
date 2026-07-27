<?php
/**
 * MZ Tech – Kundenportal: Authentifizierung & Session-Management
 *
 * Eigenständig von private/auth.php (Admin/Techniker-Login), damit eine
 * Kundenportal-Sitzung niemals mit einer Admin-Sitzung im selben Browser
 * kollidiert. Nutzt einen eigenen Session-Namen (PORTAL_SESSION_NAME) und
 * eigene $_SESSION-Schlüssel (portal_*), aber dieselbe Datenbank/PDO.
 */

function start_portal_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name(PORTAL_SESSION_NAME);
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
    if (!empty($_SESSION['portal_last_activity'])) {
        if (time() - $_SESSION['portal_last_activity'] > PORTAL_SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            session_start();
        }
    }
    $_SESSION['portal_last_activity'] = time();
}

// ── Zugriffsprüfung ────────────────────────────────────
function portal_require_login(): void {
    start_portal_session();
    if (empty($_SESSION['portal_customer_id'])) {
        header('Location: ' . url('portal.php'));
        exit;
    }
    if (!empty($_SESSION['portal_account_id'])) {
        $stmt = get_db()->prepare(
            'SELECT customer_id, is_active, is_verified FROM customer_accounts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int)$_SESSION['portal_account_id']]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || !(int)$account['is_active'] || !(int)$account['is_verified']
            || (int)$account['customer_id'] !== (int)$_SESSION['portal_customer_id']) {
            portal_logout();
            header('Location: ' . url('portal.php'));
            exit;
        }
    }
}

function portal_is_logged_in(): bool {
    return !empty($_SESSION['portal_customer_id']);
}

// Aktuell eingeloggte Kunden-ID – NIE ungeprüfte Client-Eingaben für
// Datenzugriffe verwenden, immer diese Funktion (verhindert IDOR).
function portal_current_customer_id(): ?int {
    return isset($_SESSION['portal_customer_id']) ? (int)$_SESSION['portal_customer_id'] : null;
}

/**
 * Meldet den Kunden in der Portal-Session an. Wird sowohl vom
 * passwortlosen Gast-Zugang (Auftragsnummer+PIN oder Magic-Link) als
 * auch vom registrierten Kundenkonto (E-Mail+Passwort, siehe
 * customer_auth.php) genutzt – beide münden in derselben Session, damit
 * "beide Systeme parallel" (Gast + registriertes Konto) nahtlos
 * funktionieren.
 *
 * $is_registered / $account_id werden nur beim Login über ein
 * registriertes Konto gesetzt (siehe customer_attempt_login()).
 */
function portal_login_as(int $customer_id, int $portal_access_id, bool $is_registered = false, ?int $account_id = null): void {
    // Session erneuern → Session-Fixation verhindern
    session_regenerate_id(true);
    $_SESSION['portal_customer_id']   = $customer_id;
    $_SESSION['portal_access_id']     = $portal_access_id;
    $_SESSION['portal_is_registered'] = $is_registered;
    $_SESSION['portal_account_id']    = $account_id;
    $_SESSION['portal_last_activity'] = time();
    unset($_SESSION['portal_csrf_token']); // Neues CSRF-Token erzeugen

    if ($portal_access_id > 0) {
        get_db()->prepare(
            'UPDATE customer_portal_access SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?'
        )->execute([$portal_access_id]);
    }
}

// true, wenn der aktuell angemeldete Portal-Nutzer ein registriertes
// Kundenkonto (E-Mail+Passwort) hat statt (nur) über Gast-Zugang angemeldet
// zu sein. Steuert z. B., ob Konto-Einstellungen/Nachrichten angezeigt werden.
function portal_is_registered_account(): bool {
    return !empty($_SESSION['portal_is_registered']);
}

// Aktuell angemeldete customer_accounts.id – NIE ungeprüfte Client-
// Eingaben für Datenzugriffe verwenden, immer diese Funktion.
function portal_current_account_id(): ?int {
    return isset($_SESSION['portal_account_id']) && $_SESSION['portal_account_id'] !== null
        ? (int)$_SESSION['portal_account_id']
        : null;
}

function portal_logout(): void {
    session_unset();
    session_destroy();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            PORTAL_SESSION_NAME, '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
}

// ── Portal-CSRF (unabhängig vom Admin-CSRF-Token) ─────────
function portal_csrf_token(): string {
    if (empty($_SESSION['portal_csrf_token'])) {
        $_SESSION['portal_csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['portal_csrf_token'];
}

function portal_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(portal_csrf_token()) . '">';
}

function portal_verify_csrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals(portal_csrf_token(), (string)$token);
}

// ── Brute-Force-Schutz für die manuelle Auftragsnummer+PIN-Anmeldung ──
function portal_is_brute_force_locked(string $ip): bool {
    $since = date('Y-m-d H:i:s', time() - (PORTAL_LOGIN_LOCKOUT_MINUTES * 60));
    $stmt  = get_db()->prepare(
        'SELECT COUNT(*) FROM portal_login_attempts
         WHERE ip_address = ? AND success = 0 AND attempted_at >= ?'
    );
    $stmt->execute([$ip, $since]);
    return (int)$stmt->fetchColumn() >= PORTAL_MAX_LOGIN_ATTEMPTS;
}

function portal_record_login_attempt(string $ip, string $identifier, bool $success): void {
    $stmt = get_db()->prepare(
        'INSERT INTO portal_login_attempts (ip_address, identifier, success) VALUES (?, ?, ?)'
    );
    $stmt->execute([$ip, $identifier, $success ? 1 : 0]);

    // Alte Einträge bereinigen (> 7 Tage)
    get_db()->exec("DELETE FROM portal_login_attempts WHERE attempted_at < NOW() - INTERVAL 7 DAY");
}

/**
 * Anmeldung per Auftragsnummer (repair_number) + PIN.
 * Ermittelt darüber den zugehörigen Kunden und dessen Portal-Zugang.
 * Gibt ['success'=>bool, 'message'=>?string] zurück.
 */
function portal_attempt_login(string $repair_number, string $pin): array {
    $ip = get_client_ip();

    if (portal_is_brute_force_locked($ip)) {
        return ['success' => false, 'message' => 'Zu viele Fehlversuche. Bitte ' . PORTAL_LOGIN_LOCKOUT_MINUTES . ' Minuten warten.'];
    }

    $repair_number = trim($repair_number);
    $pin           = trim($pin);

    if ($repair_number === '' || $pin === '') {
        portal_record_login_attempt($ip, $repair_number, false);
        return ['success' => false, 'message' => 'Bitte Auftragsnummer und PIN eingeben.'];
    }

    $stmt = get_db()->prepare(
        'SELECT r.customer_id
         FROM repairs r
         WHERE r.repair_number = ?
         LIMIT 1'
    );
    $stmt->execute([$repair_number]);
    $repair = $stmt->fetch();

    if (!$repair || empty($repair['customer_id'])) {
        portal_record_login_attempt($ip, $repair_number, false);
        return ['success' => false, 'message' => 'Auftragsnummer oder PIN falsch.'];
    }

    $access = get_customer_portal_access((int)$repair['customer_id']);

    if (!$access || empty($access['pin_encrypted']) || empty($access['pin_iv'])) {
        portal_record_login_attempt($ip, $repair_number, false);
        return ['success' => false, 'message' => 'Auftragsnummer oder PIN falsch.'];
    }

    $stored_pin = decrypt_passcode($access['pin_encrypted'], $access['pin_iv']);

    if ($stored_pin === '' || !hash_equals($stored_pin, $pin)) {
        portal_record_login_attempt($ip, $repair_number, false);
        return ['success' => false, 'message' => 'Auftragsnummer oder PIN falsch.'];
    }

    portal_record_login_attempt($ip, $repair_number, true);
    portal_login_as((int)$access['customer_id'], (int)$access['id']);

    return ['success' => true];
}

/**
 * Anmeldung per Magic-Link-Token (Bearer-Token-Auth, kein Passwort).
 * Gibt ['success'=>bool, 'message'=>?string] zurück.
 */
function portal_attempt_token_login(string $token): array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['success' => false, 'message' => 'Ungültiger Zugangslink.'];
    }

    $stmt = get_db()->prepare(
        'SELECT * FROM customer_portal_access
         WHERE (token_hash = ? OR token = ?)
           AND revoked_at IS NULL
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1'
    );
    $stmt->execute([portal_token_hash($token), $token]);
    $access = $stmt->fetch();

    if (!$access) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Zugangslink.'];
    }

    portal_login_as((int)$access['customer_id'], (int)$access['id']);
    portal_audit('customer_magic_link_login', 'customer', (int)$access['customer_id'], 'customer_portal_access', (int)$access['id']);
    return ['success' => true];
}
