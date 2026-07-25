<?php
/**
 * MZ Tech – Backup-Verwaltung (Admin only)
 */
require_once __DIR__ . '/init.php';
require_admin();

// BACKUP_PATH ist in config.php definiert
if (!defined('BACKUP_PATH')) {
    define('BACKUP_PATH', BASE_PATH . '/backups');
}

// Sicherstellen dass Verzeichnis existiert
if (!is_dir(BACKUP_PATH)) {
    mkdir(BACKUP_PATH, 0750, true);
}

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── Backup erstellen ──
    if ($action === 'create') {
        try {
            $db       = get_db();
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql.gz';
            $filepath = BACKUP_PATH . '/' . $filename;

            // Alle Tabellen ermitteln
            $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($tables)) {
                throw new RuntimeException('Keine Tabellen in der Datenbank gefunden.');
            }

            $sql = "-- MZ Tech Backup\n";
            $sql .= "-- Erstellt: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Datenbank: " . DB_NAME . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
            $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
            $sql .= "SET NAMES utf8mb4;\n\n";

            foreach ($tables as $table) {
                // CREATE TABLE Statement
                $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
                $sql .= "-- Tabelle: `$table`\n";
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= $create[1] . ";\n\n";

                // Daten exportieren
                $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $cols = array_map(fn($c) => "`$c`", array_keys($rows[0]));
                    $sql .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES\n";
                    $value_rows = [];
                    foreach ($rows as $row) {
                        $vals = array_map(function ($v) use ($db) {
                            if ($v === null) return 'NULL';
                            return $db->quote((string)$v);
                        }, array_values($row));
                        $value_rows[] = '(' . implode(', ', $vals) . ')';
                        // Flush in chunks to avoid memory issues
                        if (count($value_rows) >= 500) {
                            $sql .= implode(",\n", $value_rows) . ";\n";
                            $sql .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES\n";
                            $value_rows = [];
                        }
                    }
                    if (!empty($value_rows)) {
                        $sql .= implode(",\n", $value_rows) . ";\n";
                    }
                    $sql .= "\n";
                }
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            // Mit gzip komprimieren
            $gz = gzopen($filepath, 'wb9');
            if (!$gz) {
                throw new RuntimeException('Konnte Backup-Datei nicht erstellen: ' . $filepath);
            }
            gzwrite($gz, $sql);
            gzclose($gz);

            log_activity('backup_created', 'backup', null, 'Backup: ' . $filename);
            flash('success', 'Backup erfolgreich erstellt: ' . h($filename));
        } catch (Throwable $e) {
            error_log('Backup-Fehler: ' . $e->getMessage());
            flash('error', 'Backup-Fehler: ' . h($e->getMessage()));
        }
        header('Location: ' . url('backup.php'));
        exit;
    }

    // ── Backup herunterladen ──
    if ($action === 'download') {
        $file = basename($_POST['file'] ?? '');
        $path = BACKUP_PATH . '/' . $file;

        // Sicherheitsprüfung: nur .sql.gz Dateien, die tatsächlich im BACKUP_PATH liegen
        if (!$file || !preg_match('/^backup_[\d_\-]+\.sql\.gz$/', $file) || !file_exists($path)) {
            flash('error', 'Datei nicht gefunden.');
            header('Location: ' . url('backup.php'));
            exit;
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('Pragma: no-cache');
        header('Cache-Control: private');
        readfile($path);
        exit;
    }

    // ── Backup löschen ──
    if ($action === 'delete') {
        $file = basename($_POST['file'] ?? '');
        $path = BACKUP_PATH . '/' . $file;

        if (!$file || !preg_match('/^backup_[\d_\-]+\.sql\.gz$/', $file) || !file_exists($path)) {
            flash('error', 'Datei nicht gefunden.');
            header('Location: ' . url('backup.php'));
            exit;
        }

        if (unlink($path)) {
            log_activity('backup_deleted', 'backup', null, 'Backup gelöscht: ' . $file);
            flash('success', 'Backup erfolgreich gelöscht.');
        } else {
            flash('error', 'Backup konnte nicht gelöscht werden.');
        }
        header('Location: ' . url('backup.php'));
        exit;
    }
}

// ── Backup-Dateien einlesen ───────────────────────────────────────────────────
$backup_files = [];
$total_size   = 0;

if (is_dir(BACKUP_PATH)) {
    $files = glob(BACKUP_PATH . '/backup_*.sql.gz');
    if ($files) {
        // Nach Änderungszeit sortieren (neueste zuerst)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach ($files as $f) {
            $size          = filesize($f);
            $total_size   += $size;
            $backup_files[] = [
                'name'    => basename($f),
                'size'    => $size,
                'mtime'   => filemtime($f),
                'path'    => $f,
            ];
        }
    }
}

