<?php
require_once __DIR__ . '/init.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

// Load existing customer for edit mode
$customer = null;
if ($is_edit) {
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        flash('error', 'Kunde nicht gefunden.');
        header('Location: customers.php');
        exit;
    }
}

// Defaults
$fields = [
    'first_name'   => $customer['first_name']   ?? '',
    'last_name'    => $customer['last_name']     ?? '',
    'phone'        => $customer['phone']         ?? '',
    'phone2'       => $customer['phone2']        ?? '',
    'email'        => $customer['email']         ?? '',
    'address'      => $customer['address']       ?? '',
    'city'         => $customer['city']          ?? '',
    'zip'          => $customer['zip']           ?? '',
    'notes'        => $customer['notes']         ?? '',
    'gdpr_consent' => $customer['gdpr_consent']  ?? 0,
    'gdpr_date'    => $customer['gdpr_date']     ?? '',
    'company_id'   => $customer['company_id']    ?? null,
];

// Phase 5: Zuordnung zu einer Firma (Firmenkunde) – nur sichtbar/änderbar
// mit der Berechtigung "manage_companies", damit die Firmenkunden-Struktur
// weiterhin zentral über dieses Recht geschützt bleibt.
$can_assign_company = function_exists('user_has_permission') && user_has_permission('manage_companies');
$companies_for_select = $can_assign_company ? companies_list(true) : [];

$errors = [];

// Handle POST: Kundenportal-Zugang erstellen/neu erzeugen (separates
// Mini-Formular unterhalb, daher vor der eigentlichen Kundenformular-
// Verarbeitung abgefangen).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_action']) && $is_edit) {
    verify_csrf();

    $regenerate = $_POST['portal_action'] === 'regenerate';
    ensure_customer_portal_access($id, $regenerate);

    if ($regenerate) {
        log_activity('portal_regenerate', 'customers', $id, 'Portal-Zugang (Link/PIN) neu erzeugt');
        flash('success', 'Der Portal-Zugang wurde neu erzeugt. Der bisherige Link/PIN ist ab sofort ungültig.');
    } else {
        log_activity('portal_create', 'customers', $id, 'Portal-Zugang erstellt');
        flash('success', 'Der Portal-Zugang wurde erstellt.');
    }

    header('Location: customers_form.php?id=' . $id . '#portal-access');
    exit;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['portal_action'])) {
    verify_csrf();

    // Collect and sanitize input
    $fields['first_name']   = trim($_POST['first_name']   ?? '');
    $fields['last_name']    = trim($_POST['last_name']    ?? '');
    $fields['phone']        = trim($_POST['phone']        ?? '');
    $fields['phone2']       = trim($_POST['phone2']       ?? '');
    $fields['email']        = trim($_POST['email']        ?? '');
    $fields['address']      = trim($_POST['address']      ?? '');
    $fields['city']         = trim($_POST['city']         ?? '');
    $fields['zip']          = trim($_POST['zip']          ?? '');
    $fields['notes']        = trim($_POST['notes']        ?? '');
    $fields['gdpr_consent'] = isset($_POST['gdpr_consent']) ? 1 : 0;
    if ($can_assign_company) {
        $company_id_input = trim($_POST['company_id'] ?? '');
        $fields['company_id'] = $company_id_input !== '' ? (int)$company_id_input : null;
    }

    // Validation
    if ($fields['first_name'] === '') {
        $errors['first_name'] = 'Vorname ist ein Pflichtfeld.';
    }
    if ($fields['last_name'] === '') {
        $errors['last_name'] = 'Nachname ist ein Pflichtfeld.';
    }
    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    }
    if (!$is_edit && !$fields['gdpr_consent']) {
        $errors['gdpr_consent'] = 'Die DSGVO-Einwilligung ist erforderlich.';
    }

    if (empty($errors)) {
        $now = date('Y-m-d H:i:s');

        if ($is_edit) {
            // Keep existing gdpr_date if consent was already given and is not being changed
            $gdpr_date = $fields['gdpr_consent']
                ? ($customer['gdpr_date'] ?: date('Y-m-d'))
                : null;

            $stmt = $db->prepare(
                'UPDATE customers SET
                    first_name = ?, last_name = ?, phone = ?, phone2 = ?,
                    email = ?, address = ?, city = ?, zip = ?,
                    notes = ?, gdpr_consent = ?, gdpr_date = ?,
                    updated_at = ?
                WHERE id = ?'
            );
            $stmt->execute([
                $fields['first_name'],
                $fields['last_name'],
                $fields['phone']    ?: null,
                $fields['phone2']   ?: null,
                $fields['email']    ?: null,
                $fields['address']  ?: null,
                $fields['city']     ?: null,
                $fields['zip']      ?: null,
                $fields['notes']    ?: null,
                $fields['gdpr_consent'],
                $gdpr_date,
                $now,
                $id,
            ]);

            if ($can_assign_company) {
                customer_assign_company($id, $fields['company_id']);
            }

            log_activity('update', 'customers', $id);
            flash('success', 'Kunde „' . $fields['first_name'] . ' ' . $fields['last_name'] . '" wurde aktualisiert.');
        } else {
            // New customer
            $gdpr_date = $fields['gdpr_consent'] ? date('Y-m-d') : null;

            $stmt = $db->prepare(
                'INSERT INTO customers
                    (first_name, last_name, phone, phone2, email, address, city, zip,
                     notes, gdpr_consent, gdpr_date, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $fields['first_name'],
                $fields['last_name'],
                $fields['phone']    ?: null,
                $fields['phone2']   ?: null,
                $fields['email']    ?: null,
                $fields['address']  ?: null,
                $fields['city']     ?: null,
                $fields['zip']      ?: null,
                $fields['notes']    ?: null,
                $fields['gdpr_consent'],
                $gdpr_date,
                $_SESSION['user_id'] ?? null,
                $now,
                $now,
            ]);

            $new_id = (int)$db->lastInsertId();

            if ($can_assign_company && $fields['company_id']) {
                customer_assign_company($new_id, $fields['company_id']);
            }

            log_activity('create', 'customers', $new_id);
            flash('success', 'Kunde „' . $fields['first_name'] . ' ' . $fields['last_name'] . '" wurde angelegt.');
        }

        header('Location: customers.php');
        exit;
    }
}

