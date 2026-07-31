<?php
require_once __DIR__ . '/init.php';

$db = get_db();
$id      = (int)($_GET['id']          ?? 0);
$is_edit = $id > 0;

// ── Reparatur laden (Edit-Modus) ─────────────────────────────────────────────
$repair = null;
if ($is_edit) {
    $stmt = $db->prepare('SELECT r.*, c.first_name, c.last_name FROM repairs r LEFT JOIN customers c ON c.id = r.customer_id WHERE r.id = ?');
    $stmt->execute([$id]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$repair) {
        flash('error', 'Reparatur nicht gefunden.');
        header('Location: repairs.php');
        exit;
    }
}

// Vorausgewählter Kunde (aus customer_id-Parameter)
$preset_customer_id = (int)($_GET['customer_id'] ?? 0);
$source_ticket_id = !$is_edit ? (int)($_GET['ticket_id'] ?? ($_POST['source_ticket_id'] ?? 0)) : 0;
$source_ticket = null;
if ($source_ticket_id > 0) {
    $source_ticket = ticket_find($source_ticket_id);
    if ($source_ticket && !empty($source_ticket['customer_id'])) {
        $preset_customer_id = (int)$source_ticket['customer_id'];
    }
}
$preset_customer    = null;
if (!$is_edit && $preset_customer_id > 0) {
    $cs = $db->prepare('SELECT id, first_name, last_name FROM customers WHERE id = ?');
    $cs->execute([$preset_customer_id]);
    $preset_customer = $cs->fetch();
}

// ── Felder / Defaults ────────────────────────────────────────────────────────
$device_types   = device_type_records(false);
$problem_types  = ['Display','Akku','Ladeport','Kamera','Wasserschaden','Software','Gehäuse','Tastatur','Sonstiges'];
$valid_statuses = repair_valid_statuses();

$fields = [
    'customer_id'         => $repair['customer_id']         ?? $preset_customer_id ?: '',
    'device_type'         => $repair['device_type']         ?? '',
    'device_manufacturer' => $repair['manufacturer']        ?? '',
    'device_model'        => $repair['model']               ?? '',
    'device_color'        => $repair['color']                ?? '',
    'imei'                => $repair['imei']                ?? '',
    'serial_number'       => $repair['serial_number']       ?? '',
    'problem_description' => $repair['problem_description'] ?? ($source_ticket['description'] ?? ''),
    'problem_type'        => $repair['problem_type']        ?? '',
    'technician_id'       => $repair['technician_id']       ?? '',
    'price'               => $repair['price']               ?? '',
    'advance_payment'     => $repair['advance_payment']     ?? '',
    'status'              => repair_status_normalize($repair['status'] ?? 'angenommen'),
    'internal_notes'      => $repair['internal_notes']      ?? '',
    'working_hours'       => $repair['working_hours']       ?? '0.00',
    'hourly_rate'         => $repair['hourly_rate']         ?? get_setting('default_hourly_rate', '79.00'),
    'labor_cost'          => $repair['labor_cost']          ?? '0.00',
    'performed_work'      => $repair['performed_work']      ?? '',
    'device_passcode'     => '',   // nie vorbelegt aus DB
    'estimated_ready'     => $repair['estimated_ready']     ?? '',
    'warranty_months'     => $repair['warranty_months']     ?? '0',
    'project_id'          => $repair['project_id']          ?? '',
];

$errors = [];

// ── Techniker-Liste ──────────────────────────────────────────────────────────
// Defensiv gegen Schema-Abweichungen (z. B. fehlende Spalte is_active bei
// älteren/abweichenden Installationen) abgesichert, damit diese Seite niemals
// mit einem 500-Fehler abbricht, sondern im Zweifel auf eine einfachere
// Abfrage zurückfällt.
try {
    $techs_stmt = $db->query('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name');
    $technicians = $techs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('repairs_form.php: Techniker-Abfrage mit is_active fehlgeschlagen, Fallback ohne Filter: ' . $e->getMessage());
    try {
        $techs_stmt = $db->query('SELECT id, full_name FROM users ORDER BY full_name');
        $technicians = $techs_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        error_log('repairs_form.php: Techniker-Abfrage vollständig fehlgeschlagen: ' . $e2->getMessage());
        $technicians = [];
    }
}

