<?php
require_once __DIR__ . '/account_verification.php';
/**
 * MZ Tech – Kundenportal: registrierte Kundenkonten (E-Mail + Passwort)
 *
 * Läuft PARALLEL zum passwortlosen Gast-Zugang (siehe portal_auth.php /
 * Tabelle customer_portal_access). Ein Kunde kann wahlweise Gast bleiben
 * oder sich zusätzlich ein vollständiges Konto anlegen. Beide Wege
 * münden in derselben Portal-Session (portal_login_as()).
 *
 * Sicherheit:
 *  - Passwörter ausschließlich als bcrypt-Hash (password_hash()) gespeichert,
 *    niemals im Klartext.
 *  - E-Mail-Verifizierung per Zeit-limitiertem Token vor dem ersten Login.
 *  - Passwort-Reset per Zeit-limitiertem Token, keine Enumeration möglich
 *    (customer_request_password_reset() antwortet immer gleich).
 *  - IP-basierter Brute-Force-Schutz über dieselbe Tabelle/Logik wie
 *    beim Gast-Login (portal_login_attempts).
 *  - NIE ungeprüfte Client-Eingaben für Datenzugriffe verwenden (IDOR-
 *    Schutz) – siehe portal_current_customer_id() in portal_auth.php,
 *    das gilt unverändert auch für registrierte Konten.
 */

// true, wenn die Kontoregistrierung aktuell aktiviert ist (Einstellungen).
function customer_accounts_enabled(): bool {
    return get_setting('customer_accounts_enabled', '1') === '1';
}

