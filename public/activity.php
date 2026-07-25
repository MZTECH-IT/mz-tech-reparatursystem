<?php
/**
 * MZ Tech – Aktivitätslog
 */
require_once __DIR__ . '/init.php';

$db = get_db();

// ── Filter-Parameter ─────────────────────────────────────────────────────────
$filter_action     = trim($_GET['action_type'] ?? '');
$filter_user       = (int)($_GET['user_id'] ?? 0);
$filter_date_from  = trim($_GET['date_from'] ?? '');
$filter_date_to    = trim($_GET['date_to']   ?? '');
$per_page          = 50;
$current_page      = max(1, (int)($_GET['page'] ?? 1));

// Validierung Datumsformat
if ($filter_date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) $filter_date_from = '';
if ($filter_date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to))   $filter_date_to   = '';

// ── WHERE-Aufbau ─────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($filter_action !== '') {
    $where[]  = 'al.action = ?';
    $params[] = $filter_action;
}
if ($filter_user > 0) {
    $where[]  = 'al.user_id = ?';
    $params[] = $filter_user;
}
if ($filter_date_from !== '') {
    $where[]  = 'DATE(al.created_at) >= ?';
    $params[] = $filter_date_from;
}
if ($filter_date_to !== '') {
    $where[]  = 'DATE(al.created_at) <= ?';
    $params[] = $filter_date_to;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Gesamtanzahl für Pagination ───────────────────────────────────────────────
$count_stmt = $db->prepare("SELECT COUNT(*) FROM activity_log al $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$pagination = paginate($total, $per_page, $current_page);
$offset      = $pagination['offset'];
$total_pages = $pagination['total_pages'];

// ── Einträge laden ────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT al.id, al.action, al.entity_type, al.entity_id, al.details,
            al.ip_address, al.created_at,
            u.full_name, u.username
     FROM activity_log al
     LEFT JOIN users u ON u.id = al.user_id
     $where_sql
     ORDER BY al.id DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Benutzer für Filter-Dropdown ───────────────────────────────────────────────
$all_users = $db->query(
    "SELECT DISTINCT u.id, u.full_name, u.username
     FROM users u
     JOIN activity_log al ON al.user_id = u.id
     ORDER BY u.full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Aktionstypen für Filter-Dropdown ─────────────────────────────────────────
$all_actions = $db->query(
    "SELECT DISTINCT action FROM activity_log ORDER BY action ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Hilfsfunktion: Aktion lesbar ──────────────────────────────────────────────
function action_label(string $action): string {
    $map = [
        'login'               => 'Login',
        'logout'              => 'Logout',
        'login_failed'        => 'Login fehlgeschlagen',
        'repair_created'      => 'Reparatur erstellt',
        'repair_updated'      => 'Reparatur aktualisiert',
        'repair_deleted'      => 'Reparatur gelöscht',
        'repair_status'       => 'Status geändert',
        'customer_created'    => 'Kunde erstellt',
        'customer_updated'    => 'Kunde aktualisiert',
        'customer_deleted'    => 'Kunde gelöscht',
        'part_created'        => 'Ersatzteil erstellt',
        'part_updated'        => 'Ersatzteil aktualisiert',
        'part_deleted'        => 'Ersatzteil gelöscht',
        'settings_updated'    => 'Einstellungen geändert',
        'backup_created'      => 'Backup erstellt',
        'backup_deleted'      => 'Backup gelöscht',
        'user_created'        => 'Benutzer angelegt',
        'user_updated'        => 'Benutzer bearbeitet',
        'user_toggled'        => 'Benutzer aktiviert/deaktiviert',
        'profile_updated'     => 'Profil aktualisiert',
        'password_changed'    => 'Passwort geändert',
        'invoice_generated'   => 'Rechnung erstellt',
        'email_sent'          => 'E-Mail gesendet',
    ];
    return $map[$action] ?? h($action);
}

function action_badge_class(string $action): string {
    if (str_contains($action, 'deleted') || str_contains($action, 'failed'))   return 'badge-red';
    if (str_contains($action, 'created') || str_contains($action, 'login'))    return 'badge-green';
    if (str_contains($action, 'updated') || str_contains($action, 'changed'))  return 'badge-blue';
    if ($action === 'logout')                                                    return 'badge-gray';
    return 'badge-yellow';
}

// ── Query-String für Pagination-Links ─────────────────────────────────────────
function build_query(array $overrides = []): string {
    $params = [
        'action_type' => $_GET['action_type'] ?? '',
        'user_id'     => $_GET['user_id']     ?? '',
        'date_from'   => $_GET['date_from']   ?? '',
        'date_to'     => $_GET['date_to']     ?? '',
        'page'        => $_GET['page']        ?? '',
    ];
    $params = array_merge($params, $overrides);
    $params = array_filter($params, fn($v) => $v !== '');
    return $params ? '?' . http_build_query($params) : '?';
}

$page_title = 'Aktivitätslog';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Aktivitätslog</h1>
    <p class="page-subtitle">Vollständiges Protokoll aller Systemaktivitäten</p>
  </div>
  <div class="page-actions">
    <span style="font-size:.875rem;color:#6B7280;"><?= number_format($total, 0, ',', '.') ?> Einträge gesamt</span>
  </div>
</div>

<?php show_flash(); ?>

<!-- ── Filter ── -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('filter') ?> Filter</h2>
    <?php if ($filter_action || $filter_user || $filter_date_from || $filter_date_to): ?>
      <a href="<?= url('activity.php') ?>" class="btn btn-sm btn-outline">Filter zurücksetzen</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="get" id="filter-form">
      <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
        <div class="form-group">
          <label for="f_action">Aktionstyp</label>
          <select id="f_action" name="action_type" class="form-control" onchange="this.form.submit()">
            <option value="">Alle Aktionen</option>
            <?php foreach ($all_actions as $act): ?>
              <option value="<?= h($act) ?>" <?= $filter_action === $act ? 'selected' : '' ?>>
                <?= h(action_label($act)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="f_user">Benutzer</label>
          <select id="f_user" name="user_id" class="form-control" onchange="this.form.submit()">
            <option value="">Alle Benutzer</option>
            <?php foreach ($all_users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $filter_user === (int)$u['id'] ? 'selected' : '' ?>>
                <?= h($u['full_name'] ?: $u['username']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="f_from">Von Datum</label>
          <input type="date" id="f_from" name="date_from" class="form-control"
                 value="<?= h($filter_date_from) ?>" onchange="this.form.submit()">
        </div>
        <div class="form-group">
          <label for="f_to">Bis Datum</label>
          <input type="date" id="f_to" name="date_to" class="form-control"
                 value="<?= h($filter_date_to) ?>" onchange="this.form.submit()">
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Log-Tabelle ── -->
<div class="card">
  <div class="card-header">
    <h2 class="card-title">
      Aktivitäten
      <?php if ($total > 0): ?>
        <span style="color:#9CA3AF;font-weight:400;font-size:.875rem;">
          – Seite <?= $pagination['current_page'] ?> von <?= $pagination['total_pages'] ?>
          (<?= number_format($total, 0, ',', '.') ?> Einträge)
        </span>
      <?php endif; ?>
    </h2>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($logs)): ?>
      <div class="empty-state">
        <?= svg_icon('list') ?>
        <p>Keine Aktivitäten für die gewählten Filter gefunden.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Zeitpunkt</th>
              <th>Benutzer</th>
              <th>Aktion</th>
              <th>Entität</th>
              <th>Details</th>
              <th>IP-Adresse</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
              <td style="white-space:nowrap;color:#6B7280;font-size:.875rem;">
                <?= fmt_date($log['created_at'], true) ?>
              </td>
              <td>
                <?php if ($log['full_name'] || $log['username']): ?>
                  <span style="font-weight:500;"><?= h($log['full_name'] ?: $log['username']) ?></span>
                  <?php if ($log['full_name'] && $log['username']): ?>
                    <br><span style="font-size:.8rem;color:#9CA3AF;">@<?= h($log['username']) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:#9CA3AF;">System</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= action_badge_class($log['action']) ?>">
                  <?= h(action_label($log['action'])) ?>
                </span>
              </td>
              <td>
                <?php if ($log['entity_type']): ?>
                  <span style="color:#374151;"><?= h($log['entity_type']) ?></span>
                  <?php if ($log['entity_id']): ?>
                    <span style="color:#9CA3AF;"> #<?= (int)$log['entity_id'] ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:#9CA3AF;">–</span>
                <?php endif; ?>
              </td>
              <td style="max-width:260px;">
                <?php if ($log['details']): ?>
                  <span style="font-size:.875rem;color:#4B5563;" title="<?= h($log['details']) ?>">
                    <?= h(mb_strimwidth($log['details'], 0, 80, '…')) ?>
                  </span>
                <?php else: ?>
                  <span style="color:#9CA3AF;">–</span>
                <?php endif; ?>
              </td>
              <td style="font-family:monospace;font-size:.8rem;color:#6B7280;">
                <?= h($log['ip_address'] ?: '–') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Pagination ── -->
      <?php if ($total_pages > 1): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #F3F4F6;">
        <div style="font-size:.875rem;color:#6B7280;">
          Zeige <?= number_format($offset + 1, 0, ',', '.') ?>–<?= number_format(min($offset + $per_page, $total), 0, ',', '.') ?>
          von <?= number_format($total, 0, ',', '.') ?> Einträgen
        </div>
        <div style="display:flex;gap:4px;align-items:center;">
          <?php if ($current_page > 1): ?>
            <a href="<?= build_query(['page' => $current_page - 1]) ?>" class="btn btn-sm btn-outline">
              &lsaquo; Zurück
            </a>
          <?php endif; ?>

          <?php
          // Seitenzahlen anzeigen (maximal 7 Buttons)
          $start = max(1, $current_page - 3);
          $end   = min($total_pages, $start + 6);
          $start = max(1, $end - 6);

          if ($start > 1): ?>
            <a href="<?= build_query(['page' => 1]) ?>" class="btn btn-sm btn-outline">1</a>
            <?php if ($start > 2): ?><span style="padding:0 4px;color:#9CA3AF;">…</span><?php endif; ?>
          <?php endif; ?>

          <?php for ($p = $start; $p <= $end; $p++): ?>
            <a href="<?= build_query(['page' => $p]) ?>"
               class="btn btn-sm <?= $p === $current_page ? 'btn-primary' : 'btn-outline' ?>">
              <?= $p ?>
            </a>
          <?php endfor; ?>

          <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1): ?><span style="padding:0 4px;color:#9CA3AF;">…</span><?php endif; ?>
            <a href="<?= build_query(['page' => $total_pages]) ?>" class="btn btn-sm btn-outline"><?= $total_pages ?></a>
          <?php endif; ?>

          <?php if ($current_page < $total_pages): ?>
            <a href="<?= build_query(['page' => $current_page + 1]) ?>" class="btn btn-sm btn-outline">
              Weiter &rsaquo;
            </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
