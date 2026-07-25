<?php
/**
 * MZ Tech – Google-Kalender-Synchronisation (optional, kostenlos)
 *
 * Nutzt ausschließlich die kostenlose Google Calendar API v3 per
 * einfachen cURL-Aufrufen (kein SDK, keine Composer-Abhängigkeit,
 * keine Kosten). Die Funktion ist standardmäßig deaktiviert
 * (`gcal_enabled` = '0') und bricht bei fehlender Konfiguration oder
 * Netzwerkfehlern niemals die lokale Anwendung ab – alle Aufrufe
 * werden defensiv mit try/catch abgesichert und Fehler nur geloggt.
 *
 * OAuth2 "Installed/Web Application"-Flow:
 *  1. Admin hinterlegt Client-ID + Client-Secret (aus der Google Cloud
 *     Console, kostenloses Projekt) in den Einstellungen.
 *  2. Admin klickt "Mit Google verbinden" → gcal_get_authorize_url()
 *     → Google-Login/Consent → Redirect zurück auf
 *     gcal_oauth_callback.php mit einem "code".
 *  3. gcal_exchange_code() tauscht den Code gegen ein Refresh-Token,
 *     das verschlüsselt (AES-256-CBC, wie die PIN-Passcodes) in der
 *     Datenbank gespeichert wird.
 *  4. Für jeden API-Call wird per Refresh-Token ein kurzlebiges
 *     Access-Token angefordert (kein Caching nötig/möglich auf
 *     zustandslosem Shared-Hosting – ein Request pro Sync-Vorgang).
 */

// ── Ist die Google-Kalender-Synchronisation aktiviert & konfiguriert? ─
function gcal_enabled(): bool {
    if (get_setting('gcal_enabled', '0') !== '1') return false;
    if (get_setting('gcal_client_id', '') === '') return false;
    if (get_setting('gcal_refresh_token_encrypted', '') === '') return false;
    return true;
}

// ── Redirect-URI für den OAuth2-Callback (muss in der Google Cloud
//    Console als "Autorisierte Weiterleitungs-URI" hinterlegt werden) ─
function gcal_redirect_uri(): string {
    return base_app_url() . '/' . 'gcal_oauth_callback.php';
}

// ── Client-Secret verschlüsselt speichern/lesen ────────────────
function gcal_store_client_secret(string $plain): void {
    if ($plain === '') return;
    $enc = encrypt_passcode($plain);
    set_setting('gcal_client_secret_encrypted', $enc['encrypted']);
    set_setting('gcal_client_secret_iv', $enc['iv']);
}

function gcal_client_secret(): string {
    $enc = get_setting('gcal_client_secret_encrypted', '');
    $iv  = get_setting('gcal_client_secret_iv', '');
    if ($enc === '' || $iv === '') return '';
    return decrypt_passcode($enc, $iv);
}

// ── Refresh-Token verschlüsselt speichern/lesen ────────────────
function gcal_store_refresh_token(string $plain): void {
    if ($plain === '') return;
    $enc = encrypt_passcode($plain);
    set_setting('gcal_refresh_token_encrypted', $enc['encrypted']);
    set_setting('gcal_refresh_token_iv', $enc['iv']);
}

function gcal_refresh_token(): string {
    $enc = get_setting('gcal_refresh_token_encrypted', '');
    $iv  = get_setting('gcal_refresh_token_iv', '');
    if ($enc === '' || $iv === '') return '';
    return decrypt_passcode($enc, $iv);
}

// ── Verbindung vollständig trennen (Einstellungen zurücksetzen) ─
function gcal_disconnect(): void {
    set_setting('gcal_enabled', '0');
    set_setting('gcal_refresh_token_encrypted', '');
    set_setting('gcal_refresh_token_iv', '');
    set_setting('gcal_connected_account', '');
}

