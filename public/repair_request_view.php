<?php
require_once __DIR__ . '/init.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    flash('error', 'Keine Anfrage-ID angegeben.');
    header('Location: repair_requests.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM repair_requests WHERE id = ?');
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    flash('error', 'Reparaturanfrage nicht gefunden.');
    header('Location: repair_requests.php');
    exit;
}

$valid_statuses = repair_request_valid_statuses();

// ── Vom Kunden hochgeladene Fotos ─────────────────────────────────────────
$req_photos_stmt = $db->prepare('SELECT * FROM repair_request_photos WHERE request_id = ? ORDER BY created_at ASC');
$req_photos_stmt->execute([$id]);
$request_photos = $req_photos_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── POST: Aktionen ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = trim($_POST['do'] ?? '');
    $now = date('Y-m-d H:i:s');

    // Interne Notiz (unabhängig vom Status) speichern
    if ($do === 'save_note') {
        $note = trim($_POST['admin_note'] ?? '');
        $db->prepare('UPDATE repair_requests SET admin_note = ?, updated_at = ? WHERE id = ?')
           ->execute([$note ?: null, $now, $id]);
        log_activity('repair_request_note', 'repair_requests', $id, 'Notiz aktualisiert');
        flash('success', 'Notiz wurde gespeichert.');
        header('Location: repair_request_view.php?id=' . $id);
        exit;
    }

    // Ablehnen
    if ($do === 'reject') {
        $note = trim($_POST['admin_note'] ?? '');

        $db->prepare('UPDATE repair_requests SET status = ?, admin_note = ?, updated_at = ? WHERE id = ?')
           ->execute(['abgelehnt', $note ?: null, $now, $id]);

        log_activity('repair_request_rejected', 'repair_requests', $id, 'abgelehnt');

        try {
            $stmt2 = $db->prepare('SELECT * FROM repair_requests WHERE id = ?');
            $stmt2->execute([$id]);
            $fresh = $stmt2->fetch(PDO::FETCH_ASSOC);
            send_repair_request_email($fresh, 'abgelehnt');
        } catch (Throwable $e) {
            error_log('repair_request_view.php Mailversand: ' . $e->getMessage());
        }

        flash('success', 'Reparaturanfrage wurde abgelehnt und der Kunde per E-Mail benachrichtigt.');
        header('Location: repair_request_view.php?id=' . $id);
        exit;
    }

    // Archivieren (intern, keine Kunden-E-Mail vorgesehen)
    if ($do === 'archive') {
        $db->prepare('UPDATE repair_requests SET status = ?, updated_at = ? WHERE id = ?')
           ->execute(['archiviert', $now, $id]);
        log_activity('repair_request_archived', 'repair_requests', $id, 'archiviert');
        flash('success', 'Reparaturanfrage wurde archiviert.');
        header('Location: repair_request_view.php?id=' . $id);
        exit;
    }

    // In Reparatur umwandeln
    if ($do === 'convert_to_repair') {
        if ($request['repair_id']) {
            flash('error', 'Diese Anfrage wurde bereits in eine Reparatur umgewandelt.');
            header('Location: repair_request_view.php?id=' . $id);
            exit;
        }

        // Kunde finden (per E-Mail) oder neu anlegen
        $customer_id = (int)($request['customer_id'] ?? 0);
        if (!$customer_id && $request['email']) {
            $cs = $db->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            $cs->execute([$request['email']]);
            $found = $cs->fetch(PDO::FETCH_COLUMN);
            if ($found) $customer_id = (int)$found;
        }
        if (!$customer_id) {
            $db->prepare(
                'INSERT INTO customers (first_name, last_name, phone, email, notes, gdpr_consent, gdpr_date, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $request['first_name'],
                $request['last_name'],
                $request['phone'] ?: null,
                $request['email'] ?: null,
                $request['company'] ? ('Firma: ' . $request['company']) : null,
                (int)$request['privacy_consent'],
                $request['privacy_consent'] ? date('Y-m-d') : null,
                $_SESSION['user_id'] ?? null,
                $now,
                $now,
            ]);
            $customer_id = (int)$db->lastInsertId();
            log_activity('create', 'customers', $customer_id, 'Automatisch aus Reparaturanfrage ' . $request['request_number']);
        }

        // Reparatur anlegen
        $repair_number = generate_repair_number();
        $device_label  = device_type_label($request['device_type']);

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
            $request['manufacturer'] ?: null,
            $request['model'] ?: null,
            $request['problem_description'] ?: '',
            'angenommen',
            'Angelegt aus Online-Reparaturanfrage ' . $request['request_number'],
            $_SESSION['user_id'] ?? null,
            $now,
            $now,
        ]);
        $repair_id = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO repair_status_history (repair_id, status, user_id, created_at) VALUES (?, ?, ?, ?)')
           ->execute([$repair_id, 'angenommen', $_SESSION['user_id'] ?? null, $now]);

        $db->prepare('UPDATE repair_requests SET status = ?, customer_id = ?, repair_id = ?, updated_at = ? WHERE id = ?')
           ->execute(['umgewandelt', $customer_id, $repair_id, $now, $id]);

        // Vom Kunden bei der Anfrage hochgeladene Fotos in den neuen
        // Reparaturauftrag übernehmen, damit sie dort nicht verloren gehen.
        $req_photos_stmt = $db->prepare('SELECT * FROM repair_request_photos WHERE request_id = ?');
        $req_photos_stmt->execute([$id]);
        $carry_photos = $req_photos_stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($carry_photos) {
            $src_dir  = UPLOAD_PATH . '/requests/' . $id;
            $dest_dir = UPLOAD_PATH . '/repairs/' . $repair_id;
            if (!is_dir($dest_dir)) mkdir($dest_dir, 0750, true);
            $photo_insert = $db->prepare(
                'INSERT INTO repair_photos (repair_id, filename, original_name, photo_type, description, file_size, uploaded_by, source, created_at)
                 VALUES (?, ?, ?, "sonstiges", ?, ?, NULL, "customer", ?)'
            );
            foreach ($carry_photos as $rp) {
                $src_path = $src_dir . '/' . basename($rp['filename']);
                if (!is_file($src_path)) continue;
                $ext = strtolower(pathinfo($rp['filename'], PATHINFO_EXTENSION));
                $new_filename = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
                if (@copy($src_path, $dest_dir . '/' . $new_filename)) {
                    $photo_insert->execute([
                        $repair_id,
                        $new_filename,
                        $rp['original_name'],
                        'Vom Kunden bei der Online-Anfrage hochgeladen',
                        $rp['file_size'],
                        $rp['created_at'],
                    ]);
                }
            }
        }

        log_activity('repair_request_converted', 'repair_requests', $id, 'Reparatur ' . $repair_number . ' angelegt');
        flash('success', 'Reparaturanfrage wurde in Reparatur ' . h($repair_number) . ' umgewandelt.');
        header('Location: repairs_view.php?id=' . $repair_id);
        exit;
    }

    header('Location: repair_request_view.php?id=' . $id);
    exit;
}

