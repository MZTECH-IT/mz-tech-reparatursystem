<?php
/**
 * MZ Tech – E-Mail-Vorlagen (Admin only)
 *
 * Ermöglicht es, Betreff und Text aller automatischen Kunden-E-Mails ohne
 * Code-Änderung im Browser zu bearbeiten. Vorlagen liegen in der Tabelle
 * `email_templates` (status_key, subject, body, enabled) und decken drei
 * unabhängige Workflows ab:
 *   - Reparaturstatus (14-stufige Pipeline, siehe private/functions.php repair_valid_statuses())
 *   - Terminbuchung (öffentliche Terminanfrage, status_key-Präfix "buchung_")
 *   - Reparaturanfrage (öffentliches Formular ohne Termin, status_key-Präfix "ranfrage_")
 * Fehlt die Tabelle oder ein Eintrag, verwenden die jeweiligen send_*_email()-
 * Funktionen (siehe private/mailer.php) eine fest codierte Rückfall-Vorlage –
 * die Seite hier funktioniert daher auch dann sicher, wenn eine Migration
 * noch nicht eingespielt wurde.
 */
require_once __DIR__ . '/init.php';
require_admin();

$db = get_db();

// Tabelle defensiv anlegen, falls eine ältere Installation noch kein
// email_templates hat (z. B. Update ohne erneutes Einspielen von schema.sql).
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `email_templates` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `status_key`  VARCHAR(50)  NOT NULL,
            `subject`     VARCHAR(255) NOT NULL,
            `body`        TEXT         NOT NULL,
            `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
            `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_status_key` (`status_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    error_log('email_templates.php: Tabelle konnte nicht angelegt werden: ' . $e->getMessage());
}

