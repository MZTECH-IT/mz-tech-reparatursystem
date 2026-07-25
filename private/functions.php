<?php
/**
 * MZ Tech – Hilfsfunktionen
 */

// ── XSS-Schutz ────────────────────────────────────────
function h(mixed $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Installationsordner-unabhängige URLs ──────────────
// Baut aus einem Pfad relativ zum Anwendungs-Root (z.B. "dashboard.php" oder
// "api/repairs.php?action=x") die tatsächliche, vom Browser aufrufbare URL,
// unabhängig davon ob die Anwendung im Domain-Root, auf einer Subdomain oder
// in einem Unterordner installiert wurde. Siehe APP_URL_BASE in config.php.
function url(string $path = ''): string {
    $path = ltrim($path, '/');
    $base = defined('APP_URL_BASE') ? APP_URL_BASE : '';
    return $path === '' ? ($base === '' ? '/' : $base) : $base . '/' . $path;
}

// ── Datum (deutsches Format) ───────────────────────────
function fmt_date(?string $date, bool $with_time = false): string {
    if (!$date) return '–';
    $ts = strtotime($date);
    if (!$ts) return '–';
    return $with_time
        ? date('d.m.Y H:i', $ts) . ' Uhr'
        : date('d.m.Y', $ts);
}

// ── Währung ────────────────────────────────────────────
function fmt_money(?float $amount): string {
    if ($amount === null) return '–';
    return number_format($amount, 2, ',', '.') . ' €';
}

// ── Reparatur-Statuspipeline (14 Stufen) ───────────────
// Kanonische Reihenfolge der Reparaturstatus. Ältere Installationen können
// noch die alten, kürzeren Statuswerte in der Datenbank stehen haben
// (z.B. aus einer Version vor diesem Update) – repair_status_normalize()
// bildet diese verlustfrei auf die neuen Werte ab, damit bestehende
// Datensätze nach einem Update NICHT verändert oder gelöscht werden müssen
// und trotzdem korrekt angezeigt werden (siehe sql/update.sql).
function repair_valid_statuses(): array {
    return [
        'anfrage_eingegangen',
        'termin_angefragt',
        'termin_bestaetigt',
        'angenommen',
        'diagnose',
        'kostenvoranschlag',
        'freigabe_ausstehend',
        'ersatzteil_bestellt',
        'in_reparatur',
        'funktionstest',
        'fertig',
        'abholbereit',
        'abgeholt',
        'storniert',
    ];
}

// Bildet alte (vor diesem Update verwendete) Statuswerte auf die neuen ab.
function repair_status_normalize(string $status): string {
    static $legacy = [
        'eingegangen'     => 'angenommen',
        'in_arbeit'       => 'in_reparatur',
        'warte_auf_teile' => 'ersatzteil_bestellt',
        'repariert'       => 'fertig',
        // 'abgeholt' und 'storniert' sind unverändert
    ];
    return $legacy[$status] ?? $status;
}

function repair_status_label(string $status): string {
    $status = repair_status_normalize($status);
    $map = [
        'anfrage_eingegangen'  => 'Anfrage eingegangen',
        'termin_angefragt'     => 'Termin angefragt',
        'termin_bestaetigt'    => 'Termin bestätigt',
        'angenommen'           => 'Gerät angenommen',
        'diagnose'             => 'Diagnose',
        'kostenvoranschlag'    => 'Kostenvoranschlag erstellt',
        'freigabe_ausstehend'  => 'Freigabe ausstehend',
        'ersatzteil_bestellt'  => 'Ersatzteil bestellt',
        'in_reparatur'         => 'In Reparatur',
        'funktionstest'        => 'Funktionstest',
        'fertig'               => 'Fertig',
        'abholbereit'          => 'Abholbereit',
        'abgeholt'             => 'Abgeholt',
        'storniert'            => 'Storniert',
    ];
    return $map[$status] ?? $status;
}

function repair_status_badge(string $status): string {
    $norm = repair_status_normalize($status);
    $cls = match($norm) {
        'anfrage_eingegangen', 'termin_angefragt', 'termin_bestaetigt', 'angenommen' => 'badge-blue',
        'diagnose', 'kostenvoranschlag', 'in_reparatur', 'funktionstest'            => 'badge-yellow',
        'freigabe_ausstehend', 'ersatzteil_bestellt'                                => 'badge-orange',
        'fertig', 'abholbereit'                                                     => 'badge-green',
        'abgeholt'                                                                  => 'badge-gray',
        'storniert'                                                                 => 'badge-red',
        default                                                                     => 'badge-gray',
    };
    return '<span class="badge ' . $cls . '">' . h(repair_status_label($status)) . '</span>';
}

// Gerät ist fertiggestellt (Reparatur abgeschlossen, wartet ggf. auf Abholung).
function repair_status_is_completed(string $status): bool {
    return in_array(repair_status_normalize($status), ['fertig', 'abholbereit', 'abgeholt'], true);
}

// Auftrag ist noch offen (nicht abgeholt, nicht storniert).
function repair_status_is_open(string $status): bool {
    return !in_array(repair_status_normalize($status), ['abgeholt', 'storniert'], true);
}

// ── Rechnungsfreigabe-Status (Phase 2) ─────────────────
// 'entwurf' (Standardwert für alle bestehenden Aufträge, keine echte
// Rechnungsnummer vergeben), 'freigegeben' (Mitarbeiter hat freigegeben,
// Rechnungsnummer vergeben) oder 'kunde_bestaetigt' (Kunde hat die
// freigegebene Rechnung zusätzlich im Kundenportal bestätigt).
function invoice_status_label(string $status): string {
    return match ($status) {
        'freigegeben'      => 'Freigegeben',
        'kunde_bestaetigt' => 'Vom Kunden bestätigt',
        default            => 'Entwurf',
    };
}

function invoice_status_badge(string $status): string {
    $cls = match ($status) {
        'freigegeben'      => 'badge-blue',
        'kunde_bestaetigt' => 'badge-green',
        default            => 'badge-gray',
    };
    return '<span class="badge ' . $cls . '">' . h(invoice_status_label($status)) . '</span>';
}

// ── Reparaturnummer generieren ─────────────────────────
// Phase 2: nutzt intern die zentrale, nebenläufigkeitssichere
// Nummernkreis-Engine (siehe private/numbering.php, Dokumenttyp "REP").
// Das sichtbare Format (Präfix aus Einstellung "repair_prefix", Standard
// "MZ", Jahr, 4-stellig fortlaufend, z.B. "MZ20260007") bleibt dabei exakt
// unverändert – nur die Vergabe selbst ist jetzt gegen gleichzeitigen
// Zugriff mehrerer Mitarbeiter abgesichert (zuvor: theoretische Race
// Condition durch SELECT...ORDER BY...LIMIT 1 ohne Sperre).
function generate_repair_number(): string {
    // Absichtlich defensiv: das Anlegen einer neuen Reparatur ist die mit
    // Abstand am häufigsten genutzte, kritischste Aktion im gesamten System
    // (Formular, Terminbuchung, Reparaturanfrage). Falls die Phase-2-
    // Datenbankmigration (Tabelle number_ranges, siehe DATENBANKAENDERUNGEN.sql)
    // aus irgendeinem Grund noch nicht ausgeführt wurde, während die neuen
    // PHP-Dateien schon hochgeladen sind (falsche Reihenfolge beim Update),
    // würde ein ungefangener Datenbankfehler hier sonst das komplette Anlegen
    // neuer Reparaturen blockieren. Daher: bei jedem Problem in der neuen
    // Nummernkreis-Engine automatisch und geräuschlos auf die bisherige,
    // bewährte Logik zurückfallen, statt fehlzuschlagen.
    if (function_exists('generate_document_number')) {
        try {
            return generate_document_number('REP');
        } catch (Throwable $e) {
            error_log('generate_repair_number(): Nummernkreis-Engine nicht verfügbar (evtl. Datenbank-Update noch nicht ausgeführt?), Rückfall auf Standardlogik: ' . $e->getMessage());
        }
    }

    // Fallback, falls private/numbering.php aus irgendeinem Grund nicht
    // eingebunden wurde oder die Nummernkreis-Engine (noch) nicht nutzbar
    // ist (siehe oben) – identisch zur bisherigen Logik vor Phase 2.
    $prefix = get_setting('repair_prefix', 'MZ');
    $year   = date('Y');
    $db     = get_db();
    $stmt   = $db->prepare(
        "SELECT repair_number FROM repairs
         WHERE repair_number LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . $year . '%']);
    $row = $stmt->fetch();

    if ($row) {
        $last = (int)substr($row['repair_number'], -4);
        $next = $last + 1;
    } else {
        $next = 1;
    }
    return $prefix . $year . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ── Gerätetypen (öffentliche Formulare + interne Auswahl) ──
function device_type_options(): array {
    return [
        'smartphone' => 'Smartphone',
        'tablet'     => 'Tablet',
        'pc'         => 'PC',
        'laptop'     => 'Laptop',
        'konsole'    => 'Konsole',
        'controller' => 'Controller',
        'it_service' => 'IT-Service',
        'firmen_it'  => 'Firmen-IT',
        'sonstiges'  => 'Sonstiges',
    ];
}

function device_type_label(string $key): string {
    return device_type_options()[$key] ?? $key;
}

// ── Terminbuchung: Auftragsnummer ─────────────────────
function booking_generate_number(): string {
    $year = date('Y');
    $db   = get_db();
    $stmt = $db->prepare(
        "SELECT booking_number FROM booking_requests
         WHERE booking_number LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['TB' . $year . '%']);
    $row = $stmt->fetch();

    if ($row) {
        $last = (int)substr($row['booking_number'], -4);
        $next = $last + 1;
    } else {
        $next = 1;
    }
    return 'TB' . $year . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ── Terminbuchung: Status ──────────────────────────────
function booking_valid_statuses(): array {
    return ['angefragt', 'bestaetigt', 'abgelehnt', 'umgeplant', 'storniert', 'umgewandelt'];
}

function booking_status_label(string $status): string {
    static $labels = [
        'angefragt'   => 'Angefragt',
        'bestaetigt'  => 'Bestätigt',
        'abgelehnt'   => 'Abgelehnt',
        'umgeplant'   => 'Umgeplant',
        'storniert'   => 'Storniert',
        'umgewandelt' => 'In Reparatur umgewandelt',
    ];
    return $labels[$status] ?? ucfirst($status);
}

function booking_status_badge(string $status): string {
    static $classes = [
        'angefragt'   => 'badge-blue',
        'bestaetigt'  => 'badge-green',
        'abgelehnt'   => 'badge-red',
        'umgeplant'   => 'badge-orange',
        'storniert'   => 'badge-gray',
        'umgewandelt' => 'badge-green',
    ];
    $class = $classes[$status] ?? 'badge-gray';
    return '<span class="badge ' . $class . '">' . h(booking_status_label($status)) . '</span>';
}

// ── Terminbuchung: Verfügbarkeit ───────────────────────
// Liest die admin-konfigurierten Öffnungszeiten/Regeln aus den settings.
function booking_get_settings(): array {
    $hours = json_decode(get_setting('booking_hours', '{}'), true);
    if (!is_array($hours)) $hours = [];
    $blocked = json_decode(get_setting('booking_blocked_dates', '[]'), true);
    if (!is_array($blocked)) $blocked = [];

    return [
        'enabled'           => get_setting('booking_enabled', '1') === '1',
        'hours'             => $hours,
        'slot_minutes'      => max(5, (int)get_setting('booking_slot_minutes', '30')),
        'capacity_per_slot' => max(1, (int)get_setting('booking_capacity_per_slot', '1')),
        'lead_hours'        => max(0, (int)get_setting('booking_lead_hours', '24')),
        'max_days_ahead'    => max(1, (int)get_setting('booking_max_days_ahead', '30')),
        'blocked_dates'     => $blocked,
    ];
}

// Liefert alle theoretisch buchbaren Zeitfenster ("HH:MM") für ein Datum
// (Y-m-d) auf Basis der Öffnungszeiten des jeweiligen Wochentags – OHNE
// Berücksichtigung bereits belegter Kapazität (siehe booking_available_slots()).
function booking_day_slots(string $date): array {
    $settings = booking_get_settings();
    $ts = strtotime($date);
    if ($ts === false) return [];

    $weekday_map = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $weekday_key = $weekday_map[(int)date('N', $ts) - 1];
    $day_hours   = $settings['hours'][$weekday_key] ?? null;

    if (!is_array($day_hours) || empty($day_hours['open']) || empty($day_hours['close'])) {
        return [];
    }

    $open  = strtotime($date . ' ' . $day_hours['open']);
    $close = strtotime($date . ' ' . $day_hours['close']);
    if ($open === false || $close === false || $open >= $close) return [];

    $slots = [];
    $step  = $settings['slot_minutes'] * 60;
    for ($t = $open; $t < $close; $t += $step) {
        $slots[] = date('H:i', $t);
    }
    return $slots;
}

// Prüft, ob ein Datum grundsätzlich buchbar ist (Vorlaufzeit, max.
// Vorausbuchungszeitraum, gesperrte Tage, Terminbuchung aktiviert).
function booking_date_is_bookable(string $date): bool {
    $settings = booking_get_settings();
    if (!$settings['enabled']) return false;
    if (in_array($date, $settings['blocked_dates'], true)) return false;

    $ts = strtotime($date);
    if ($ts === false) return false;

    $today = strtotime(date('Y-m-d'));
    $max_ts = strtotime('+' . $settings['max_days_ahead'] . ' days', $today);
    if ($ts < $today || $ts > $max_ts) return false;

    return true;
}

// Liefert die tatsächlich noch buchbaren Zeitfenster für ein Datum unter
// Berücksichtigung von: Vorlaufzeit (Mindestabstand bis zum Termin),
// bereits ausgeschöpfter Kapazität (bestätigte/angefragte Buchungen +
// interne Termine) sowie Kapazitätsgrenze je Zeitfenster. Damit wird eine
// Doppelbuchung über dieselbe Kapazitätsgrenze hinaus verhindert.
function booking_available_slots(string $date): array {
    if (!booking_date_is_bookable($date)) return [];

    $settings   = booking_get_settings();
    $all_slots  = booking_day_slots($date);
    if (empty($all_slots)) return [];

    $earliest_ts = time() + ($settings['lead_hours'] * 3600);

    $db = get_db();
    $stmt = $db->prepare(
        "SELECT preferred_time, COUNT(*) AS cnt FROM booking_requests
         WHERE preferred_date = ? AND status IN ('angefragt','bestaetigt','umgeplant')
         GROUP BY preferred_time"
    );
    $stmt->execute([$date]);
    $booked_counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $booked_counts[substr($row['preferred_time'], 0, 5)] = (int)$row['cnt'];
    }

    // Zusätzlich interne Termine (appointments) an diesem Tag als belegt werten.
    $astmt = $db->prepare(
        "SELECT start_datetime FROM appointments WHERE DATE(start_datetime) = ?"
    );
    $astmt->execute([$date]);
    foreach ($astmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $time_key = date('H:i', strtotime($row['start_datetime']));
        $booked_counts[$time_key] = ($booked_counts[$time_key] ?? 0) + 1;
    }

    $available = [];
    foreach ($all_slots as $slot) {
        $slot_ts = strtotime($date . ' ' . $slot);
        if ($slot_ts < $earliest_ts) continue; // zu kurzfristig
        $used = $booked_counts[$slot] ?? 0;
        if ($used < $settings['capacity_per_slot']) {
            $available[] = $slot;
        }
    }
    return $available;
}

// ── Reparaturanfrage (öffentliches Formular ohne Termin) ──
function repair_request_generate_number(): string {
    $year = date('Y');
    $db   = get_db();
    $stmt = $db->prepare(
        "SELECT request_number FROM repair_requests
         WHERE request_number LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['RA' . $year . '%']);
    $row = $stmt->fetch();

    if ($row) {
        $last = (int)substr($row['request_number'], -4);
        $next = $last + 1;
    } else {
        $next = 1;
    }
    return 'RA' . $year . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function repair_request_valid_statuses(): array {
    return ['neu', 'abgelehnt', 'archiviert', 'umgewandelt'];
}

function repair_request_status_label(string $status): string {
    static $labels = [
        'neu'         => 'Neu',
        'abgelehnt'   => 'Abgelehnt',
        'archiviert'  => 'Archiviert',
        'umgewandelt' => 'In Reparatur umgewandelt',
    ];
    return $labels[$status] ?? ucfirst($status);
}

function repair_request_status_badge(string $status): string {
    static $classes = [
        'neu'         => 'badge-blue',
        'abgelehnt'   => 'badge-red',
        'archiviert'  => 'badge-gray',
        'umgewandelt' => 'badge-green',
    ];
    $class = $classes[$status] ?? 'badge-gray';
    return '<span class="badge ' . $class . '">' . h(repair_request_status_label($status)) . '</span>';
}

function repair_request_enabled(): bool {
    return get_setting('repair_request_enabled', '1') === '1';
}

// ── AES-256 Passcode verschlüsseln ────────────────────
function encrypt_passcode(string $plain): array {
    $key = hex2bin(ENCRYPTION_KEY);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return [
        'encrypted' => base64_encode($enc),
        'iv'        => base64_encode($iv),
    ];
}

function decrypt_passcode(string $encrypted, string $iv): string {
    $key = hex2bin(ENCRYPTION_KEY);
    $dec = openssl_decrypt(
        base64_decode($encrypted),
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        base64_decode($iv)
    );
    return $dec !== false ? $dec : '';
}

// ── SMTP-Passwort (Phase 1 – Sicherheitsvereinheitlichung) ────────────
// Bislang lag das SMTP-Passwort als Klartext im Setting "smtp_pass",
// anders als vergleichbare Werte (z. B. Google-Kalender-Zugangsdaten), die
// bereits mit encrypt_passcode()/decrypt_passcode() verschlüsselt werden.
// Diese beiden Funktionen stellen exakt dasselbe, bereits im System
// etablierte Verschlüsselungsverfahren (AES-256-CBC, siehe oben) auch für
// das SMTP-Passwort her. Der bestehende Klartextwert wird beim ersten
// Lesevorgang automatisch übernommen, verschlüsselt gespeichert und die
// alte Klartext-Einstellung geleert – der Zugangsdaten-Wert selbst bleibt
// dabei unverändert, nur die Speicherung wird sicherer.
function smtp_password_get(): string {
    $enc = get_setting('smtp_pass_encrypted', '');
    $iv  = get_setting('smtp_pass_iv', '');
    if ($enc !== '' && $iv !== '') {
        return decrypt_passcode($enc, $iv);
    }

    // Migration: alter Klartextwert vorhanden? Einmalig verschlüsselt
    // übernehmen, damit die bestehende SMTP-Konfiguration ohne erneute
    // Eingabe des Passworts weiter funktioniert.
    $legacy = get_setting('smtp_pass', '');
    if ($legacy !== '') {
        smtp_password_set($legacy);
        set_setting('smtp_pass', '');
        return $legacy;
    }

    return '';
}

function smtp_password_set(string $plain): void {
    $enc = encrypt_passcode($plain);
    set_setting('smtp_pass_encrypted', $enc['encrypted']);
    set_setting('smtp_pass_iv', $enc['iv']);
}

// ── Basis-URL der Installation (Schema + Host + APP_URL_BASE) ─
// Wird sowohl für QR-Codes als auch für Portal-Links verwendet.
// Nutzt zuerst die in den Einstellungen hinterlegte site_url,
// sonst automatische Erkennung über HTTPS/HTTP_HOST.
function base_app_url(): string {
    $configured = get_setting('site_url', '');
    if ($configured) {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return rtrim($scheme . '://' . $host . url(''), '/');
}

// ── Kundenportal: Zugang sicherstellen (Link/PIN, ohne Passwort) ─
// Legt bei Bedarf einen Zugang für den angegebenen Kunden an (idempotent)
// oder liest den bestehenden aus. Bei $regenerate=true wird ein neuer
// Token + eine neue PIN erzeugt (z. B. bei Verdacht auf Kompromittierung).
// Rückgabe: ['token'=>string, 'pin'=>string (Klartext, nur bei Neuanlage/
// Regenerierung bekannt), 'link'=>string, 'is_new'=>bool]
function ensure_customer_portal_access(int $customer_id, bool $regenerate = false): array {
    $db = get_db();

    if (!$regenerate) {
        $stmt = $db->prepare('SELECT * FROM customer_portal_access WHERE customer_id = ? LIMIT 1');
        $stmt->execute([$customer_id]);
        $existing = $stmt->fetch();
        if ($existing) {
            return [
                'token'  => $existing['token'],
                'pin'    => null, // Klartext-PIN wird nicht gespeichert, nur bei (Neu-)Anlage bekannt
                'link'   => portal_link_for_token($existing['token']),
                'is_new' => false,
            ];
        }
    }

    $token = bin2hex(random_bytes(32));
    $pin   = (string)random_int(100000, 999999);
    $enc   = encrypt_passcode($pin);

    $stmt = $db->prepare(
        'INSERT INTO customer_portal_access (customer_id, token, pin_encrypted, pin_iv)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE token = VALUES(token), pin_encrypted = VALUES(pin_encrypted), pin_iv = VALUES(pin_iv)'
    );
    $stmt->execute([$customer_id, $token, $enc['encrypted'], $enc['iv']]);

    return [
        'token'  => $token,
        'pin'    => $pin,
        'link'   => portal_link_for_token($token),
        'is_new' => true,
    ];
}

// ── Kundenportal: bestehenden Zugang lesen (ohne Anlage) ──────
function get_customer_portal_access(int $customer_id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM customer_portal_access WHERE customer_id = ? LIMIT 1');
    $stmt->execute([$customer_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ── Kundenportal: absoluten Link zu einem Token bilden ────────
function portal_link_for_token(string $token): string {
    return base_app_url() . '/' . 'portal.php?t=' . urlencode($token);
}

// ── Kundenportal: Link zu einem Kunden bilden (leer, falls kein
//    Zugang existiert und keine Anlage gewünscht ist) ─────────
function portal_link_for_customer(?int $customer_id): string {
    if (!$customer_id) return '';
    $access = get_customer_portal_access($customer_id);
    if (!$access) return '';
    return portal_link_for_token($access['token']);
}

// ── Wiederverwendbarer SVG-Platzhalter-QR-Code ────────────────
// Wird genutzt, wenn der abhängigkeitsfreie PNG-QR-Encoder den Inhalt
// nicht darstellen kann (z. B. zu lang für die unterstützte QR-Version)
// oder generell nicht verfügbar ist. Gibt direkt SVG-Markup aus.
function render_qr_svg_fallback(string $data): void {
    $cell = 8;
    $modules = 25; // 25x25 Raster für Platzhalter
    $size = $modules * $cell + 40;

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . ($size + 30) . '" viewBox="0 0 ' . $size . ' ' . ($size + 30) . '">';
    echo '<rect width="' . $size . '" height="' . ($size + 30) . '" fill="white"/>';

    // Äußerer Rahmen
    echo '<rect x="10" y="10" width="' . ($size - 20) . '" height="' . ($size - 20) . '" fill="none" stroke="#333" stroke-width="2"/>';

    // Eckmuster (Finder Patterns) – oben links
    echo '<rect x="14" y="14" width="56" height="56" fill="#333" rx="2"/>';
    echo '<rect x="22" y="22" width="40" height="40" fill="white" rx="1"/>';
    echo '<rect x="30" y="30" width="24" height="24" fill="#333" rx="1"/>';

    // Eckmuster – oben rechts
    $rx = $size - 70;
    echo '<rect x="' . $rx . '" y="14" width="56" height="56" fill="#333" rx="2"/>';
    echo '<rect x="' . ($rx + 8) . '" y="22" width="40" height="40" fill="white" rx="1"/>';
    echo '<rect x="' . ($rx + 16) . '" y="30" width="24" height="24" fill="#333" rx="1"/>';

    // Eckmuster – unten links
    $ry = $size - 70;
    echo '<rect x="14" y="' . $ry . '" width="56" height="56" fill="#333" rx="2"/>';
    echo '<rect x="22" y="' . ($ry + 8) . '" width="40" height="40" fill="white" rx="1"/>';
    echo '<rect x="30" y="' . ($ry + 16) . '" width="24" height="24" fill="#333" rx="1"/>';

    // Platzhalter-Muster in der Mitte
    for ($row = 0; $row < 8; $row++) {
        for ($col = 0; $col < 8; $col++) {
            if (($row + $col) % 2 === 0) {
                $px = 90 + $col * $cell;
                $py = 90 + $row * $cell;
                echo '<rect x="' . $px . '" y="' . $py . '" width="' . ($cell - 1) . '" height="' . ($cell - 1) . '" fill="#333"/>';
            }
        }
    }

    // Inhalt-Text unten
    echo '<text x="' . ($size / 2) . '" y="' . ($size + 20) . '" text-anchor="middle" font-family="monospace" font-size="9" fill="#555">';
    echo htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    echo '</text>';

    // Hinweis: Platzhalter, nur falls Inhalt zu lang für QR-Version 10 war
    echo '<text x="' . ($size / 2) . '" y="' . ($size - 5) . '" text-anchor="middle" font-family="sans-serif" font-size="8" fill="#999">';
    echo 'QR-Vorschau (Inhalt zu lang)';
    echo '</text>';

    echo '</svg>';
}

// ── Kundenportal: aktiviert? ───────────────────────────────────
function portal_enabled(): bool {
    return get_setting('portal_enabled', '1') === '1';
}

// ── Datenschutzhinweistext (admin-editierbar über Einstellungen) ──
// Wird auf allen öffentlichen Formularen (Terminbuchung, Reparatur-
// anfrage, Kundenportal) angezeigt. Der Text selbst wird bewusst NICHT
// über h() escaped, da er ausschließlich von einem angemeldeten Admin
// über Einstellungen > Datenschutz gepflegt wird und einfaches HTML
// (z. B. <p>, <a>, <strong>) enthalten darf – gleiches Vertrauensmodell
// wie bei den E-Mail-Vorlagen (email_templates.php). Die Platzhalter-
// werte selbst werden dennoch escaped.
function render_privacy_notice(): string {
    $default = '<p>Ihre Angaben werden ausschließlich zur Bearbeitung Ihrer Anfrage bzw. '
        . 'Ihres Reparaturauftrags durch {{firma}} genutzt und nicht an Dritte weitergegeben. '
        . 'Sie können der Verarbeitung Ihrer Daten jederzeit formlos per E-Mail an {{email}} '
        . 'widersprechen. Die vollständige Datenschutzerklärung erhalten Sie auf Anfrage.</p>';
    $text = get_setting('privacy_notice_text', $default);
    if (trim($text) === '') {
        $text = $default;
    }
    $safe = [
        '{{firma}}' => h(get_setting('company_name', 'MZ Tech')),
        '{{email}}' => h(get_setting('company_email', '')),
    ];
    return strtr($text, $safe);
}

// ── CSRF ──────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Ungültiges CSRF-Token.']));
    }
}

// ── Aktivitätslog ─────────────────────────────────────
function log_activity(string $action, ?string $entity_type = null, ?int $entity_id = null, ?string $details = null): void {
    try {
        $stmt = get_db()->prepare(
            'INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $entity_type,
            $entity_id,
            $details,
            get_client_ip(),
        ]);
    } catch (Throwable) { /* nicht blockieren */ }
}

// ── IP-Adresse ────────────────────────────────────────
function get_client_ip(): string {
    foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ── Flash-Nachrichten ─────────────────────────────────
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function get_flash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function show_flash(): void {
    $f = get_flash();
    if (!$f) return;
    $cls = match($f['type']) {
        'success' => 'alert-success',
        'error'   => 'alert-danger',
        'warning' => 'alert-warning',
        default   => 'alert-info',
    };
    echo '<div class="alert ' . $cls . '">' . h($f['msg']) . '</div>';
}

// ── JSON-Antwort ──────────────────────────────────────
function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Mehrfach-Datei-Upload ($_FILES['feld'][]) in Einzeldateien zerlegen ──
function normalize_multi_upload(string $field): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) return [];
    $out = [];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        $error = $_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE || $name === '') continue;
        $out[] = [
            'name'     => $name,
            'type'     => $_FILES[$field]['type'][$i]     ?? '',
            'tmp_name' => $_FILES[$field]['tmp_name'][$i] ?? '',
            'error'    => $error,
            'size'     => $_FILES[$field]['size'][$i]     ?? 0,
        ];
    }
    return $out;
}

