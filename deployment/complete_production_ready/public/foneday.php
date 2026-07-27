<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

$message = null;
$messageType = 'info';
$result = null;

function foneday_uploaded_catalog(): array
{
    if (!isset($_FILES['catalog']) || ($_FILES['catalog']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Keine Foneday-Katalogdatei empfangen.');
    }
    if ((int) $_FILES['catalog']['size'] > 50 * 1024 * 1024) {
        throw new RuntimeException('Die Katalogdatei überschreitet 50 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['catalog']['tmp_name']);
    if (!in_array($mime, ['application/json', 'text/plain', 'application/octet-stream'], true)) {
        throw new RuntimeException('Die Datei ist kein zulässiger JSON-Katalog.');
    }
    $raw = file_get_contents($_FILES['catalog']['tmp_name']);
    if ($raw === false) {
        throw new RuntimeException('Die Katalogdatei konnte nicht gelesen werden.');
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Die Katalogdatei enthält kein gültiges JSON.');
    } finally {
        $raw = null;
    }
    $products = foneday_extract_products($decoded);
    if ($products === []) {
        throw new RuntimeException('Die Katalogdatei enthält keine erkennbare Produktliste.');
    }
    return $products;
}

function foneday_public_error_message(Throwable $error): string
{
    $allowed = [
        'Keine Foneday-Katalogdatei empfangen.',
        'Die Katalogdatei überschreitet 50 MB.',
        'Die Datei ist kein zulässiger JSON-Katalog.',
        'Die Katalogdatei konnte nicht gelesen werden.',
        'Die Katalogdatei enthält kein gültiges JSON.',
        'Die Katalogdatei enthält keine erkennbare Produktliste.',
        'Der Import wurde nicht ausdrücklich bestätigt.',
        'Der angeforderte Einzelartikel wurde im Katalog nicht eindeutig gefunden.',
        'Nicht erlaubte Foneday-Aktion.',
    ];
    return in_array($error->getMessage(), $allowed, true)
        ? $error->getMessage()
        : 'Die Foneday-Aktion ist sicher fehlgeschlagen. Interne Details wurden nicht ausgegeben.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'connection_info') {
            $message = 'Der Foneday-Token liegt ausschließlich im benutzergebundenen Windows-DPAPI-Speicher. '
                . 'Ein serverseitiger Verbindungstest ist ohne Übertragung oder Zweitspeicherung des Tokens absichtlich deaktiviert.';
            $messageType = 'warning';
        } elseif ($action === 'dry_run') {
            $products = foneday_uploaded_catalog();
            $result = foneday_dry_run($products);
            $message = $result['success']
                ? 'Dry-Run erfolgreich. Es wurden keine Artikel- oder Angebotsdaten verändert.'
                : $result['message'];
            $messageType = $result['success'] ? 'success' : 'error';
        } elseif (in_array($action, ['full_import', 'price_stock', 'single_item'], true)) {
            if (($_POST['confirm'] ?? '') !== 'YES') {
                throw new RuntimeException('Der Import wurde nicht ausdrücklich bestätigt.');
            }
            $products = foneday_uploaded_catalog();
            if ($action === 'single_item') {
                $sku = trim((string) ($_POST['sku'] ?? ''));
                $products = array_values(array_filter(
                    $products,
                    static fn(array $product): bool =>
                        hash_equals($sku, trim((string) ($product['sku'] ?? '')))
                ));
                if ($sku === '' || count($products) !== 1) {
                    throw new RuntimeException(
                        'Der angeforderte Einzelartikel wurde im Katalog nicht eindeutig gefunden.'
                    );
                }
            }
            $result = foneday_apply_catalog(
                $products,
                $action === 'full_import' ? 'full_import' : 'price_stock',
                (int) ($_SESSION['user_id'] ?? 0)
            );
            $message = $result['success']
                ? 'Foneday-Katalog erfolgreich verarbeitet.'
                : (($result['partial'] ?? false)
                    ? 'Foneday-Katalog mit protokollierten Einzelfehlern verarbeitet.'
                    : ($result['message'] ?? 'Foneday-Verarbeitung fehlgeschlagen.'));
            $messageType = $result['success'] ? 'success' : (($result['partial'] ?? false) ? 'warning' : 'error');
        } else {
            throw new RuntimeException('Nicht erlaubte Foneday-Aktion.');
        }
    } catch (Throwable $error) {
        $message = foneday_public_error_message($error);
        $messageType = 'error';
    }
}

$supplier = null;
$runs = [];
$queueCount = 0;
try {
    $supplier = foneday_supplier();
    $runs = foneday_sync_runs(20);
    $queueCount = count(foneday_queue_list('offen', 500));
} catch (Throwable) {
    $message = $message ?? 'Die Foneday-Datenbankmigration ist noch nicht vollständig ausgeführt.';
    $messageType = 'warning';
}

$page_title = 'Foneday API';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Foneday API</h1>
    <p class="text-muted">Lesender Produktkatalog, sichere Preisberechnung und Importwarteschlange</p>
  </div>
  <div>
    <a href="foneday_logs.php" class="btn btn-outline">Fehlerprotokoll</a>
    <a href="foneday_queue.php" class="btn btn-outline">Importwarteschlange (<?= (int) $queueCount ?>)</a>
  </div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= h($messageType) ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="stats-grid">
  <div class="stat-card"><span>Bestehender Lieferant</span><strong><?= $supplier ? h($supplier['name']) : 'NICHT EINDEUTIG' ?></strong></div>
  <div class="stat-card"><span>Token konfiguriert</span><strong>Windows-DPAPI</strong></div>
  <div class="stat-card"><span>Serverseitige Tokenkopie</span><strong>NEIN</strong></div>
  <div class="stat-card"><span>Automatische Bestellung</span><strong>DEAKTIVIERT</strong></div>
</div>

<div class="card">
  <h2>Verbindung und Katalog</h2>
  <p>
    Der Foneday-Token wird nicht auf dem Webserver gespeichert. Der Katalog wird mit dem
    lokalen sicheren Abrufwerkzeug ausschließlich per GET geladen und anschließend hier
    als JSON für Dry-Run oder Import verarbeitet.
  </p>
  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="connection_info">
    <button class="btn btn-outline" type="submit">Verbindung testen</button>
  </form>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Dry-Run</h2>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="dry_run">
      <label>Foneday-Katalog (JSON)<input type="file" name="catalog" accept=".json,application/json" required></label>
      <button class="btn btn-primary" type="submit">Dry-Run durchführen</button>
    </form>
  </div>
  <div class="card">
    <h2>Verbindliche Verarbeitung</h2>
    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Foneday-Katalog jetzt verbindlich verarbeiten?');">
      <?= csrf_field() ?>
      <label>Modus
        <select name="action">
          <option value="full_import">Vollständigen Katalog synchronisieren</option>
          <option value="price_stock">Preise und Bestand aktualisieren</option>
        </select>
      </label>
      <label>Foneday-Katalog (JSON)<input type="file" name="catalog" accept=".json,application/json" required></label>
      <input type="hidden" name="confirm" value="YES">
      <button class="btn btn-danger" type="submit">Ausdrücklich ausführen</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>Einzelnen Artikel aktualisieren</h2>
  <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Diesen Foneday-Artikel jetzt aktualisieren?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="single_item">
    <input type="hidden" name="confirm" value="YES">
    <label>Foneday-SKU<input type="text" name="sku" maxlength="100" required></label>
    <label>Foneday-Katalog (JSON)<input type="file" name="catalog" accept=".json,application/json" required></label>
    <button class="btn btn-outline" type="submit">Einzelnen Artikel aktualisieren</button>
  </form>
</div>

<?php if ($result && isset($result['stats'])): ?>
  <div class="card">
    <h2>Bereinigte Statistik</h2>
    <table><tbody>
    <?php foreach ($result['stats'] as $key => $value): ?>
      <tr><th><?= h((string) $key) ?></th><td><?= (int) $value ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Letzte Synchronisierungsläufe</h2>
  <?php if (!$runs): ?>
    <p class="text-muted">Noch keine Läufe vorhanden.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Zeit</th><th>Modus</th><th>Status</th><th>Empfangen</th><th>Neu</th><th>Aktualisiert</th><th>Unverändert</th><th>Nicht lieferbar</th><th>Warteschlange</th><th>Fehler</th><th>Laufzeit</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $run): ?>
        <tr>
          <td><?= h((string) $run['started_at']) ?></td>
          <td><?= h((string) $run['run_mode']) ?></td>
          <td><?= h((string) $run['status']) ?></td>
          <td><?= (int) $run['products_received'] ?></td>
          <td><?= (int) $run['products_created'] ?></td>
          <td><?= (int) $run['products_updated'] ?></td>
          <td><?= (int) $run['products_unchanged'] ?></td>
          <td><?= (int) $run['products_unavailable'] ?></td>
          <td><?= (int) $run['queue_count'] ?></td>
          <td><?= (int) $run['error_count'] ?></td>
          <td><?= $run['duration_ms'] === null ? '–' : h(number_format((int) $run['duration_ms'] / 1000, 1, ',', '.') . ' s') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