$geraet = trim(($request['manufacturer'] ?? '') . ' ' . ($request['model'] ?? '')) ?: device_type_label($request['device_type']);
$wa_msg = 'Hallo ' . ($request['first_name'] ?? '') . ', vielen Dank für Ihre Reparaturanfrage (' . ($request['request_number'] ?? '') . ') bei uns!';
$wa_link = ($request['phone'] ?? '') ? whatsapp_link($request['phone'], $wa_msg) : '';

$page_title = 'Reparaturanfrage ' . h($request['request_number']);
require_once __DIR__ . '/includes/header.php';
?>

<?php show_flash(); ?>

<!-- ── Kopfzeile ──────────────────────────────────────────────────────────── -->
<div class="page-header-bar">
    <div class="page-header-info">
        <a href="repair_requests.php" class="btn btn-outline btn-sm">
            <?= svg_icon('arrow-left', 16) ?> Zurück
        </a>
        <h1 class="page-title">
            <?= svg_icon('inbox', 22) ?>
            <?= h($request['request_number']) ?>
        </h1>
        <?= repair_request_status_badge($request['status']) ?>
    </div>
    <div class="page-header-actions">
        <?php if (!empty($request['email'])): ?>
            <a href="mailto:<?= h($request['email']) ?>?subject=<?= rawurlencode('Ihre Reparaturanfrage ' . ($request['request_number'] ?? '')) ?>" class="btn btn-outline btn-sm">
                <?= svg_icon('mail', 15) ?> E-Mail
            </a>
        <?php endif; ?>
        <?php if ($wa_link): ?>
            <a href="<?= h($wa_link) ?>" target="_blank" class="btn btn-sm btn-green">
                <?= svg_icon('whatsapp', 15) ?> WhatsApp
            </a>
        <?php endif; ?>
        <?php if (!empty($request['repair_id'])): ?>
            <a href="repairs_view.php?id=<?= (int)$request['repair_id'] ?>" class="btn btn-primary btn-sm">
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
                <dd><?= h(trim($request['first_name'] . ' ' . $request['last_name'])) ?></dd>

                <?php if ($request['company']): ?>
                    <dt>Firma</dt>
                    <dd><?= h($request['company']) ?></dd>
                <?php endif; ?>

                <dt>E-Mail</dt>
                <dd><a href="mailto:<?= h($request['email']) ?>"><?= h($request['email']) ?></a></dd>

                <?php if ($request['phone']): ?>
                    <dt>Telefon</dt>
                    <dd>
                        <a href="tel:<?= h($request['phone']) ?>"><?= h($request['phone']) ?></a>
                        <?php if ($wa_link): ?>
                            <a href="<?= h($wa_link) ?>" target="_blank" class="btn btn-xs btn-green" title="WhatsApp" style="margin-left:.5rem;">
                                <?= svg_icon('whatsapp', 14) ?> WhatsApp
                            </a>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($request['customer_id']): ?>
                    <dt>Kundendatensatz</dt>
                    <dd><a href="repairs.php?customer_id=<?= (int)$request['customer_id'] ?>">Bereits bekannter Kunde</a></dd>
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
                <dd><?= h(device_type_label($request['device_type'])) ?></dd>

                <?php if ($request['manufacturer']): ?>
                    <dt>Hersteller</dt>
                    <dd><?= h($request['manufacturer']) ?></dd>
                <?php endif; ?>

                <?php if ($request['model']): ?>
                    <dt>Modell</dt>
                    <dd><?= h($request['model']) ?></dd>
                <?php endif; ?>

                <dt>Problembeschreibung</dt>
                <dd style="white-space:pre-wrap;"><?= h($request['problem_description'] ?: '—') ?></dd>
            </dl>
        </div>
    </div>

    <!-- Details -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= svg_icon('clipboard', 18) ?> Details</h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Datenschutz</dt>
                <dd><?= $request['privacy_consent'] ? 'Zugestimmt' : 'Nicht zugestimmt' ?></dd>

                <dt>Newsletter/Info</dt>
                <dd><?= $request['marketing_consent'] ? 'Zugestimmt' : 'Nicht zugestimmt' ?></dd>

                <dt>Eingegangen am</dt>
                <dd><?= h(fmt_date($request['created_at'], true)) ?></dd>

                <?php if ($request['ip_address']): ?>
                    <dt>IP-Adresse</dt>
                    <dd><code><?= h($request['ip_address']) ?></code></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