$default_bodies = [
    // ── Reparaturstatus-Pipeline ───────────────────────────────────────
    'anfrage_eingegangen' => ['subject' => 'Ihre Anfrage bei MZ Tech ist eingegangen – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Anfrage bei MZ Tech. Wir haben Ihre Anfrage zu Ihrem Gerät ({{geraet}}) unter der Nummer <strong>{{auftragsnummer}}</strong> erfasst und melden uns in Kürze bei Ihnen.</p><p>Ihr MZ Tech Team</p>'],
    'termin_angefragt' => ['subject' => 'Terminanfrage erhalten – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>wir haben Ihre Terminanfrage erhalten und prüfen die Verfügbarkeit. Sie erhalten in Kürze eine Bestätigung.</p><p>Ihr MZ Tech Team</p>'],
    'termin_bestaetigt' => ['subject' => 'Ihr Termin wurde bestätigt – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin für {{geraet}} wurde bestätigt. Wir freuen uns auf Ihren Besuch.</p><p>Ihr MZ Tech Team</p>'],
    'angenommen' => ['subject' => 'Ihr Gerät wurde angenommen – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>wir haben Ihr Gerät ({{hersteller}} {{modell}}) unter der Auftragsnummer <strong>{{auftragsnummer}}</strong> entgegengenommen. Der aktuelle Status ist: {{status}}.</p><p>Ihr MZ Tech Team</p>'],
    'diagnose' => ['subject' => 'Diagnose läuft – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>wir prüfen aktuell Ihr Gerät ({{geraet}}) und melden uns mit dem Ergebnis der Diagnose.</p><p>Ihr MZ Tech Team</p>'],
    'kostenvoranschlag' => ['subject' => 'Kostenvoranschlag verfügbar – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihren Auftrag {{auftragsnummer}} liegt nun ein Kostenvoranschlag vor. Bitte nutzen Sie das Kundenportal, um diesen einzusehen und freizugeben.</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Jetzt im Kundenportal ansehen &amp; freigeben</a></p><p>Ihr MZ Tech Team</p>'],
    'freigabe_ausstehend' => ['subject' => 'Freigabe erforderlich – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>wir warten auf Ihre Freigabe zum Kostenvoranschlag für Auftrag {{auftragsnummer}}, um mit der Reparatur fortzufahren.</p><p><a href="{{portal_link}}" style="color:#0057B8;font-weight:bold;">Jetzt im Kundenportal ansehen &amp; freigeben</a></p><p>Ihr MZ Tech Team</p>'],
    'ersatzteil_bestellt' => ['subject' => 'Ersatzteil wurde bestellt – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>für Ihren Auftrag {{auftragsnummer}} wurde ein benötigtes Ersatzteil bestellt. Sobald es eingetroffen ist, setzen wir die Reparatur fort.</p><p>Ihr MZ Tech Team</p>'],
    'in_reparatur' => ['subject' => 'Ihr Gerät wird repariert – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>die Reparatur Ihres Geräts ({{geraet}}) hat begonnen.</p><p>Ihr MZ Tech Team</p>'],
    'funktionstest' => ['subject' => 'Funktionstest läuft – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Gerät befindet sich aktuell im abschließenden Funktionstest.</p><p>Ihr MZ Tech Team</p>'],
    'fertig' => ['subject' => 'Ihr Gerät ist fertig – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>gute Nachrichten: Ihr Gerät ({{geraet}}) ist fertig repariert.</p><p>Ihr MZ Tech Team</p>'],
    'abholbereit' => ['subject' => 'Ihr Gerät ist abholbereit – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Gerät ({{geraet}}) ist abholbereit. Sie können es zu unseren Öffnungszeiten bei uns abholen.</p><p>Ihr MZ Tech Team</p>'],
    'abgeholt' => ['subject' => 'Vielen Dank für Ihren Besuch – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank, dass Sie Ihr Gerät ({{geraet}}) bei uns abgeholt haben. Wir wünschen Ihnen viel Freude damit!</p><p>Ihr MZ Tech Team</p>'],
    'storniert' => ['subject' => 'Ihr Auftrag wurde storniert – Auftrag #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Auftrag {{auftragsnummer}} wurde storniert. Bei Fragen kontaktieren Sie uns gerne.</p><p>Ihr MZ Tech Team</p>'],

    // ── Terminbuchung (öffentliche Terminanfrage) ──────────────────────
    'buchung_angefragt' => ['subject' => 'Ihre Terminanfrage bei MZ Tech – #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Terminanfrage bei MZ Tech. Ihre Wunschzeit: <strong>{{status}}</strong>.</p><p>Wir prüfen die Verfügbarkeit und bestätigen Ihnen den Termin in Kürze per E-Mail. Ihre Anfragenummer lautet <strong>{{auftragsnummer}}</strong>.</p><p>Ihr MZ Tech Team</p>'],
    'buchung_bestaetigt' => ['subject' => 'Ihr Termin wurde bestätigt – #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin bei MZ Tech wurde bestätigt: <strong>{{status}}</strong>.</p><p>Wir freuen uns auf Ihren Besuch. Anfragenummer: {{auftragsnummer}}.</p><p>Ihr MZ Tech Team</p>'],
    'buchung_abgelehnt' => ['subject' => 'Ihre Terminanfrage konnte leider nicht bestätigt werden – #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>leider können wir Ihre Terminanfrage ({{auftragsnummer}}) zum gewünschten Zeitpunkt nicht bestätigen. Bitte wählen Sie gerne einen anderen Termin oder kontaktieren Sie uns direkt.</p><p>Ihr MZ Tech Team</p>'],
    'buchung_umgeplant' => ['subject' => 'Ihr Termin wurde verschoben – #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>Ihr Termin bei MZ Tech wurde auf einen neuen Zeitpunkt verschoben: <strong>{{status}}</strong>.</p><p>Anfragenummer: {{auftragsnummer}}.</p><p>Ihr MZ Tech Team</p>'],

    // ── Reparaturanfrage (öffentliches Formular ohne Termin) ───────────
    'ranfrage_neu' => ['subject' => 'Ihre Reparaturanfrage bei MZ Tech – #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>vielen Dank für Ihre Reparaturanfrage bei MZ Tech für Ihr Gerät: <strong>{{geraet}}</strong>.</p><p>Wir prüfen Ihre Anfrage und melden uns in Kürze bei Ihnen. Ihre Anfragenummer lautet <strong>{{auftragsnummer}}</strong>.</p><p>Ihr MZ Tech Team</p>'],
    'ranfrage_abgelehnt' => ['subject' => 'Ihre Reparaturanfrage – #{{auftragsnummer}}',
        'body' => '<p>Hallo {{vorname}} {{nachname}},</p><p>leider können wir Ihre Reparaturanfrage ({{auftragsnummer}}) nicht bearbeiten. Bei Fragen kontaktieren Sie uns gerne direkt.</p><p>Ihr MZ Tech Team</p>'],
];