// Nur letzte 10 anzeigen
$display_files = array_slice($backup_files, 0, 10);

// Hilfsfunktion: Dateigröße lesbar
function fmt_filesize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}

$page_title = 'Backup';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Backup-Verwaltung</h1>
    <p class="page-subtitle">Datenbankbackups erstellen, herunterladen und verwalten</p>
  </div>
  <div class="page-actions">
    <form method="post" onsubmit="return confirm('Backup jetzt erstellen? Dies kann einige Sekunden dauern.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <button type="submit" class="btn btn-primary"><?= svg_icon('database') ?> Backup jetzt erstellen</button>
    </form>
  </div>
</div>

<?php show_flash(); ?>

<!-- ── Info-Kacheln ── -->
<div class="stats-grid" style="margin-bottom:24px;">
  <div class="stat-card">
    <div class="stat-icon blue"><?= svg_icon('database') ?></div>
    <div>
      <div class="stat-label">Gespeicherte Backups</div>
      <div class="stat-value"><?= count($backup_files) ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><?= svg_icon('package') ?></div>
    <div>
      <div class="stat-label">Gesamtgröße</div>
      <div class="stat-value"><?= fmt_filesize($total_size) ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow"><?= svg_icon('clock') ?></div>
    <div>
      <div class="stat-label">Letztes Backup</div>
      <div class="stat-value" style="font-size:1rem;">
        <?php if (!empty($backup_files)): ?>
          <?= date('d.m.Y H:i', $backup_files[0]['mtime']) ?>
        <?php else: ?>
          Noch kein Backup
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><?= svg_icon('info') ?></div>
    <div>
      <div class="stat-label">Backup-Pfad</div>
      <div style="font-size:.8rem;color:#6B7280;word-break:break-all;"><?= h(BACKUP_PATH) ?></div>
    </div>
  </div>
</div>

<!-- ── Backup-Liste ── -->
<div class="card">
  <div class="card-header">
    <h2 class="card-title">Vorhandene Backups <span style="color:#9CA3AF;font-weight:400;font-size:.875rem;">(letzte 10)</span></h2>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($display_files)): ?>
      <div class="empty-state">
        <?= svg_icon('database') ?>
        <p>Noch keine Backups vorhanden. Erstellen Sie jetzt Ihr erstes Backup.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Dateiname</th>
              <th>Erstellt am</th>
              <th style="text-align:right;">Größe</th>
              <th style="text-align:right;">Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($display_files as $bf): ?>
            <tr>
              <td style="font-family:monospace;font-size:.875rem;"><?= h($bf['name']) ?></td>
              <td><?= date('d.m.Y H:i:s', $bf['mtime']) ?> Uhr</td>
              <td style="text-align:right;"><?= fmt_filesize($bf['size']) ?></td>
              <td style="text-align:right;">
                <div style="display:flex;gap:6px;justify-content:flex-end;">
                  <!-- Herunterladen -->
                  <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="download">
                    <input type="hidden" name="file"   value="<?= h($bf['name']) ?>">
                    <button type="submit" class="btn btn-sm btn-outline" title="Herunterladen">
                      <?= svg_icon('download') ?> Herunterladen
                    </button>
                  </form>
                  <!-- Löschen -->
                  <form method="post" style="display:inline;"
                        onsubmit="return confirm('Backup &quot;<?= h(addslashes($bf['name'])) ?>&quot; wirklich löschen?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="file"   value="<?= h($bf['name']) ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Löschen">
                      <?= svg_icon('trash') ?> Löschen
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($backup_files) > 10): ?>
        <div style="padding:12px 16px;color:#6B7280;font-size:.875rem;border-top:1px solid #F3F4F6;">
          Zeige 10 von <?= count($backup_files) ?> Backups. Ältere Backups werden nicht angezeigt, sind aber auf dem Server vorhanden.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Hinweise ── -->
<div class="card" style="margin-top:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('info') ?> Hinweise</h2>
  </div>
  <div class="card-body">
    <ul style="margin:0;padding-left:1.25rem;color:#4B5563;line-height:1.8;">
      <li>Backups werden als komprimierte SQL-Dateien (.sql.gz) gespeichert.</li>
      <li>Backup-Pfad: <code style="background:#F3F4F6;padding:2px 6px;border-radius:4px;"><?= h(BACKUP_PATH) ?></code></li>
      <li>Empfehlung: Backups regelmäßig herunterladen und extern speichern.</li>
      <li>Für automatische Backups kann ein Cronjob eingerichtet werden.</li>
      <li>Zum Wiederherstellen: SQL-Datei entpacken und in die Datenbank importieren (z.B. mit phpMyAdmin oder mysql-CLI).</li>
    </ul>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