</div><!-- /.detail-grid -->

<?php if (!empty($request_photos)): ?>
<!-- ── Vom Kunden hochgeladene Fotos ──────────────────────────────────────── -->
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('image', 18) ?> Fotos vom Kunden <span class="badge-secondary" style="font-weight:400;"><?= count($request_photos) ?></span></h3>
    </div>
    <div class="card-body">
        <div class="photo-grid">
            <?php foreach ($request_photos as $photo): ?>
                <?php $photo_url = url('api/repairs.php') . '?action=get_request_photo&id=' . (int)$photo['id']; ?>
                <div class="photo-thumb">
                    <img
                        src="<?= h($photo_url) ?>"
                        alt="<?= h($photo['original_name'] ?? 'Foto') ?>"
                        loading="lazy"
                        onclick="openLightbox('<?= h($photo_url) ?>')"
                        title="<?= h($photo['original_name'] ?? '') ?>"
                    >
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!in_array($request['status'], ['umgewandelt'], true)): ?>
<!-- ── Aktionen ───────────────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3 class="card-title"><?= svg_icon('check', 18) ?> Anfrage bearbeiten</h3>
    </div>
    <div class="card-body">
        <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem;">

            <!-- In Reparatur umwandeln -->
            <div class="form-section" style="margin:0;padding:1rem;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;">
                <legend style="font-weight:600;margin-bottom:.5rem;">Reparatur anlegen</legend>
                <p class="text-muted" style="font-size:.85rem;margin-bottom:.75rem;">
                    Legt einen Kunden (falls neu) und einen Reparaturauftrag mit den Daten dieser Anfrage an.
                </p>
                <form method="post" action="repair_request_view.php?id=<?= $id ?>"
                      onsubmit="return confirm('Aus dieser Anfrage einen Reparaturauftrag anlegen?');">
                    <?= csrf_field() ?>
                    <button type="submit" name="do" value="convert_to_repair" class="btn btn-outline btn-sm">
                        <?= svg_icon('wrench', 15) ?> In Reparatur umwandeln
                    </button>
                </form>
            </div>

            <!-- Ablehnen -->
            <?php if ($request['status'] !== 'abgelehnt'): ?>
            <form method="post" action="repair_request_view.php?id=<?= $id ?>" class="form-section" style="margin:0;padding:1rem;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;"
                  onsubmit="return confirm('Diese Reparaturanfrage wirklich ablehnen? Der Kunde erhält eine E-Mail.');">
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
            <?php endif; ?>

            <!-- Archivieren -->
            <?php if ($request['status'] !== 'archiviert'): ?>
            <div class="form-section" style="margin:0;padding:1rem;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;">
                <legend style="font-weight:600;margin-bottom:.5rem;">Archivieren</legend>
                <p class="text-muted" style="font-size:.85rem;margin-bottom:.75rem;">
                    Markiert die Anfrage intern als archiviert (z. B. doppelte oder erledigte Anfrage). Es wird keine E-Mail versendet.
                </p>
                <form method="post" action="repair_request_view.php?id=<?= $id ?>"
                      onsubmit="return confirm('Diese Reparaturanfrage wirklich archivieren?');">
                    <?= csrf_field() ?>
                    <button type="submit" name="do" value="archive" class="btn btn-outline btn-sm">
                        <?= svg_icon('x', 15) ?> Archivieren
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
        <form method="post" action="repair_request_view.php?id=<?= $id ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <textarea name="admin_note" rows="3" placeholder="Interne Notiz zu dieser Anfrage …"><?= h($request['admin_note'] ?? '') ?></textarea>
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
