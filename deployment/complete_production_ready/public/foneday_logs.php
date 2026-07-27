<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

$runs = [];
try {
    $runs = array_values(array_filter(
        foneday_sync_runs(200),
        static fn(array $run): bool => in_array($run['status'] ?? '', ['partial', 'failed'], true)
    ));
} catch (Throwable) {
    // Die Seite bleibt ohne interne Fehlerdetails nutzbar, solange die Migration fehlt.
}

$page_title = 'Foneday-Fehlerprotokoll';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Foneday-Fehlerprotokoll</h1><p class="text-muted">Bereinigte Laufstatus ohne Token oder API-Nutzdaten</p></div>
  <a href="foneday.php" class="btn btn-outline">Zurück zur Foneday API</a>
</div>
<div class="card">
  <?php if (!$runs): ?>
    <p class="text-muted">Keine fehlgeschlagenen oder teilweise erfolgreichen Läufe vorhanden.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Zeit</th><th>Modus</th><th>Status</th><th>Empfangen</th><th>Warteschlange</th><th>Fehler</th><th>Fehlercode</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $run): ?>
        <tr>
          <td><?= h((string) $run['started_at']) ?></td>
          <td><?= h((string) $run['run_mode']) ?></td>
          <td><?= h((string) $run['status']) ?></td>
          <td><?= (int) $run['products_received'] ?></td>
          <td><?= (int) $run['queue_count'] ?></td>
          <td><?= (int) $run['error_count'] ?></td>
          <td><?= h((string) ($run['error_code'] ?? '–')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