// ── POST-Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $fields['customer_id']         = (int)trim($_POST['customer_id']         ?? 0);
    $fields['device_type']         = trim($_POST['device_type']         ?? '');
    $fields['device_manufacturer'] = trim($_POST['device_manufacturer'] ?? '');
    $fields['device_model']        = trim($_POST['device_model']        ?? '');
    $fields['device_color']        = trim($_POST['device_color']        ?? '');
    $fields['imei']                = trim($_POST['imei']                ?? '');
    $fields['serial_number']       = trim($_POST['serial_number']       ?? '');
    $fields['problem_description'] = trim($_POST['problem_description'] ?? '');
    $fields['problem_type']        = trim($_POST['problem_type']        ?? '');
    $fields['technician_id']       = (int)trim($_POST['technician_id']  ?? 0) ?: null;
    $fields['price']               = trim($_POST['price']               ?? '');
    $fields['advance_payment']     = trim($_POST['advance_payment']     ?? '');
    $fields['status']              = repair_status_normalize(trim($_POST['status']              ?? 'angenommen'));
    $fields['internal_notes']      = trim($_POST['internal_notes']      ?? '');
    $fields['working_hours']       = trim($_POST['working_hours']       ?? '');
    $fields['hourly_rate']         = trim($_POST['hourly_rate']         ?? '');
    // labor_cost aus dem Browser ist nur Anzeige und wird nie übernommen.
    $fields['labor_cost']          = '0.00';
    $fields['performed_work']      = trim($_POST['performed_work']      ?? '');
    $fields['device_passcode']     = trim($_POST['device_passcode']     ?? '');
    $fields['estimated_ready']     = trim($_POST['estimated_ready']     ?? '') ?: null;
    $fields['warranty_months']     = (int)trim($_POST['warranty_months'] ?? 0);
    $fields['project_id']          = (int)trim($_POST['project_id']     ?? 0) ?: null;

    // Validierung
    if (!$fields['customer_id']) {
        $errors['customer_id'] = 'Bitte einen Kunden auswählen.';
    }
    if ($fields['device_type'] === '') {
        $errors['device_type'] = 'Geräteart ist ein Pflichtfeld.';
    }
    if ($fields['problem_description'] === '') {
        $errors['problem_description'] = 'Fehlerbeschreibung ist ein Pflichtfeld.';
    }
    if ($fields['status'] !== '' && !in_array($fields['status'], $valid_statuses, true)) {
        $errors['status'] = 'Ungültiger Status.';
    }
    try {
        $labor = repair_labor_values($fields['working_hours'], $fields['hourly_rate']);
        $fields = array_replace($fields, $labor);
    } catch (InvalidArgumentException $e) {
        $errors['labor'] = $e->getMessage();
    }

    if (empty($errors)) {
        $now   = date('Y-m-d H:i:s');
        $price = $fields['price']           !== '' ? (float)str_replace(',', '.', $fields['price'])           : null;
        $adv   = $fields['advance_payment'] !== '' ? (float)str_replace(',', '.', $fields['advance_payment']) : 0.0;
        $deviceTypeKey = device_type_key_for_value($fields['device_type']);
        $deviceTypeId = device_type_id_for_value($deviceTypeKey);

        // Passcode
        $passcode_encrypted = $is_edit ? ($repair['passcode_encrypted'] ?? null) : null;
        $passcode_iv        = $is_edit ? ($repair['passcode_iv']        ?? null) : null;
        if ($fields['device_passcode'] !== '') {
            $enc = encrypt_passcode($fields['device_passcode']);
            $passcode_encrypted = $enc['encrypted'];
            $passcode_iv        = $enc['iv'];
        }

        // completed_at / picked_up_at
        $old_status    = repair_status_normalize($repair['status'] ?? '');
        $new_status    = $fields['status'];
        $completed_at  = $repair['completed_at']  ?? null;
        $picked_up_at  = $repair['picked_up_at']  ?? null;

        if ($new_status === 'fertig' && $old_status !== 'fertig') {
            $completed_at = $now;
        } elseif ($new_status !== 'fertig') {
            $completed_at = null;
        }
        if ($new_status === 'abgeholt' && $old_status !== 'abgeholt') {
            $picked_up_at = $now;
        } elseif ($new_status !== 'abgeholt') {
            $picked_up_at = null;
        }

        if ($is_edit) {
            // Update
            $stmt = $db->prepare(
                'UPDATE repairs SET
                    customer_id = ?, device_type_id = ?, device_type = ?, manufacturer = ?, model = ?,
                    color = ?, imei = ?, serial_number = ?,
                    problem_description = ?, problem_type = ?,
                    technician_id = ?, price = ?, advance_payment = ?,
                    status = ?, internal_notes = ?,
                    working_hours = ?, hourly_rate = ?, labor_cost = ?, performed_work = ?,
                    passcode_encrypted = ?, passcode_iv = ?,
                    estimated_ready = ?, warranty_months = ?,
                    completed_at = ?, picked_up_at = ?,
                    project_id = ?,
                    updated_at = ?
                WHERE id = ?'
            );
            $stmt->execute([
                $fields['customer_id'],
                $deviceTypeId,
                $deviceTypeKey,
                $fields['device_manufacturer'] ?: null,
                $fields['device_model']        ?: null,
                $fields['device_color']        ?: null,
                $fields['imei']                ?: null,
                $fields['serial_number']       ?: null,
                $fields['problem_description'],
                $fields['problem_type']        ?: null,
                $fields['technician_id'],
                $price,
                $adv,
                $new_status,
                $fields['internal_notes']      ?: null,
                $fields['working_hours'],
                $fields['hourly_rate'],
                $fields['labor_cost'],
                $fields['performed_work']      ?: null,
                $passcode_encrypted,
                $passcode_iv,
                $fields['estimated_ready'],
                $fields['warranty_months'],
                $completed_at,
                $picked_up_at,
                $fields['project_id'],
                $now,
                $id,
            ]);

            // Status-History eintragen wenn geändert
            if ($old_status !== $new_status) {
                $h_stmt = $db->prepare(
                    'INSERT INTO repair_status_history (repair_id, status, user_id, created_at)
                     VALUES (?, ?, ?, ?)'
                );
                $h_stmt->execute([$id, $new_status, $_SESSION['user_id'] ?? null, $now]);
            }

            log_activity('update', 'repairs', $id);
            flash('success', 'Reparatur ' . h($repair['repair_number'] ?? '#' . $id) . ' wurde aktualisiert.');
            header('Location: repairs_view.php?id=' . $id);
            exit;

        } else {
            // Insert
            $repair_number = generate_repair_number();

            $stmt = $db->prepare(
                'INSERT INTO repairs
                    (repair_number, customer_id, device_type_id, device_type, manufacturer, model,
                     color, imei, serial_number, problem_description, problem_type,
                     technician_id, price, advance_payment, status, internal_notes,
                     working_hours, hourly_rate, labor_cost, performed_work,
                     passcode_encrypted, passcode_iv, estimated_ready, warranty_months,
                     project_id, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $repair_number,
                $fields['customer_id'],
                $deviceTypeId,
                $deviceTypeKey,
                $fields['device_manufacturer'] ?: null,
                $fields['device_model']        ?: null,
                $fields['device_color']        ?: null,
                $fields['imei']                ?: null,
                $fields['serial_number']       ?: null,
                $fields['problem_description'],
                $fields['problem_type']        ?: null,
                $fields['technician_id'],
                $price,
                $adv,
                $new_status,
                $fields['internal_notes']      ?: null,
                $fields['working_hours'],
                $fields['hourly_rate'],
                $fields['labor_cost'],
                $fields['performed_work']      ?: null,
                $passcode_encrypted,
                $passcode_iv,
                $fields['estimated_ready'],
                $fields['warranty_months'],
                $fields['project_id'],
                $_SESSION['user_id'] ?? null,
                $now,
                $now,
            ]);

            $new_id = (int)$db->lastInsertId();
            if ($source_ticket_id > 0 && $source_ticket
                && (int)($source_ticket['customer_id'] ?? 0) === (int)$fields['customer_id']) {
                $db->prepare('UPDATE tickets SET repair_id = ? WHERE id = ? AND repair_id IS NULL')
                   ->execute([$new_id, $source_ticket_id]);
                ticket_add_history(
                    $source_ticket_id,
                    'repair_linked',
                    ['type' => 'staff', 'ref' => $_SESSION['user_id'] ?? null],
                    null,
                    (string)$new_id
                );
            }

            // Initiale Status-History
            $h_stmt = $db->prepare(
                'INSERT INTO repair_status_history (repair_id, status, user_id, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            $h_stmt->execute([$new_id, $new_status, $_SESSION['user_id'] ?? null, $now]);

            log_activity('create', 'repairs', $new_id);
            flash('success', 'Reparatur ' . h($repair_number) . ' wurde angelegt.');
            header('Location: repairs_view.php?id=' . $new_id);
            exit;
        }
    }
}