function customer_find_account_by_email(string $email): ?array {
    $stmt = get_db()->prepare('SELECT * FROM customer_accounts WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function customer_find_account_by_customer_id(int $customer_id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM customer_accounts WHERE customer_id = ? LIMIT 1');
    $stmt->execute([$customer_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Registrierung eines neuen Kundenkontos.
 * $data: first_name, last_name, email, phone, password, password2, gdpr_consent
 * Rückgabe: ['success' => bool, 'message' => string]
 */
function customer_register(array $data): array {
    if (!customer_accounts_enabled()) {
        return ['success' => false, 'message' => 'Die Kontoregistrierung ist derzeit nicht verfügbar.'];
    }

    $first_name = trim((string)($data['first_name'] ?? ''));
    $last_name  = trim((string)($data['last_name']  ?? ''));
    $email      = trim((string)($data['email']      ?? ''));
    $phone      = trim((string)($data['phone']      ?? ''));
    $password   = (string)($data['password']  ?? '');
    $password2  = (string)($data['password2'] ?? '');
    $gdpr       = !empty($data['gdpr_consent']);

    if ($first_name === '' || $last_name === '' || $email === '') {
        return ['success' => false, 'message' => 'Bitte füllen Sie alle Pflichtfelder aus.'];
    }
    if (mb_strlen($first_name) > 100 || mb_strlen($last_name) > 100) {
        return ['success' => false, 'message' => 'Vor-/Nachname ist zu lang.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'];
    }
    if (mb_strlen($password) < 8) {
        return ['success' => false, 'message' => 'Das Passwort muss mindestens 8 Zeichen lang sein.'];
    }
    if ($password !== $password2) {
        return ['success' => false, 'message' => 'Die Passwörter stimmen nicht überein.'];
    }
    if (!$gdpr) {
        return ['success' => false, 'message' => 'Bitte stimmen Sie der Datenschutzerklärung zu.'];
    }

    $db = get_db();

    if (customer_find_account_by_email($email)) {
        return ['success' => false, 'message' => 'Für diese E-Mail-Adresse besteht bereits ein Konto. Bitte melden Sie sich an oder setzen Sie Ihr Passwort zurück.'];
    }

    // Vorhandenen Gast-Kunden mit gleicher E-Mail wiederverwenden, damit
    // die bestehende Reparaturhistorie automatisch mit dem neuen Konto
    // verknüpft ist – sonst neuen Kundendatensatz anlegen.
    $stmt = $db->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing_customer = $stmt->fetch();

    if ($existing_customer) {
        $customer_id = (int)$existing_customer['id'];
        if ($phone !== '') {
            $db->prepare("UPDATE customers SET phone = COALESCE(NULLIF(phone, ''), ?) WHERE id = ?")
               ->execute([$phone, $customer_id]);
        }
    } else {
        $stmt = $db->prepare(
            'INSERT INTO customers (first_name, last_name, email, phone, gdpr_consent, gdpr_date)
             VALUES (?, ?, ?, ?, 1, NOW())'
        );
        $stmt->execute([$first_name, $last_name, $email, $phone !== '' ? $phone : null]);
        $customer_id = (int)$db->lastInsertId();
    }

    $verify = portal_new_token();
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    try {
        $stmt = $db->prepare(
            'INSERT INTO customer_accounts
                (customer_id, email, password_hash, is_verified, verify_token, verify_token_hash, verify_expires)
             VALUES (?, ?, ?, 0, NULL, ?, DATE_ADD(NOW(), INTERVAL 72 HOUR))'
        );
        $stmt->execute([$customer_id, $email, $hash, $verify['hash']]);
    } catch (PDOException $e) {
        error_log('customer_register: Insert fehlgeschlagen: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Registrierung derzeit nicht möglich. Bitte versuchen Sie es später erneut.'];
    }

    $account_id = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO portal_invitations
            (recipient_type, recipient_id, email, token_hash, expires_at)
         VALUES (\'customer\', ?, ?, ?, DATE_ADD(NOW(), INTERVAL 72 HOUR))'
    )->execute([$account_id, $email, $verify['hash']]);

    try {
        if (portal_email_delivery_enabled()) {
            $verify_link = base_app_url() . '/portal.php?verify=' . $verify['plain'];
            send_customer_account_email('konto_verifizieren', [
                'first_name' => $first_name, 'last_name' => $last_name, 'email' => $email,
            ], ['verify_link' => $verify_link]);
        }
    } catch (Throwable $e) {
        error_log('customer_register: Verifizierungsmail fehlgeschlagen: ' . $e->getMessage());
    }

    log_activity('customer_register', 'customer_accounts', $account_id, 'Neue Kontoregistrierung: ' . $email);
    portal_admin_notification_create('customer', $account_id);

    return [
        'success' => true,
        'message' => portal_email_delivery_enabled()
            ? 'Registrierung erfolgreich! Bitte bestätigen Sie Ihre E-Mail-Adresse über den zugesandten Link.'
            : 'Registrierung erfolgreich. Ihr Konto ist noch nicht bestätigt. MZ Tech kann Ihren Zugang direkt freischalten oder Ihnen einen Aktivierungslink bereitstellen.',
    ];
}

/**
 * Bestätigt eine E-Mail-Adresse anhand des Tokens aus der Verifizierungsmail.
 * Rückgabe: ['success' => bool, 'message' => string]
 */
function customer_verify_email(string $token): array {
    $token = trim($token);
    $ip = get_client_ip();
    if (!portal_activation_attempt_allowed($ip)) {
        return ['success' => false, 'message' => 'Zu viele Aktivierungsversuche. Bitte versuchen Sie es später erneut.'];
    }
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        portal_activation_attempt_record($ip, false);
        return ['success' => false, 'message' => 'Ungültiger Bestätigungslink.'];
    }

    $db = get_db();
    $stmt = $db->prepare(
        'SELECT * FROM customer_accounts
         WHERE verify_token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([portal_token_hash($token)]);
    $account = $stmt->fetch();

    if (!$account) {
        portal_activation_attempt_record($ip, false);
        $state = portal_activation_token_state('customer', $token);
        return ['success' => false, 'message' => match ($state) {
            'used' => 'Dieses Konto wurde bereits aktiviert.',
            'expired' => 'Dieser Aktivierungslink ist abgelaufen. Bitte wenden Sie sich an MZ Tech, um einen neuen Link zu erhalten.',
            'revoked' => 'Dieser Aktivierungslink ist nicht mehr gültig.',
            default => 'Dieser Aktivierungslink ist nicht mehr gültig.',
        }];
    }
    if (!empty($account['verify_expires']) && strtotime($account['verify_expires']) < time()) {
        portal_activation_attempt_record($ip, false);
        return ['success' => false, 'message' => 'Der Bestätigungslink ist abgelaufen. Bitte fordern Sie über "Passwort vergessen" einen neuen Zugang an oder kontaktieren Sie uns.'];
    }

    $db->prepare(
        'UPDATE customer_accounts
         SET is_verified = 1, verified_at = NOW(), verified_by = NULL,
             verify_token = NULL, verify_token_hash = NULL, verify_expires = NULL
         WHERE id = ?'
    )
       ->execute([$account['id']]);
    $db->prepare(
        'UPDATE portal_invitations SET accepted_at = NOW()
         WHERE recipient_type = \'customer\' AND recipient_id = ?
           AND token_hash = ? AND accepted_at IS NULL'
    )->execute([$account['id'], portal_token_hash($token)]);
    $db->prepare(
        'UPDATE portal_admin_notifications
            SET status = "done", resolution = "self_activated", resolved_at = NOW()
          WHERE account_type = "customer" AND account_id = ? AND status = "pending"'
    )->execute([$account['id']]);
    portal_activation_attempt_record($ip, true);
    portal_audit('customer_account_activated', 'customer', (int)$account['customer_id'], 'customer_accounts', (int)$account['id']);

    log_activity('customer_verify_email', 'customer_accounts', (int)$account['id'], 'E-Mail-Adresse bestätigt');

    return ['success' => true, 'message' => 'Ihr Zugang wurde erfolgreich aktiviert. Sie können sich jetzt anmelden.'];
}

/**
 * Anmeldung per registriertem Konto (E-Mail + Passwort). Bei Erfolg wird
 * automatisch auch der passwortlose Gast-Zugang (customer_portal_access)
 * sichergestellt und dieselbe Portal-Session gestartet wie beim Gast-
 * Login, damit beide Zugangswege identisch funktionieren.
 */
function customer_attempt_login(string $email, string $password): array {
    $ip = get_client_ip();
    $email = trim($email);

    if (portal_is_brute_force_locked($ip)) {
        return ['success' => false, 'message' => 'Zu viele Fehlversuche. Bitte ' . PORTAL_LOGIN_LOCKOUT_MINUTES . ' Minuten warten.'];
    }

    if ($email === '' || $password === '') {
        portal_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'Bitte E-Mail-Adresse und Passwort eingeben.'];
    }

    $account = customer_find_account_by_email($email);

    if (!$account || !password_verify($password, $account['password_hash'])) {
        portal_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'E-Mail-Adresse oder Passwort falsch.'];
    }
    if (!(int)$account['is_active']) {
        portal_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'Dieses Konto wurde deaktiviert. Bitte kontaktieren Sie uns.'];
    }
    if (!(int)$account['is_verified']) {
        portal_record_login_attempt($ip, $email, false);
        return ['success' => false, 'message' => 'Ihr Konto ist noch nicht bestätigt. MZ Tech kann Ihren Zugang direkt freischalten oder Ihnen einen Aktivierungslink bereitstellen.'];
    }

    portal_record_login_attempt($ip, $email, true);

    $db = get_db();
    $db->prepare('UPDATE customer_accounts SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?')
       ->execute([$account['id']]);

    $customer_id = (int)$account['customer_id'];

    // Passwortlosen Gast-Zugang (Link/PIN) parallel sicherstellen, damit
    // dieselbe customer_portal_access-Zeile für die Session verwendet
    // wird (z. B. für QR-Codes in E-Mails/PDFs weiterhin funktioniert).
    ensure_customer_portal_access($customer_id);
    $access = get_customer_portal_access($customer_id);

    portal_login_as($customer_id, (int)($access['id'] ?? 0), true, (int)$account['id']);

    return ['success' => true];
}

