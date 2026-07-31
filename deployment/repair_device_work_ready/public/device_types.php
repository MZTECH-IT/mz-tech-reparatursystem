<?php
/**
 * Zentrale Gerätearten-Stammdaten.
 * Datensätze werden nur deaktiviert, nie gelöscht.
 */
require_once __DIR__ . '/init.php';
require_admin();

$db = get_db();
$schemaReady = true;

try {
    $db->query('SELECT 1 FROM device_types LIMIT 1');
} catch (Throwable) {
    $schemaReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$schemaReady) {
        flash('error', 'Die Gerätearten-Migration wurde noch nicht ausgeführt.');
        header('Location: ' . url('device_types.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $sortOrder = max(0, min(9999, (int)($_POST['sort_order'] ?? 100)));

        if ($displayName === '' || mb_strlen($displayName) > 100) {
            flash('error', 'Bitte einen Anzeigenamen mit höchstens 100 Zeichen angeben.');
        } elseif ($id > 0) {
            $db->prepare(
                'UPDATE device_types
                    SET display_name = ?, category = ?, sort_order = ?, is_active = ?
                  WHERE id = ?'
            )->execute([
                $displayName,
                $category !== '' ? $category : null,
                $sortOrder,
                !empty($_POST['is_active']) ? 1 : 0,
                $id,
            ]);
            log_activity('device_type_updated', 'device_types', $id, 'Geräteart aktualisiert');
            flash('success', 'Geräteart wurde aktualisiert.');
        } else {
            $technicalKey = strtolower(trim((string)($_POST['technical_key'] ?? '')));
            $technicalKey = str_replace(['-', ' '], '_', $technicalKey);
            if (!preg_match('/^[a-z0-9_]{2,80}$/', $technicalKey)) {
                flash('error', 'Der technische Schlüssel darf nur Kleinbuchstaben, Ziffern und Unterstriche enthalten.');
            } else {
                try {
                    $stmt = $db->prepare(
                        'INSERT INTO device_types
                            (technical_key, display_name, category, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $technicalKey,
                        $displayName,
                        $category !== '' ? $category : null,
                        $sortOrder,
                        !empty($_POST['is_active']) ? 1 : 0,
                    ]);
                    $newId = (int)$db->lastInsertId();
                    log_activity('device_type_created', 'device_types', $newId, 'Geräteart angelegt');
                    flash('success', 'Geräteart wurde angelegt.');
                } catch (PDOException $e) {
                    flash('error', $e->getCode() === '23000'
                        ? 'Dieser technische Schlüssel ist bereits vorhanden.'
                        : 'Die Geräteart konnte nicht gespeichert werden.');
                }
            }
        }
    } elseif ($action === 'toggle' && $id > 0) {
        $db->prepare(
            'UPDATE device_types SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?'
        )->execute([$id]);
        log_activity('device_type_toggled', 'device_types', $id, 'Geräteart aktiviert/deaktiviert');
        flash('success', 'Status der Geräteart wurde geändert. Bestehende Reparaturen bleiben unverändert.');
    }

    header('Location: ' . url('device_types.php'));
    exit;
}

$rows = $schemaReady ? device_type_records(true) : device_type_default_records();
$page_title = 'Gerätearten';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Gerätearten</h1>
    <p class="page-subtitle">Zentrale Stammdaten für Reparaturen und Dokumente</p>
  </div>
</div>

<?php show_flash(); ?>

<?php if (!$schemaReady): ?>
  <div class="alert alert-warning">
    Die lokale Anwendung verwendet derzeit die sichere Standardliste.
    Die Stammdatenverwaltung wird nach der Datenbankmigration aktiv.
  </div>
<?php else: ?>
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Neue Geräteart</h2></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
          <div class="form-group">
            <label for="technical_key">Technischer Schlüssel</label>
            <input id="technical_key" name="technical_key" required maxlength="80" placeholder="z. B. netzwerkgeraet">
          </div>
          <div class="form-group">
            <label for="display_name">Anzeigename</label>
            <input id="display_name" name="display_name" required maxlength="100" placeholder="z. B. Netzwerkgerät">
          </div>
          <div class="form-group">
            <label for="category">Kategorie</label>
            <input id="category" name="category" maxlength="100" placeholder="z. B. Computer">
          </div>
          <div class="form-group">
            <label for="sort_order">Sortierung</label>
            <input type="number" id="sort_order" name="sort_order" min="0" max="9999" value="100">
          </div>
          <div class="form-group">
            <label><input type="checkbox" name="is_active" value="1" checked> Aktiv</label>
          </div>
        </div>
        <button class="btn btn-primary" type="submit">Geräteart anlegen</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h2 class="card-title">Vorhandene Gerätearten</h2></div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrap"><table>
      <thead><tr><th>Schlüssel</th><th>Anzeigename</th><th>Kategorie</th><th>Sortierung</th><th>Status</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <?php if ($schemaReady): ?>
            <?php $formId = 'device-type-' . (int)$row['id']; ?>
            <td><code><?= h($row['technical_key']) ?></code></td>
            <td><input form="<?= $formId ?>" name="display_name" value="<?= h($row['display_name']) ?>" maxlength="100" required></td>
            <td><input form="<?= $formId ?>" name="category" value="<?= h($row['category'] ?? '') ?>" maxlength="100"></td>
            <td><input form="<?= $formId ?>" type="number" name="sort_order" value="<?= (int)$row['sort_order'] ?>" min="0" max="9999" style="width:90px;"></td>
            <td><label><input form="<?= $formId ?>" type="checkbox" name="is_active" value="1" <?= !empty($row['is_active']) ? 'checked' : '' ?>> Aktiv</label></td>
            <td>
              <form id="<?= $formId ?>" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Speichern</button>
              </form>
            </td>
          <?php else: ?>
            <td><code><?= h($row['technical_key']) ?></code></td>
            <td><?= h($row['display_name']) ?></td>
            <td><?= h($row['category'] ?? '') ?></td>
            <td><?= (int)$row['sort_order'] ?></td>
            <td>Standard</td><td>–</td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