// Kunden laden für vorausgewählte Anzeige
$selected_customer = null;
if ($fields['customer_id']) {
    $cs2 = $db->prepare('SELECT id, first_name, last_name, company_id FROM customers WHERE id = ?');
    $cs2->execute([$fields['customer_id']]);
    $selected_customer = $cs2->fetch();
}

// Phase 5: Projekt-Zuordnung (optional) – nur relevant, wenn der gewählte
// Kunde einer Firma zugeordnet ist. Die Liste wird anhand des aktuell
// geladenen Kunden ermittelt; wird der Kunde über die Autocomplete-Suche
// gewechselt, muss die Seite einmal neu geladen werden, damit hier die
// Projekte der neu gewählten Firma erscheinen (bekannte Einschränkung,
// siehe OFFENE_PUNKTE.txt).
$available_projects = [];
if (function_exists('projects_list_for_company') && $selected_customer && !empty($selected_customer['company_id'])) {
    $available_projects = projects_list_for_company((int)$selected_customer['company_id']);
}

$page_title = $is_edit
    ? 'Reparatur bearbeiten: ' . h($repair['repair_number'] ?? '#' . $id)
    : 'Neue Reparatur';

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-nav">
    <a href="<?= $is_edit ? 'repairs_view.php?id=' . $id : 'repairs.php' ?>" class="btn btn-outline btn-sm">
        <?= svg_icon('arrow-left', 16) ?> <?= $is_edit ? 'Zurück zur Reparatur' : 'Zurück zu Reparaturen' ?>
    </a>