// ── Upload-Datei sichern ──────────────────────────────
function save_upload(array $file, int $repair_id): array|false {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;
    // Zusätzlich zur Endungsprüfung sicherstellen, dass die Datei tatsächlich
    // ein gültiges Bild ist (verhindert z. B. eine als .jpg getarnte PHP-Datei).
    if (@getimagesize($file['tmp_name']) === false) return false;

    $dir = UPLOAD_PATH . '/repairs/' . $repair_id;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return false;

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    return [
        'filename'      => $filename,
        'original_name' => basename($file['name']),
        'file_size'     => $file['size'],
    ];
}

// ── Bild sicher ausgeben (base64 stream) ──────────────
function serve_photo(string $filename, int $repair_id): void {
    $path = UPLOAD_PATH . '/repairs/' . $repair_id . '/' . basename($filename);
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'jpg','jpeg' => 'image/jpeg',
        'png'        => 'image/png',
        'gif'        => 'image/gif',
        'webp'       => 'image/webp',
        default      => 'application/octet-stream',
    };
    header('Content-Type: '  . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

// ── Upload-Datei für Reparaturanfragen sichern (vor Auftragserstellung) ──
// Analog zu save_upload(), aber für repair_request_photos / eine Anfrage,
// zu der noch kein Reparaturauftrag existiert.
function save_request_photo(array $file, int $request_id): array|false {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;
    if (@getimagesize($file['tmp_name']) === false) return false;

    $dir = UPLOAD_PATH . '/requests/' . $request_id;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return false;

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    return [
        'filename'      => $filename,
        'original_name' => basename($file['name']),
        'file_size'     => $file['size'],
    ];
}

// ── Foto einer Reparaturanfrage sicher ausgeben ───────
function serve_request_photo(string $filename, int $request_id): void {
    $path = UPLOAD_PATH . '/requests/' . $request_id . '/' . basename($filename);
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'jpg','jpeg' => 'image/jpeg',
        'png'        => 'image/png',
        'gif'        => 'image/gif',
        'webp'       => 'image/webp',
        default      => 'application/octet-stream',
    };
    header('Content-Type: '  . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

// ── Hochgeladene Bilddatei validieren (ohne sie zu speichern) ─────────
// Wird verwendet, bevor überhaupt ein Datensatz angelegt wird, damit dem
// Nutzer eine verständliche Fehlermeldung angezeigt werden kann.
function validate_photo_upload(array $file): ?string {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null; // keine Datei ausgewählt – ok
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Fehler beim Hochladen einer Datei.';
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return 'Ungültiger Dateityp: "' . basename($file['name']) . '" (erlaubt: JPG, PNG, GIF, WebP).';
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return 'Datei "' . basename($file['name']) . '" ist zu groß (max. ' . (int)(MAX_UPLOAD_SIZE / 1024 / 1024) . ' MB).';
    }
    if (!is_uploaded_file($file['tmp_name']) || @getimagesize($file['tmp_name']) === false) {
        return 'Datei "' . basename($file['name']) . '" ist kein gültiges Bild.';
    }
    return null;
}

// ── WhatsApp-Link ─────────────────────────────────────
function whatsapp_link(string $phone, string $msg = ''): string {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $phone = ltrim(str_replace('+', '00', $phone), '0');
    if (str_starts_with($phone, '49')) {
        // ok
    } elseif (str_starts_with($phone, '0')) {
        $phone = '49' . substr($phone, 1);
    } else {
        $phone = '49' . $phone;
    }
    $url = 'https://wa.me/' . $phone;
    if ($msg) $url .= '?text=' . rawurlencode($msg);
    return $url;
}

// ── Pagination ────────────────────────────────────────
function paginate(int $total, int $per_page, int $current_page): array {
    $total_pages = (int)ceil($total / $per_page);
    $current_page = max(1, min($current_page, $total_pages ?: 1));
    $offset = ($current_page - 1) * $per_page;
    return compact('total', 'per_page', 'current_page', 'total_pages', 'offset');
}

// ── Firmen-Assets: Logo, PDF-Logo, Favicon (Phase 3) ──────────────────
// Gespeichert AUSSERHALB des Webroots (UPLOAD_PATH/company/), analog zu
// allen anderen Uploads im System, und über den Streaming-Endpunkt
// public/company_asset.php ausgeliefert. Ist kein eigenes Bild hochgeladen,
// wird transparent auf das vorhandene Standard-Asset unter
// public/assets/img/ zurückgegriffen – nichts an der bestehenden
// Optik ändert sich, solange nichts hochgeladen wurde.
function company_asset_dir(): string {
    $dir = UPLOAD_PATH . '/company';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    return $dir;
}

// $type: 'logo' | 'logo_pdf' | 'favicon'
function company_asset_allowed_extensions(string $type): array {
    return $type === 'favicon' ? ['ico', 'png'] : ['png', 'jpg', 'jpeg', 'webp'];
}

/** Findet die aktuell hinterlegte Custom-Datei eines Asset-Typs, falls vorhanden. */
function company_asset_find(string $type): ?array {
    foreach (company_asset_allowed_extensions($type) as $ext) {
        $path = company_asset_dir() . '/' . $type . '.' . $ext;
        if (is_file($path)) {
            return ['path' => $path, 'ext' => $ext];
        }
    }
    return null;
}

/** Speichert ein hochgeladenes Firmen-Asset (überschreibt evtl. vorhandenes vorheriges). */
function company_asset_save(array $file, string $type): array|false {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return false;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, company_asset_allowed_extensions($type), true)) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;

    // Favicon darf ein .ico sein (kein reguläres Bild im getimagesize()-Sinn
    // bei mehrschichtigen .ico-Dateien) – dort keine getimagesize()-Pflicht.
    if ($ext !== 'ico' && @getimagesize($file['tmp_name']) === false) return false;

    // Alte Datei(en) dieses Typs (ggf. andere Endung) zuerst entfernen, damit
    // company_asset_find() nie zwei Kandidaten gleichzeitig sieht.
    foreach (company_asset_allowed_extensions($type) as $oldExt) {
        $old = company_asset_dir() . '/' . $type . '.' . $oldExt;
        if (is_file($old)) @unlink($old);
    }

    $dest = company_asset_dir() . '/' . $type . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    return ['path' => $dest, 'ext' => $ext];
}

