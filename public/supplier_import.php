<?php
/**
 * MZ Tech – Universeller Import-Assistent (Phase 6, Auftragsabschnitt 4)
 * ----------------------------------------------------------------------
 * 7-Schritte-Ablauf:
 *   1. Quelle wählen (Datei-Upload oder Abruf über ein hinterlegtes
 *      Lieferanten-Schnittstellenprofil)
 *   2. Format automatisch erkennen (mit manueller Korrekturmöglichkeit)
 *   3./4. Spaltenzuordnung gegen die Zielfelder + Speichern als
 *      wiederverwendbares Import-Profil
 *   5. Detaillierte Vorschau (neu/aktualisiert/unverändert/Duplikat/
 *      Fehler/Warnung) – erzeugt einen Import-Auftrag im Status
 *      "wartet_auf_freigabe"
 *   6. Explizite Freigabe (siehe public/suppliers_form.php, Abschnitt
 *      "Import-Historie" – bewusst als separater, gut sichtbarer
 *      Bestätigungsschritt außerhalb dieses Assistenten)
 *   7. Vollständiges Audit-Log + garantierter Rollback (ebenfalls dort,
 *      siehe import_job_apply()/import_job_rollback())
 *
 * Der Zustand zwischen den Schritten wird in der Session gehalten;
 * hochgeladene/abgerufene Rohdaten liegen dabei temporär unter
 * uploads/imports/tmp/ (siehe import_wizard_store_raw()).
 */
require_once __DIR__ . '/init.php';
require_permission('manage_imports');

const IMPORT_WIZARD_SESSION_KEY = 'import_wizard_v1';

function import_wizard_state(): array {
    return $_SESSION[IMPORT_WIZARD_SESSION_KEY] ?? [];
}
function import_wizard_set(array $state): void {
    $_SESSION[IMPORT_WIZARD_SESSION_KEY] = $state;
}
function import_wizard_reset(): void {
    unset($_SESSION[IMPORT_WIZARD_SESSION_KEY]);
}
function import_wizard_store_raw(string $raw, string $filename): string {
    $dir = UPLOAD_PATH . '/imports/tmp';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $token = bin2hex(random_bytes(16));
    $path = $dir . '/' . $token . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    file_put_contents($path, $raw);
    return $path;
}

$suppliers = suppliers_list(['status' => 'aktiv']);
$state = import_wizard_state();
$step = (int)($_GET['step'] ?? $state['step'] ?? 1);
$flashError = null;

if (isset($_GET['reset'])) {
    import_wizard_reset();
    header('Location: ' . url('supplier_import.php') . (isset($_GET['supplier_id']) ? '?supplier_id=' . (int)$_GET['supplier_id'] : ''));
    exit;
}

// ── Schritt 1: Quelle wählen ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['wizard_step'] ?? '') === '1') {
    verify_csrf();
    $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $sourceMode = $_POST['source_mode'] ?? 'upload';

    if ($sourceMode === 'upload') {
        if (empty($_FILES['import_file']['name']) || ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'Bitte eine Datei auswählen.';
        } elseif ($_FILES['import_file']['size'] > MAX_UPLOAD_SIZE) {
            $flashError = 'Datei ist zu groß (max. ' . round(MAX_UPLOAD_SIZE / 1024 / 1024) . ' MB).';
        } else {
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
            $filename = basename($_FILES['import_file']['name']);
            $path = import_wizard_store_raw($raw, $filename);
            $state = ['step' => 2, 'supplier_id' => $supplierId, 'source_path' => $path, 'source_filename' => $filename, 'source_type' => 'upload'];
            import_wizard_set($state);
            header('Location: ' . url('supplier_import.php') . '?step=2');
            exit;
        }
    } elseif ($sourceMode === 'adapter' && $supplierId) {
        $profiles = array_values(array_filter(supplier_interface_profiles_list($supplierId), fn($p) => $p['is_active']));
        if (empty($profiles)) {
            $flashError = 'Für diesen Lieferanten ist kein aktives Schnittstellenprofil hinterlegt.';
        } else {
            $profile = $profiles[0];
            $credentials = supplier_interface_credentials_decrypt($profile);
            $adapter = supplier_adapter_factory($profile['interface_type']);
            $result = $adapter->fetchPayload($profile, $credentials);
            unset($credentials);
            if (!$result['success'] || $result['raw'] === null) {
                $flashError = 'Abruf fehlgeschlagen: ' . $result['message'];
            } else {
                $filename = $result['filename'] ?: 'lieferant_daten';
                $path = import_wizard_store_raw($result['raw'], $filename);
                $state = ['step' => 2, 'supplier_id' => $supplierId, 'source_path' => $path, 'source_filename' => $filename, 'source_type' => 'adapter_sync', 'profile_format' => $profile['format'], 'profile_delimiter' => $profile['delimiter']];
                import_wizard_set($state);
                header('Location: ' . url('supplier_import.php') . '?step=2');
                exit;
            }
        }
    } else {
        $flashError = 'Bitte einen Lieferanten mit hinterlegter Schnittstelle wählen.';
    }
}

