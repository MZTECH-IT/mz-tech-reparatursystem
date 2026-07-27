<?php
/**
 * Zeitlich begrenzter, auf genau einen Vorgang eingeschränkter Gastzugang.
 */
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/portal_security.php';
require_once $private . '/tickets.php';
require_once __DIR__ . '/includes/icons.php';

$token = trim((string)($_GET['t'] ?? ''));
$access = $token !== '' ? portal_guest_access_resolve($token) : null;
$companyName = get_setting('company_name', 'MZ Tech');
$record = null;

if ($access) {
    if ($access['scope_type'] === 'repair') {
        $stmt = get_db()->prepare(
            'SELECT id, repair_number, device_type, manufacturer, model, status, estimated_ready, updated_at
             FROM repairs WHERE id = ? AND (? IS NULL OR customer_id = ?) LIMIT 1'
        );
        $stmt->execute([$access['scope_id'], $access['customer_id'], $access['customer_id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($access['scope_type'] === 'ticket') {
        $ticket = ticket_find((int)$access['scope_id']);
        if ($ticket && (!$access['customer_id'] || (int)$ticket['customer_id'] === (int)$access['customer_id'])) {
            $record = $ticket;
            $record['comments'] = ticket_comments((int)$ticket['id'], false);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Gastzugang – <?= h($companyName) ?></title>
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body style="background:#f5f7fa;">
<main style="max-width:760px;margin:40px auto;padding:0 20px;">
  <div class="card"><div class="card-body">
    <h1 style="font-size:1.35rem;">Sicherer Gastzugang</h1>
    <?php if (!$access || !$record): ?>
      <div class="alert alert-danger">Dieser Link ist ungültig, abgelaufen, widerrufen oder bereits verwendet.</div>
    <?php elseif ($access['scope_type'] === 'repair'): ?>
      <h2>Reparatur <?= h($record['repair_number']) ?></h2>
      <p><strong>Gerät:</strong> <?= h(trim(($record['manufacturer'] ?? '') . ' ' . ($record['model'] ?? '')) ?: $record['device_type']) ?></p>
      <p><strong>Status:</strong> <?= repair_status_badge($record['status']) ?></p>
      <?php if ($record['estimated_ready']): ?><p><strong>Voraussichtlich fertig:</strong> <?= h(fmt_date($record['estimated_ready'], true)) ?></p><?php endif; ?>
    <?php elseif ($access['scope_type'] === 'ticket'): ?>
      <h2><?= h($record['ticket_number']) ?> – <?= h($record['subject']) ?></h2>
      <p><?= ticket_status_badge($record['status']) ?> <?= ticket_priority_badge($record['priority']) ?></p>
      <?php foreach ($record['comments'] as $comment): ?>
        <article style="border-top:1px solid #e5e7eb;padding:12px 0;">
          <small><?= h(fmt_date($comment['created_at'], true)) ?></small>
          <div><?= nl2br(h($comment['body'])) ?></div>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="alert alert-info">Der freigegebene Dokumenttyp kann hier noch nicht dargestellt werden.</div>
    <?php endif; ?>
  </div></div>
</main>
</body>
</html>