function company_asset_delete(string $type): void {
    foreach (company_asset_allowed_extensions($type) as $ext) {
        $path = company_asset_dir() . '/' . $type . '.' . $ext;
        if (is_file($path)) @unlink($path);
    }
}

/** Web-URL für <img>-Tags: eigenes Logo, sonst Standard-Asset. */
function company_logo_url(): string {
    return company_asset_find('logo') !== null
        ? url('company_asset.php?type=logo')
        : url('assets/img/logo-compact.png');
}

function company_favicon_url(): string {
    return company_asset_find('favicon') !== null
        ? url('company_asset.php?type=favicon')
        : url('assets/img/favicon.ico');
}

/**
 * Absoluter Dateisystempfad des PDF-Logos für die direkte Einbettung via
 * TCPDF::Image() (kein HTTP-Umweg nötig). Fällt auf das Web-Logo zurück,
 * falls kein eigenes PDF-Logo hochgeladen wurde, und auf das Standard-Asset,
 * falls gar kein eigenes Logo existiert.
 */
function company_pdf_logo_path(): ?string {
    $custom = company_asset_find('logo_pdf') ?? company_asset_find('logo');
    if ($custom !== null) return $custom['path'];
    $default = BASE_PATH . '/public/assets/img/logo-full.png';
    return is_file($default) ? $default : null;
}