// ── Gruppen: fassen die drei unabhängigen E-Mail-Workflows für die Anzeige
// zusammen. Jede Gruppe kennt ihre gültigen status_key-Werte sowie eigene
// Label-/Badge-Funktionen (die bestehenden Domänenfunktionen aus
// functions.php werden wiederverwendet, damit Label/Badge konsistent mit
// dem Rest der Anwendung bleiben).
$groups = [
    [
        'title' => 'Reparaturstatus (automatische E-Mails im Reparaturverlauf)',
        'keys'  => repair_valid_statuses(),
        'label' => static fn(string $k): string => repair_status_label($k),
        'badge' => static fn(string $k): string => repair_status_badge($k),
    ],
    [
        'title' => 'Terminbuchung (öffentliche Terminanfrage ohne Login)',
        'keys'  => ['buchung_angefragt', 'buchung_bestaetigt', 'buchung_abgelehnt', 'buchung_umgeplant'],
        'label' => static fn(string $k): string => booking_status_label(substr($k, 8)),
        'badge' => static fn(string $k): string => booking_status_badge(substr($k, 8)),
    ],
    [
        'title' => 'Reparaturanfrage (öffentliches Formular ohne Termin)',
        'keys'  => ['ranfrage_neu', 'ranfrage_abgelehnt'],
        'label' => static fn(string $k): string => repair_request_status_label(substr($k, 9)),
        'badge' => static fn(string $k): string => repair_request_status_badge(substr($k, 9)),
    ],
];

$all_keys = [];
foreach ($groups as $g) {
    $all_keys = array_merge($all_keys, $g['keys']);
}

// Ermittelt Anzeigename für einen status_key gruppenübergreifend (für Flash-Meldungen).
$key_label = static function (string $status_key) use ($groups): string {
    foreach ($groups as $g) {
        if (in_array($status_key, $g['keys'], true)) {
            return ($g['label'])($status_key);
        }
    }
    return $status_key;
};

// Fehlende Vorlagen (z. B. nach einem Update) automatisch mit Standardtext anlegen
try {
    $existing_stmt = $db->query('SELECT status_key FROM email_templates');
    $existing = $existing_stmt->fetchAll(PDO::FETCH_COLUMN);
    $ins = $db->prepare('INSERT INTO email_templates (status_key, subject, body, enabled) VALUES (?, ?, ?, 1)');
    foreach ($all_keys as $sk) {
        if (!in_array($sk, $existing, true) && isset($default_bodies[$sk])) {
            $ins->execute([$sk, $default_bodies[$sk]['subject'], $default_bodies[$sk]['body']]);
        }
    }
} catch (Throwable $e) {
    error_log('email_templates.php: Standardvorlagen konnten nicht ergänzt werden: ' . $e->getMessage());
}

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $status_key = trim($_POST['status_key'] ?? '');
        $subject    = trim($_POST['subject'] ?? '');
        $body       = trim($_POST['body'] ?? '');
        $enabled    = isset($_POST['enabled']) ? 1 : 0;

        if (!in_array($status_key, $all_keys, true)) {
            flash('error', 'Ungültiger Status.');
            header('Location: ' . url('email_templates.php'));
            exit;
        }
        if ($subject === '' || $body === '') {
            flash('error', 'Betreff und Text dürfen nicht leer sein.');
            header('Location: ' . url('email_templates.php'));
            exit;
        }

        $stmt = $db->prepare(
            'INSERT INTO email_templates (status_key, subject, body, enabled)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), enabled = VALUES(enabled)'
        );
        $stmt->execute([$status_key, $subject, $body, $enabled]);

        log_activity('update', 'email_templates', null, 'Vorlage aktualisiert: ' . $status_key);
        flash('success', 'Vorlage für „' . h($key_label($status_key)) . '" wurde gespeichert.');
        header('Location: ' . url('email_templates.php'));
        exit;
    }

    if ($action === 'reset') {
        $status_key = trim($_POST['status_key'] ?? '');
        if (in_array($status_key, $all_keys, true) && isset($default_bodies[$status_key])) {
            $stmt = $db->prepare(
                'INSERT INTO email_templates (status_key, subject, body, enabled)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), enabled = 1'
            );
            $stmt->execute([$status_key, $default_bodies[$status_key]['subject'], $default_bodies[$status_key]['body']]);
            log_activity('reset', 'email_templates', null, 'Vorlage zurückgesetzt: ' . $status_key);
            flash('success', 'Vorlage wurde auf den Standardtext zurückgesetzt.');
        }
        header('Location: ' . url('email_templates.php'));
        exit;
    }
}

