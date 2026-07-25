<?php
require_once __DIR__ . '/init.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    flash('error', 'Keine Anfrage-ID angegeben.');
    header('Location: booking_requests.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM booking_requests WHERE id = ?');
$stmt->execute([$id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    flash('error', 'Terminanfrage nicht gefunden.');
    header('Location: booking_requests.php');
    exit;
}

$valid_statuses = booking_valid_statuses();

// ── POST: Aktionen ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = trim($_POST['do'] ?? '');
    $now = date('Y-m-d H:i:s');

    // Interne Notiz (unabhängig vom Status) speichern
    if ($do === 'save_note') {
        $note = trim($_POST['admin_note'] ?? '');
        $db->prepare('UPDATE booking_requests SET admin_note = ?, updated_at = ? WHERE id = ?')
           ->execute([$note ?: null, $now, $id]);
        log_activity('booking_note', 'booking_requests', $id, 'Notiz aktualisiert');
        flash('success', 'Notiz wurde gespeichert.');
        header('Location: booking_view.php?id=' . $id);
        exit;
    }

    // Bestätigen oder Umplanen (beide benötigen Datum + Uhrzeit)
    if ($do === 'confirm' || $do === 'reschedule') {
        $c_date = trim($_POST['confirmed_date'] ?? '');
        $c_time = trim($_POST['confirmed_time'] ?? '');
        $note   = trim($_POST['admin_note'] ?? '');

        $errors = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $c_date)) {
            $errors[] = 'Bitte ein gültiges Datum angeben.';
        } else {
            $dp = explode('-', $c_date);
            if (!checkdate((int)$dp[1], (int)$dp[2], (int)$dp[0])) {
                $errors[] = 'Das angegebene Datum ist ungültig.';
            }
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $c_time)) {
            $errors[] = 'Bitte eine gültige Uhrzeit angeben.';
        }

        if (empty($errors)) {
            $new_status = $do === 'confirm' ? 'bestaetigt' : 'umgeplant';
            $confirmed_datetime = $c_date . ' ' . $c_time . ':00';

            $db->prepare(
                'UPDATE booking_requests
                 SET status = ?, confirmed_datetime = ?, admin_note = ?, updated_at = ?
                 WHERE id = ?'
            )->execute([$new_status, $confirmed_datetime, $note ?: null, $now, $id]);

            // Internen Kalendertermin anlegen/aktualisieren
            $title = 'Termin: ' . trim($booking['first_name'] . ' ' . $booking['last_name']) . ' (' . device_type_label($booking['device_type']) . ')';
            if (!empty($booking['appointment_id'])) {
                $db->prepare('UPDATE appointments SET title = ?, start_datetime = ?, notes = ? WHERE id = ?')
                   ->execute([$title, $confirmed_datetime, $booking['issue_description'], (int)$booking['appointment_id']]);
                $appointment_id = (int)$booking['appointment_id'];
            } else {
                $db->prepare(
                    'INSERT INTO appointments (repair_id, customer_id, title, start_datetime, type, notes, created_by, created_at)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $booking['customer_id'] ?: null,
                    $title,
                    $confirmed_datetime,
                    'eingang',
                    $booking['issue_description'],
                    $_SESSION['user_id'] ?? null,
                    $now,
                ]);
                $appointment_id = (int)$db->lastInsertId();
                $db->prepare('UPDATE booking_requests SET appointment_id = ? WHERE id = ?')
                   ->execute([$appointment_id, $id]);
            }

            // Optionale Google-Kalender-Synchronisation (no-op, falls nicht konfiguriert)
            sync_appointment_calendar($appointment_id);

            log_activity($do === 'confirm' ? 'booking_confirmed' : 'booking_rescheduled', 'booking_requests', $id, $new_status);

            // E-Mail an Kunden (nicht blockierend)
            try {
                $stmt2 = $db->prepare('SELECT * FROM booking_requests WHERE id = ?');
                $stmt2->execute([$id]);
                $fresh = $stmt2->fetch(PDO::FETCH_ASSOC);
                send_booking_status_email($fresh, $new_status);
            } catch (Throwable $e) {
                error_log('booking_view.php Mailversand: ' . $e->getMessage());
            }

            flash('success', $do === 'confirm'
                ? 'Termin wurde bestätigt und der Kunde per E-Mail benachrichtigt.'
                : 'Termin wurde umgeplant und der Kunde per E-Mail benachrichtigt.');
        } else {
            foreach ($errors as $e) flash('error', $e);
        }

        header('Location: booking_view.php?id=' . $id);
        exit;
    }

    // Ablehnen
    if ($do === 'reject') {
        $note = trim($_POST['admin_note'] ?? '');

        $db->prepare('UPDATE booking_requests SET status = ?, admin_note = ?, updated_at = ? WHERE id = ?')
           ->execute(['abgelehnt', $note ?: null, $now, $id]);

        log_activity('booking_rejected', 'booking_requests', $id, 'abgelehnt');

        try {
            $stmt2 = $db->prepare('SELECT * FROM booking_requests WHERE id = ?');
            $stmt2->execute([$id]);
            $fresh = $stmt2->fetch(PDO::FETCH_ASSOC);
            send_booking_status_email($fresh, 'abgelehnt');
        } catch (Throwable $e) {
            error_log('booking_view.php Mailversand: ' . $e->getMessage());
        }

        flash('success', 'Terminanfrage wurde abgelehnt und der Kunde per E-Mail benachrichtigt.');
        header('Location: booking_view.php?id=' . $id);
        exit;
    }

    // Stornieren (intern, keine Kunden-E-Mail-Vorlage vorgesehen)
    if ($do === 'cancel') {
        $db->prepare('UPDATE booking_requests SET status = ?, updated_at = ? WHERE id = ?')
           ->execute(['storniert', $now, $id]);
        log_activity('booking_cancelled', 'booking_requests', $id, 'storniert');
        flash('success', 'Terminanfrage wurde storniert.');
        header('Location: booking_view.php?id=' . $id);
        exit;
    }

    // In Reparatur umwandeln
    if ($do === 'convert_to_repair') {
        if ($booking['repair_id']) {
            flash('error', 'Diese Anfrage wurde bereits in eine Reparatur umgewandelt.');
            header('Location: booking_view.php?id=' . $id);
            exit;
        }

        // Kunde finden (per E-Mail) oder neu anlegen
        $customer_id = (int)($booking['customer_id'] ?? 0);
        if (!$customer_id && $booking['email']) {
            $cs = $db->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            $cs->execute([$booking['email']]);
            $found = $cs->fetch(PDO::FETCH_COLUMN);
            if ($found) $customer_id = (int)$found;
        }
        if (!$customer_id) {
            $db->prepare(
                'INSERT INTO customers (first_name, last_name, phone, email, notes, gdpr_consent, gdpr_date, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $booking['first_name'],
                $booking['last_name'],
                $booking['phone'] ?: null,
                $booking['email'] ?: null,
                $booking['company'] ? ('Firma: ' . $booking['company']) : null,
                (int)$booking['privacy_consent'],
                $booking['privacy_consent'] ? date('Y-m-d') : null,
                $_SESSION['user_id'] ?? null,
                $now,
                $now,
            ]);
            $customer_id = (int)$db->lastInsertId();
            log_activity('create', 'customers', $customer_id, 'Automatisch aus Terminanfrage ' . $booking['booking_number']);
        }

        // Reparatur anlegen
        $repair_number = generate_repair_number();
        $device_label  = device_type_label($booking['device_type']);

        $db->prepare(
            'INSERT INTO repairs
                (repair_number, customer_id, device_type, manufacturer, model,
                 problem_description, status, internal_notes,
                 created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $repair_number,
            $customer_id,
            $device_label,
            $booking['manufacturer'] ?: null,
            $booking['model'] ?: null,
            $booking['issue_description'] ?: '',
            'angenommen',
            'Angelegt aus Online-Terminanfrage ' . $booking['booking_number'],
            $_SESSION['user_id'] ?? null,
            $now,
            $now,
        ]);
        $repair_id = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO repair_status_history (repair_id, status, user_id, created_at) VALUES (?, ?, ?, ?)')
           ->execute([$repair_id, 'angenommen', $_SESSION['user_id'] ?? null, $now]);

        $db->prepare('UPDATE booking_requests SET status = ?, customer_id = ?, repair_id = ?, updated_at = ? WHERE id = ?')
           ->execute(['umgewandelt', $customer_id, $repair_id, $now, $id]);

        // Ggf. verknüpften internen Termin auf die neue Reparatur verlinken
        if (!empty($booking['appointment_id'])) {
            $db->prepare('UPDATE appointments SET repair_id = ?, customer_id = ? WHERE id = ?')
               ->execute([$repair_id, $customer_id, (int)$booking['appointment_id']]);
        }

        log_activity('booking_converted', 'booking_requests', $id, 'Reparatur ' . $repair_number . ' angelegt');
        flash('success', 'Terminanfrage wurde in Reparatur ' . h($repair_number) . ' umgewandelt.');
        header('Location: repairs_view.php?id=' . $repair_id);
        exit;
    }

    header('Location: booking_view.php?id=' . $id);
    exit;
}

