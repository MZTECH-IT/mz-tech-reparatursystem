<?php
/**
 * Gemeinsame Sicherheitsfunktionen für Kunden-, Firmen- und Gastportal.
 *
 * Tokens werden ausschließlich gehasht gespeichert. Der Klartextwert wird
 * nur einmal für den Link zurückgegeben und danach nicht protokolliert.
 */

function portal_token_hash(string $token): string {
    return hash('sha256', $token);
}

function portal_new_token(): array {
    $plain = bin2hex(random_bytes(32));
    return ['plain' => $plain, 'hash' => portal_token_hash($plain)];
}

function portal_email_delivery_enabled(): bool {
    return get_setting('portal_email_delivery_enabled', '0') === '1';
}

function portal_audit(
    string $event,
    string $actorType,
    ?int $actorId = null,
    ?string $entityType = null,
    ?int $entityId = null,
    array $metadata = []
): void {
    try {
        $stmt = get_db()->prepare(
            'INSERT INTO portal_activity_log
                (event_type, actor_type, actor_id, entity_type, entity_id, ip_address, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $event,
            $actorType,
            $actorId,
            $entityType,
            $entityId,
            get_client_ip(),
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        error_log('portal_audit: ' . $e->getMessage());
    }
}

function portal_guest_access_create(
    string $scopeType,
    int $scopeId,
    ?int $customerId,
    int $lifetimeHours = 72,
    bool $oneTime = false,
    ?int $createdBy = null
): array {
    if (!in_array($scopeType, ['repair', 'ticket', 'document'], true) || $scopeId < 1) {
        return ['success' => false, 'message' => 'Ungültiger Freigabebereich.'];
    }
    $lifetimeHours = max(1, min(720, $lifetimeHours));
    $token = portal_new_token();
    $stmt = get_db()->prepare(
        'INSERT INTO portal_guest_access
            (token_hash, scope_type, scope_id, customer_id, expires_at, one_time, created_by)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?, ?)'
    );
    $stmt->execute([
        $token['hash'], $scopeType, $scopeId, $customerId,
        $lifetimeHours, $oneTime ? 1 : 0, $createdBy,
    ]);
    $id = (int)get_db()->lastInsertId();
    portal_audit('guest_access_created', 'staff', $createdBy, $scopeType, $scopeId, [
        'guest_access_id' => $id,
        'expires_in_hours' => $lifetimeHours,
        'one_time' => $oneTime,
    ]);
    return [
        'success' => true,
        'id' => $id,
        'url' => base_app_url() . '/portal_guest.php?t=' . $token['plain'],
    ];
}

function portal_guest_access_resolve(string $plainToken): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
        return null;
    }
    $stmt = get_db()->prepare(
        'SELECT * FROM portal_guest_access
         WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
           AND (one_time = 0 OR used_at IS NULL)
         LIMIT 1'
    );
    $stmt->execute([portal_token_hash($plainToken)]);
    $access = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$access) {
        return null;
    }
    get_db()->prepare(
        'UPDATE portal_guest_access
         SET last_access_at = NOW(), used_at = CASE WHEN one_time = 1 THEN NOW() ELSE used_at END
         WHERE id = ?'
    )->execute([$access['id']]);
    portal_audit('guest_access_used', 'guest', null, $access['scope_type'], (int)$access['scope_id'], [
        'guest_access_id' => (int)$access['id'],
    ]);
    return $access;
}

function portal_guest_access_revoke(int $id, ?int $revokedBy): void {
    get_db()->prepare(
        'UPDATE portal_guest_access SET revoked_at = NOW(), revoked_by = ? WHERE id = ? AND revoked_at IS NULL'
    )->execute([$revokedBy, $id]);
    portal_audit('guest_access_revoked', 'staff', $revokedBy, 'guest_access', $id);
}

function portal_safe_upload(array $file, string $targetDirectory): array|false {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)($file['tmp_name'] ?? ''))
        || (int)($file['size'] ?? 0) < 1
        || (int)$file['size'] > MAX_UPLOAD_SIZE) {
        return false;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return false;
    }

    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true)) {
        return false;
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $destination = rtrim($targetDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return false;
    }
    return [
        'filename' => $filename,
        'original_name' => mb_substr(basename((string)$file['name']), 0, 255),
        'file_size' => (int)$file['size'],
        'mime_type' => $mime,
    ];
}
