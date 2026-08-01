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

function portal_activation_lifetime_hours(): int {
    return max(1, min(168, (int)get_setting('portal_activation_lifetime_hours', '72')));
}

function portal_activation_resend_minutes(): int {
    return max(1, min(60, (int)get_setting('portal_activation_resend_minutes', '5')));
}

function portal_activation_delivery_log(
    string $type,
    int $accountId,
    string $recipient,
    string $result,
    ?string $errorCategory,
    int $adminId,
    string $templateKey,
    ?int $invitationId
): void {
    get_db()->prepare(
        'INSERT INTO portal_activation_mail_log
            (account_type, account_id, recipient_email, delivery_method, result,
             error_category, admin_id, template_key, invitation_id, attempted_at)
         VALUES (?, ?, ?, "smtp_tls", ?, ?, ?, ?, ?, NOW())'
    )->execute([
        $type, $accountId, mb_strtolower(trim($recipient)), $result,
        $errorCategory, $adminId, $templateKey, $invitationId,
    ]);
}

function portal_activation_resend_wait_seconds(string $type, int $accountId): int {
    try {
        $stmt = get_db()->prepare(
            'SELECT attempted_at FROM portal_activation_mail_log
              WHERE account_type = ? AND account_id = ?
              ORDER BY attempted_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$type, $accountId]);
        $last = $stmt->fetchColumn();
        if (!$last) return 0;
        return max(0, strtotime((string)$last) + portal_activation_resend_minutes() * 60 - time());
    } catch (Throwable $e) {
        error_log('Aktivierungsversand: Rate-Limit konnte nicht geprüft werden.');
        return portal_activation_resend_minutes() * 60;
    }
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
    $lifetimeHours = portal_activation_lifetime_hours();
    $expiresAt = date('Y-m-d H:i:s', time() + $lifetimeHours * 3600);
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
                    verify_expires = ?
              WHERE id = ? AND (is_verified = 0" .
                ($type === 'company_contact' ? ' OR password_initialized = 0)' : ')')
        )->execute([$token['hash'], $expiresAt, $accountId]);
        $db->prepare(
            'INSERT INTO portal_invitations
                (recipient_type, recipient_id, email, token_hash, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$type, $accountId, $account['email'], $token['hash'], $expiresAt, $adminId]);
        $invitationId = (int)$db->lastInsertId();
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
        'account_type' => $type, 'expires_in_hours' => $lifetimeHours,
        'invitation_id' => $invitationId,
    ]);
    return [
        'success' => true,
        'message' => 'Aktivierungslink wurde erstellt und ist ' . $lifetimeHours . ' Stunden gültig.',
        'link' => $url,
        'masked_email' => portal_mask_email((string)$account['email']),
        'account' => $account,
        'invitation_id' => $invitationId,
        'token_hash' => $token['hash'],
        'expires_at' => $expiresAt,
        'lifetime_hours' => $lifetimeHours,
    ];
}