$geraet = trim(($booking['manufacturer'] ?? '') . ' ' . ($booking['model'] ?? '')) ?: device_type_label($booking['device_type']);
$wa_msg = 'Hallo ' . ($booking['first_name'] ?? '') . ', vielen Dank für Ihre Terminanfrage (' . ($booking['booking_number'] ?? '') . ') bei uns!';
$wa_link = ($booking['phone'] ?? '') ? whatsapp_link($booking['phone'], $wa_msg) : '';

$page_title = 'Terminanfrage ' . h($booking['booking_number']);
require_once __DIR__ . '/includes/header.php';
?>

<?php show_flash(); ?>

<!-- ── Kopfzeile ──────────────────────────────────────────────────────────── -->
<div class="page-header-bar">
    <div class="page-header-info">
        <a href="booking_requests.php" class="btn btn-outline btn-sm">
            <?= svg_icon('arrow-left', 16) ?> Zurück
        </a>
        <h1 class="page-title">
            <?= svg_icon('clock', 22) ?>
            <?= h($booking['booking_number']) ?>
        </h1>
        <?= booking_status_badge($booking['status']) ?>
    </div>
    <div class="page-header-actions">
        <a href="pdf/terminbestaetigung.php?id=<?= (int)$booking['id'] ?>" class="btn btn-outline btn-sm" target="_blank">
            <?= svg_icon('pdf', 15) ?> Terminbestätigung
        </a>
        <?php if (!empty($booking['email'])): ?>
            <a href="mailto:<?= h($booking['email']) ?>?subject=<?= rawurlencode('Ihre Terminanfrage ' . ($booking['booking_number'] ?? '')) ?>" class="btn btn-outline btn-sm">
                <?= svg_icon('mail', 15) ?> E-Mail
            </a>
        <?php endif; ?>
        <?php if ($wa_link): ?>
            <a href="<?= h($wa_link) ?>" target="_blank" class="btn btn-sm btn-green">
                <?= svg_icon('whatsapp', 15) ?> WhatsApp
            </a>
        <?php endif; ?>
        <?php if (!empty($booking['repair_id'])): ?>
            <a href="repairs_view.php?id=<?= (int)$booking['repair_id'] ?>" class="btn btn-primary btn-sm">
                <?= svg_icon('wrench', 15) ?> Zur Reparatur
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Infopanels ─────────────────────────────────────────────────────────── -->
<div class="detail-grid">

    <!-- Kontaktdaten -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= svg_icon('user', 18) ?> Kontaktdaten</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Name</dt>
                <dd><?= h(trim($booking['first_name'] . ' ' . $booking['last_name'])) ?></dd>

                <?php if ($booking['company']): ?>
                    <dt>Firma</dt>
                    <dd><?= h($booking['company']) ?></dd>
                <?php endif; ?>

                <dt>E-Mail</dt>
                <dd><a href="mailto:<?= h($booking['email']) ?>"><?= h($booking['email']) ?></a></dd>

                <?php if ($booking['phone']): ?>
                    <dt>Telefon</dt>
                    <dd>
                        <a href="tel:<?= h($booking['phone']) ?>"><?= h($booking['phone']) ?></a>
                        <?php if ($wa_link): ?>
                            <a href="<?= h($wa_link) ?>" target="_blank" class="btn btn-xs btn-green" title="WhatsApp" style="margin-left:.5rem;">
                                <?= svg_icon('whatsapp', 14) ?> WhatsApp
                            </a>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($booking['customer_id']): ?>
                    <dt>Kundendatensatz</dt>
                    <dd><a href="repairs.php?customer_id=<?= (int)$booking['customer_id'] ?>">Bereits bekannter Kunde</a></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- Geräteinfos -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= svg_icon('smartphone', 18) ?> Gerät &amp; Anliegen</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Geräteart</dt>
                <dd><?= h(device_type_label($booking['device_type'])) ?></dd>

                <?php if ($booking['manufacturer']): ?>
                    <dt>Hersteller</dt>
                    <dd><?= h($booking['manufacturer']) ?></dd>
                <?php endif; ?>

                <?php if ($booking['model']): ?>
                    <dt>Modell</dt>
                    <dd><?= h($booking['model']) ?></dd>
                <?php endif; ?>

                <dt>Anliegen</dt>
                <dd style="white-space:pre-wrap;"><?= h($booking['issue_description'] ?: '—') ?></dd>
            </dl>
        </div>
    </div>

    <!-- Termin -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= svg_icon('calendar', 18) ?> Termin</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Wunschtermin</dt>
                <dd><?= h(fmt_date($booking['preferred_date'])) ?>, <?= h(substr((string)$booking['preferred_time'], 0, 5)) ?> Uhr</dd>

                <?php if ($booking['confirmed_datetime']): ?>
                    <dt>Bestätigt für</dt>
                    <dd><strong><?= h(fmt_date($booking['confirmed_datetime'], true)) ?></strong></dd>
                <?php endif; ?>

                <dt>Datenschutz</dt>
                <dd><?= $booking['privacy_consent'] ? 'Zugestimmt' : 'Nicht zugestimmt' ?></dd>

                <dt>Newsletter/Info</dt>
                <dd><?= $booking['marketing_consent'] ? 'Zugestimmt' : 'Nicht zugestimmt' ?></dd>

                <dt>Eingegangen am</dt>
                <dd><?= h(fmt_date($booking['created_at'], true)) ?></dd>

                <?php if ($booking['ip_address']): ?>
                    <dt>IP-Adresse</dt>
                    <dd><code><?= h($booking['ip_address']) ?></code></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

