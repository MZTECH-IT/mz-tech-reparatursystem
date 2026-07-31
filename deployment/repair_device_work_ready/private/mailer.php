<?php
/**
 * MZ Tech – E-Mail-Versand via PHPMailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

function send_email(string $to_email, string $to_name, string $subject, string $html_body, string $plain_body = '', array $attachments = []): bool {
    $vendor_path = BASE_PATH . '/vendor/autoload.php';
    if (!file_exists($vendor_path)) {
        error_log('PHPMailer autoload nicht gefunden.');
        return false;
    }
    require_once $vendor_path;

    $host      = get_setting('smtp_host');
    $port      = (int)get_setting('smtp_port', '587');
    $user      = get_setting('smtp_user');
    $pass      = smtp_password_get(); // Phase 1: verschlüsselt gespeichert (siehe functions.php)
    $from_name = get_setting('smtp_from_name', 'MZ Tech');
    $from_mail = get_setting('smtp_from_email', 'info@mztech-it.de');

    if (!$host || !$user) {
        error_log('SMTP nicht konfiguriert.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';

        $mail->setFrom($from_mail, $from_name);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($from_mail, $from_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $plain_body ?: strip_tags($html_body);
        foreach ($attachments as $attachment) {
            $path = (string)($attachment['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                $mail->addAttachment($path, (string)($attachment['name'] ?? basename($path)));
            }
        }

        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

// ── Generische Vorlagen-E-Mail (Phase 4/5) ────────────────────────────
// Wird von allen NEUEN Funktionen (Mitarbeiter-Passwort-Reset, Firmen-
// kontakt-Konto, Ticket-Benachrichtigungen) genutzt, um Code-Duplizierung
// zu vermeiden. Lädt Betreff/Text aus `email_templates` (siehe
// $template_key), ersetzt alle bekannten Platzhalter und hängt – sofern
// unter Einstellungen > E-Mail hinterlegt – automatisch die zentrale
// E-Mail-Signatur an (siehe get_setting('email_signature')).
//
// $to: ['first_name'=>, 'last_name'=>, 'email'=>]
// $placeholders: zusätzliche {{platzhalter}} => Wert (bereits als Klartext,
//                wird intern per h() escaped, KEIN HTML übergeben).
function send_generic_template_email(string $template_key, array $to, array $placeholders = []): bool {
    $company = get_setting('company_name', 'MZ Tech');
    $address = get_setting('company_address', '');

    $first_name = (string)($to['first_name'] ?? '');
    $last_name  = (string)($to['last_name']  ?? '');
    $email      = (string)($to['email']      ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $template = null;
    try {
        $stmt = get_db()->prepare('SELECT subject, body, enabled FROM email_templates WHERE status_key = ? LIMIT 1');
        $stmt->execute([$template_key]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        $template = null;
    }
    if ($template === null) return false;
    if ((int)$template['enabled'] === 0) return false;

    $safe = [
        '{{vorname}}'  => h($first_name),
        '{{nachname}}' => h($last_name),
        '{{firma}}'    => h($company !== '' ? $company : 'MZ Tech'),
    ];
    foreach ($placeholders as $key => $val) {
        $safe['{{' . $key . '}}'] = h((string)$val);
    }

    $subject = preg_replace('/[\r\n]+/', ' ', strtr((string)$template['subject'], $safe));
    $body    = strtr((string)$template['body'], $safe);

    $signature = trim(get_setting('email_signature', ''));
    if ($signature !== '') {
        $body .= '<div style="margin-top:16px;white-space:pre-line;">' . h($signature) . '</div>';
    }

    $html = "<html><body style='font-family:sans-serif;color:#333;'><div style='max-width:600px;margin:0 auto;'>"
        . "<h2 style='color:" . h(get_setting('company_color_primary', '#0057B8')) . ";'>" . h($company !== '' ? $company : 'MZ Tech') . "</h2>"
        . $body
        . "<hr style='border:none;border-top:1px solid #e8ecf0;margin:24px 0;'>"
        . "<p style='font-size:12px;color:#888;'>" . h($company !== '' ? $company : 'MZ Tech') . " · " . h($address) . "</p>"
        . "</div></body></html>";

    return send_email($email, trim($first_name . ' ' . $last_name), $subject ?: $template_key, $html);
}

// ── Vorlage: Statusänderung (admin-editierbar über email_templates) ──
// Lädt Betreff/Text aus der Tabelle `email_templates` (siehe settings.php /
// email_templates.php) und ersetzt Platzhalter wie {{vorname}}. Ist für den
// jeweiligen Status keine Vorlage hinterlegt oder die Vorlage deaktiviert,
// wird defensiv auf eine feste Standard-Vorlage zurückgegriffen, damit der
// Versand auch bei fehlender/älterer Datenbank nicht komplett ausfällt.
function send_repair_status_email(array $repair, array $customer, string $new_status): bool {
    $status_key   = function_exists('repair_status_normalize') ? repair_status_normalize($new_status) : $new_status;
    $status_label = repair_status_label($new_status);
    $company      = get_setting('company_name', 'MZ Tech');
    $phone        = get_setting('company_phone', '');
    $address      = get_setting('company_address', '');

    $first_name    = (string)($customer['first_name'] ?? '');
    $last_name     = (string)($customer['last_name']  ?? '');
    $repair_number = (string)($repair['repair_number'] ?? '');
    $manufacturer  = (string)($repair['manufacturer']  ?? '');
    $model         = (string)($repair['model']         ?? '');
    $device_type   = (string)($repair['device_type']   ?? '');
    $geraet        = trim($manufacturer . ' ' . $model) ?: $device_type;

    // Kundenportal-Link: wird nur gebildet, wenn eine customer_id vorliegt
    // (bei repairs immer der Fall). Zugang wird bei Bedarf automatisch
    // angelegt (idempotent), damit der Link in der E-Mail funktioniert.
    $portal_link = '';
    $repair_customer_id = (int)($repair['customer_id'] ?? 0);
    if ($repair_customer_id > 0 && function_exists('ensure_customer_portal_access')) {
        try {
            $access = ensure_customer_portal_access($repair_customer_id);
            $portal_link = $access['link'] ?? '';
        } catch (Throwable $e) {
            error_log('Kundenportal-Link konnte nicht erzeugt werden: ' . $e->getMessage());
        }
    }

    $template = null;
    try {
        $stmt = get_db()->prepare('SELECT subject, body, enabled FROM email_templates WHERE status_key = ? LIMIT 1');
        $stmt->execute([$status_key]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        $template = null; // Tabelle evtl. noch nicht migriert – fest codierte Vorlage greift unten
    }

    // Vorlage bewusst deaktiviert → keine E-Mail versenden für diesen Status
    if ($template !== null && (int)$template['enabled'] === 0) {
        return true;
    }

    // Platzhalterwerte werden vor der Ersetzung escaped, damit Kunden-/Auftrags-
    // daten (die potenziell vom Nutzer stammen) niemals als rohes HTML in die
    // E-Mail gelangen können, auch wenn die Vorlage selbst HTML enthält.
    $safe = [
        '{{vorname}}'        => h($first_name),
        '{{nachname}}'       => h($last_name),
        '{{firma}}'          => h($company !== '' ? $company : 'MZ Tech'),
        '{{auftragsnummer}}' => h($repair_number),
        '{{status}}'         => h($status_label),
        '{{geraet}}'         => h($geraet),
        '{{hersteller}}'     => h($manufacturer),
        '{{modell}}'         => h($model),
        '{{portal_link}}'    => h($portal_link),
    ];

    if ($template !== null) {
        $subject_raw = (string)$template['subject'];
        $body        = strtr((string)$template['body'], $safe);
    } else {
        // Fest codierte Standard-Vorlage als Rückfallebene, falls die
        // Tabelle email_templates (noch) nicht existiert oder kein
        // passender Eintrag gefunden wurde.
        $subject_raw = "[" . ($company !== '' ? $company : 'MZ Tech') . "] Ihr Reparaturauftrag #{{auftragsnummer}} – Status: {{status}}";
        $body = "<p>Hallo {{vorname}} {{nachname}},</p>"
            . "<p>der Status Ihres Reparaturauftrags hat sich geändert:</p>"
            . "<table style='width:100%;border-collapse:collapse;margin:16px 0;'>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Auftragsnummer</td><td style='padding:8px;'>{{auftragsnummer}}</td></tr>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Gerät</td><td style='padding:8px;'>{{geraet}}</td></tr>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Neuer Status</td><td style='padding:8px;font-weight:bold;color:#0057B8;'>{{status}}</td></tr>"
            . "</table>";
        if (repair_status_is_completed($new_status) && $status_key !== 'abgeholt') {
            $body .= "<p>Ihr Gerät ist fertig und kann bei uns abgeholt werden.</p>";
            if ($phone) $body .= "<p>Bei Fragen erreichen Sie uns unter: <strong>" . h($phone) . "</strong></p>";
        }
        if ($portal_link && in_array($status_key, ['kostenvoranschlag', 'freigabe_ausstehend'], true)) {
            $body .= "<p><a href='{{portal_link}}' style='color:#0057B8;font-weight:bold;'>Jetzt im Kundenportal ansehen &amp; freigeben</a></p>";
        }
        $body = strtr($body, $safe);
    }

    // Subject header: Steuer-/Zeilenumbruchzeichen entfernen (Header-Injection-Schutz)
    $subject = preg_replace('/[\r\n]+/', ' ', strtr($subject_raw, $safe));

    $html = "<html><body style='font-family:sans-serif;color:#333;'><div style='max-width:600px;margin:0 auto;'>"
        . "<h2 style='color:#0057B8;'>" . h($company !== '' ? $company : 'MZ Tech') . "</h2>"
        . $body
        . "<hr style='border:none;border-top:1px solid #e8ecf0;margin:24px 0;'>"
        . "<p style='font-size:12px;color:#888;'>" . h($company !== '' ? $company : 'MZ Tech') . " · " . h($address) . "</p>"
        . "</div></body></html>";

    return send_email(
        $customer['email'] ?? '',
        trim($first_name . ' ' . $last_name),
        $subject,
        $html
    );
}

// ── Vorlage: Terminbuchung (öffentliche Terminanfrage) ─────
// Analog zu send_repair_status_email(), aber für den eigenständigen
// booking_requests-Workflow (Terminbuchung vor Anlage einer Reparatur).
// $status muss einer von: angefragt, bestaetigt, abgelehnt, umgeplant sein.
function send_booking_status_email(array $booking, string $status): bool {
    $template_key = 'buchung_' . $status;
    $company = get_setting('company_name', 'MZ Tech');
    $address = get_setting('company_address', '');

    $first_name      = (string)($booking['first_name'] ?? '');
    $last_name       = (string)($booking['last_name']  ?? '');
    $booking_number  = (string)($booking['booking_number'] ?? '');
    $device_type     = device_type_label((string)($booking['device_type'] ?? ''));
    $manufacturer    = (string)($booking['manufacturer'] ?? '');
    $model           = (string)($booking['model'] ?? '');
    $geraet          = trim($manufacturer . ' ' . $model) ?: $device_type;

    $wunschzeit = fmt_date($booking['preferred_date'] ?? null) . ' '
        . substr((string)($booking['preferred_time'] ?? ''), 0, 5) . ' Uhr';
    if ($status === 'bestaetigt' && !empty($booking['confirmed_datetime'])) {
        $wunschzeit = fmt_date($booking['confirmed_datetime'], true);
    }
    if ($status === 'umgeplant' && !empty($booking['confirmed_datetime'])) {
        $wunschzeit = fmt_date($booking['confirmed_datetime'], true);
    }

    $template = null;
    try {
        $stmt = get_db()->prepare('SELECT subject, body, enabled FROM email_templates WHERE status_key = ? LIMIT 1');
        $stmt->execute([$template_key]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        $template = null;
    }

    if ($template !== null && (int)$template['enabled'] === 0) {
        return true;
    }

    $safe = [
        '{{vorname}}'        => h($first_name),
        '{{nachname}}'       => h($last_name),
        '{{firma}}'          => h($company !== '' ? $company : 'MZ Tech'),
        '{{auftragsnummer}}' => h($booking_number),
        '{{status}}'         => h($wunschzeit),
        '{{geraet}}'         => h($geraet),
        '{{hersteller}}'     => h($manufacturer),
        '{{modell}}'         => h($model),
        '{{portal_link}}'    => '',
    ];

    if ($template !== null) {
        $subject_raw = (string)$template['subject'];
        $body        = strtr((string)$template['body'], $safe);
    } else {
        $labels = [
            'angefragt'  => 'Ihre Terminanfrage wurde erfasst',
            'bestaetigt' => 'Ihr Termin wurde bestätigt',
            'abgelehnt'  => 'Ihre Terminanfrage konnte nicht bestätigt werden',
            'umgeplant'  => 'Ihr Termin wurde verschoben',
        ];
        $subject_raw = "[" . ($company !== '' ? $company : 'MZ Tech') . "] " . ($labels[$status] ?? 'Terminanfrage') . " – #{{auftragsnummer}}";
        $body = "<p>Hallo {{vorname}} {{nachname}},</p>"
            . "<p>" . h($labels[$status] ?? 'Es gibt eine Aktualisierung zu Ihrer Terminanfrage.') . ".</p>"
            . "<table style='width:100%;border-collapse:collapse;margin:16px 0;'>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Anfragenummer</td><td style='padding:8px;'>{{auftragsnummer}}</td></tr>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Gerät</td><td style='padding:8px;'>{{geraet}}</td></tr>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Termin</td><td style='padding:8px;font-weight:bold;color:#0057B8;'>{{status}}</td></tr>"
            . "</table>";
        $body = strtr($body, $safe);
    }

    $subject = preg_replace('/[\r\n]+/', ' ', strtr($subject_raw, $safe));

    $html = "<html><body style='font-family:sans-serif;color:#333;'><div style='max-width:600px;margin:0 auto;'>"
        . "<h2 style='color:#0057B8;'>" . h($company !== '' ? $company : 'MZ Tech') . "</h2>"
        . $body
        . "<hr style='border:none;border-top:1px solid #e8ecf0;margin:24px 0;'>"
        . "<p style='font-size:12px;color:#888;'>" . h($company !== '' ? $company : 'MZ Tech') . " · " . h($address) . "</p>"
        . "</div></body></html>";

    return send_email(
        $booking['email'] ?? '',
        trim($first_name . ' ' . $last_name),
        $subject,
        $html
    );
}

// ── Vorlage: Kundenkonto (Registrierung/Verifizierung, Passwort-Reset,
//    neue Nachricht im Portal) – siehe private/customer_auth.php ─────
// $customer benötigt mind. first_name/last_name/email. $extra kann
// verify_link, reset_link, portal_link und auftragsnummer enthalten,
// je nachdem, welche Platzhalter die jeweilige Vorlage nutzt.
function send_customer_account_email(string $template_key, array $customer, array $extra = []): bool {
    $company = get_setting('company_name', 'MZ Tech');
    $address = get_setting('company_address', '');

    $first_name = (string)($customer['first_name'] ?? '');
    $last_name  = (string)($customer['last_name']  ?? '');
    $email      = (string)($customer['email']      ?? '');

    if ($email === '') return false;

    $template = null;
    try {
        $stmt = get_db()->prepare('SELECT subject, body, enabled FROM email_templates WHERE status_key = ? LIMIT 1');
        $stmt->execute([$template_key]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        $template = null;
    }

    // Ohne Vorlage kein Versand – die Vorlagen werden per Migration
    // (schema.sql/update.sql) automatisch angelegt, sollte also nur bei
    // einer sehr alten, noch nicht migrierten Installation vorkommen.
    if ($template === null) return false;
    if ((int)$template['enabled'] === 0) return false;

    $safe = [
        '{{vorname}}'        => h($first_name),
        '{{nachname}}'       => h($last_name),
        '{{firma}}'          => h($company !== '' ? $company : 'MZ Tech'),
        '{{auftragsnummer}}'  => h((string)($extra['auftragsnummer']  ?? '')),
        '{{portal_link}}'     => h((string)($extra['portal_link']     ?? '')),
        '{{verify_link}}'     => h((string)($extra['verify_link']     ?? '')),
        '{{reset_link}}'      => h((string)($extra['reset_link']      ?? '')),
        // Phase 2: Rechnungsfreigabe (siehe email_templates.status_key = 'rechnung_freigegeben')
        '{{rechnungsnummer}}' => h((string)($extra['rechnungsnummer'] ?? '')),
    ];

    $subject_raw = (string)$template['subject'];
    $body        = strtr((string)$template['body'], $safe);
    $subject     = preg_replace('/[\r\n]+/', ' ', strtr($subject_raw, $safe));

    $html = "<html><body style='font-family:sans-serif;color:#333;'><div style='max-width:600px;margin:0 auto;'>"
        . "<h2 style='color:#0057B8;'>" . h($company !== '' ? $company : 'MZ Tech') . "</h2>"
        . $body
        . "<hr style='border:none;border-top:1px solid #e8ecf0;margin:24px 0;'>"
        . "<p style='font-size:12px;color:#888;'>" . h($company !== '' ? $company : 'MZ Tech') . " · " . h($address) . "</p>"
        . "</div></body></html>";

    return send_email($email, trim($first_name . ' ' . $last_name), $subject, $html);
}

// ── Vorlage: Reparaturanfrage (öffentliches Formular ohne Termin) ─
// Analog zu send_booking_status_email(), aber für den eigenständigen
// repair_requests-Workflow. $status muss einer von: neu, abgelehnt sein
// (archiviert/umgewandelt lösen bewusst keine automatische Kunden-E-Mail aus).
function send_repair_request_email(array $request, string $status): bool {
    $template_key = 'ranfrage_' . $status;
    $company = get_setting('company_name', 'MZ Tech');
    $address = get_setting('company_address', '');

    $first_name     = (string)($request['first_name'] ?? '');
    $last_name      = (string)($request['last_name']  ?? '');
    $request_number = (string)($request['request_number'] ?? '');
    $device_type    = device_type_label((string)($request['device_type'] ?? ''));
    $manufacturer   = (string)($request['manufacturer'] ?? '');
    $model          = (string)($request['model'] ?? '');
    $geraet         = trim($manufacturer . ' ' . $model) ?: $device_type;

    $template = null;
    try {
        $stmt = get_db()->prepare('SELECT subject, body, enabled FROM email_templates WHERE status_key = ? LIMIT 1');
        $stmt->execute([$template_key]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        $template = null;
    }

    if ($template !== null && (int)$template['enabled'] === 0) {
        return true;
    }

    $safe = [
        '{{vorname}}'        => h($first_name),
        '{{nachname}}'       => h($last_name),
        '{{firma}}'          => h($company !== '' ? $company : 'MZ Tech'),
        '{{auftragsnummer}}' => h($request_number),
        '{{status}}'         => h(repair_request_status_label($status)),
        '{{geraet}}'         => h($geraet),
        '{{hersteller}}'     => h($manufacturer),
        '{{modell}}'         => h($model),
        '{{portal_link}}'    => '',
    ];

    if ($template !== null) {
        $subject_raw = (string)$template['subject'];
        $body        = strtr((string)$template['body'], $safe);
    } else {
        $labels = [
            'neu'       => 'Ihre Reparaturanfrage wurde erfasst',
            'abgelehnt' => 'Ihre Reparaturanfrage konnte nicht bearbeitet werden',
        ];
        $subject_raw = "[" . ($company !== '' ? $company : 'MZ Tech') . "] " . ($labels[$status] ?? 'Reparaturanfrage') . " – #{{auftragsnummer}}";
        $body = "<p>Hallo {{vorname}} {{nachname}},</p>"
            . "<p>" . h($labels[$status] ?? 'Es gibt eine Aktualisierung zu Ihrer Reparaturanfrage.') . ".</p>"
            . "<table style='width:100%;border-collapse:collapse;margin:16px 0;'>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Anfragenummer</td><td style='padding:8px;'>{{auftragsnummer}}</td></tr>"
            . "<tr><td style='padding:8px;background:#f5f7fa;font-weight:bold;'>Gerät</td><td style='padding:8px;'>{{geraet}}</td></tr>"
            . "</table>";
        $body = strtr($body, $safe);
    }

    $subject = preg_replace('/[\r\n]+/', ' ', strtr($subject_raw, $safe));

    $html = "<html><body style='font-family:sans-serif;color:#333;'><div style='max-width:600px;margin:0 auto;'>"
        . "<h2 style='color:#0057B8;'>" . h($company !== '' ? $company : 'MZ Tech') . "</h2>"
        . $body
        . "<hr style='border:none;border-top:1px solid #e8ecf0;margin:24px 0;'>"
        . "<p style='font-size:12px;color:#888;'>" . h($company !== '' ? $company : 'MZ Tech') . " · " . h($address) . "</p>"
        . "</div></body></html>";

    return send_email(
        $request['email'] ?? '',
        trim($first_name . ' ' . $last_name),
        $subject,
        $html
    );
}