</div>

<?php show_flash(); ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?= svg_icon('alert-circle', 18) ?>
        <ul style="margin:0;padding-left:1.25rem;">
            <?php foreach ($errors as $err): ?>
                <li><?= h($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon($is_edit ? 'edit' : 'tool', 20) ?>
            <?= $is_edit ? 'Reparatur bearbeiten' : 'Neue Reparatur anlegen' ?>
        </h2>
        <?php if ($is_edit): ?>
            <span class="text-muted">
                <?= h($repair['repair_number'] ?? '') ?> &middot;
                Angelegt am <?= h(fmt_date($repair['created_at'])) ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <form method="post" action="repairs_form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($source_ticket_id > 0): ?>
              <input type="hidden" name="source_ticket_id" value="<?= $source_ticket_id ?>">
            <?php endif; ?>

            <!-- ═══ KUNDENAUSWAHL ═══════════════════════════════════════════ -->
            <fieldset class="form-section">
                <legend><?= svg_icon('user', 16) ?> Kundendaten</legend>

                <div class="form-group full" style="position:relative;">
                    <label for="customer_search">
                        Kunde <span class="req">*</span>
                    </label>

                    <!-- Verstecktes Feld für tatsächliche customer_id -->
                    <input type="hidden" id="customer_id" name="customer_id" value="<?= h($fields['customer_id']) ?>">

                    <!-- Sichtbares Suchfeld -->
                    <input
                        type="text"
                        id="customer_search"
                        placeholder="Name eingeben und suchen …"
                        value="<?= $selected_customer ? h($selected_customer['first_name'] . ' ' . $selected_customer['last_name']) : '' ?>"
                        autocomplete="off"
                        class="<?= isset($errors['customer_id']) ? 'input-error' : '' ?>"
                    >
                    <div id="customer_results" class="autocomplete-dropdown" style="display:none;"></div>

                    <?php if (isset($errors['customer_id'])): ?>
                        <span class="field-error"><?= h($errors['customer_id']) ?></span>
                    <?php endif; ?>

                    <p class="form-hint">
                        <a href="customers_form.php" target="_blank" class="link-small">
                            <?= svg_icon('user-plus', 13) ?> Neuen Kunden anlegen
                        </a>
                    </p>
                </div>
            </fieldset>

            <!-- ═══ GERÄTEINFOS ════════════════════════════════════════════ -->
            <fieldset class="form-section">
                <legend><?= svg_icon('smartphone', 16) ?> Gerätedaten</legend>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="device_type">Geräteart <span class="req">*</span></label>
                        <select id="device_type" name="device_type" class="<?= isset($errors['device_type']) ? 'input-error' : '' ?>">
                            <option value="">– bitte wählen –</option>
                            <?php $selectedDeviceKey = device_type_key_for_value((string)$fields['device_type']); ?>
                            <?php foreach ($device_types as $dt): ?>
                                <option value="<?= h($dt['technical_key']) ?>" <?= $selectedDeviceKey === $dt['technical_key'] ? 'selected' : '' ?>>
                                    <?= h($dt['display_name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($selectedDeviceKey !== '' && !array_filter($device_types, static fn(array $dt): bool => $dt['technical_key'] === $selectedDeviceKey)): ?>
                                <option value="<?= h($fields['device_type']) ?>" selected>
                                    <?= h(device_type_label((string)$fields['device_type'])) ?> (Altwert)
                                </option>
                            <?php endif; ?>
                        </select>
                        <?php if (isset($errors['device_type'])): ?>
                            <span class="field-error"><?= h($errors['device_type']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="device_manufacturer">Hersteller</label>
                        <input type="text" id="device_manufacturer" name="device_manufacturer"
                               value="<?= h($fields['device_manufacturer']) ?>"
                               placeholder="z. B. Samsung, Apple, Xiaomi …">
                    </div>

                    <div class="form-group">
                        <label for="device_model">Modell</label>
                        <input type="text" id="device_model" name="device_model"
                               value="<?= h($fields['device_model']) ?>"
                               placeholder="z. B. Galaxy S24, iPhone 15 …">
                    </div>

                    <div class="form-group">
                        <label for="device_color">Farbe</label>
                        <input type="text" id="device_color" name="device_color"
                               value="<?= h($fields['device_color']) ?>"
                               placeholder="z. B. Schwarz, Silber …">
                    </div>

                    <div class="form-group">
                        <label for="imei">IMEI</label>
                        <input type="text" id="imei" name="imei"
                               value="<?= h($fields['imei']) ?>"
                               placeholder="15-stellige IMEI-Nummer"
                               maxlength="20">
                    </div>

                    <div class="form-group">
                        <label for="serial_number">Seriennummer</label>
                        <input type="text" id="serial_number" name="serial_number"
                               value="<?= h($fields['serial_number']) ?>"
                               placeholder="Seriennummer des Geräts">
                    </div>

                    <div class="form-group">
                        <label for="device_passcode">
                            Gerätecode
                            <span class="form-hint-inline">(wird verschlüsselt gespeichert)</span>
                        </label>
                        <input type="password" id="device_passcode" name="device_passcode"
                               value=""
                               autocomplete="new-password"
                               placeholder="<?= $is_edit && !empty($repair['passcode_encrypted']) ? '(vorhanden – leer lassen zum Beibehalten)' : 'PIN oder Entsperrmuster …' ?>">
                    </div>
                </div>
            </fieldset>

            <!-- ═══ REPARATURINFOS ══════════════════════════════════════════ -->
            <fieldset class="form-section">
                <legend><?= svg_icon('wrench', 16) ?> Reparaturdetails</legend>

                <div class="form-grid">
                    <div class="form-group full">
                        <label for="problem_type">Fehlertyp</label>
                        <select id="problem_type" name="problem_type">
                            <option value="">– bitte wählen –</option>
                            <?php foreach ($problem_types as $pt): ?>
                                <option value="<?= h($pt) ?>" <?= $fields['problem_type'] === $pt ? 'selected' : '' ?>>
                                    <?= h($pt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Wasserschaden-Warnung (JS-gesteuert) -->
                    <div class="form-group full" id="wasserschaden_warning" style="display:none;">
                        <div class="alert alert-warning" style="margin:0;">
                            <strong>&#9888; Wasserschaden-Hinweis:</strong>
                            Wir nehmen grundsätzlich keine Wasserschäden an. Kunden müssen über die eingeschränkten Reparaturmöglichkeiten informiert werden. Es wird kein eigener Reparaturablauf für Wasserschäden eingeleitet.
                        </div>
                    </div>

                    <div class="form-group full">
                        <label for="problem_description">
                            Fehlerbeschreibung <span class="req">*</span>
                        </label>
                        <textarea id="problem_description" name="problem_description"
                                  rows="5"
                                  placeholder="Bitte den Fehler des Kunden möglichst genau beschreiben …"
                                  class="<?= isset($errors['problem_description']) ? 'input-error' : '' ?>"><?= h($fields['problem_description']) ?></textarea>
                        <?php if (isset($errors['problem_description'])): ?>
                            <span class="field-error"><?= h($errors['problem_description']) ?></span>
                        <?php endif; ?>
                    </div>

                </div>
            </fieldset>

            <!-- ═══ AUFTRAGSDETAILS ═════════════════════════════════════════ -->
            <fieldset class="form-section">
                <legend><?= svg_icon('clipboard', 16) ?> Auftragsdetails</legend>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="technician_id">Techniker</label>
                        <select id="technician_id" name="technician_id">
                            <option value="">– nicht zugewiesen –</option>
                            <?php foreach ($technicians as $tech): ?>
                                <option value="<?= (int)$tech['id'] ?>"
                                        <?= (string)$fields['technician_id'] === (string)$tech['id'] ? 'selected' : '' ?>>
                                    <?= h($tech['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach ($valid_statuses as $s): ?>
                                <option value="<?= $s ?>" <?= $fields['status'] === $s ? 'selected' : '' ?>>
                                    <?= h(repair_status_label($s)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="price">Zusätzlicher Servicepreis netto (€)</label>
                        <input type="number" id="price" name="price"
                               value="<?= h($fields['price']) ?>"
                               min="0" step="0.01"
                               placeholder="0,00">
                        <p class="form-hint">Arbeitskosten und Ersatzteile hier nicht erneut eintragen; sie werden separat addiert.</p>
                    </div>

                    <div class="form-group">
                        <label for="advance_payment">Anzahlung (€)</label>
                        <input type="number" id="advance_payment" name="advance_payment"
                               value="<?= h($fields['advance_payment']) ?>"
                               min="0" step="0.01"
                               placeholder="0,00">
                    </div>

                    <div class="form-group">
                        <label for="working_hours">Arbeitszeit in Stunden</label>
                        <input type="text" inputmode="decimal" id="working_hours" name="working_hours"
                               value="<?= h($fields['working_hours']) ?>"
                               class="<?= isset($errors['labor']) ? 'input-error' : '' ?>"
                               placeholder="z. B. 1,25">
                    </div>

                    <div class="form-group">
                        <label for="hourly_rate">Stundensatz netto (€)</label>
                        <input type="text" inputmode="decimal" id="hourly_rate" name="hourly_rate"
                               value="<?= h($fields['hourly_rate']) ?>"
                               class="<?= isset($errors['labor']) ? 'input-error' : '' ?>"
                               placeholder="79,00">
                    </div>

                    <div class="form-group">
                        <label for="labor_cost">Arbeitskosten netto (€)</label>
                        <input type="text" id="labor_cost" name="labor_cost"
                               value="<?= h(number_format((float)$fields['labor_cost'], 2, ',', '.')) ?>"
                               readonly aria-readonly="true">
                        <p class="form-hint">Wird beim Speichern serverseitig neu berechnet.</p>
                        <?php if (isset($errors['labor'])): ?>
                            <span class="field-error"><?= h($errors['labor']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group full">
                        <label for="performed_work">Durchgeführte Arbeiten</label>
                        <textarea id="performed_work" name="performed_work" rows="6"
                                  placeholder="- Fehlerdiagnose durchgeführt&#10;- Gerät gereinigt&#10;- Funktionstest erfolgreich abgeschlossen"><?= h($fields['performed_work']) ?></textarea>
                        <p class="form-hint">Kundengeeignete Leistungsbeschreibung für Belege und PDFs.</p>
                    </div>

                    <div class="form-group">
                        <label for="estimated_ready">Voraussichtliche Fertigstellung</label>
                        <input type="date" id="estimated_ready" name="estimated_ready"
                               value="<?= h($fields['estimated_ready'] ? substr($fields['estimated_ready'], 0, 10) : '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="warranty_months">Garantie</label>
                        <select id="warranty_months" name="warranty_months">
                            <option value="0"  <?= (string)$fields['warranty_months'] === '0'  ? 'selected' : '' ?>>Keine Garantie</option>
                            <option value="3"  <?= (string)$fields['warranty_months'] === '3'  ? 'selected' : '' ?>>3 Monate</option>
                            <option value="6"  <?= (string)$fields['warranty_months'] === '6'  ? 'selected' : '' ?>>6 Monate</option>
                            <option value="12" <?= (string)$fields['warranty_months'] === '12' ? 'selected' : '' ?>>12 Monate</option>
                        </select>
                    </div>

                    <?php if ($selected_customer && !empty($selected_customer['company_id'])): ?>
                    <div class="form-group">
                        <label for="project_id">Projekt (Firmenkunde, optional)</label>
                        <select id="project_id" name="project_id">
                            <option value="">– keinem Projekt zugeordnet –</option>
                            <?php foreach ($available_projects as $proj): ?>
                                <option value="<?= (int)$proj['id'] ?>" <?= (string)$fields['project_id'] === (string)$proj['id'] ? 'selected' : '' ?>>
                                    <?= h($proj['project_number'] ?? '') ?> <?= h($proj['name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">
                            Ordnet diese Reparatur optional einem Projekt des Firmenkunden zu (z. B. für die
                            gebündelte Übersicht im Firmenkundenportal).
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend><?= svg_icon('lock', 16) ?> Interne Dokumentation</legend>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="internal_notes">Interne Notizen – nicht für Kunden sichtbar</label>
                        <textarea id="internal_notes" name="internal_notes" rows="4"
                                  placeholder="Ausschließlich interne Hinweise; niemals für Kundendokumente oder Portale."><?= h($fields['internal_notes']) ?></textarea>
                    </div>
                </div>
            </fieldset>

            <!-- ── Aktionen ──────────────────────────────────────────────── -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= svg_icon($is_edit ? 'save' : 'tool', 18) ?>
                    <?= $is_edit ? 'Änderungen speichern' : 'Reparatur anlegen' ?>
                </button>
                <a href="<?= $is_edit ? 'repairs_view.php?id=' . $id : 'repairs.php' ?>" class="btn btn-outline">
                    Abbrechen
                </a>
                <?php if ($is_edit && is_admin()): ?>
                    <span class="form-meta text-muted">
                        Zuletzt geändert: <?= h(fmt_date($repair['updated_at'] ?? '', true)) ?>
                    </span>
                <?php endif; ?>
            </div>

        </form>
    </div>
</div>

<?php
$extra_js = <<<JS
<script>
// ── Wasserschaden-Warnung ──────────────────────────────────────────────────
const problemTypeSelect = document.getElementById('problem_type');
const wasserschadenBox  = document.getElementById('wasserschaden_warning');

function checkWasserschaden() {
    if (problemTypeSelect.value === 'Wasserschaden') {
        wasserschadenBox.style.display = 'block';
    } else {
        wasserschadenBox.style.display = 'none';
    }
}
problemTypeSelect.addEventListener('change', checkWasserschaden);
checkWasserschaden(); // Beim Laden prüfen (Edit-Modus)

// Arbeitskosten-Vorschau; der Server berechnet beim Speichern erneut.
(function() {
    const hoursInput = document.getElementById('working_hours');
    const rateInput = document.getElementById('hourly_rate');
    const costInput = document.getElementById('labor_cost');
    if (!hoursInput || !rateInput || !costInput) return;

    function parseGermanDecimal(value) {
        let normalized = String(value || '').trim().replace(/\s|€/g, '');
        if (!normalized) return 0;
        if (normalized.includes(',') && normalized.includes('.')) {
            if (normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
                normalized = normalized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = normalized.replace(/,/g, '');
            }
        } else {
            normalized = normalized.replace(',', '.');
        }
        const number = Number(normalized);
        return Number.isFinite(number) && number >= 0 ? number : null;
    }

    function updateLaborCost() {
        const hours = parseGermanDecimal(hoursInput.value);
        const rate = parseGermanDecimal(rateInput.value);
        if (hours === null || rate === null) {
            costInput.value = 'Ungültige Eingabe';
            return;
        }
        const cents = Math.round((Math.round(hours * 100) * Math.round(rate * 100)) / 100);
        costInput.value = (cents / 100).toLocaleString('de-DE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    hoursInput.addEventListener('input', updateLaborCost);
    rateInput.addEventListener('input', updateLaborCost);
    updateLaborCost();
})();

// ── Kunden-Autocomplete ───────────────────────────────────────────────────
(function() {
    const searchInput  = document.getElementById('customer_search');
    const hiddenInput  = document.getElementById('customer_id');
    const resultsBox   = document.getElementById('customer_results');
    let debounceTimer  = null;

    function closeResults() {
        resultsBox.style.display = 'none';
        resultsBox.innerHTML     = '';
    }

    function selectCustomer(id, label) {
        hiddenInput.value   = id;
        searchInput.value   = label;
        closeResults();
    }

    searchInput.addEventListener('input', function() {
        const q = this.value.trim();
        clearTimeout(debounceTimer);

        if (q.length < 2) {
            closeResults();
            if (q.length === 0) {
                hiddenInput.value = '';
            }
            return;
        }

        debounceTimer = setTimeout(async () => {
            try {
                const res  = await fetch((window.APP_URL_BASE || '') + '/api/customers.php?q=' + encodeURIComponent(q) + '&format=autocomplete');
                const data = await res.json();

                if (!data.length) {
                    resultsBox.innerHTML = '<div class="autocomplete-empty">Keine Kunden gefunden</div>';
                    resultsBox.style.display = 'block';
                    return;
                }

                resultsBox.innerHTML = '';
                data.forEach(function(item) {
                    const div = document.createElement('div');
                    div.className   = 'autocomplete-item';
                    div.textContent = item.label;
                    div.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        selectCustomer(item.id, item.label);
                    });
                    resultsBox.appendChild(div);
                });
                resultsBox.style.display = 'block';
            } catch(e) {
                console.error('Autocomplete-Fehler:', e);
            }
        }, 250);
    });

    // Schließen bei Klick außerhalb
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customer_search') && !e.target.closest('#customer_results')) {
            closeResults();
        }
    });

    searchInput.addEventListener('keydown', function(e) {
        const items = resultsBox.querySelectorAll('.autocomplete-item');
        const active = resultsBox.querySelector('.autocomplete-item.focused');
        let idx = -1;
        items.forEach((it, i) => { if (it === active) idx = i; });

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (active) active.classList.remove('focused');
            const next = items[idx + 1] || items[0];
            if (next) next.classList.add('focused');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (active) active.classList.remove('focused');
            const prev = items[idx - 1] || items[items.length - 1];
            if (prev) prev.classList.add('focused');
        } else if (e.key === 'Enter' && active) {
            e.preventDefault();
            active.dispatchEvent(new MouseEvent('mousedown'));
        } else if (e.key === 'Escape') {
            closeResults();
        }
    });
})();
</script>
<style>
.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid var(--border-color, #ddd);
    border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,.12);
    z-index: 200;
    max-height: 220px;
    overflow-y: auto;
    margin-top: 2px;
}
.autocomplete-item {
    padding: .6rem 1rem;
    cursor: pointer;
    font-size: .9rem;
}
.autocomplete-item:hover,
.autocomplete-item.focused {
    background: var(--primary-50, #eff6ff);
}
.autocomplete-empty {
    padding: .6rem 1rem;
    color: var(--text-muted, #888);
    font-size: .9rem;
}
.form-section {
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
}
.form-section legend {
    font-weight: 600;
    padding: 0 .5rem;
    font-size: .95rem;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.form-hint-inline {
    font-size: .8rem;
    font-weight: 400;
    color: var(--text-muted, #888);
}
</style>
JS;

require_once __DIR__ . '/includes/footer.php';
?>