$page_title = $is_edit
    ? 'Kunde bearbeiten: ' . h($customer['first_name']) . ' ' . h($customer['last_name'])
    : 'Neuen Kunden anlegen';

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-nav">
    <a href="customers.php" class="btn btn-outline btn-sm">
        <?= svg_icon('arrow-left', 16) ?> Zurück zu Kunden
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon($is_edit ? 'edit' : 'user-plus', 20) ?>
            <?= $is_edit ? 'Kunde bearbeiten' : 'Neuen Kunden anlegen' ?>
        </h2>
        <?php if ($is_edit): ?>
            <span class="text-muted">
                Angelegt am <?= h(fmt_date($customer['created_at'])) ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <?php if (!empty($errors)): ?>
            <div class="alert-danger">
                <?= svg_icon('alert-circle', 18) ?>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="customers_form.php<?= $is_edit ? '?id=' . $id : '' ?>" novalidate>
            <?= csrf_field() ?>

            <div class="form-grid">

                <!-- Vorname -->
                <div class="form-group">
                    <label for="first_name">
                        Vorname <span class="req">*</span>
                    </label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        value="<?= h($fields['first_name']) ?>"
                        class="<?= isset($errors['first_name']) ? 'input-error' : '' ?>"
                        required
                        autofocus
                    >
                    <?php if (isset($errors['first_name'])): ?>
                        <span class="field-error"><?= h($errors['first_name']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Nachname -->
                <div class="form-group">
                    <label for="last_name">
                        Nachname <span class="req">*</span>
                    </label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        value="<?= h($fields['last_name']) ?>"
                        class="<?= isset($errors['last_name']) ? 'input-error' : '' ?>"
                        required
                    >
                    <?php if (isset($errors['last_name'])): ?>
                        <span class="field-error"><?= h($errors['last_name']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Telefon -->
                <div class="form-group">
                    <label for="phone">Telefon</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        value="<?= h($fields['phone']) ?>"
                        placeholder="+49 521 …"
                    >
                </div>

                <!-- Telefon 2 -->
                <div class="form-group">
                    <label for="phone2">Telefon 2</label>
                    <input
                        type="tel"
                        id="phone2"
                        name="phone2"
                        value="<?= h($fields['phone2']) ?>"
                        placeholder="Alternativnummer"
                    >
                </div>

                <!-- E-Mail -->
                <div class="form-group full">
                    <label for="email">E-Mail</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= h($fields['email']) ?>"
                        class="<?= isset($errors['email']) ? 'input-error' : '' ?>"
                        placeholder="kunde@beispiel.de"
                    >
                    <?php if (isset($errors['email'])): ?>
                        <span class="field-error"><?= h($errors['email']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Adresse -->
                <div class="form-group full">
                    <label for="address">Adresse</label>
                    <input
                        type="text"
                        id="address"
                        name="address"
                        value="<?= h($fields['address']) ?>"
                        placeholder="Straße und Hausnummer"
                    >
                </div>

                <!-- PLZ -->
                <div class="form-group">
                    <label for="zip">PLZ</label>
                    <input
                        type="text"
                        id="zip"
                        name="zip"
                        value="<?= h($fields['zip']) ?>"
                        placeholder="33602"
                        maxlength="10"
                    >
                </div>

                <!-- Stadt -->
                <div class="form-group">
                    <label for="city">Stadt</label>
                    <input
                        type="text"
                        id="city"
                        name="city"
                        value="<?= h($fields['city']) ?>"
                        placeholder="Bielefeld"
                    >
                </div>

                <?php if ($can_assign_company): ?>
                <!-- Firma (Firmenkunde) – Phase 5 -->
                <div class="form-group full">
                    <label for="company_id">Firma (Firmenkunde)</label>
                    <select id="company_id" name="company_id">
                        <option value="">– Keine Zuordnung (Privatkunde) –</option>
                        <?php foreach ($companies_for_select as $co): ?>
                            <option value="<?= (int)$co['id'] ?>" <?= (int)($fields['company_id'] ?? 0) === (int)$co['id'] ? 'selected' : '' ?>>
                                <?= h($co['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">
                        Ordnet diesen Kunden einer Firma zu – seine Reparaturen, Kostenvoranschläge
                        und Rechnungen erscheinen dadurch automatisch im Firmenkundenportal, ohne
                        dass Daten dupliziert werden.
                    </p>
                </div>
                <?php endif; ?>

                <!-- Notizen -->
                <div class="form-group full">
                    <label for="notes">Notizen</label>
                    <textarea
                        id="notes"
                        name="notes"
                        rows="4"
                        placeholder="Interne Notizen zum Kunden …"
                    ><?= h($fields['notes']) ?></textarea>
                </div>

                <!-- DSGVO -->
                <div class="form-group full">
                    <fieldset class="checkbox-group">
                        <legend>Datenschutz (DSGVO)</legend>
                        <label class="checkbox-label <?= isset($errors['gdpr_consent']) ? 'label-error' : '' ?>">
                            <input
                                type="checkbox"
                                name="gdpr_consent"
                                value="1"
                                <?= $fields['gdpr_consent'] ? 'checked' : '' ?>
                                <?= !$is_edit ? 'required' : '' ?>
                            >
                            Der Kunde hat der Speicherung und Verarbeitung seiner personenbezogenen Daten
                            gemäß DSGVO zugestimmt.
                            <?php if (!$is_edit): ?><span class="req">*</span><?php endif; ?>
                        </label>
                        <?php if (isset($errors['gdpr_consent'])): ?>
                            <span class="field-error"><?= h($errors['gdpr_consent']) ?></span>
                        <?php endif; ?>
                        <?php if ($is_edit && $customer['gdpr_date']): ?>
                            <p class="form-hint">
                                <?= svg_icon('calendar', 14) ?>
                                Einwilligung erteilt am <?= h(fmt_date($customer['gdpr_date'])) ?>
                            </p>
                        <?php elseif (!$is_edit): ?>
                            <p class="form-hint">
                                Das Datum der Einwilligung wird automatisch auf heute gesetzt.
                            </p>
                        <?php endif; ?>
                    </fieldset>
                </div>

            </div><!-- /.form-grid -->

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= svg_icon($is_edit ? 'save' : 'user-plus', 18) ?>
                    <?= $is_edit ? 'Änderungen speichern' : 'Kunden anlegen' ?>
                </button>
                <a href="customers.php" class="btn btn-outline">
                    Abbrechen
                </a>
                <?php if ($is_edit && is_admin()): ?>
                    <span class="form-meta text-muted">
                        Zuletzt geändert: <?= h(fmt_date($customer['updated_at'])) ?>
                    </span>
                <?php endif; ?>
            </div>

        </form>
    </div>
</div>

<?php if ($is_edit): ?>
<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header">
        <h3 class="card-title">
            <?= svg_icon('tool', 18) ?> Reparaturen dieses Kunden
        </h3>
        <a href="repairs_form.php?customer_id=<?= $id ?>" class="btn btn-sm btn-outline">
            <?= svg_icon('plus', 16) ?> Neue Reparatur
        </a>
    </div>
    <div class="card-body">
        <?php
        $r_stmt = $db->prepare(
            'SELECT id, device_type, model, status, created_at
             FROM repairs
             WHERE customer_id = ?
             ORDER BY created_at DESC
             LIMIT 10'
        );
        $r_stmt->execute([$id]);
        $repairs = $r_stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (empty($repairs)): ?>
            <div class="empty-state">
                <?= svg_icon('tool', 36) ?>
                <p>Noch keine Reparaturen für diesen Kunden.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Gerät</th>
                            <th>Modell</th>
                            <th>Status</th>
                            <th>Erstellt</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repairs as $r): ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td><?= h($r['device_type'] ?? '—') ?></td>
                                <td><?= h($r['model'] ?? '—') ?></td>
                                <td><?= repair_status_badge($r['status']) ?></td>
                                <td><?= h(fmt_date($r['created_at'])) ?></td>
                                <td>
                                    <a href="repairs_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline">
                                        <?= svg_icon('edit', 14) ?> Öffnen
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer-link">
                <a href="repairs.php?customer_id=<?= $id ?>">
                    Alle Reparaturen dieses Kunden anzeigen <?= svg_icon('arrow-right', 15) ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: 1.5rem;" id="portal-access">
    <div class="card-header">
        <h3 class="card-title">
            <?= svg_icon('link', 18) ?> Kundenportal-Zugang
        </h3>
    </div>
    <div class="card-body">
        <?php
        $portal_access = get_customer_portal_access($id);
        $portal_pin = null;
        if ($portal_access && !empty($portal_access['pin_encrypted']) && !empty($portal_access['pin_iv'])) {
            try {
                $portal_pin = decrypt_passcode($portal_access['pin_encrypted'], $portal_access['pin_iv']);
            } catch (Throwable $e) {
                $portal_pin = null;
            }
        }
        ?>
        <?php if (!portal_enabled()): ?>
            <div class="alert-warning">
                <?= svg_icon('alert', 18) ?>
                Das Kundenportal ist derzeit systemweit deaktiviert (siehe Einstellungen).
            </div>
        <?php endif; ?>

        <?php if (!$portal_access): ?>
            <p class="text-muted">Für diesen Kunden wurde noch kein Portal-Zugang erstellt. Ein Zugang wird außerdem automatisch angelegt, sobald die erste Status-E-Mail zu einer Reparatur dieses Kunden versendet wird.</p>
            <form method="post" action="customers_form.php?id=<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="portal_action" value="create">
                <button type="submit" class="btn btn-primary btn-sm">
                    <?= svg_icon('key', 16) ?> Portal-Zugang jetzt erstellen
                </button>
            </form>
        <?php else: ?>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="portal_link">Persönlicher Zugangslink</label>
                    <input type="text" id="portal_link" readonly
                           value="<?= h(portal_link_for_token($portal_access['token'])) ?>"
                           onclick="this.select()">
                </div>
                <div class="form-group">
                    <label for="portal_pin">PIN (für Anmeldung mit Auftragsnummer)</label>
                    <input type="text" id="portal_pin" readonly
                           value="<?= h($portal_pin !== null && $portal_pin !== '' ? $portal_pin : '—') ?>"
                           onclick="this.select()">
                </div>
                <div class="form-group">
                    <label>Letzte Anmeldung</label>
                    <input type="text" readonly
                           value="<?= h($portal_access['last_login_at'] ? fmt_date($portal_access['last_login_at'], true) : 'Noch nicht genutzt') ?>">
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-top:12px;">
                <img src="<?= h(url('api/portal_qrcode.php?customer_id=' . $id)) ?>"
                     alt="QR-Code Portal-Zugang" width="140" height="140"
                     style="border:1px solid #e5e7eb;border-radius:8px;">
                <div>
                    <p class="form-hint" style="margin-top:0;">
                        Diesen QR-Code kann der Kunde mit dem Smartphone scannen, um sich ohne
                        Eingabe automatisch im Kundenportal anzumelden.
                    </p>
                    <form method="post" action="customers_form.php?id=<?= $id ?>"
                          onsubmit="return confirm('Neuen Zugangslink und neue PIN erzeugen? Der bisherige Link/PIN wird dadurch sofort ungültig.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="portal_action" value="regenerate">
                        <button type="submit" class="btn btn-outline btn-sm">
                            <?= svg_icon('refresh-cw', 16) ?> Link &amp; PIN neu erzeugen
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