function portal_account_revoke_activation(string $type, int $accountId, int $adminId): array {
    $account = portal_account_find($type, $accountId);
    if (!$account) return ['success' => false, 'message' => 'Konto wurde nicht gefunden.'];
    $table = $type === 'customer' ? 'customer_accounts' : 'company_contacts';
    get_db()->prepare(
        "UPDATE {$table}
            SET verify_token = NULL, verify_token_hash = NULL, verify_expires = NULL
          WHERE id = ? AND (is_verified = 0" .
            ($type === 'company_contact' ? ' OR password_initialized = 0)' : ')')
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
    $account = portal_account_find($type, $accountId);
    if (!$account) return ['success' => false, 'message' => 'Konto wurde nicht gefunden.'];
    if (!portal_email_delivery_enabled()) {
        return ['success' => false, 'message' => 'Die E-Mail konnte nicht versendet werden. Das Konto wurde nicht automatisch aktiviert. Bitte SMTP-Konfiguration und Versandprotokoll prüfen.'];
    }
    $waitSeconds = portal_activation_resend_wait_seconds($type, $accountId);
    if ($waitSeconds > 0) {
        return [
            'success' => false,
            'message' => 'Für dieses Konto wurde vor Kurzem bereits ein Versand versucht. Bitte warten Sie noch ' . (int)ceil($waitSeconds / 60) . ' Minute(n).',
        ];
    }
    $created = portal_account_create_activation($type, $accountId, $adminId);
    if (!$created['success']) return $created;
    $account = $created['account'];
    $templateKey = $type === 'customer' ? 'portal_activation_customer' : 'portal_activation_company';
    $delivery = ['success' => false, 'error_category' => 'application'];
    try {
        $delivery = send_portal_activation_email(
            $type,
            $account,
            (string)$created['link'],
            (int)$created['lifetime_hours']
        );
    } catch (Throwable $e) {
        error_log('Aktivierungsversand: Anwendungsfehler.');
    }
    $sent = !empty($delivery['success']);
    try {
        portal_activation_delivery_log(
            $type, $accountId, (string)$account['email'], $sent ? 'sent' : 'failed',
            $sent ? null : (string)($delivery['error_category'] ?? 'smtp'),
            $adminId, $templateKey, (int)$created['invitation_id']
        );
    } catch (Throwable $e) {
        if ($sent) {
            error_log('Aktivierungsversand: Versandprotokoll konnte nicht gespeichert werden.');
            $sent = false;
            $delivery['error_category'] = 'logging';
        }
    }
    if (!$sent) {
        $table = $type === 'customer' ? 'customer_accounts' : 'company_contacts';
        $db = get_db();
        try {
            $db->beginTransaction();
            $db->prepare('UPDATE portal_invitations SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?')
                ->execute([(int)$created['invitation_id']]);
            $db->prepare(
                "UPDATE {$table} SET verify_token_hash = NULL, verify_expires = NULL
                  WHERE id = ? AND verify_token_hash = ?"
            )->execute([$accountId, $created['token_hash']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Aktivierungsversand: fehlgeschlagener Token konnte nicht vollständig widerrufen werden.');
        }
        portal_audit('activation_email_failed', 'staff', $adminId, $table, $accountId, [
            'invitation_id' => (int)$created['invitation_id'],
            'error_category' => (string)($delivery['error_category'] ?? 'smtp'),
        ]);
        return ['success' => false, 'message' => 'Die E-Mail konnte nicht versendet werden. Das Konto wurde nicht automatisch aktiviert. Bitte SMTP-Konfiguration und Versandprotokoll prüfen.'];
    }
    portal_audit('activation_link_sent', 'staff', $adminId, $type === 'customer' ? 'customer_accounts' : 'company_contacts', $accountId, [
        'invitation_id' => (int)$created['invitation_id'],
        'template' => $templateKey,
    ]);
    return ['success' => true, 'message' => 'Der Aktivierungslink wurde erfolgreich per E-Mail versendet.'];
}

function portal_account_deactivate(string $type, int $accountId, int $adminId): array {
    $account = portal_account_find($type, $accountId);
    if (!$account) return ['success' => false, 'message' => 'Konto wurde nicht gefunden.'];
    $table = $type === 'customer' ? 'customer_accounts' : 'company_contacts';
    $db = get_db();
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE {$table} SET is_active = 0, verify_token = NULL, verify_token_hash = NULL, verify_expires = NULL WHERE id = ?")
            ->execute([$accountId]);
        $db->prepare(
            'UPDATE portal_invitations SET revoked_at = COALESCE(revoked_at, NOW())
              WHERE recipient_type = ? AND recipient_id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
        )->execute([$type, $accountId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => 'Das Konto konnte nicht deaktiviert werden.'];
    }
    portal_audit('portal_account_deactivated', 'staff', $adminId, $table, $accountId, ['account_type' => $type]);
    return ['success' => true, 'message' => 'Das Konto wurde deaktiviert und offene Aktivierungslinks wurden widerrufen.'];
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