// ── Vorlagen laden ─────────────────────────────────────────────────────────────
$templates_stmt = $db->query('SELECT * FROM email_templates');
$templates_raw  = $templates_stmt->fetchAll(PDO::FETCH_ASSOC);
$templates_by_key = [];
foreach ($templates_raw as $t) {
    $templates_by_key[$t['status_key']] = $t;
}

$page_title = 'E-Mail-Vorlagen';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">E-Mail-Vorlagen</h1>
    <p class="page-subtitle">Automatische Kunden-E-Mails bearbeiten. Platzhalter: <code>{{vorname}}</code>, <code>{{nachname}}</code>, <code>{{firma}}</code>, <code>{{auftragsnummer}}</code>, <code>{{status}}</code>, <code>{{geraet}}</code>, <code>{{hersteller}}</code>, <code>{{modell}}</code>, <code>{{portal_link}}</code></p>
  </div>
</div>

<?php show_flash(); ?>

<div class="card" style="margin-bottom:24px;">
  <div class="card-body">
    <p style="margin:0;color:#4B5563;">
      Für jeden Status kann eine E-Mail-Vorlage hinterlegt werden. Ist eine Vorlage
      deaktiviert, wird bei diesem Statuswechsel keine automatische E-Mail versendet.
      Änderungen wirken sich sofort auf alle künftigen Status-E-Mails aus. Die Vorlagen
      sind nach Workflow gruppiert: Reparaturstatus, Terminbuchung und Reparaturanfrage.
    </p>
  </div>
</div>

<?php foreach ($groups as $group): ?>
<h2 style="margin:32px 0 12px;font-size:1.1rem;color:#1F2937;"><?= h($group['title']) ?></h2>

<?php foreach ($group['keys'] as $status_key):
    $t = $templates_by_key[$status_key] ?? null;
    $subject = $t['subject'] ?? ($default_bodies[$status_key]['subject'] ?? '');
    $body    = $t['body']    ?? ($default_bodies[$status_key]['body']    ?? '');
    $enabled = $t !== null ? (int)$t['enabled'] : 1;
?>
<div class="card" style="margin-bottom:16px;">
  <div class="card-header">
    <h3 class="card-title"><?= ($group['badge'])($status_key) ?> <?= h(($group['label'])($status_key)) ?></h3>
    <label style="display:flex;align-items:center;gap:6px;font-size:.875rem;font-weight:500;cursor:pointer;">
      <?= $enabled ? 'Aktiv' : 'Deaktiviert' ?>
    </label>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="status_key" value="<?= h($status_key) ?>">

      <div class="form-group">
        <label>
          <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
          E-Mail bei diesem Status automatisch versenden
        </label>
      </div>

      <div class="form-group">
        <label for="subject_<?= h($status_key) ?>">Betreff</label>
        <input type="text" id="subject_<?= h($status_key) ?>" name="subject" value="<?= h($subject) ?>" required maxlength="255" style="width:100%;">
      </div>

      <div class="form-group">
        <label for="body_<?= h($status_key) ?>">Text (HTML erlaubt)</label>
        <textarea id="body_<?= h($status_key) ?>" name="body" rows="5" style="width:100%;font-family:monospace;font-size:.85rem;"><?= h($body) ?></textarea>
      </div>

      <div style="display:flex;gap:.5rem;">
        <button type="submit" class="btn btn-primary btn-sm"><?= svg_icon('check', 16) ?> Speichern</button>
      </div>
    </form>
    <form method="post" onsubmit="return confirm('Vorlage auf den Standardtext zurücksetzen?');" style="margin-top:.5rem;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset">
      <input type="hidden" name="status_key" value="<?= h($status_key) ?>">
      <button type="submit" class="btn btn-sm btn-outline"><?= svg_icon('refresh-cw', 16) ?> Auf Standard zurücksetzen</button>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
