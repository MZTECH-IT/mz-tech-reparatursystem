<?php
/**
 * MZ Tech – Einstellungen (Admin only)
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/numbering.php';
require_once PRIVATE_PATH . '/permissions.php';
require_admin();

$db = get_db();
$errors   = [];
$success  = [];

// ── POST-Handler ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── Firmendaten speichern ──
    if ($action === 'save_company') {
        $billingMode = $_POST['billing_mode'] ?? BILLING_MODE_SMALL_BUSINESS;
        if (!in_array($billingMode, [BILLING_MODE_SMALL_BUSINESS, BILLING_MODE_STANDARD_TAX], true)) {
            $billingMode = BILLING_MODE_SMALL_BUSINESS;
        }
        try {
            $defaultHourlyRate = repair_decimal_input(
                $_POST['default_hourly_rate'] ?? '79.00',
                'Standard-Stundensatz',
                '9999.99'
            );
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: ' . url('settings.php') . '#firmendaten');
            exit;
        }

        $company_fields = [
            'company_name', 'company_address', 'company_phone', 'company_email',
            'company_website', 'company_iban', 'company_bic', 'company_tax_id',
            'invoice_prefix', 'repair_prefix', 'warranty_default', 'tax_rate',
            'currency_symbol',
        ];
        foreach ($company_fields as $field) {
            set_setting($field, trim($_POST[$field] ?? ''));
        }
        set_setting('billing_mode', $billingMode);
        if (billing_is_small_business($billingMode)) {
            set_setting('tax_rate', '0.00');
        }
        set_setting('default_hourly_rate', $defaultHourlyRate);
        // Im Kleinunternehmermodus ist der vorgeschriebene Wortlaut zentral
        // und kann nicht versehentlich durch einen Formularwert ersetzt werden.
        $notice = billing_is_small_business($billingMode)
            ? BILLING_SMALL_BUSINESS_NOTICE
            : trim($_POST['ustg_notice_text'] ?? '');
        set_setting('ustg_notice_text', $notice);
        log_activity('settings_updated', 'settings', null, 'Firmendaten aktualisiert');
        flash('success', 'Firmendaten erfolgreich gespeichert.');
        header('Location: ' . url('settings.php') . '#firmendaten');
        exit;
    }

    // ── SMTP speichern ──
    if ($action === 'save_smtp') {
        flash('error', 'SMTP-Zugangsdaten werden ausschließlich über den sicheren lokalen Credential-Workflow verwaltet.');
        header('Location: ' . url('settings.php') . '#smtp');
        exit;
    }

    // ── Test-E-Mail senden ──
    if ($action === 'test_smtp') {
        flash('error', 'Test-E-Mails dürfen nur über den kontrollierten Deployment-Test an eine ausdrücklich bestätigte TEST-Adresse versendet werden.');
        header('Location: ' . url('settings.php') . '#smtp');
        exit;
    }

    // ── Terminbuchung speichern ──
    if ($action === 'save_booking') {
        set_setting('booking_enabled', isset($_POST['booking_enabled']) ? '1' : '0');

        $hours = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $closed = isset($_POST["booking_closed_$d"]);
            $open   = trim($_POST["booking_open_$d"]  ?? '');
            $close  = trim($_POST["booking_close_$d"] ?? '');
            if ($closed || $open === '' || $close === '') {
                $hours[$d] = null;
            } else {
                $hours[$d] = ['open' => $open, 'close' => $close];
            }
        }
        set_setting('booking_hours', json_encode($hours));

        set_setting('booking_slot_minutes',      (string)max(5, (int)($_POST['booking_slot_minutes'] ?? 30)));
        set_setting('booking_capacity_per_slot', (string)max(1, (int)($_POST['booking_capacity_per_slot'] ?? 1)));
        set_setting('booking_lead_hours',        (string)max(0, (int)($_POST['booking_lead_hours'] ?? 24)));
        set_setting('booking_max_days_ahead',    (string)max(1, (int)($_POST['booking_max_days_ahead'] ?? 30)));

        $blocked_raw = trim($_POST['booking_blocked_dates'] ?? '');
        $blocked = [];
        if ($blocked_raw !== '') {
            foreach (preg_split('/[\r\n,]+/', $blocked_raw) as $d) {
                $d = trim($d);
                if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $blocked[] = $d;
                }
            }
        }
        set_setting('booking_blocked_dates', json_encode(array_values(array_unique($blocked))));

        log_activity('settings_updated', 'settings', null, 'Terminbuchungs-Einstellungen aktualisiert');
        flash('success', 'Terminbuchungs-Einstellungen erfolgreich gespeichert.');
        header('Location: ' . url('settings.php') . '#terminbuchung');
        exit;
    }

    // ── Datenschutzhinweis speichern ──
    if ($action === 'save_privacy') {
        $text = trim($_POST['privacy_notice_text'] ?? '');
        if ($text === '') {
            flash('error', 'Der Datenschutzhinweis darf nicht leer sein.');
        } else {
            set_setting('privacy_notice_text', $text);
            log_activity('settings_updated', 'settings', null, 'Datenschutzhinweis aktualisiert');
            flash('success', 'Datenschutzhinweis erfolgreich gespeichert.');
        }
        header('Location: ' . url('settings.php') . '#datenschutz');
        exit;
    }

    // ── Kundenportal-Einstellungen speichern ──
    if ($action === 'save_portal') {
        set_setting('portal_enabled', isset($_POST['portal_enabled']) ? '1' : '0');
        set_setting('customer_accounts_enabled', isset($_POST['customer_accounts_enabled']) ? '1' : '0');
        log_activity('settings_updated', 'settings', null, 'Kundenportal-Einstellungen aktualisiert');
        flash('success', 'Kundenportal-Einstellungen erfolgreich gespeichert.');
        header('Location: ' . url('settings.php') . '#kundenportal');
        exit;
    }

    // ── Kalender-Synchronisation speichern (.ics-Feed + Google Kalender) ──
    if ($action === 'save_calendar') {
        set_setting('ics_feed_enabled', isset($_POST['ics_feed_enabled']) ? '1' : '0');

        set_setting('gcal_client_id', trim($_POST['gcal_client_id'] ?? ''));
        // Client-Secret nur überschreiben, wenn ausgefüllt (wie beim SMTP-Passwort)
        $gcal_secret = trim($_POST['gcal_client_secret'] ?? '');
        if ($gcal_secret !== '') {
            gcal_store_client_secret($gcal_secret);
        }
        set_setting('gcal_calendar_id', trim($_POST['gcal_calendar_id'] ?? '') ?: 'primary');

        // "Synchronisation aktiv" kann nur greifen, wenn bereits eine Verbindung
        // (Refresh-Token) besteht – die Erstverbindung erfolgt über den
        // separaten "Mit Google verbinden"-Button (OAuth2-Flow).
        if (get_setting('gcal_refresh_token_encrypted', '') !== '') {
            set_setting('gcal_enabled', isset($_POST['gcal_enabled']) ? '1' : '0');
        }

        log_activity('settings_updated', 'settings', null, 'Kalender-Synchronisation aktualisiert');
        flash('success', 'Kalender-Einstellungen erfolgreich gespeichert.');
        header('Location: ' . url('settings.php') . '#kalender');
        exit;
    }

    // ── .ics-Feed-Token neu generieren (z. B. bei Verdacht auf Missbrauch) ──
    if ($action === 'regenerate_ics_token') {
        ics_regenerate_feed_token();
        log_activity('settings_updated', 'settings', null, '.ics-Feed-Token neu generiert');
        flash('success', 'Der Abo-Link wurde erneuert. Der alte Link funktioniert nicht mehr.');
        header('Location: ' . url('settings.php') . '#kalender');
        exit;
    }

    // ── Google-Kalender-Verbindung trennen ──
    if ($action === 'gcal_disconnect') {
        gcal_disconnect();
        log_activity('settings_updated', 'settings', null, 'Google-Kalender getrennt');
        flash('success', 'Die Verbindung zu Google Kalender wurde getrennt.');
        header('Location: ' . url('settings.php') . '#kalender');
        exit;
    }

    // ── Neuen Benutzer anlegen ──
    if ($action === 'create_user') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username']  ?? '');
        $email     = trim($_POST['email']     ?? '');
        $password  = $_POST['password']        ?? '';
        $password2 = $_POST['password2']       ?? '';
        $role      = in_array($_POST['role'] ?? '', ['admin','techniker','empfang']) ? $_POST['role'] : 'techniker';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!$full_name) $errors[] = 'Vollständiger Name ist erforderlich.';
        if (!$username)  $errors[] = 'Benutzername ist erforderlich.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Gültige E-Mail erforderlich.';
        if (!$password)  $errors[] = 'Passwort ist erforderlich.';
        if ($password !== $password2) $errors[] = 'Passwörter stimmen nicht überein.';

        if (empty($errors)) {
            $pw_error = validate_password($password);
            if ($pw_error !== null) {
                $errors[] = $pw_error;
            }
        }

        if (empty($errors)) {
            // Prüfen ob Username/Email bereits existiert
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Benutzername oder E-Mail bereits vergeben.';
            }
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $stmt = $db->prepare(
                "INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$username, $email, $hash, $full_name, $role, $is_active]);
            $new_id = (int)$db->lastInsertId();
            log_activity('user_created', 'users', $new_id, "Benutzer '$username' angelegt");
            flash('success', "Benutzer '$username' erfolgreich angelegt.");
            header('Location: ' . url('settings.php') . '#benutzer');
            exit;
        }
        // Errors → fall through to display
    }

    // ── Benutzer aktivieren/deaktivieren ──
    if ($action === 'toggle_user') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $activate = (int)($_POST['activate'] ?? 0);
        if ($uid && $uid !== (int)($_SESSION['user_id'] ?? 0)) {
            $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$activate, $uid]);
            $label = $activate ? 'aktiviert' : 'deaktiviert';
            log_activity('user_toggled', 'users', $uid, "Benutzer $label");
            flash('success', "Benutzer erfolgreich $label.");
        }
        header('Location: ' . url('settings.php') . '#benutzer');
        exit;
    }

    // ── Benutzer bearbeiten ──
    if ($action === 'edit_user') {
        $uid       = (int)($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $role      = in_array($_POST['role'] ?? '', ['admin','techniker','empfang']) ? $_POST['role'] : 'techniker';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password  = $_POST['password']  ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (!$uid) { flash('error', 'Ungültige Benutzer-ID.'); header('Location: ' . url('settings.php') . '#benutzer'); exit; }
        if (!$full_name) $errors[] = 'Vollständiger Name ist erforderlich.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Gültige E-Mail erforderlich.';

        if ($password !== '') {
            if ($password !== $password2) {
                $errors[] = 'Passwörter stimmen nicht überein.';
            } else {
                $pw_error = validate_password($password);
                if ($pw_error !== null) $errors[] = $pw_error;
            }
        }

        if (empty($errors)) {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                $stmt = $db->prepare(
                    "UPDATE users SET full_name=?, email=?, role=?, is_active=?, password_hash=? WHERE id=?"
                );
                $stmt->execute([$full_name, $email, $role, $is_active, $hash, $uid]);
            } else {
                $stmt = $db->prepare(
                    "UPDATE users SET full_name=?, email=?, role=?, is_active=? WHERE id=?"
                );
                $stmt->execute([$full_name, $email, $role, $is_active, $uid]);
            }
            log_activity('user_updated', 'users', $uid, "Benutzer #$uid bearbeitet");
            flash('success', 'Benutzer erfolgreich aktualisiert.');
            header('Location: ' . url('settings.php') . '#benutzer');
            exit;
        }
    }

    // ── Nummernkreise speichern (Dokumentenmodul) ──
    // Bewusst nur für Administratoren zugänglich (die gesamte Seite ist
    // require_admin()-geschützt) – Auftrag Abschnitt 4: "Startnummer darf
    // nur von Administratoren geändert werden."
    if ($action === 'save_number_ranges') {
        $nr = $_POST['nr'] ?? [];
        $collisionWarnings = [];
        foreach (number_range_known_types() as $docType) {
            if (!isset($nr[$docType])) continue;
            $row = $nr[$docType];
            $prefix      = trim((string)($row['prefix'] ?? ''));
            $separator   = (string)($row['separator'] ?? '-');
            $digits      = (int)($row['digits'] ?? 6);
            $yearlyReset = isset($row['yearly_reset']);
            $startNumber = (int)($row['start_number'] ?? 1);

            if ($prefix === '') {
                $errors[] = 'Präfix für "' . h(number_range_defaults($docType)['label']) . '" darf nicht leer sein.';
                continue;
            }

            // Heuristische Kollisionswarnung (siehe private/numbering.php) –
            // blockiert das Speichern NICHT (der UNIQUE-Index auf den
            // jeweiligen Nummernspalten bleibt die harte Absicherung gegen
            // tatsächliche Doppelvergabe), informiert den Administrator aber
            // sofort sichtbar.
            $collisions = number_range_check_collision($docType, $startNumber);
            if (!empty($collisions)) {
                $collisionWarnings[] = number_range_defaults($docType)['label'] . ': bereits vergebene Nummern ≥ neuer Startnummer vorhanden (' . implode(', ', array_slice($collisions, 0, 5)) . (count($collisions) > 5 ? ', …' : '') . ')';
            }

            number_range_save($docType, $prefix, $separator, $digits, $yearlyReset, $startNumber);
        }
        log_activity('settings_updated', 'settings', null, 'Nummernkreise aktualisiert');
        if (!empty($collisionWarnings)) {
            flash('error', 'Nummernkreise gespeichert – ACHTUNG, mögliche Kollision: ' . implode(' | ', $collisionWarnings));
        } else {
            flash('success', 'Nummernkreise erfolgreich gespeichert.');
        }
        header('Location: ' . url('settings.php') . '#nummernkreise');
        exit;
    }

    // ── Rechte-Matrix speichern ──
    if ($action === 'save_permissions') {
        role_permissions_save($_POST['perm'] ?? []);
        log_activity('settings_updated', 'settings', null, 'Rechte-Matrix aktualisiert');
        flash('success', 'Berechtigungen erfolgreich gespeichert.');
        header('Location: ' . url('settings.php') . '#rechte');
        exit;
    }
}

// ── Daten laden ───────────────────────────────────────────────────────────────

// Firmendaten
$company_keys = [
    'company_name', 'company_address', 'company_phone', 'company_email',
    'company_website', 'company_iban', 'company_bic', 'company_tax_id',
    'invoice_prefix', 'repair_prefix', 'warranty_default', 'tax_rate',
    'currency_symbol', 'default_hourly_rate',
];
$settings = [];
foreach ($company_keys as $k) {
    $settings[$k] = get_setting($k);
}
$settings['default_hourly_rate'] = $settings['default_hourly_rate'] ?: '79.00';
$settings['billing_mode'] = billing_mode();
$settings['ustg_notice_text'] = get_setting(
    'ustg_notice_text',
    'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet und ausgewiesen (Kleinunternehmerregelung).'
);

// SMTP: ausschließlich bereinigter Status; Geheimnisse werden nie ausgegeben.
$smtp_status = smtp_configuration_status();
$smtp_sender = get_setting('smtp_from_email', '');
$smtp_sender_masked = $smtp_sender !== '' ? portal_mask_email($smtp_sender) : '–';

// Terminbuchung
$booking_settings = booking_get_settings();
$weekday_labels = ['mon' => 'Montag', 'tue' => 'Dienstag', 'wed' => 'Mittwoch', 'thu' => 'Donnerstag', 'fri' => 'Freitag', 'sat' => 'Samstag', 'sun' => 'Sonntag'];

// Alle Benutzer
$users = $db->query(
    "SELECT id, username, email, full_name, role, is_active, last_login
     FROM users ORDER BY full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Rollen-Label: siehe private/permissions.php (role_label()) – die früher
// hier lokal duplizierte Funktion wurde entfernt, um die Rechte-Matrix unten
// (aus derselben Datei) ohne Namenskollision einbinden zu können. Verhalten
// unverändert für die drei bisher verwendeten Rollen (admin/techniker/
// empfang), ergänzt um "mitarbeiter"/"buchhaltung" aus Phase 4.

// Nummernkreise (Dokumentenmodul)
$number_ranges = number_range_list();

// Rechte-Matrix (Phase 4 – bisher ohne UI, wird hier erstmals angebunden)
$all_permissions   = permissions_all();
$perm_matrix       = role_permissions_matrix();
$perm_by_category  = [];
foreach ($all_permissions as $p) {
    $perm_by_category[$p['category']][] = $p;
}
$editable_roles = array_values(array_diff(known_roles(), ['admin']));

$page_title = 'Einstellungen';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Einstellungen</h1>
    <p class="page-subtitle">System- und Unternehmenskonfiguration</p>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php show_flash(); ?>

<!-- ── Firmendaten ── -->
<div class="card" id="firmendaten" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('settings') ?> Firmendaten</h2>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_company">
      <div class="form-grid">
        <div class="form-group">
          <label for="company_name">Firmenname</label>
          <input type="text" id="company_name" name="company_name" class="form-control" value="<?= h($settings['company_name']) ?>">
        </div>
        <div class="form-group">
          <label for="company_email">E-Mail</label>
          <input type="email" id="company_email" name="company_email" class="form-control" value="<?= h($settings['company_email']) ?>">
        </div>
        <div class="form-group">
          <label for="company_phone">Telefon</label>
          <input type="text" id="company_phone" name="company_phone" class="form-control" value="<?= h($settings['company_phone']) ?>">
        </div>
        <div class="form-group">
          <label for="company_website">Website</label>
          <input type="url" id="company_website" name="company_website" class="form-control" value="<?= h($settings['company_website']) ?>" placeholder="https://...">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label for="company_address">Adresse</label>
          <textarea id="company_address" name="company_address" class="form-control" rows="3"><?= h($settings['company_address']) ?></textarea>
        </div>
        <div class="form-group">
          <label for="company_iban">IBAN</label>
          <input type="text" id="company_iban" name="company_iban" class="form-control" value="<?= h($settings['company_iban']) ?>">
        </div>
        <div class="form-group">
          <label for="company_bic">BIC</label>
          <input type="text" id="company_bic" name="company_bic" class="form-control" value="<?= h($settings['company_bic']) ?>">
        </div>
        <div class="form-group">
          <label for="company_tax_id">Steuer-ID / USt-IdNr.</label>
          <input type="text" id="company_tax_id" name="company_tax_id" class="form-control" value="<?= h($settings['company_tax_id']) ?>">
        </div>
        <div class="form-group">
          <label for="invoice_prefix">Rechnungsnummer-Präfix</label>
          <input type="text" id="invoice_prefix" name="invoice_prefix" class="form-control" value="<?= h($settings['invoice_prefix'] ?: 'RE') ?>" maxlength="10">
        </div>
        <div class="form-group">
          <label for="repair_prefix">Auftragsnummer-Präfix</label>
          <input type="text" id="repair_prefix" name="repair_prefix" class="form-control" value="<?= h($settings['repair_prefix'] ?: 'MZ') ?>" maxlength="10">
        </div>
        <div class="form-group">
          <label for="warranty_default">Standard-Garantie (Monate)</label>
          <input type="number" id="warranty_default" name="warranty_default" class="form-control" value="<?= h($settings['warranty_default'] ?: '6') ?>" min="0" max="120">
        </div>
        <div class="form-group">
          <label for="default_hourly_rate">Standard-Stundensatz netto (€)</label>
          <input type="text" inputmode="decimal" id="default_hourly_rate" name="default_hourly_rate"
                 class="form-control" value="<?= h($settings['default_hourly_rate']) ?>"
                 placeholder="79,00">
          <small class="form-hint">Wird nur für neue Reparaturen vorbelegt. Bestehende Reparaturen bleiben unverändert.</small>
        </div>
        <div class="form-group">
          <label>Abrechnungsart</label>
          <label style="display:block;margin:.35rem 0;">
            <input type="radio" name="billing_mode" value="<?= BILLING_MODE_SMALL_BUSINESS ?>"
                   <?= $settings['billing_mode'] === BILLING_MODE_SMALL_BUSINESS ? 'checked' : '' ?>>
            Kleinunternehmer gemäß § 19 UStG
          </label>
          <label style="display:block;margin:.35rem 0;">
            <input type="radio" name="billing_mode" value="<?= BILLING_MODE_STANDARD_TAX ?>"
                   <?= $settings['billing_mode'] === BILLING_MODE_STANDARD_TAX ? 'checked' : '' ?>>
            Regelbesteuerung (zukünftige Alternative)
          </label>
          <small class="form-hint">Aktuell muss die Kleinunternehmerregelung aktiv bleiben. In diesem Modus erzwingt das System 0,00 % Steuer und zeigt keine Umsatzsteuer an.</small>
        </div>
        <div class="form-group">
          <label for="tax_rate">Umsatzsteuersatz bei Regelbesteuerung (%)</label>
          <input type="number" id="tax_rate" name="tax_rate" class="form-control" value="<?= h($settings['tax_rate']) ?>" min="0" max="100" step="0.1"
                 <?= $settings['billing_mode'] === BILLING_MODE_SMALL_BUSINESS ? 'readonly' : '' ?>>
        </div>
        <div class="form-group">
          <label for="currency_symbol">Währungssymbol</label>
          <input type="text" id="currency_symbol" name="currency_symbol" class="form-control" value="<?= h($settings['currency_symbol'] ?: '€') ?>" maxlength="5">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label for="ustg_notice_text">Hinweistext § 19 UStG (nur bei MwSt-Satz 0 sichtbar)</label>
          <textarea id="ustg_notice_text" name="ustg_notice_text" class="form-control" rows="2"
                    <?= $settings['billing_mode'] === BILLING_MODE_SMALL_BUSINESS ? 'readonly' : '' ?>><?= h($settings['billing_mode'] === BILLING_MODE_SMALL_BUSINESS ? BILLING_SMALL_BUSINESS_NOTICE : $settings['ustg_notice_text']) ?></textarea>
          <small class="form-hint">Im Kleinunternehmermodus ist der rechtlich vorgesehene Hinweis fest vorgegeben und wird unverändert auf Kundendokumenten gespeichert.</small>
        </div>
      </div>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Firmendaten speichern</button>
      </div>
    </form>
  </div>
</div>

<!-- ── SMTP ── -->
<div class="card" id="smtp" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('mail') ?> SMTP-Einstellungen</h2>
  </div>
  <div class="card-body">
    <p>SMTP-Zugangsdaten werden ausschließlich über den verschlüsselten lokalen Credential-Workflow verwaltet. Auf dieser Seite können weder Kennwörter eingegeben noch Testmails ausgelöst werden.</p>
    <div class="form-grid">
      <div><strong>Konfiguration vollständig:</strong> <?= !empty($smtp_status['configured']) ? 'JA' : 'NEIN' ?></div>
      <div><strong>TLS und Zertifikatsprüfung:</strong> <?= !empty($smtp_status['tls']) ? 'JA' : 'NEIN' ?></div>
      <div><strong>Authentifizierung:</strong> <?= !empty($smtp_status['user_configured']) && !empty($smtp_status['password_configured']) ? 'JA' : 'NEIN' ?></div>
      <div><strong>Absender:</strong> <?= h($smtp_sender_masked) ?></div>
      <div><strong>Portal-Aktivierungsversand:</strong> <?= portal_email_delivery_enabled() ? 'AKTIV' : 'DEAKTIVIERT' ?></div>
    </div>
  </div>
</div>

<!-- ── Terminbuchung ── -->
<div class="card" id="terminbuchung" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('calendar') ?> Öffentliche Terminbuchung</h2>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_booking">

      <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-bottom:20px;">
        <input type="checkbox" id="booking_enabled" name="booking_enabled" value="1" <?= $booking_settings['enabled'] ? 'checked' : '' ?>>
        <label for="booking_enabled" style="margin:0;">Öffentliche Terminbuchung aktiviert</label>
      </div>

      <h3 style="font-size:.95rem;margin:0 0 10px;">Öffnungszeiten für Terminbuchung</h3>
      <div class="table-wrap" style="margin-bottom:20px;">
        <table>
          <thead>
            <tr><th>Wochentag</th><th>Geschlossen</th><th>Von</th><th>Bis</th></tr>
          </thead>
          <tbody>
            <?php foreach ($weekday_labels as $dkey => $dlabel): $dh = $booking_settings['hours'][$dkey] ?? null; ?>
            <tr>
              <td><?= h($dlabel) ?></td>
              <td><input type="checkbox" name="booking_closed_<?= $dkey ?>" <?= $dh === null ? 'checked' : '' ?>></td>
              <td><input type="time" name="booking_open_<?= $dkey ?>"  value="<?= h($dh['open']  ?? '09:00') ?>" class="form-control" style="width:130px;"></td>
              <td><input type="time" name="booking_close_<?= $dkey ?>" value="<?= h($dh['close'] ?? '18:00') ?>" class="form-control" style="width:130px;"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label for="booking_slot_minutes">Zeitfenster-Dauer (Minuten)</label>
          <input type="number" id="booking_slot_minutes" name="booking_slot_minutes" class="form-control" value="<?= h($booking_settings['slot_minutes']) ?>" min="5" step="5">
        </div>
        <div class="form-group">
          <label for="booking_capacity_per_slot">Kapazität je Zeitfenster</label>
          <input type="number" id="booking_capacity_per_slot" name="booking_capacity_per_slot" class="form-control" value="<?= h($booking_settings['capacity_per_slot']) ?>" min="1">
        </div>
        <div class="form-group">
          <label for="booking_lead_hours">Mindestvorlauf (Stunden)</label>
          <input type="number" id="booking_lead_hours" name="booking_lead_hours" class="form-control" value="<?= h($booking_settings['lead_hours']) ?>" min="0">
        </div>
        <div class="form-group">
          <label for="booking_max_days_ahead">Buchbar bis (Tage im Voraus)</label>
          <input type="number" id="booking_max_days_ahead" name="booking_max_days_ahead" class="form-control" value="<?= h($booking_settings['max_days_ahead']) ?>" min="1">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label for="booking_blocked_dates">Gesperrte Tage (Feiertage/Urlaub, ein Datum pro Zeile, Format JJJJ-MM-TT)</label>
          <textarea id="booking_blocked_dates" name="booking_blocked_dates" class="form-control" rows="4" placeholder="2026-12-24&#10;2026-12-25&#10;2026-12-31"><?= h(implode("\n", $booking_settings['blocked_dates'])) ?></textarea>
        </div>
      </div>

      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Terminbuchung speichern</button>
        <a href="<?= url('termin.php') ?>" target="_blank" class="btn btn-outline"><?= svg_icon('link') ?> Buchungsseite ansehen</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Datenschutz ── -->
<div class="card" id="datenschutz" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('lock') ?> Datenschutzhinweis</h2>
  </div>
  <div class="card-body">
    <p class="form-hint" style="margin-top:0;">
      Dieser Text wird auf allen öffentlichen Formularen angezeigt (Terminbuchung,
      Reparaturanfrage, Kundenportal). HTML ist erlaubt (z. B. <code>&lt;p&gt;</code>,
      <code>&lt;a&gt;</code>, <code>&lt;strong&gt;</code>). Platzhalter:
      <code>{{firma}}</code>, <code>{{email}}</code>.
    </p>
    <form method="post" action="<?= url('settings.php') ?>#datenschutz">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_privacy">
      <div class="form-group">
        <label for="privacy_notice_text">Datenschutzhinweis (Text/HTML)</label>
        <textarea id="privacy_notice_text" name="privacy_notice_text" class="form-control" rows="6" style="font-family:monospace;font-size:.85rem;"><?= h(get_setting('privacy_notice_text', '')) ?></textarea>
      </div>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Datenschutzhinweis speichern</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Kundenportal ── -->
<div class="card" id="kundenportal" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('user') ?> Kundenportal</h2>
  </div>
  <div class="card-body">
    <p class="form-hint" style="margin-top:0;">
      Steuert den Zugriff auf das Online-Kundenportal (Reparaturstatus,
      Rechnungen, Terminübersicht). Der Gast-Zugang funktioniert per
      Auftragsnummer+PIN oder per Zugangslink (E-Mail) und benötigt kein
      Kundenkonto. Das Kundenkonto (E-Mail+Passwort) ist ein zusätzliches,
      optionales Angebot – beide Zugangswege funktionieren parallel.
    </p>
    <form method="post" action="<?= url('settings.php') ?>#kundenportal">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_portal">
      <div class="form-group" style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" id="portal_enabled" name="portal_enabled" value="1" <?= portal_enabled() ? 'checked' : '' ?>>
        <label for="portal_enabled" style="margin:0;">Kundenportal aktiviert (Gast-Zugang per Auftragsnummer+PIN oder Zugangslink)</label>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:12px;">
        <input type="checkbox" id="customer_accounts_enabled" name="customer_accounts_enabled" value="1" <?= customer_accounts_enabled() ? 'checked' : '' ?>>
        <label for="customer_accounts_enabled" style="margin:0;">Registrierung von Kundenkonten (E-Mail+Passwort) erlauben</label>
      </div>
      <p class="form-hint">
        Wenn deaktiviert, können sich Kunden weiterhin per Auftragsnummer+PIN
        oder Zugangslink anmelden (Gast-Zugang) – nur die Registrierung neuer
        Kundenkonten wird ausgeblendet. Bereits registrierte Kunden können
        sich weiterhin einloggen.
      </p>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Kundenportal-Einstellungen speichern</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Kalender-Synchronisation ── -->
<div class="card" id="kalender" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('calendar') ?> Kalender-Synchronisation</h2>
  </div>
  <div class="card-body">
    <form method="post" action="<?= url('settings.php') ?>#kalender">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_calendar">

      <h3 style="margin:0 0 8px;font-size:1rem;">Lokaler Kalender-Abo-Feed (.ics)</h3>
      <p class="form-hint" style="margin-top:0;">
        Funktioniert komplett lokal ohne Google-Konto und ohne Kosten. Die
        untenstehende Adresse kann in Google Kalender, Apple Kalender,
        Outlook oder Thunderbird über "Kalender per URL abonnieren"
        hinterlegt werden – neue und geänderte Termine erscheinen dann
        automatisch (Aktualisierung i. d. R. alle 30&nbsp;Minuten,
        abhängig von der jeweiligen Kalender-App).
      </p>
      <div class="form-group" style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" id="ics_feed_enabled" name="ics_feed_enabled" value="1" <?= ics_feed_enabled() ? 'checked' : '' ?>>
        <label for="ics_feed_enabled" style="margin:0;">Lokalen .ics-Abo-Feed aktivieren</label>
      </div>
      <?php if (ics_feed_enabled()): ?>
        <div class="form-group" style="margin-top:12px;">
          <label for="ics_feed_url">Abo-Adresse (geheim – nicht weitergeben)</label>
          <input type="text" id="ics_feed_url" class="form-control" style="font-family:monospace;font-size:.82rem;" readonly onclick="this.select();" value="<?= h(ics_feed_url()) ?>">
        </div>
      <?php endif; ?>

      <hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">

      <h3 style="margin:0 0 8px;font-size:1rem;">Google Kalender (optional)</h3>
      <p class="form-hint" style="margin-top:0;">
        Zusätzlich zum lokalen Feed kann optional eine direkte Synchronisation
        mit einem Google-Kalender aktiviert werden (kostenloses Google-Cloud-
        Projekt mit "Google Calendar API" erforderlich). Ohne Verbindung
        bleibt diese Funktion vollständig inaktiv und beeinflusst die lokale
        Terminverwaltung nicht.
      </p>

      <?php if (get_setting('gcal_refresh_token_encrypted', '') !== ''): ?>
        <div class="alert alert-success" style="margin-bottom:16px;">
          <?= svg_icon('check', 16) ?> Verbunden mit Google-Konto:
          <strong><?= h(get_setting('gcal_connected_account', 'unbekannt')) ?></strong>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" id="gcal_enabled" name="gcal_enabled" value="1" <?= get_setting('gcal_enabled', '0') === '1' ? 'checked' : '' ?>>
          <label for="gcal_enabled" style="margin:0;">Google-Kalender-Synchronisation aktiv</label>
        </div>
      <?php else: ?>
        <p class="form-hint" style="margin-top:0;"><em>Noch nicht mit Google verbunden.</em></p>
      <?php endif; ?>

      <div class="form-group" style="margin-top:12px;">
        <label for="gcal_client_id">Google OAuth Client-ID</label>
        <input type="text" id="gcal_client_id" name="gcal_client_id" class="form-control" value="<?= h(get_setting('gcal_client_id', '')) ?>" placeholder="xxxxxxxxxx.apps.googleusercontent.com">
      </div>
      <div class="form-group">
        <label for="gcal_client_secret">Google OAuth Client-Secret</label>
        <input type="password" id="gcal_client_secret" name="gcal_client_secret" class="form-control" placeholder="<?= gcal_client_secret() !== '' ? '•••••••• (unverändert lassen zum Beibehalten)' : '' ?>" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label for="gcal_calendar_id">Google-Kalender-ID</label>
        <input type="text" id="gcal_calendar_id" name="gcal_calendar_id" class="form-control" value="<?= h(get_setting('gcal_calendar_id', 'primary')) ?>" placeholder="primary">
        <p class="form-hint">In der Regel <code>primary</code> (Hauptkalender des verbundenen Kontos).</p>
      </div>

      <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Kalender-Einstellungen speichern</button>
        <a href="<?= url('gcal_oauth_start.php') ?>" class="btn btn-outline"><?= svg_icon('key', 16) ?> Mit Google verbinden</a>
      </div>
    </form>

    <?php if (get_setting('gcal_refresh_token_encrypted', '') !== ''): ?>
      <form method="post" action="<?= url('settings.php') ?>#kalender" style="margin-top:12px;" onsubmit="return confirm('Verbindung zu Google Kalender wirklich trennen?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="gcal_disconnect">
        <button type="submit" class="btn btn-outline"><?= svg_icon('x', 16) ?> Google-Verbindung trennen</button>
      </form>
    <?php endif; ?>

    <?php if (ics_feed_enabled()): ?>
      <form method="post" action="<?= url('settings.php') ?>#kalender" style="margin-top:12px;" onsubmit="return confirm('Abo-Link wirklich erneuern? Der alte Link funktioniert danach nicht mehr.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regenerate_ics_token">
        <button type="submit" class="btn btn-outline"><?= svg_icon('refresh-cw', 16) ?> .ics-Abo-Link erneuern</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- ── Nummernkreise (Dokumentenmodul) ── -->
<div class="card" id="nummernkreise" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('list') ?> Nummernkreise</h2>
  </div>
  <div class="card-body">
    <p class="form-hint" style="margin-top:0;">
      Steuert Präfix, Trennzeichen, Ziffernanzahl, jährlichen Reset und
      Startnummer je Dokumenttyp. Die laufende Zählung selbst wird hier
      NICHT verändert – eine bereits vergebene Nummer wird nie erneut
      vergeben oder verändert. Nur fett hervorgehobene Zeilen werden vom
      aktuellen Funktionsumfang aktiv genutzt; die übrigen sind für
      künftige Module reserviert. Die Startnummer kann ausschließlich von
      Administratoren geändert werden.
    </p>
    <form method="post" action="<?= url('settings.php') ?>#nummernkreise">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_number_ranges">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Dokumenttyp</th>
              <th>Präfix</th>
              <th>Trennzeichen</th>
              <th>Ziffern</th>
              <th>Jährlicher Reset</th>
              <th>Startnummer</th>
              <th>Aktueller Zähler</th>
              <th>Vorschau</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($number_ranges as $r): ?>
            <tr<?= $r['is_active_module'] ? '' : ' style="opacity:.6;"' ?>>
              <td>
                <strong><?= h($r['label']) ?></strong><br>
                <small style="color:#9CA3AF;"><?= h($r['doc_type']) ?><?= $r['is_active_module'] ? '' : ' · reserviert' ?></small>
              </td>
              <td><input type="text" name="nr[<?= h($r['doc_type']) ?>][prefix]" class="form-control" style="width:90px;" value="<?= h($r['prefix']) ?>" maxlength="10"></td>
              <td><input type="text" name="nr[<?= h($r['doc_type']) ?>][separator]" class="form-control" style="width:60px;" value="<?= h($r['separator']) ?>" maxlength="1"></td>
              <td><input type="number" name="nr[<?= h($r['doc_type']) ?>][digits]" class="form-control" style="width:70px;" value="<?= (int)$r['digits'] ?>" min="1" max="10"></td>
              <td style="text-align:center;"><input type="checkbox" name="nr[<?= h($r['doc_type']) ?>][yearly_reset]" <?= $r['yearly_reset'] ? 'checked' : '' ?>></td>
              <td><input type="number" name="nr[<?= h($r['doc_type']) ?>][start_number]" class="form-control" style="width:100px;" value="<?= (int)$r['start_number'] ?>" min="1"></td>
              <td style="color:#9CA3AF;"><?= $r['current_number'] ? (int)$r['current_number'] . ($r['current_year'] ? ' (' . (int)$r['current_year'] . ')' : '') : '<em>noch keine vergeben</em>' ?></td>
              <td style="font-family:monospace;"><?= h($r['preview']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary" onclick="return confirm('Änderungen an Nummernkreisen (insbesondere der Startnummer) können bei falscher Anpassung zu Kollisionen mit bereits vergebenen Nummern führen. Wirklich speichern?');"><?= svg_icon('check') ?> Nummernkreise speichern</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Rechte-Matrix ── -->
<div class="card" id="rechte" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('lock') ?> Rollen &amp; Rechte</h2>
  </div>
  <div class="card-body">
    <p class="form-hint" style="margin-top:0;">
      Die Rolle "Administrator" besitzt immer und unabhängig von dieser
      Matrix sämtliche Berechtigungen. Für die übrigen Rollen wird hier
      granular festgelegt, welche Aktionen erlaubt sind.
    </p>
    <form method="post" action="<?= url('settings.php') ?>#rechte">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_permissions">
      <?php foreach ($perm_by_category as $category => $perms): ?>
        <h3 style="font-size:.95rem;margin:20px 0 8px;"><?= h($category) ?></h3>
        <div class="table-wrap" style="margin-bottom:8px;">
          <table>
            <thead>
              <tr>
                <th>Berechtigung</th>
                <?php foreach ($editable_roles as $role): ?>
                  <th style="text-align:center;"><?= h(role_label($role)) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($perms as $p): ?>
              <tr>
                <td><?= h($p['label']) ?></td>
                <?php foreach ($editable_roles as $role): ?>
                  <td style="text-align:center;">
                    <input type="checkbox"
                      name="perm[<?= h($role) ?>][<?= h($p['perm_key']) ?>]"
                      <?= !empty($perm_matrix[$p['perm_key']][$role]) ? 'checked' : '' ?>>
                  </td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Berechtigungen speichern</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Benutzerverwaltung ── -->
<div class="card" id="benutzer">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('users') ?> Benutzerverwaltung</h2>
    <button class="btn btn-primary" onclick="document.getElementById('modal-new-user').style.display='flex'">
      <?= svg_icon('plus') ?> Neuen Benutzer anlegen
    </button>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($users)): ?>
      <div class="empty-state"><?= svg_icon('users') ?><p>Keine Benutzer gefunden.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Benutzername</th>
              <th>Name</th>
              <th>E-Mail</th>
              <th>Rolle</th>
              <th>Status</th>
              <th>Letzter Login</th>
              <th>Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <?php $is_self = ((int)$u['id'] === (int)$_SESSION['user_id']); ?>
            <tr>
              <td style="font-weight:600;"><?= h($u['username']) ?> <?= $is_self ? '<span class="badge badge-blue">Ich</span>' : '' ?></td>
              <td><?= h($u['full_name']) ?></td>
              <td><?= h($u['email']) ?></td>
              <td><span class="badge badge-gray"><?= h(role_label($u['role'])) ?></span></td>
              <td>
                <?php if ($u['is_active']): ?>
                  <span class="badge badge-green">Aktiv</span>
                <?php else: ?>
                  <span class="badge badge-red">Inaktiv</span>
                <?php endif; ?>
              </td>
              <td><?= $u['last_login'] ? fmt_date($u['last_login'], true) : '<span style="color:#9CA3AF;">Nie</span>' ?></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap;">
                <!-- Bearbeiten-Button -->
                <button class="btn btn-sm btn-outline"
                  onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                  <?= svg_icon('edit') ?> Bearbeiten
                </button>
                <!-- Aktivieren/Deaktivieren -->
                <?php if (!$is_self): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Benutzer wirklich <?= $u['is_active'] ? 'deaktivieren' : 'aktivieren' ?>?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action"   value="toggle_user">
                  <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="activate" value="<?= $u['is_active'] ? '0' : '1' ?>">
                  <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-outline' ?>">
                    <?= $u['is_active'] ? svg_icon('x') . ' Deaktivieren' : svg_icon('check') . ' Aktivieren' ?>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Modal: Neuen Benutzer anlegen ── -->
<div id="modal-new-user" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;">
  <div class="card" style="width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;margin:0;">
    <div class="card-header">
      <h2 class="card-title">Neuen Benutzer anlegen</h2>
      <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-new-user').style.display='none'">✕</button>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_user">
        <div class="form-grid">
          <div class="form-group">
            <label for="nu_full_name">Vollständiger Name *</label>
            <input type="text" id="nu_full_name" name="full_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="nu_username">Benutzername *</label>
            <input type="text" id="nu_username" name="username" class="form-control" required autocomplete="off">
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label for="nu_email">E-Mail *</label>
            <input type="email" id="nu_email" name="email" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="nu_password">Passwort *</label>
            <input type="password" id="nu_password" name="password" class="form-control" required autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="nu_password2">Passwort bestätigen *</label>
            <input type="password" id="nu_password2" name="password2" class="form-control" required autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="nu_role">Rolle</label>
            <select id="nu_role" name="role" class="form-control">
              <option value="techniker">Techniker</option>
              <option value="empfang">Empfang</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px;">
            <input type="checkbox" id="nu_is_active" name="is_active" value="1" checked>
            <label for="nu_is_active" style="margin:0;">Benutzer aktiv</label>
          </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-new-user').style.display='none'">Abbrechen</button>
          <button type="submit" class="btn btn-primary"><?= svg_icon('plus') ?> Anlegen</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Benutzer bearbeiten ── -->
<div id="modal-edit-user" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;">
  <div class="card" style="width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;margin:0;">
    <div class="card-header">
      <h2 class="card-title">Benutzer bearbeiten</h2>
      <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-user').style.display='none'">✕</button>
    </div>
    <div class="card-body">
      <form method="post" id="form-edit-user">
        <?= csrf_field() ?>
        <input type="hidden" name="action"  value="edit_user">
        <input type="hidden" name="user_id" id="eu_id">
        <div class="form-grid">
          <div class="form-group">
            <label for="eu_full_name">Vollständiger Name *</label>
            <input type="text" id="eu_full_name" name="full_name" class="form-control" required>
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label for="eu_email">E-Mail *</label>
            <input type="email" id="eu_email" name="email" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="eu_password">Neues Passwort</label>
            <input type="password" id="eu_password" name="password" class="form-control" placeholder="Leer = unverändert" autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="eu_password2">Passwort bestätigen</label>
            <input type="password" id="eu_password2" name="password2" class="form-control" autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="eu_role">Rolle</label>
            <select id="eu_role" name="role" class="form-control">
              <option value="techniker">Techniker</option>
              <option value="empfang">Empfang</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px;">
            <input type="checkbox" id="eu_is_active" name="is_active" value="1">
            <label for="eu_is_active" style="margin:0;">Benutzer aktiv</label>
          </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-user').style.display='none'">Abbrechen</button>
          <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditUserModal(user) {
  document.getElementById('eu_id').value        = user.id;
  document.getElementById('eu_full_name').value = user.full_name;
  document.getElementById('eu_email').value     = user.email;
  document.getElementById('eu_role').value      = user.role;
  document.getElementById('eu_is_active').checked = user.is_active == 1;
  document.getElementById('eu_password').value  = '';
  document.getElementById('eu_password2').value = '';
  document.getElementById('modal-edit-user').style.display = 'flex';
}

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.getElementById('modal-new-user').style.display  = 'none';
    document.getElementById('modal-edit-user').style.display = 'none';
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