/**
 * Passwort-Reset anfordern: erzeugt bei existierendem Konto einen Token
 * und sendet eine E-Mail. Gibt aus Sicherheitsgründen IMMER
 * success=true zurück (kein Enumerations-Leck, ob eine E-Mail-Adresse
 * registriert ist).
 */
function customer_request_password_reset(string $email): array {
    $email = trim($email);
    $generic = [
        'success' => true,
        'message' => portal_email_delivery_enabled()
            ? 'Falls zu dieser E-Mail-Adresse ein Konto besteht, wurde ein Link zum Zurücksetzen versendet.'
            : 'Falls zu dieser E-Mail-Adresse ein Konto besteht, kontaktieren Sie bitte MZ Tech. Der E-Mail-Versand ist derzeit deaktiviert.',
    ];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $generic;
    }

    $account = customer_find_account_by_email($email);
    if ($account) {
        $token = portal_new_token();
        $db = get_db();
        $db->prepare(
            'UPDATE customer_accounts
             SET reset_token = NULL, reset_token_hash = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 60 MINUTE)
             WHERE id = ?'
        )->execute([$token['hash'], $account['id']]);

        $stmt = $db->prepare('SELECT first_name, last_name FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$account['customer_id']]);
        $customer = $stmt->fetch() ?: ['first_name' => '', 'last_name' => ''];

        try {
            if (portal_email_delivery_enabled()) {
                $reset_link = base_app_url() . '/portal.php?reset=' . $token['plain'];
                send_customer_account_email('konto_passwort_reset', [
                    'first_name' => $customer['first_name'], 'last_name' => $customer['last_name'], 'email' => $email,
                ], ['reset_link' => $reset_link]);
            }
        } catch (Throwable $e) {
            error_log('customer_request_password_reset: Mail fehlgeschlagen: ' . $e->getMessage());
        }

        log_activity('customer_password_reset_request', 'customer_accounts', (int)$account['id'], 'Passwort-Reset angefordert');
    }

    return $generic;
}