</div><!-- /.detail-grid -->

<?php if (!in_array($booking['status'], ['umgewandelt'], true)): ?>
<!-- ── Aktionen ───────────────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('check', 18) ?> Anfrage bearbeiten</h3>
    </div>
    <div class="card-body">
        <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem;">

            <!-- Bestätigen / Umplanen -->
            <form method="post" action="booking_view.php?id=<?= $id ?>" class="form-section" style="margin:0;padding:1rem;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;">
                <?= csrf_field() ?>
                <legend style="font-weight:600;margin-bottom:.5rem;">
                    <?= $booking['status'] === 'bestaetigt' || $booking['status'] === 'umgeplant' ? 'Termin umplanen' : 'Termin bestätigen' ?>
                </legend>
                <div class="form-group">
                    <label for="confirmed_date">Datum</label>
                    <input type="date" id="confirmed_date" name="confirmed_date"
                           value="<?= h($booking['confirmed_datetime'] ? substr($booking['confirmed_datetime'], 0, 10) : $booking['preferred_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="confirmed_time">Uhrzeit</label>
                    <input type="time" id="confirmed_time" name="confirmed_time"
                           value="<?= h($booking['confirmed_datetime'] ? substr($booking['confirmed_datetime'], 11, 5) : substr((string)$booking['preferred_time'], 0, 5)) ?>" required>
                </div>
                <button type="submit" name="do" value="<?= ($booking['status'] === 'bestaetigt' || $booking['status'] === 'umgeplant') ? 'reschedule' : 'confirm' ?>" class="btn btn-primary btn-sm" style="margin-top:.5rem;">
                    <?= svg_icon('check', 15) ?> <?= ($booking['status'] === 'bestaetigt' || $booking['status'] === 'umgeplant') ? 'Umplanen & E-Mail senden' : 'Bestätigen & E-Mail senden' ?>
                </button>
            </form>

            <!-- Ablehnen -->
            <form method="post" action="booking_view.php?id=<?= $id ?>" class="form-section" style="margin:0;padding:1rem;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;"
                  onsubmit="return confirm('Diese Terminanfrage wirklich ablehnen? Der Kunde erhält eine E-Mail.');">
                <?= csrf_field() ?>
                <legend style="font-weight:600;margin-bottom:.5rem;">Ablehnen</legend>
                <div class="form-group">
                    <label for="reject_note">Grund (optional, erscheint nicht automatisch in der E-Mail)</label>
                    <textarea id="reject_note" name="admin_note" rows="3" placeholder="Interner Vermerk …"></textarea>
                </div>
                <button type="submit" name="do" value="reject" class="btn btn-danger btn-sm" style="margin-top:.5rem;">
                    <?= svg_icon('x', 15) ?> Ablehnen & E-Mail senden
                </button>
            </form>

            <!-- In Reparatur umwandeln -->
            <div class="form-section" style="margin:0;padding:1rem;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;">
                <legend style="font-weight:600;margin-bottom:.5rem;">Reparatur anlegen</legend>
                <p class="text-muted" style="font-size:.85rem;margin-bottom:.75rem;">
                    Legt einen Kunden (falls neu) und einen Reparaturauftrag mit den Daten dieser Anfrage an.
                </p>
                <form method="post" action="booking_view.php?id=<?= $id ?>"
                      onsubmit="return confirm('Aus dieser Anfrage einen Reparaturauftrag anlegen?');">
                    <?= csrf_field() ?>
                    <button type="submit" name="do" value="convert_to_repair" class="btn btn-outline btn-sm">
                        <?= svg_icon('wrench', 15) ?> In Reparatur umwandeln
                    </button>
                </form>
            </div>

            <!-- Stornieren -->
            <?php if ($booking['status'] !== 'storniert'): ?>
            <div class="form-section" style="margin:0;padding:1rem;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;">
                <legend style="font-weight:600;margin-bottom:.5rem;">Stornieren</legend>
                <p class="text-muted" style="font-size:.85rem;margin-bottom:.75rem;">
                    Markiert die Anfrage intern als storniert (z. B. auf Kundenwunsch). Es wird keine E-Mail versendet.
                </p>
                <form method="post" action="booking_view.php?id=<?= $id ?>"
                      onsubmit="return confirm('Diese Terminanfrage wirklich stornieren?');">
                    <?= csrf_field() ?>
                    <button type="submit" name="do" value="cancel" class="btn btn-outline btn-sm">
                        <?= svg_icon('x', 15) ?> Stornieren
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Interne Notiz ──────────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('edit', 18) ?> Interne Notiz</h3>
    </div>
    <div class="card-body">
        <form method="post" action="booking_view.php?id=<?= $id ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <textarea name="admin_note" rows="3" placeholder="Interne Notiz zu dieser Anfrage …"><?= h($booking['admin_note'] ?? '') ?></textarea>
            </div>
            <button type="submit" name="do" value="save_note" class="btn btn-outline btn-sm">
                <?= svg_icon('save', 15) ?> Notiz speichern
            </button>
        </form>
    </div>
</div>

<?php
$extra_js = <<<'CSS'
<style>
.page-header-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .75rem;
    margin-bottom: 1.25rem;
}
.page-header-info { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
.page-header-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
.page-title { font-size: 1.4rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: .4rem; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; }
.detail-list { display: grid; grid-template-columns: max-content 1fr; gap: .35rem .75rem; font-size: .9rem; }
.detail-list dt { font-weight: 600; color: var(--text-muted, #666); white-space: nowrap; }
.detail-list dd { margin: 0; }
.btn-xs { font-size: .75rem; padding: .2rem .5rem; }
.btn-green { background: #dcfce7; color: #15803d; border-color: #86efac; }
.btn-green:hover { background: #bbf7d0; }
</style>
CSS;
require_once __DIR__ . '/includes/footer.php';
?>
