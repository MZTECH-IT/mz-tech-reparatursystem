<?php
/**
 * MZ Tech – Öffentliche Terminbuchung (kein Login erforderlich)
 */

$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
require_once $private . '/mailer.php';
require_once __DIR__ . '/includes/icons.php';

start_secure_session();

$db = get_db();
$booking_settings = booking_get_settings();
$device_types = device_type_options();

$errors  = [];
$success = false;
$booking_number = '';

$fields = [
    'device_type'       => '',
    'manufacturer'      => '',
    'model'             => '',
    'issue_description' => '',
    'first_name'        => '',
    'last_name'         => '',
    'email'             => '',
    'phone'             => '',
    'company'           => '',
    'preferred_date'    => '',
    'preferred_time'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte Formular erneut absenden.';
    } elseif (!$booking_settings['enabled']) {
        $errors[] = 'Die Online-Terminbuchung ist derzeit leider nicht verfügbar. Bitte kontaktieren Sie uns direkt.';
    } else {
        foreach (array_keys($fields) as $f) {
            $fields[$f] = trim($_POST[$f] ?? '');
        }
        $privacy_consent   = isset($_POST['privacy_consent']) ? 1 : 0;
        $marketing_consent = isset($_POST['marketing_consent']) ? 1 : 0;

        if ($fields['device_type'] === '' || !array_key_exists($fields['device_type'], $device_types)) {
            $errors[] = 'Bitte eine gültige Geräteart auswählen.';
        }
        if ($fields['issue_description'] === '') {
            $errors[] = 'Bitte beschreiben Sie kurz Ihr Anliegen.';
        }
        if ($fields['first_name'] === '' || $fields['last_name'] === '') {
            $errors[] = 'Bitte Vor- und Nachname angeben.';
        }
        if ($fields['email'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if ($fields['preferred_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['preferred_date'])) {
            $errors[] = 'Bitte ein gültiges Wunschdatum auswählen.';
        }
        if ($fields['preferred_time'] === '' || !preg_match('/^\d{2}:\d{2}$/', $fields['preferred_time'])) {
            $errors[] = 'Bitte eine Uhrzeit auswählen.';
        }
        if (!$privacy_consent) {
            $errors[] = 'Bitte bestätigen Sie die Datenschutzhinweise, um fortzufahren.';
        }

        // Verfügbarkeit erneut serverseitig prüfen (Schutz vor Doppelbuchung/Race Conditions)
        if (empty($errors)) {
            if (!booking_date_is_bookable($fields['preferred_date'])) {
                $errors[] = 'Das gewählte Datum ist leider nicht mehr buchbar. Bitte wählen Sie ein anderes Datum.';
            } else {
                $available = booking_available_slots($fields['preferred_date']);
                if (!in_array($fields['preferred_time'], $available, true)) {
                    $errors[] = 'Das gewählte Zeitfenster ist leider nicht mehr verfügbar. Bitte wählen Sie eine andere Uhrzeit.';
                }
            }
        }

        if (empty($errors)) {
            $booking_number = booking_generate_number();
            $stmt = $db->prepare(
                'INSERT INTO booking_requests
                    (booking_number, status, device_type, manufacturer, model, issue_description,
                     first_name, last_name, email, phone, company,
                     preferred_date, preferred_time, privacy_consent, marketing_consent,
                     ip_address, created_at, updated_at)
                 VALUES (?, "angefragt", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([
                $booking_number,
                $fields['device_type'],
                $fields['manufacturer']      ?: null,
                $fields['model']             ?: null,
                $fields['issue_description'],
                $fields['first_name'],
                $fields['last_name'],
                $fields['email'],
                $fields['phone']             ?: null,
                $fields['company']           ?: null,
                $fields['preferred_date'],
                $fields['preferred_time'],
                $privacy_consent,
                $marketing_consent,
                get_client_ip(),
            ]);
            $new_id = (int)$db->lastInsertId();

            try {
                send_booking_status_email([
                    'first_name'     => $fields['first_name'],
                    'last_name'      => $fields['last_name'],
                    'email'          => $fields['email'],
                    'booking_number' => $booking_number,
                    'device_type'    => $fields['device_type'],
                    'manufacturer'   => $fields['manufacturer'],
                    'model'          => $fields['model'],
                    'preferred_date' => $fields['preferred_date'],
                    'preferred_time' => $fields['preferred_time'],
                ], 'angefragt');
            } catch (Throwable $e) {
                error_log('termin.php: Bestätigungsmail fehlgeschlagen: ' . $e->getMessage());
            }

            log_activity('booking_created', 'booking_requests', $new_id, 'Öffentliche Terminanfrage: ' . $booking_number);

            $success = true;
        }
    }
}

$csrf = csrf_token();
$company_name = get_setting('company_name', 'MZ Tech');
$today = date('Y-m-d');
$max_date = date('Y-m-d', strtotime('+' . $booking_settings['max_days_ahead'] . ' days'));
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Termin vereinbaren – <?= h($company_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
  <script>window.APP_URL_BASE = <?= json_encode(APP_URL_BASE, JSON_UNESCAPED_SLASHES) ?>;</script>
  <style>
    body { background: var(--bg-body, #f5f7fa); }
    .public-page { max-width: 720px; margin: 0 auto; padding: 32px 20px 60px; }
    .public-header { text-align:center; margin-bottom: 28px; }
    .public-header .brand { font-size:1.6rem; font-weight:800; color:#0057B8; letter-spacing:-.02em; }
    .public-header .sub { color:#6B7280; font-size:.9rem; margin-top:6px; }
    .success-box { text-align:center; padding: 40px 20px; }
    .success-box .num { font-size:1.8rem; font-weight:800; color:#0057B8; margin: 12px 0; letter-spacing:.03em; }
    .req { color:#DC2626; }
    fieldset.form-section { border:1px solid var(--border-color,#e5e7eb); border-radius:8px; padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
    fieldset.form-section legend { font-weight:600; padding:0 .5rem; font-size:.95rem; }
    .privacy-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:14px 16px; font-size:.82rem; color:#4B5563; margin-bottom:16px; max-height:160px; overflow-y:auto; }
  </style>
</head>
<body>
<div class="public-page">

  <div class="public-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 60 60" style="margin:0 auto 10px;">
      <rect width="60" height="60" rx="14" fill="#0057B8"/>
      <text x="30" y="42" font-family="Arial" font-weight="bold" font-size="28" fill="white" text-anchor="middle">MZ</text>
    </svg>
    <div class="brand"><?= h($company_name) ?></div>
    <div class="sub">Termin vereinbaren – schnell &amp; unverbindlich</div>
  </div>

  <?php if ($success): ?>
    <div class="card">
      <div class="card-body success-box">
        <?= svg_icon('check', 48) ?>
        <h2 style="margin:16px 0 4px;">Vielen Dank für Ihre Terminanfrage!</h2>
        <p style="color:#6B7280;">Ihre Anfragenummer lautet:</p>
        <div class="num"><?= h($booking_number) ?></div>
        <p style="color:#6B7280;max-width:440px;margin:0 auto;">
          Wir prüfen die Verfügbarkeit und senden Ihnen in Kürze eine Bestätigung per E-Mail
          an <strong><?= h($fields['email']) ?></strong>.
        </p>
        <p style="margin-top:24px;">
          <a href="<?= url('termin.php') ?>" class="btn btn-outline">Weiteren Termin anfragen</a>
        </p>
      </div>
    </div>
  <?php else: ?>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul style="margin:0;padding-left:1.25rem;">
          <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!$booking_settings['enabled']): ?>
      <div class="alert alert-warning">
        Die Online-Terminbuchung ist derzeit leider nicht verfügbar. Bitte kontaktieren Sie uns telefonisch oder per E-Mail.
      </div>
    <?php else: ?>

    <div class="card">
      <div class="card-body">
        <form method="post" action="<?= url('termin.php') ?>" novalidate id="booking-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

          <fieldset class="form-section">
            <legend>Gerät &amp; Anliegen</legend>
            <div class="form-grid">
              <div class="form-group">
                <label for="device_type">Geräteart <span class="req">*</span></label>
                <select id="device_type" name="device_type" required>
                  <option value="">– bitte wählen –</option>
                  <?php foreach ($device_types as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $fields['device_type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="manufacturer">Hersteller</label>
                <input type="text" id="manufacturer" name="manufacturer" value="<?= h($fields['manufacturer']) ?>" placeholder="z. B. Samsung, Apple …">
              </div>
              <div class="form-group">
                <label for="model">Modell</label>
                <input type="text" id="model" name="model" value="<?= h($fields['model']) ?>" placeholder="z. B. Galaxy S24, iPhone 15 …">
              </div>
              <div class="form-group full">
                <label for="issue_description">Was ist das Problem? <span class="req">*</span></label>
                <textarea id="issue_description" name="issue_description" rows="4" required placeholder="Bitte kurz beschreiben …"><?= h($fields['issue_description']) ?></textarea>
              </div>
            </div>
          </fieldset>

          <fieldset class="form-section">
            <legend>Ihre Kontaktdaten</legend>
            <div class="form-grid">
              <div class="form-group">
                <label for="first_name">Vorname <span class="req">*</span></label>
                <input type="text" id="first_name" name="first_name" value="<?= h($fields['first_name']) ?>" required>
              </div>
              <div class="form-group">
                <label for="last_name">Nachname <span class="req">*</span></label>
                <input type="text" id="last_name" name="last_name" value="<?= h($fields['last_name']) ?>" required>
              </div>
              <div class="form-group">
                <label for="email">E-Mail <span class="req">*</span></label>
                <input type="email" id="email" name="email" value="<?= h($fields['email']) ?>" required>
              </div>
              <div class="form-group">
                <label for="phone">Telefon</label>
                <input type="tel" id="phone" name="phone" value="<?= h($fields['phone']) ?>">
              </div>
              <div class="form-group full">
                <label for="company">Firma (optional, für Firmen-IT-Anfragen)</label>
                <input type="text" id="company" name="company" value="<?= h($fields['company']) ?>">
              </div>
            </div>
          </fieldset>

          <fieldset class="form-section">
            <legend>Wunschtermin</legend>
            <div class="form-grid">
              <div class="form-group">
                <label for="preferred_date">Datum <span class="req">*</span></label>
                <input type="date" id="preferred_date" name="preferred_date"
                       value="<?= h($fields['preferred_date']) ?>"
                       min="<?= h($today) ?>" max="<?= h($max_date) ?>" required>
              </div>
              <div class="form-group">
                <label for="preferred_time">Uhrzeit <span class="req">*</span></label>
                <select id="preferred_time" name="preferred_time" required>
                  <option value="">– zuerst Datum wählen –</option>
                </select>
                <span class="form-hint" id="slots-hint"></span>
              </div>
            </div>
          </fieldset>

          <fieldset class="form-section">
            <legend>Datenschutz</legend>
            <div class="privacy-box"><?= render_privacy_notice() ?></div>
            <div class="form-group" style="display:flex;align-items:flex-start;gap:8px;">
              <input type="checkbox" id="privacy_consent" name="privacy_consent" value="1" required style="margin-top:3px;">
              <label for="privacy_consent" style="margin:0;font-weight:400;">
                Ich habe die Datenschutzhinweise zur Kenntnis genommen und bin mit der Verarbeitung
                meiner Daten zur Bearbeitung dieser Terminanfrage einverstanden. <span class="req">*</span>
              </label>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-start;gap:8px;">
              <input type="checkbox" id="marketing_consent" name="marketing_consent" value="1" style="margin-top:3px;">
              <label for="marketing_consent" style="margin:0;font-weight:400;">
                Ich möchte gelegentlich über Angebote und Neuigkeiten von <?= h($company_name) ?> informiert werden (optional).
              </label>
            </div>
          </fieldset>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg"><?= svg_icon('calendar', 18) ?> Termin unverbindlich anfragen</button>
          </div>
        </form>
      </div>
    </div>

    <?php endif; ?>
  <?php endif; ?>

  <p style="text-align:center;margin-top:28px;font-size:.78rem;color:#9CA3AF;">
    &copy; <?= date('Y') ?> <?= h($company_name) ?>
  </p>
</div>

<script>
(function() {
  const dateInput = document.getElementById('preferred_date');
  const timeSelect = document.getElementById('preferred_time');
  const hint = document.getElementById('slots-hint');
  if (!dateInput || !timeSelect) return;

  const presetTime = <?= json_encode($fields['preferred_time']) ?>;

  async function loadSlots() {
    const date = dateInput.value;
    timeSelect.innerHTML = '';
    if (!date) {
      timeSelect.innerHTML = '<option value="">– zuerst Datum wählen –</option>';
      return;
    }
    timeSelect.innerHTML = '<option value="">Lade verfügbare Zeiten …</option>';
    hint.textContent = '';
    try {
      const res = await fetch((window.APP_URL_BASE || '') + '/api/public_slots.php?date=' + encodeURIComponent(date));
      const data = await res.json();
      timeSelect.innerHTML = '';
      if (!data.success || !data.slots || !data.slots.length) {
        timeSelect.innerHTML = '<option value="">Keine freien Termine an diesem Tag</option>';
        hint.textContent = 'Bitte wählen Sie ein anderes Datum.';
        return;
      }
      const opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = '– bitte wählen –';
      timeSelect.appendChild(opt0);
      data.slots.forEach(function(slot) {
        const opt = document.createElement('option');
        opt.value = slot;
        opt.textContent = slot + ' Uhr';
        if (slot === presetTime) opt.selected = true;
        timeSelect.appendChild(opt);
      });
    } catch (e) {
      timeSelect.innerHTML = '<option value="">Fehler beim Laden</option>';
    }
  }

  dateInput.addEventListener('change', loadSlots);
  if (dateInput.value) loadSlots();
})();
</script>
</body>
</html>