/**
 * Inline-<style>-Block, der die zentralen Marken-CSS-Variablen (siehe
 * assets/css/style.css :root) mit den unter Einstellungen > Logo & Design
 * hinterlegten Firmenfarben überschreibt. Ohne abweichende Einstellung
 * (Standardwerte) wird bewusst NICHTS ausgegeben, damit sich am
 * bestehenden Erscheinungsbild nichts ändert.
 */
function company_color_css_override(): string {
    $primary   = get_setting('company_color_primary',   '#0057B8');
    $secondary = get_setting('company_color_secondary', '#003D82');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary))   $primary   = '#0057B8';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $secondary)) $secondary = '#003D82';
    if ($primary === '#0057B8' && $secondary === '#003D82') return '';
    return '<style>:root{--blue:' . h($primary) . ';--blue-dark:' . h($secondary) . ';--blue-darker:' . h($secondary) . ';}</style>';
}

// ── Rechnungs-Einfrieren (Phase 3) ─────────────────────────────────────
// Erstellt beim Freigeben einer Rechnung einen vollständigen Schnappschuss
// aller rechnungsrelevanten Daten (Firmendaten UND Auftrags-/Positions-
// daten zum Freigabezeitpunkt) und liefert ihn als JSON-String zum
// Speichern in repairs.invoice_snapshot zurück. Einmal gespeichert, wird
// dieser Schnappschuss NIE wieder verändert – siehe rechnung.php, das
// nach Freigabe ausschließlich aus dem Snapshot statt aus Live-Daten
// rendert. So bleibt eine bereits ausgestellte Rechnung auch dann
// unverändert, wenn sich spätere Firmeneinstellungen oder Auftragsdaten
// ändern (siehe Master-Auftrag Phase 3, "Rechnungen dürfen niemals
// nachträglich verändert werden").
function build_invoice_snapshot(array $repair, array $customer, array $parts): string {
    return json_encode([
        'created_at' => date('c'),
        'company' => [
            'name'          => get_setting('company_name', 'MZ Tech'),
            'owner'         => get_setting('company_owner', ''),
            'address'       => get_setting('company_address', ''),
            'phone'         => get_setting('company_phone', ''),
            'email'         => get_setting('company_email', ''),
            'website'       => get_setting('company_website', ''),
            'iban'          => get_setting('company_iban', ''),
            'bic'           => get_setting('company_bic', ''),
            'bank_name'     => get_setting('company_bank_name', ''),
            'tax_id'        => get_setting('company_tax_id', ''),
            'steuernummer'  => get_setting('company_steuernummer', ''),
            'payment_terms' => get_setting('payment_terms', ''),
        ],
        'tax_rate'         => get_setting('tax_rate', '0'),
        'currency_symbol'  => get_setting('currency_symbol', '€'),
        'ustg_notice_text' => get_setting('ustg_notice_text', ''),
        'customer' => [
            'first_name' => $customer['first_name'] ?? '',
            'last_name'  => $customer['last_name']  ?? '',
            'company'    => $customer['company']    ?? '',
            'address'    => $customer['address']    ?? '',
            'zip'        => $customer['zip']         ?? '',
            'city'       => $customer['city']        ?? '',
            'email'      => $customer['email']       ?? '',
        ],
        'repair' => [
            'repair_number'  => $repair['repair_number']  ?? '',
            'invoice_number' => $repair['invoice_number'] ?? '',
            'device_type'    => $repair['device_type']    ?? '',
            'manufacturer'   => $repair['manufacturer']   ?? '',
            'model'          => $repair['model']          ?? '',
            'problem_description' => $repair['problem_description'] ?? '',
            'price'          => $repair['price'] ?? 0,
        ],
        'parts' => array_map(static function (array $p): array {
            return [
                'name'              => $p['name'] ?? ($p['part_name'] ?? ''),
                'quantity'          => $p['quantity'] ?? 1,
                'selling_price_at_time' => $p['selling_price_at_time'] ?? ($p['selling_price'] ?? 0),
            ];
        }, $parts),
    ], JSON_UNESCAPED_UNICODE);
}
