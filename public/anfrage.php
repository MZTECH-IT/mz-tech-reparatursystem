<?php
/**
 * MZ Tech – Öffentliche Reparaturanfrage (kein Login erforderlich, kein Termin)
 *
 * Analog zu termin.php, aber ohne Terminwahl: Der Kunde beschreibt sein
 * Anliegen, MZ Tech meldet sich anschließend zur Terminvereinbarung / mit
 * einem Angebot. Anfragen landen zur Prüfung in repair_requests (siehe
 * public/repair_requests.php für die Admin-Ansicht).
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
$device_types = device_type_options();
$requests_enabled = repair_request_enabled();

$errors  = [];
$success = false;
$request_number = '';

$fields = [
    'device_type'         => '',
    'manufacturer'        => '',
    'model'               => '',
    'problem_description' => '',
    'first_name'          => '',
    'last_name'           => '',
    'email'               => '',
    'phone'               => '',
    'company'             => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültige Anfrage (Sicherheitstoken abgelaufen). Bitte Formular erneut absenden.';
    } elseif (!$requests_enabled) {
        $errors[] = 'Die Online-Reparaturanfrage ist derzeit leider nicht verfügbar. Bitte kontaktieren Sie uns direkt.';
    } else {
        foreach (array_keys($fields) as $f) {
            $fields[$f] = trim($_POST[$f] ?? '');
        }
        $privacy_consent   = isset($_POST['privacy_consent']) ? 1 : 0;
        $marketing_consent = isset($_POST['marketing_consent']) ? 1 : 0;

        if ($fields['device_type'] === '' || !array_key_exists($fields['device_type'], $device_types)) {
            $errors[] = 'Bitte eine gültige Geräteart auswählen.';
        }
        if ($fields['problem_description'] === '') {
            $errors[] = 'Bitte beschreiben Sie kurz Ihr Anliegen.';
        }
        if ($fields['first_name'] === '' || $fields['last_name'] === '') {
            $errors[] = 'Bitte Vor- und Nachname angeben.';
        }
        if ($fields['email'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if (!$privacy_consent) {
            $errors[] = 'Bitte bestätigen Sie die Datenschutzhinweise, um fortzufahren.';
        }

        // Optionale Fotos validieren (Endung, Größe, echtes Bild, max. Anzahl)
        $uploaded_photos = normalize_multi_upload('photos');
        if (count($uploaded_photos) > MAX_PHOTOS_PER_UPLOAD) {
            $errors[] = 'Sie können maximal ' . MAX_PHOTOS_PER_UPLOAD . ' Fotos gleichzeitig hochladen.';
        } else {
            foreach ($uploaded_photos as $pf) {
                $perr = validate_photo_upload($pf);
                if ($perr !== null) $errors[] = $perr;
            }
        }

        if (empty($errors)) {
            $request_number = repair_request_generate_number();
            $stmt = $db->prepare(
                'INSERT INTO repair_requests
                    (request_number, status, device_type, manufacturer, model, problem_description,
                     first_name, last_name, email, phone, company,
                     privacy_consent, marketing_consent, ip_address, created_at, updated_at)
                 VALUES (?, "neu", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([
                $request_number,
                $fields['device_type'],
                $fields['manufacturer'] ?: null,
                $fields['model']        ?: null,
                $fields['problem_description'],
                $fields['first_name'],
                $fields['last_name'],
                $fields['email'],
                $fields['phone']   ?: null,
                $fields['company'] ?: null,
                $privacy_consent,
                $marketing_consent,
                get_client_ip(),
            ]);
            $new_id = (int)$db->lastInsertId();

            // Optionale Fotos speichern (bereits oben validiert)
            if (!empty($uploaded_photos)) {
                $photo_stmt = $db->prepare(
                    'INSERT INTO repair_request_photos (request_id, filename, original_name, file_size, created_at)
                     VALUES (?, ?, ?, ?, NOW())'
                );
                foreach ($uploaded_photos as $pf) {
                    $result = save_request_photo($pf, $new_id);
                    if ($result !== false) {
                        $photo_stmt->execute([$new_id, $result['filename'], $result['original_name'], $result['file_size']]);
                    }
                }
            }

            try {
                send_repair_request_email([
                    'first_name'     => $fields['first_name'],
                    'last_name'      => $fields['last_name'],
                    'email'          => $fields['email'],
                    'request_number' => $request_number,
                    'device_type'    => $fields['device_type'],
                    'manufacturer'   => $fields['manufacturer'],
                    'model'          => $fields['model'],
                ], 'neu');
            } catch (Throwable $e) {
                error_log('anfrage.php: Bestätigungsmail fehlgeschlagen: ' . $e->getMessage());
            }

            log_activity('repair_request_created', 'repair_requests', $new_id, 'Öffentliche Reparaturanfrage: ' . $request_number);

            $success = true;
        }
    }
}

$csrf = csrf_token();
$company_name = get_setting('company_name', 'MZ Tech');
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reparatur anfragen – <?= h($company_name) ?></title>
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
    <div class="sub">Reparatur anfragen – schnell &amp; unverbindlich</div>
  </div>

  <?php if ($success): ?>
    <div class="card">
      <div class="card-body success-box">
        <?= svg_icon('check', 48) ?>
        <h2 style="margin:16px 0 4px;">Vielen Dank für Ihre Reparaturanfrage!</h2>
        <p style="color:#6B7280;">Ihre Anfragenummer lautet:</p>
        <div class="num"><?= h($request_number) ?></div>
        <p style="color:#6B7280;max-width:440px;margin:0 auto;">
          Wir prüfen Ihre Anfrage und melden uns in Kürze per E-Mail
          an <strong><?= h($fields['email']) ?></strong> bei Ihnen – z. B. mit einem Terminvorschlag
          oder einer ersten Einschätzung.
        </p>
        <p style="margin-top:24px;">
          <a href="<?= url('anfrage.php') ?>" class="btn btn-outline">Weitere Anfrage stellen</a>
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

    <?php if (!$requests_enabled): ?>
      <div class="alert alert-warning">
        Die Online-Reparaturanfrage ist derzeit leider nicht verfügbar. Bitte kontaktieren Sie uns telefonisch oder per E-Mail.
      </div>
    <?php else: ?>

    <div class="card">
      <div class="card-body">
        <form method="post" action="<?= url('anfrage.php') ?>" enctype="multipart/form-data" novalidate>
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
                <label for="problem_description">Was ist das Problem? <span class="req">*</span></label>
                <textarea id="problem_description" name="problem_description" rows="4" required placeholder="Bitte kurz beschreiben …"><?= h($fields['problem_description']) ?></textarea>
              </div>
              <div class="form-group full">
                <label for="photos"><?= svg_icon('image', 16) ?> Fotos (optional)</label>
                <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                <small style="color:#6B7280;">Bis zu <?= (int)MAX_PHOTOS_PER_UPLOAD ?> Fotos, je max. 10 MB (JPG, PNG, GIF oder WebP). Hilft uns, das Problem besser einzuschätzen.</small>
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
            <legend>Datenschutz</legend>
            <div class="privacy-box"><?= render_privacy_notice() ?></div>
            <div class="form-group" style="display:flex;align-items:flex-start;gap:8px;">
              <input type="checkbox" id="privacy_consent" name="privacy_consent" value="1" required style="margin-top:3px;">
              <label for="privacy_consent" style="margin:0;font-weight:400;">
                Ich habe die Datenschutzhinweise zur Kenntnis genommen und bin mit der Verarbeitung
                meiner Daten zur Bearbeitung dieser Reparaturanfrage einverstanden. <span class="req">*</span>
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
            <button type="submit" class="btn btn-primary btn-lg"><?= svg_icon('tool', 18) ?> Reparatur unverbindlich anfragen</button>
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
</body>
</html>
