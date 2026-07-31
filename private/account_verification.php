<?php
/**
 * Gemeinsame Konto-Verifizierung für Kunden- und Firmenkonten.
 * Klartext-Tokens werden nur im Rückgabewert der Erstellungsaktion gehalten.
 */

function portal_account_type_valid(string $type): bool {
    return in_array($type, ['customer', 'company_contact'], true);
}

function portal_mask_email(string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') return '***';
    $visible = mb_substr($local, 0, 1);
    return $visible . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
}

function portal_account_find(string $type, int $id): ?array {
    if ($id < 1 || !portal_account_type_valid($type)) return null;
    if ($type === 'customer') {
        $stmt = get_db()->prepare(
            'SELECT ca.id, ca.customer_id AS owner_id, ca.email, ca.is_verified, ca.is_active,
                    ca.verify_token_hash, ca.verify_expires, c.first_name, c.last_name,
                    NULL AS company_name, 1 AS password_initialized
               FROM customer_accounts ca
               JOIN customers c ON c.id = ca.customer_id
              WHERE ca.id = ? LIMIT 1'
        );
    } else {
        $stmt = get_db()->prepare(
            'SELECT cc.id, cc.company_id AS owner_id, cc.email, cc.is_verified, cc.is_active,
                    cc.verify_token_hash, cc.verify_expires, cc.first_name, cc.last_name,
                    c.company_name, cc.password_initialized
               FROM company_contacts cc
               JOIN companies c ON c.id = cc.company_id
              WHERE cc.id = ? LIMIT 1'
        );
    }
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function portal_admin_notification_create(string $type, int $accountId): void {
    if (!portal_account_type_valid($type) || $accountId < 1) return;
    try {
        get_db()->prepare(
            'INSERT INTO portal_admin_notifications
                (account_type, account_id, event_type, status, created_at)
             VALUES (?, ?, "account_pending_verification", "pending", NOW())
             ON DUPLICATE KEY UPDATE
                status = "pending", resolved_at = NULL, resolved_by = NULL, updated_at = NOW()'
        )->execute([$type, $accountId]);
    } catch (Throwable $e) {
        error_log('portal_admin_notification_create: ' . $e->getMessage());
    }
}

function portal_admin_notification_resolve(string $type, int $accountId, int $adminId, string $reason): void {
    try {
        get_db()->prepare(
            'UPDATE portal_admin_notifications
                SET status = "done", resolution = ?, resolved_at = NOW(), resolved_by = ?
              WHERE account_type = ? AND account_id = ? AND status = "pending"'
        )->execute([$reason, $adminId, $type, $accountId]);
    } catch (Throwable $e) {
        error_log('portal_admin_notification_resolve: ' . $e->getMessage());
    }
}

function portal_pending_account_count(): int {
    try {
        return (int)get_db()->query(
            'SELECT COUNT(*) FROM portal_admin_notifications WHERE status = "pending"'
        )->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function portal_account_manual_verify(string $type, int $accountId, int $adminId): array {
    $account = portal_account_find($type, $accountId);
    if (!$account) return ['success' => false, 'message' => 'Konto wurde nicht gefunden.'];
    if ((int)$account['is_verified'] === 1) {
        return ['success' => true, 'message' => 'Das Konto ist bereits bestätigt.'];
    }

    $table = $type === 'customer' ? 'customer_accounts' : 'company_contacts';
    $db = get_db();
    try {
        $db->beginTransaction();
        $db->prepare(
            "UPDATE {$table}
                SET is_verified = 1, verified_at = NOW(), verified_by = ?,
                    verify_token = NULL, verify_token_hash = NULL, verify_expires = NULL
              WHERE id = ? AND is_verified = 0"
        )->execute([$adminId, $accountId]);
        $db->prepare(
            'UPDATE portal_invitations
                SET revoked_at = COALESCE(revoked_at, NOW())
              WHERE recipient_type = ? AND recipient_id = ?
                AND accepted_at IS NULL AND revoked_at IS NULL'
        )->execute([$type, $accountId]);
        portal_admin_notification_resolve($type, $accountId, $adminId, 'manually_verified');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('portal_account_manual_verify: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Das Konto konnte nicht bestätigt werden.'];
    }

    portal_audit('account_manually_verified', 'staff', $adminId, $table, $accountId, ['account_type' => $type]);
    log_activity('portal_account_verified', $table, $accountId, 'Portalzugang manuell bestätigt');
    return [
        'success' => true,
        'message' => $type === 'company_contact' && !(int)$account['password_initialized']
            ? 'Das Konto wurde bestätigt. Für die erste Anmeldung muss noch ein Aktivierungslink zum Festlegen des Passworts bereitgestellt werden.'
            : 'Das Konto wurde bestätigt.',
    ];
}

function portal_account_create_activation(string $type, int $accountId, int $adminId): array {
    $account = portal_account_find($type, $accountId);
    if (!$account) return ['success' => false, 'message' => 'Konto wurde nicht gefunden.'];
    if ((int)$account['is_verified'] === 1 &&
        !($type === 'company_contact' && !(int)$account['password_initialized'])) {
        return ['success' => false, 'message' => 'Das Konto ist bereits bestätigt.'];
    }
    if (!(int)$account['is_active']) {
        return ['success' => false, 'message' => 'Das Konto ist deaktiviert und erhält keinen Aktivierungslink.'];
    }

    $token = portal_new_token();
    $table = $type === 'customer' ? 'customer_accounts' : 'company_contacts';
    $db = get_db();
    try {
        $db->beginTransaction();
        $db->prepare(
            'UPDATE portal_invitations SET revoked_at = COALESCE(revoked_at, NOW())
              WHERE recipient_type = ? AND recipient_id = ?
                AND accepted_at IS NULL AND revoked_at IS NULL'
        )->execute([$type, $accountId]);
        $db->prepare(
            "UPDATE {$table}
                SET verify_token = NULL, verify_token_hash = ?,
                    verify_expires = DATE_ADD(NOW(), INTERVAL 72 HOUR)
              WHERE id = ? AND (is_verified = 0" .
                ($type === 'company_contact' ? ' OR password_initialized = 0)' : ')')
        )->execute([$token['hash'], $accountId]);
        $db->prepare(
            'INSERT INTO portal_invitations
                (recipient_type, recipient_id, email, token_hash, expires_at, created_by)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 72 HOUR), ?)'
        )->execute([$type, $accountId, $account['email'], $token['hash'], $adminId]);
        portal_admin_notification_resolve($type, $accountId, $adminId, 'activation_link_created');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('portal_account_create_activation: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Der Aktivierungslink konnte nicht erstellt werden.'];
    }

    $base = preg_replace('/^http:/i', 'https:', rtrim(base_app_url(), '/'));
    $url = $type === 'customer'
        ? $base . '/portal.php?verify=' . $token['plain']
        : $base . '/portal_business.php?activate=' . $token['plain'];
    portal_audit('activation_link_created', 'staff', $adminId, $table, $accountId, [
        'account_type' => $type, 'expires_in_hours' => 72,
    ]);
    return [
        'success' => true,
        'message' => 'Aktivierungslink wurde erstellt und ist 72 Stunden gültig.',
        'link' => $url,
        'masked_email' => portal_mask_email((string)$account['email']),
        'account' => $account,
    ];
}

function portal_account_revoke_activation(string $type, int $accountId, int $adminId): array {
    $account = portal_account_find($type, $accountId);
    if (!$account) return ['success' => false, 'message' => 'Konto wurde nicht gefunden.'];
    $table = $type === 'customer' ? 'customer_accounts' : 'company_contacts';
    get_db()->prepare(
        "UPDATE {$table}
            SET verify_token = NULL, verify_token_hash = NULL, verify_expires = NULL
          WHERE id = ? AND is_verified = 0"
    )->execute([$accountId]);
    get_db()->prepare(
        'UPDATE portal_invitations SET revoked_at = COALESCE(revoked_at, NOW())
          WHERE recipient_type = ? AND recipient_id = ?
            AND accepted_at IS NULL AND revoked_at IS NULL'
    )->execute([$type, $accountId]);
    portal_audit('activation_link_revoked', 'staff', $adminId, $table, $accountId, ['account_type' => $type]);
    return ['success' => true, 'message' => 'Vorhandene Aktivierungslinks wurden widerrufen.'];
}

function portal_account_send_activation(string $type, int $accountId, int $adminId): array {
    if (!portal_email_delivery_enabled()) {
        return ['success' => false, 'message' => 'Der E-Mail-Versand ist derzeit nicht eingerichtet. Bitte erstellen und kopieren Sie den Aktivierungslink.'];
    }
    $created = portal_account_create_activation($type, $accountId, $adminId);
    if (!$created['success']) return $created;
    $account = $created['account'];
    $sent = false;
    try {
        if ($type === 'customer') {
            $sent = send_customer_account_email('konto_verifizieren', [
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'email' => $account['email'],
            ], ['verify_link' => $created['link']]);
        } else {
            $sent = send_generic_template_email('firmenkontakt_konto_erstellt', [
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'email' => $account['email'],
            ], [
                'verify_link' => $created['link'],
                'firma' => $account['company_name'] ?? '',
            ]);
        }
    } catch (Throwable $e) {
        error_log('portal_account_send_activation: ' . $e->getMessage());
    }
    if (!$sent) {
        return ['success' => false, 'message' => 'Die E-Mail wurde nicht angenommen. Der Link kann weiterhin manuell bereitgestellt werden.', 'link' => $created['link']];
    }
    portal_audit('activation_link_sent', 'staff', $adminId, $type === 'customer' ? 'customer_accounts' : 'company_contacts', $accountId);
    return ['success' => true, 'message' => 'Der Aktivierungslink wurde an ' . $created['masked_email'] . ' gesendet.'];
}

function portal_activation_attempt_allowed(string $ip): bool {
    try {
        $stmt = get_db()->prepare(
            'SELECT COUNT(*) FROM portal_activation_attempts
              WHERE ip_address = ? AND success = 0
                AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn() < 20;
    } catch (Throwable) {
        return true;
    }
}

function portal_activation_attempt_record(string $ip, bool $success): void {
    try {
        get_db()->prepare(
            'INSERT INTO portal_activation_attempts (ip_address, success, created_at) VALUES (?, ?, NOW())'
        )->execute([$ip, $success ? 1 : 0]);
    } catch (Throwable) {
    }
}

function portal_activation_token_state(string $type, string $token): string {
    if (!portal_account_type_valid($type) || !preg_match('/^[a-f0-9]{64}$/', $token)) return 'invalid';
    try {
        $stmt = get_db()->prepare(
            'SELECT accepted_at, revoked_at, expires_at FROM portal_invitations
              WHERE recipient_type = ? AND token_hash = ? LIMIT 1'
        );
        $stmt->execute([$type, portal_token_hash($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'invalid';
        if (!empty($row['accepted_at'])) return 'used';
        if (!empty($row['revoked_at'])) return 'revoked';
        if (strtotime((string)$row['expires_at']) < time()) return 'expired';
        return 'valid';
    } catch (Throwable) {
        return 'invalid';
    }
}