// ── OAuth2-CSRF-Schutz (state-Parameter) ────────────────────────
function gcal_csrf_state(): string {
    if (empty($_SESSION['gcal_oauth_state'])) {
        $_SESSION['gcal_oauth_state'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['gcal_oauth_state'];
}

function gcal_verify_state(string $state): bool {
    $valid = !empty($_SESSION['gcal_oauth_state']) && hash_equals($_SESSION['gcal_oauth_state'], $state);
    unset($_SESSION['gcal_oauth_state']);
    return $valid;
}

// ── Google-Autorisierungs-URL für den "Mit Google verbinden"-Link ─
function gcal_get_authorize_url(): string {
    $params = [
        'client_id'              => get_setting('gcal_client_id', ''),
        'redirect_uri'           => gcal_redirect_uri(),
        'response_type'          => 'code',
        'scope'                  => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/userinfo.email',
        'access_type'            => 'offline',
        'prompt'                 => 'consent',
        'include_granted_scopes' => 'true',
        'state'                  => gcal_csrf_state(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

// ── Generischer, robuster cURL-JSON-Request-Helfer ──────────────
function gcal_http_request(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : $body;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $error ?: 'cURL-Verbindungsfehler'];
    }

    $decoded = json_decode($response, true);
    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'data'   => is_array($decoded) ? $decoded : null,
        'error'  => $status >= 400 ? ($decoded['error_description'] ?? $decoded['error']['message'] ?? 'HTTP ' . $status) : '',
    ];
}

// ── Autorisierungscode gegen Refresh-/Access-Token tauschen ────
// Wird einmalig nach dem Google-Consent-Redirect aufgerufen.
function gcal_exchange_code(string $code): bool {
    $res = gcal_http_request(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'code'          => $code,
            'client_id'     => get_setting('gcal_client_id', ''),
            'client_secret' => gcal_client_secret(),
            'redirect_uri'  => gcal_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ]
    );

    if (!$res['ok'] || empty($res['data']['refresh_token'])) {
        error_log('gcal_exchange_code fehlgeschlagen: ' . ($res['error'] ?: 'kein refresh_token erhalten'));
        return false;
    }

    gcal_store_refresh_token($res['data']['refresh_token']);

    // Verbundenes Google-Konto (E-Mail) zur Anzeige in den Einstellungen ermitteln
    if (!empty($res['data']['access_token'])) {
        $info = gcal_http_request('GET', 'https://www.googleapis.com/oauth2/v2/userinfo', [
            'Authorization: Bearer ' . $res['data']['access_token'],
        ]);
        if ($info['ok'] && !empty($info['data']['email'])) {
            set_setting('gcal_connected_account', (string)$info['data']['email']);
        }
    }

    set_setting('gcal_enabled', '1');
    return true;
}

// ── Kurzlebiges Access-Token per Refresh-Token anfordern ────────
// Kein Caching (zustandsloses Shared-Hosting) – wird bei jedem
// Sync-Vorgang einmal neu angefordert (kostenlos, kein Rate-Limit-
// Problem bei den hier üblichen Aufruf-Häufigkeiten).
function gcal_get_access_token(): ?string {
    $refresh = gcal_refresh_token();
    if ($refresh === '') return null;

    $res = gcal_http_request(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'client_id'     => get_setting('gcal_client_id', ''),
            'client_secret' => gcal_client_secret(),
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]
    );

    if (!$res['ok'] || empty($res['data']['access_token'])) {
        error_log('gcal_get_access_token fehlgeschlagen: ' . ($res['error'] ?: 'unbekannter Fehler'));
        return null;
    }

    return (string)$res['data']['access_token'];
}

// ── MySQL-Datetime → RFC3339 (für die Google Calendar API) ──────
function gcal_rfc3339(string $mysql_datetime): string {
    try {
        $dt = new DateTime($mysql_datetime, new DateTimeZone('Europe/Berlin'));
        return $dt->format(DateTime::RFC3339);
    } catch (Throwable $e) {
        return date(DateTime::RFC3339);
    }
}