/**
 * Setzt anhand eines gültigen Reset-Tokens ein neues Passwort.
 * Rückgabe: ['success' => bool, 'message' => string]
 */
function customer_reset_password(string $token, string $password, string $password2): array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Link.'];
    }
    if (mb_strlen($password) < 8) {
        return ['success' => false, 'message' => 'Das Passwort muss mindestens 8 Zeichen lang sein.'];
    }
    if ($password !== $password2) {
        return ['success' => false, 'message' => 'Die Passwörter stimmen nicht überein.'];
    }

    $db = get_db();
    $stmt = $db->prepare(
        'SELECT * FROM customer_accounts
         WHERE reset_token_hash = ? OR reset_token = ?
         LIMIT 1'
    );
    $stmt->execute([portal_token_hash($token), $token]);
    $account = $stmt->fetch();

    if (!$account || empty($account['reset_expires']) || strtotime($account['reset_expires']) < time()) {
        return ['success' => false, 'message' => 'Ungültiger oder abgelaufener Link. Bitte fordern Sie einen neuen an.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $db->prepare(
        'UPDATE customer_accounts
         SET password_hash = ?, reset_token = NULL, reset_token_hash = NULL,
             reset_expires = NULL, is_verified = 1
         WHERE id = ?'
    )
       ->execute([$hash, $account['id']]);
    portal_audit('customer_password_reset', 'customer', (int)$account['customer_id'], 'customer_accounts', (int)$account['id']);

    log_activity('customer_password_reset', 'customer_accounts', (int)$account['id'], 'Passwort erfolgreich zurückgesetzt');

    return ['success' => true, 'message' => 'Ihr Passwort wurde erfolgreich geändert. Sie können sich jetzt anmelden.'];
}

/**
 * Aktives, angemeldetes Konto ändert sein eigenes Passwort (aus dem
 * Portal-Bereich heraus, nicht per Reset-Link). $account_id muss aus
 * portal_current_account_id() stammen (nie aus Client-Eingaben).
 */
function customer_change_password(int $account_id, string $current_password, string $new_password, string $new_password2): array {
    if (mb_strlen($new_password) < 8) {
        return ['success' => false, 'message' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein.'];
    }
    if ($new_password !== $new_password2) {
        return ['success' => false, 'message' => 'Die neuen Passwörter stimmen nicht überein.'];
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM customer_accounts WHERE id = ? LIMIT 1');
    $stmt->execute([$account_id]);
    $account = $stmt->fetch();

    if (!$account || !password_verify($current_password, $account['password_hash'])) {
        return ['success' => false, 'message' => 'Das aktuelle Passwort ist nicht korrekt.'];
    }

    $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $db->prepare('UPDATE customer_accounts SET password_hash = ? WHERE id = ?')->execute([$hash, $account_id]);

    log_activity('customer_password_change', 'customer_accounts', $account_id, 'Passwort im Kundenportal geändert');

    return ['success' => true, 'message' => 'Ihr Passwort wurde erfolgreich geändert.'];
}