// ── Schritt 2: Format bestätigen ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['wizard_step'] ?? '') === '2') {
    verify_csrf();
    $state['format'] = $_POST['format'] ?: null;
    $state['delimiter'] = trim($_POST['delimiter'] ?? '') ?: null;
    $state['has_header'] = !empty($_POST['has_header']);
    $state['step'] = 3;
    import_wizard_set($state);
    header('Location: ' . url('supplier_import.php') . '?step=3');
    exit;
}

// ── Schritt 3/4: Spaltenzuordnung + Profil speichern ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['wizard_step'] ?? '') === '3') {
    verify_csrf();
    $mapping = [];
    foreach (import_target_fields() as $field) {
        $val = trim($_POST['map_' . $field['key']] ?? '');
        if ($val !== '') $mapping[$field['key']] = $val;
    }
    $state['mapping'] = $mapping;
    $state['dedupe_key'] = $_POST['dedupe_key'] ?? 'supplier_sku';
    $state['import_mode'] = $_POST['import_mode'] ?? 'neue_und_aktualisierung';
    $state['profile_name'] = trim($_POST['profile_name'] ?? '');
    $state['step'] = 5;
    import_wizard_set($state);
    header('Location: ' . url('supplier_import.php') . '?step=5');
    exit;
}

// ── Schritt 5: Vorschau anzeigen + Import-Auftrag erzeugen ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['wizard_step'] ?? '') === '5') {
    verify_csrf();
    $raw = file_get_contents($state['source_path']);
    $format = $state['format'] ?: import_format_detect($state['source_filename'], $raw);
    $parsed = import_parse_rows($format, $raw, ['delimiter' => $state['delimiter'], 'has_header' => $state['has_header']]);

    if ($parsed['error']) {
        $flashError = $parsed['error'];
    } elseif (empty($state['supplier_id'])) {
        $flashError = 'Bitte in Schritt 1 einen Lieferanten wählen (Angebote sind immer einem Lieferanten zugeordnet).';
    } else {
        $preview = import_build_preview((int)$state['supplier_id'], $parsed['header'], $parsed['rows'], $state['mapping'], $state['dedupe_key']);

        // Import-Profil speichern (wiederverwendbar für künftige Syncs)
        if (!empty($state['profile_name'])) {
            get_db()->prepare(
                'INSERT INTO import_profiles (supplier_id, name, source_format, delimiter, has_header, column_mapping_json, import_mode, dedupe_key, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $state['supplier_id'], $state['profile_name'], $format, $state['delimiter'], $state['has_header'] ? 1 : 0,
                json_encode($state['mapping'], JSON_UNESCAPED_UNICODE), $state['import_mode'], $state['dedupe_key'], $_SESSION['user_id'] ?? null,
            ]);
        }

        $jobId = import_job_create((int)$state['supplier_id'], null, $state['source_type'] ?? 'upload', $state['source_filename'], $state['source_path'], $preview, $_SESSION['user_id'] ?? null);
        import_wizard_reset();
        flash('success', 'Vorschau erstellt (Import-Auftrag #' . $jobId . '). Bitte jetzt in der Lieferantenansicht unter "Import-Historie" prüfen und explizit freigeben.');
        header('Location: ' . url('suppliers_form.php') . '?id=' . (int)$state['supplier_id'] . '#importe');
        exit;
    }
}

$state = import_wizard_state();
$step = $state['step'] ?? 1;
$preselectSupplier = (int)($_GET['supplier_id'] ?? 0);

$parsedPreview = null;
if ($step >= 2 && !empty($state['source_path']) && is_file($state['source_path'])) {
    $raw = file_get_contents($state['source_path']);
    $detectedFormat = $state['format'] ?? import_format_detect($state['source_filename'], $raw);
    $parsedPreview = import_parse_rows($detectedFormat, $raw, ['delimiter' => $state['delimiter'] ?? null, 'has_header' => $state['has_header'] ?? true]);
}