// ── Termin in Google Kalender anlegen oder aktualisieren ────────
// Legt beim ersten Sync ein Event an und speichert dessen Google-
// Event-ID in `appointments.google_event_id`; bei weiteren Aufrufen
// wird dasselbe Event aktualisiert (idempotent). Bricht niemals die
// aufrufende Anwendung ab – Fehler werden nur geloggt.
function gcal_upsert_event(array $appointment): void {
    if (!gcal_enabled()) return;

    try {
        $token = gcal_get_access_token();
        if (!$token) return;

        $calendarId = get_setting('gcal_calendar_id', 'primary') ?: 'primary';
        $type       = (string)($appointment['type'] ?? 'sonstiges');
        $typeLbl    = ['eingang' => 'Geräteannahme', 'reparatur' => 'Reparatur', 'abholung' => 'Abholung', 'sonstiges' => 'Termin'][$type] ?? 'Termin';

        $endDatetime = !empty($appointment['end_datetime'])
            ? $appointment['end_datetime']
            : date('Y-m-d H:i:s', strtotime($appointment['start_datetime']) + 3600);

        $eventBody = [
            'summary'     => '[MZ Tech] ' . $typeLbl . ' – ' . ($appointment['title'] ?? 'Termin'),
            'description' => (string)($appointment['notes'] ?? ''),
            'start'       => ['dateTime' => gcal_rfc3339($appointment['start_datetime']), 'timeZone' => 'Europe/Berlin'],
            'end'         => ['dateTime' => gcal_rfc3339($endDatetime), 'timeZone' => 'Europe/Berlin'],
        ];

        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        $base    = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events';

        $existingId = (string)($appointment['google_event_id'] ?? '');

        if ($existingId !== '') {
            $res = gcal_http_request('PUT', $base . '/' . rawurlencode($existingId), $headers, json_encode($eventBody));
            // Falls das Event bei Google zwischenzeitlich gelöscht wurde (404/410), neu anlegen.
            if (!$res['ok'] && in_array($res['status'], [404, 410], true)) {
                $existingId = '';
            }
        }

        if ($existingId === '') {
            $res = gcal_http_request('POST', $base, $headers, json_encode($eventBody));
            if ($res['ok'] && !empty($res['data']['id'])) {
                get_db()->prepare('UPDATE appointments SET google_event_id = ? WHERE id = ?')
                    ->execute([(string)$res['data']['id'], (int)$appointment['id']]);
            } elseif (!$res['ok']) {
                error_log('gcal_upsert_event (create) fehlgeschlagen: ' . $res['error']);
            }
        } elseif (!empty($res) && !$res['ok']) {
            error_log('gcal_upsert_event (update) fehlgeschlagen: ' . $res['error']);
        }
    } catch (Throwable $e) {
        error_log('gcal_upsert_event Ausnahme: ' . $e->getMessage());
    }
}

// ── Termin aus Google Kalender löschen ──────────────────────────
function gcal_delete_event(?string $google_event_id): void {
    if (!gcal_enabled() || !$google_event_id) return;

    try {
        $token = gcal_get_access_token();
        if (!$token) return;

        $calendarId = get_setting('gcal_calendar_id', 'primary') ?: 'primary';
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId)
             . '/events/' . rawurlencode($google_event_id);

        $res = gcal_http_request('DELETE', $url, ['Authorization: Bearer ' . $token]);
        // 404/410 (bereits gelöscht) ist für uns kein Fehlerfall.
        if (!$res['ok'] && !in_array($res['status'], [404, 410], true)) {
            error_log('gcal_delete_event fehlgeschlagen: ' . $res['error']);
        }
    } catch (Throwable $e) {
        error_log('gcal_delete_event Ausnahme: ' . $e->getMessage());
    }
}

// ── Bequemer Wrapper: Termin anhand seiner ID nachladen und mit Google
//    Kalender abgleichen. Für Aufrufstellen gedacht, die nur die ID zur
//    Hand haben (z. B. nach INSERT/UPDATE in api/calendar.php oder
//    booking_view.php). No-op, falls Google-Sync nicht aktiv ist.
function sync_appointment_calendar(int $appointment_id): void {
    if (!gcal_enabled() || $appointment_id <= 0) return;

    try {
        $stmt = get_db()->prepare('SELECT * FROM appointments WHERE id = ? LIMIT 1');
        $stmt->execute([$appointment_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($appointment) {
            gcal_upsert_event($appointment);
        }
    } catch (Throwable $e) {
        error_log('sync_appointment_calendar Ausnahme: ' . $e->getMessage());
    }
}
