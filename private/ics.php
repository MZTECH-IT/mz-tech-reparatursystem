<?php
/**
 * MZ Tech – Lokaler Kalender-Export (.ics / iCalendar, RFC 5545)
 *
 * Erzeugt .ics-Dateien bzw. einen abonnierbaren .ics-Feed für die interne
 * Terminübersicht (Tabelle `appointments`), komplett ohne externe Dienste.
 * Wird sowohl für den Einzel-Download eines Termins (Admin) als auch für
 * den token-geschützten Abo-Feed (z. B. in Google Kalender/Apple Kalender/
 * Outlook per "Kalender per URL abonnieren") genutzt.
 */

// ── Text für .ics escapen (RFC 5545 Abschnitt 3.3.11) ─────────
function ics_escape(string $text): string {
    $text = str_replace(["\\", "\n", "\r", ",", ";"], ["\\\\", "\\n", "", "\\,", "\\;"], $text);
    return $text;
}

// ── Lange Zeilen gemäß RFC 5545 auf 75 Oktette umbrechen ──────
function ics_fold(string $line): string {
    if (strlen($line) <= 75) return $line;
    $out    = '';
    $remain = $line;
    $first  = true;
    while (strlen($remain) > 0) {
        $chunkLen = $first ? 75 : 74;
        $out .= ($first ? '' : "\r\n ") . substr($remain, 0, $chunkLen);
        $remain = substr($remain, $chunkLen);
        $first  = false;
    }
    return $out;
}

// ── MySQL-Datetime (lokale Zeit Europe/Berlin) → UTC-.ics-Format ─
function ics_datetime(string $mysql_datetime): string {
    try {
        $dt = new DateTime($mysql_datetime, new DateTimeZone('Europe/Berlin'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Ymd\THis\Z');
    } catch (Throwable $e) {
        return gmdate('Ymd\THis\Z');
    }
}

// ── Aktueller Zeitstempel (UTC) für DTSTAMP/CREATED ────────────
function ics_now(): string {
    return gmdate('Ymd\THis\Z');
}

// ── Eindeutige UID für einen Termin sicherstellen (persistent) ─
function ensure_appointment_ics_uid(int $appointment_id): string {
    $db   = get_db();
    $stmt = $db->prepare('SELECT ics_uid FROM appointments WHERE id = ? LIMIT 1');
    $stmt->execute([$appointment_id]);
    $uid = $stmt->fetchColumn();

    if ($uid) return (string)$uid;

    $uid = bin2hex(random_bytes(16)) . '-appt' . $appointment_id . '@mztech-it.de';
    $db->prepare('UPDATE appointments SET ics_uid = ? WHERE id = ?')->execute([$uid, $appointment_id]);
    return $uid;
}

// ── Einzelnen VEVENT-Block für einen Termin erzeugen ───────────
function build_vevent(array $appointment): string {
    $uid   = $appointment['ics_uid'] ?: ensure_appointment_ics_uid((int)$appointment['id']);
    $start = ics_datetime($appointment['start_datetime']);
    $end   = !empty($appointment['end_datetime'])
        ? ics_datetime($appointment['end_datetime'])
        : ics_datetime(date('Y-m-d H:i:s', strtotime($appointment['start_datetime']) + 3600));

    $summary = ics_escape((string)($appointment['title'] ?? 'Termin'));
    $desc    = ics_escape((string)($appointment['notes'] ?? ''));
    $type    = (string)($appointment['type'] ?? 'sonstiges');
    $typeLbl = ['eingang' => 'Geräteannahme', 'reparatur' => 'Reparatur', 'abholung' => 'Abholung', 'sonstiges' => 'Termin'][$type] ?? 'Termin';

    $location = ics_escape(trim((string)get_setting('company_address', '')));

    $lines   = [];
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = ics_fold('UID:' . $uid);
    $lines[] = 'DTSTAMP:' . ics_now();
    $lines[] = 'DTSTART:' . $start;
    $lines[] = 'DTEND:' . $end;
    $lines[] = ics_fold('SUMMARY:[MZ Tech] ' . $typeLbl . ' – ' . $summary);
    if ($desc !== '') $lines[] = ics_fold('DESCRIPTION:' . $desc);
    if ($location !== '') $lines[] = ics_fold('LOCATION:' . $location);
    $lines[] = 'CATEGORIES:MZ Tech';
    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'END:VEVENT';

    return implode("\r\n", $lines);
}

// ── Vollständiges VCALENDAR-Dokument aus mehreren Terminen bauen ─
function build_vcalendar(array $appointments, string $calname = 'MZ Tech Termine'): string {
    $lines   = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//MZ Tech//Reparaturverwaltung//DE';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = 'METHOD:PUBLISH';
    $lines[] = ics_fold('X-WR-CALNAME:' . ics_escape($calname));
    $lines[] = 'X-WR-TIMEZONE:Europe/Berlin';
    $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT30M';
    $lines[] = 'X-PUBLISHED-TTL:PT30M';

    foreach ($appointments as $appointment) {
        $lines[] = build_vevent($appointment);
    }

    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines) . "\r\n";
}

// ── Feed aktiviert? (lokaler .ics-Abo-Feed, Standard: an) ──────
function ics_feed_enabled(): bool {
    return get_setting('ics_feed_enabled', '1') === '1';
}

// ── Geheimes Feed-Token lazy erzeugen/lesen (schützt den Feed,
//    da er ohne Login abrufbar sein muss, um in Kalender-Apps
//    per URL abonniert werden zu können) ────────────────────────
function ics_feed_token(): string {
    $token = get_setting('ics_feed_token', '');
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        set_setting('ics_feed_token', $token);
    }
    return $token;
}

// ── Token neu generieren (z. B. bei Verdacht auf Kompromittierung) ─
function ics_regenerate_feed_token(): string {
    $token = bin2hex(random_bytes(24));
    set_setting('ics_feed_token', $token);
    return $token;
}

// ── Vollständige Abo-Feed-URL für die Anzeige in den Einstellungen ─
function ics_feed_url(): string {
    return base_app_url() . '/' . 'ics/feed.php?token=' . urlencode(ics_feed_token());
}