$page_title = 'Import-Assistent';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <a href="suppliers.php" class="btn btn-outline"><?= svg_icon('arrow-left', 18) ?> Zurück zu Lieferanten</a>
    <div class="toolbar-actions">
        <a href="supplier_import.php?reset=1<?= $preselectSupplier ? '&supplier_id=' . $preselectSupplier : '' ?>" class="btn btn-outline">Assistent neu starten</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('upload', 20) ?> Import-Assistent – Schritt <?= $step ?> von 5</h2>
    </div>
    <div class="card-body">
        <?php if ($flashError): ?>
            <div class="alert alert-danger"><?= h($flashError) ?></div>
        <?php endif; ?>

        <?php if ($step === 1 || $step > 5): ?>
        <h3 style="font-size:1rem;">Schritt 1: Quelle wählen</h3>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="wizard_step" value="1">
            <div class="form-group">
                <label>Lieferant</label>
                <select name="supplier_id" required>
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $preselectSupplier === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><input type="radio" name="source_mode" value="upload" checked style="width:auto;"> Datei manuell hochladen (CSV/TSV/TXT/XLSX/XML/JSON/ZIP)</label><br>
                <input type="file" name="import_file" accept=".csv,.tsv,.txt,.xlsx,.xls,.xml,.json,.zip">
            </div>
            <div class="form-group">
                <label><input type="radio" name="source_mode" value="adapter" style="width:auto;"> Über hinterlegte Lieferanten-Schnittstelle abrufen</label>
            </div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('arrow-right', 16) ?> Weiter</button>
        </form>

        <?php elseif ($step === 2 && $parsedPreview): ?>
        <h3 style="font-size:1rem;">Schritt 2: Format bestätigen</h3>
        <?php if ($parsedPreview['error']): ?>
            <div class="alert alert-danger"><?= h($parsedPreview['error']) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="wizard_step" value="2">
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:1rem;">
                <div class="form-group"><label>Erkanntes Format</label>
                    <select name="format">
                        <?php $detected = $state['format'] ?? import_format_detect($state['source_filename'], file_get_contents($state['source_path'])); ?>
                        <?php foreach (['csv','tsv','txt','xlsx','xml','json'] as $f): ?>
                            <option value="<?= $f ?>" <?= $detected === $f ? 'selected' : '' ?>><?= strtoupper($f) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Trennzeichen (nur CSV/TSV)</label><input type="text" name="delimiter" maxlength="3" value="<?= h($state['delimiter'] ?? '') ?>" placeholder=","></div>
                <div class="form-group"><label><input type="checkbox" name="has_header" <?= ($state['has_header'] ?? true) ? 'checked' : '' ?> style="width:auto;"> Erste Zeile enthält Spaltenüberschriften</label></div>
            </div>
            <?php if (!empty($parsedPreview['header'])): ?>
            <p class="text-muted">Erkannte Spalten: <?= h(implode(', ', $parsedPreview['header'])) ?></p>
            <p class="text-muted"><?= count($parsedPreview['rows']) ?> Datenzeile(n) gefunden.</p>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= svg_icon('arrow-right', 16) ?> Weiter</button>
        </form>

        <?php elseif ($step === 3 && $parsedPreview): ?>
        <h3 style="font-size:1rem;">Schritt 3/4: Spaltenzuordnung &amp; Import-Profil</h3>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="wizard_step" value="3">
            <div class="table-wrap" style="margin-bottom:1rem;">
                <table class="table">
                    <thead><tr><th>Zielfeld</th><th>Spalte in Ihrer Datei</th></tr></thead>
                    <tbody>
                    <?php foreach (import_target_fields() as $field): ?>
                        <tr>
                            <td><?= h($field['label']) ?><?= $field['required'] ? ' <span style="color:var(--red)">*</span>' : '' ?>
                                <div class="text-muted" style="font-size:.75rem;"><?= h($field['group']) ?></div></td>
                            <td>
                                <select name="map_<?= h($field['key']) ?>">
                                    <option value="">– nicht zuordnen –</option>
                                    <?php foreach ($parsedPreview['header'] as $col): ?>
                                        <option value="<?= h($col) ?>" <?= (($state['mapping'][$field['key']] ?? '') === $col || strcasecmp($col, $field['key']) === 0) ? 'selected' : '' ?>><?= h($col) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:1rem;">
                <div class="form-group"><label>Abgleichsschlüssel (Duplikaterkennung)</label>
                    <select name="dedupe_key">
                        <?php foreach (['supplier_sku' => 'Lieferanten-Artikelnummer', 'sku' => 'Eigene SKU', 'ean' => 'EAN', 'mpn' => 'MPN'] as $k => $l): ?>
                            <option value="<?= $k ?>"><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Import-Modus</label>
                    <select name="import_mode">
                        <option value="nur_neue">Nur neue Produkte</option>
                        <option value="neue_und_aktualisierung" selected>Neue + Preis-/Bestandsaktualisierung</option>
                        <option value="vollabgleich_mit_deaktivierung">Vollabgleich (fehlende Angebote markieren)</option>
                        <option value="nur_preise_aktualisieren">Nur Preise aktualisieren</option>
                    </select></div>
                <div class="form-group"><label>Als Profil speichern unter (optional)</label>
                    <input type="text" name="profile_name" placeholder="z.B. Standard-Preisliste"></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= svg_icon('arrow-right', 16) ?> Vorschau erstellen</button>
        </form>

        <?php elseif ($step === 5): ?>
        <h3 style="font-size:1rem;">Schritt 5: Vorschau erstellen &amp; Import-Auftrag anlegen</h3>
        <p class="text-muted">Es wird jetzt ein Import-Auftrag mit vollständiger Vorschau erzeugt. Es werden dabei
            <strong>noch keine Daten übernommen</strong> – das geschieht erst nach expliziter Freigabe in der
            Lieferantenansicht unter "Import-Historie".</p>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="wizard_step" value="5">
            <button type="submit" class="btn btn-primary"><?= svg_icon('check', 16) ?> Vorschau jetzt erstellen</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
