<?php
/**
 * MZ Tech – Firmenkunden: Übersicht (Phase 5)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_companies');

$companies = companies_list();
$page_title = 'Firmenkunden';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Firmenkunden</h1>
    <p class="page-subtitle">Firmen, Ansprechpartner und Projekte für das Firmenkundenportal</p>
  </div>
  <a href="<?= url('companies_form.php') ?>" class="btn btn-primary"><?= svg_icon('plus') ?> Neue Firma anlegen</a>
</div>

<?php show_flash(); ?>

<div class="card">
  <div class="card-body" style="padding:0;">
    <?php if (empty($companies)): ?>
      <div class="empty-state"><?= svg_icon('users') ?><p>Noch keine Firmenkunden angelegt.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Firma</th>
              <th>Kontakt</th>
              <th>Ansprechpartner</th>
              <th>Kunden</th>
              <th>Projekte</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($companies as $c): ?>
            <tr>
              <td style="font-weight:600;"><?= h($c['company_name']) ?></td>
              <td>
                <?php if ($c['email']): ?><div><?= h($c['email']) ?></div><?php endif; ?>
                <?php if ($c['phone']): ?><div class="text-muted" style="font-size:.82rem;"><?= h($c['phone']) ?></div><?php endif; ?>
              </td>
              <td><?= (int)$c['contact_count'] ?></td>
              <td><?= (int)$c['customer_count'] ?></td>
              <td><?= (int)$c['project_count'] ?></td>
              <td>
                <?php if ($c['is_active']): ?><span class="badge badge-green">Aktiv</span>
                <?php else: ?><span class="badge badge-red">Inaktiv</span><?php endif; ?>
              </td>
              <td><a href="<?= url('companies_form.php') ?>?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye') ?> Öffnen</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
