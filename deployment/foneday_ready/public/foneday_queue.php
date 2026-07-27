<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

$status = in_array($_GET['status'] ?? '', ['offen', 'zugeordnet', 'ignoriert'], true)
    ? (string) $_GET['status']
    : 'offen';
$rows = [];
$error = null;
try {
    $rows = foneday_queue_list($status, 500);
} catch (Throwable) {
    $error = 'Die Foneday-Datenbankmigration ist noch nicht vollständig ausgeführt.';
}

$page_title = 'Foneday Importwarteschlange';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Foneday Importwarteschlange</h1><p class="text-muted">Unklare, ungültige oder mehrdeutige Produkte</p></div>
  <a href="foneday.php" class="btn btn-outline">Zurück zu Foneday API</a>
</div>
<?php if ($error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endif; ?>
<form method="get" class="filter-bar">
  <select name="status" onchange="this.form.submit()">
    <?php foreach (['offen' => 'Offen', 'zugeordnet' => 'Zugeordnet', 'ignoriert' => 'Ignoriert'] as $key => $label): ?>
      <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<div class="card">
  <?php if (!$rows): ?>
    <p class="text-muted">Keine Einträge für diesen Status.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>SKU</th><th>EAN</th><th>artcode</th><th>Produkt</th><th>Grund</th><th>Aktualisiert</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h((string) $row['supplier_sku']) ?></td>
          <td><?= h((string) $row['ean']) ?></td>
          <td><?= h((string) $row['artcode']) ?></td>
          <td><?= h((string) $row['title']) ?></td>
          <td><?= h((string) $row['reason_code']) ?></td>
          <td><?= h((string) $row['updated_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
