<?php
/**
 * MZ Tech – Firmenkundenportal: Authentifizierung & Session (Phase 5)
 *
 * Eigenständig von private/auth.php (Admin) UND von private/portal_auth.php
 * (Privatkundenportal) – nutzt einen eigenen Session-Namen
 * (BUSINESS_SESSION_NAME), damit ein Ansprechpartner niemals versehentlich
 * mit einer Admin- oder Privatkunden-Sitzung im selben Browser kollidiert
 * (siehe Architekturentscheidung: getrennte Login-/Rechte-Systeme,
 * Master-Auftrag Phase 3-5).
 *
 * WICHTIG (Datenisolation): Jede Datenbankabfrage im Firmenkundenportal
 * muss zwingend nach business_current_contact_id()/business_current_company_id()
 * filtern – niemals nach einer vom Client übergebenen ID (IDOR-Schutz).
 */

function start_business_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name(BUSINESS_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => SESSION_COOKIE_PATH,
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!empty($_SESSION['business_last_activity'])) {
        if (time() - $_SESSION['business_last_activity'] > BUSINESS_SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            session_start();
        }
    }
    $_SESSION['business_last_activity'] = time();
}

function business_require_login(): void {
    start_business_session();
    if (empty($_SESSION['business_contact_id'])) {
        header('Location: ' . url('portal_business.php'));
        exit;
    }
    $stmt = get_db()->prepare(
        'SELECT id, company_id, portal_role, is_active, is_verified
         FROM company_contacts WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int)$_SESSION['business_contact_id']]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact || !(int)$contact['is_active'] || !(int)$contact['is_verified']
        || (int)$contact['company_id'] !== (int)($_SESSION['business_company_id'] ?? 0)) {
        business_logout();
        header('Location: ' . url('portal_business.php'));
        exit;
    }
    $_SESSION['business_portal_role'] = $contact['portal_role'] ?: 'employee';
}

function business_is_logged_in(): bool {
    return !empty($_SESSION['business_contact_id']);
}

function business_current_contact_id(): ?int {
    return isset($_SESSION['business_contact_id']) ? (int)$_SESSION['business_contact_id'] : null;
}

function business_current_company_id(): ?int {
    return isset($_SESSION['business_company_id']) ? (int)$_SESSION['business_company_id'] : null;
}

function business_current_role(): string {
    return in_array(($_SESSION['business_portal_role'] ?? ''), ['admin', 'employee', 'read_only'], true)
        ? $_SESSION['business_portal_role'] : 'employee';
}

function business_can(string $capability): bool {
    $role = business_current_role();
    return match ($capability) {
        'manage_users' => $role === 'admin',
        'create_ticket', 'reply_ticket', 'upload_attachment' => in_array($role, ['admin', 'employee'], true),
        'view' => in_array($role, ['admin', 'employee', 'read_only'], true),
        default => false,
    };
}

function business_login_as(int $contactId, int $companyId, string $portalRole = 'employee'): void {
    session_regenerate_id(true);
    $_SESSION['business_contact_id']    = $contactId;
    $_SESSION['business_company_id']    = $companyId;
    $_SESSION['business_portal_role']   = in_array($portalRole, ['admin', 'employee', 'read_only'], true)
        ? $portalRole : 'employee';
    $_SESSION['business_last_activity'] = time();
    unset($_SESSION['business_csrf_token']);

    get_db()->prepare('UPDATE company_contacts SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?')
        ->execute([$contactId]);
}

function business_logout(): void {
    session_unset();
    session_destroy();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            BUSINESS_SESSION_NAME, '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
}

function business_csrf_token(): string {
    if (empty($_SESSION['business_csrf_token'])) {
        $_SESSION['business_csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['business_csrf_token'];
}

function business_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(business_csrf_token()) . '">';
}

function business_verify_csrf(): bool {
    return hash_equals(business_csrf_token(), (string)($_POST['csrf_token'] ?? ''));
}

// ── Brute-Force-Schutz (wiederverwendet die generische Tabelle
//    portal_login_attempts – identifier = E-Mail des Ansprechpartners,
//    vermeidet eine doppelte Funktion für denselben Zweck). ──
function business_is_brute_force_locked(string $ip): bool {
    $since = date('Y-m-d H:i:s', time() - (BUSINESS_LOGIN_LOCKOUT_MINUTES * 60));
    $stmt  = get_db()->prepare(
        'SELECT COUNT(*) FROM portal_login_attempts
         WHERE ip_address = ? AND success = 0 AND attempted_at >= ?'
    );
    $stmt->execute([$ip, $since]);
    return (int)$stmt->fetchColumn() >= BUSINESS_MAX_LOGIN_ATTEMPTS;
}

function business_record_login_attempt(string $ip, string $identifier, bool $success): void {
    get_db()->prepare('INSERT INTO portal_login_attempts (ip_address, identifier, success) VALUES (?, ?, ?)')
        ->execute([$ip, $identifier, $success ? 1 : 0]);
}

function business_attempt_login(string $email, string $password): array {
    $ip = get_client_ip();
    $email = trim($email);

    if (business_is_brute_force_locked($ip)) {
        return ['success' => false, 'message' => 'Zu viele Fehlversuche. Bitte ' . BUSINESS_LOGIN_LOCKOUT_MINUTES . ' Minuten warten.'];
    }
    if ($email === '' || $password === '') {
        business_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'Bitte E-Mail-Adresse und Passwort eingeben.'];
    }

    $contact = company_contact_find_by_email($email);
    if (!$contact || !password_verify($password, $contact['password_hash'])) {
        business_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'E-Mail-Adresse oder Passwort falsch.'];
    }
    if (!(int)$contact['is_active']) {
        business_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'Dieser Zugang wurde deaktiviert. Bitte kontaktieren Sie uns.'];
    }
    if (!(int)$contact['is_verified']) {
        business_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'Ihr Konto ist noch nicht bestätigt. MZ Tech kann Ihren Zugang direkt freischalten oder Ihnen einen Aktivierungslink bereitstellen.'];
    }
    if (!(int)($contact['password_initialized'] ?? 0)) {
        business_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'Für diesen Zugang muss zuerst über einen Aktivierungslink ein persönliches Passwort festgelegt werden.'];
    }

    business_record_login_attempt($ip, $email, true);
    business_login_as(
        (int)$contact['id'],
        (int)$contact['company_id'],
        (string)($contact['portal_role'] ?? 'employee')
    );
    portal_audit('business_login', 'company_contact', (int)$contact['id'], 'companies', (int)$contact['company_id']);

    return ['success' => true];
}
