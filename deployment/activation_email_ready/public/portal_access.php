<?php
/**
 * Interne Übersicht für Kunden-/Firmenzugänge, Einladungen, Gastlinks
 * und Portal-Aktivitäten. Keine Geheimnisse oder Token-Hashes werden gezeigt.
 */
require_once __DIR__ . '/init.php';
require_permission('manage_companies');

$db = get_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['verify_account', 'create_activation', 'send_activation', 'revoke_activation', 'deactivate_account'], true)) {
        if (!is_admin()) {
            http_response_code(403);
            exit('Diese Aktion ist ausschließlich Administratoren erlaubt.');
        }
        $accountType = (string)($_POST['account_type'] ?? '');
        $accountId = (int)($_POST['account_id'] ?? 0);
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        $result = match ($action) {
            'verify_account' => portal_account_manual_verify($accountType, $accountId, $adminId),
            'create_activation' => portal_account_create_activation($accountType, $accountId, $adminId),
            'send_activation' => portal_account_send_activation($accountType, $accountId, $adminId),
            'revoke_activation' => portal_account_revoke_activation($accountType, $accountId, $adminId),
            'deactivate_account' => portal_account_deactivate($accountType, $accountId, $adminId),
        };
        if (!empty($result['link'])) {
            $_SESSION['activation_link_once'] = [
                'link' => (string)$result['link'],
                'masked_email' => (string)($result['masked_email'] ?? ''),
            ];
        }
        flash(!empty($result['success']) ? 'success' : 'error', (string)$result['message']);
        header('Location: ' . url('portal_access.php'));
        exit;
    }
    if ($action === 'create_guest') {
        $scopeType = (string)($_POST['scope_type'] ?? '');
        $scopeId = (int)($_POST['scope_id'] ?? 0);
        $customerId = null;
        if ($scopeType === 'repair') {
            $stmt = $db->prepare('SELECT customer_id FROM repairs WHERE id = ? LIMIT 1');
            $stmt->execute([$scopeId]);
            $customerId = ($value = $stmt->fetchColumn()) ? (int)$value : null;
        } elseif ($scopeType === 'ticket') {
            $stmt = $db->prepare('SELECT customer_id FROM tickets WHERE id = ? LIMIT 1');
            $stmt->execute([$scopeId]);
            $value = $stmt->fetchColumn();
            $customerId = $value ? (int)$value : null;
        }
        $result = portal_guest_access_create(
            $scopeType,
            $scopeId,
            $customerId,
            (int)($_POST['lifetime_hours'] ?? 72),
            !empty($_POST['one_time']),
            (int)($_SESSION['user_id'] ?? 0)
        );
        if ($result['success']) {
            $_SESSION['guest_link_once'] = $result['url'];
        }
        flash($result['success'] ? 'success' : 'error', $result['success']
            ? 'Gastlink erstellt. Er wird aus Sicherheitsgründen nur einmal angezeigt.'
            : $result['message']);
        header('Location: ' . url('portal_access.php'));
        exit;
    }
    if ($action === 'revoke_guest') {
        portal_guest_access_revoke((int)($_POST['id'] ?? 0), (int)($_SESSION['user_id'] ?? 0));
        flash('success', 'Gastzugang wurde widerrufen.');
        header('Location: ' . url('portal_access.php'));
        exit;
    }
}

$guestLinkOnce = $_SESSION['guest_link_once'] ?? null;
unset($_SESSION['guest_link_once']);
$activationLinkOnce = $_SESSION['activation_link_once'] ?? null;
unset($_SESSION['activation_link_once']);
$customerAccounts = $db->query(
    'SELECT ca.id, ca.email, ca.is_verified, ca.is_active, ca.last_login_at, ca.verify_expires,
            1 AS password_initialized,
            c.first_name, c.last_name,
            ml.attempted_at AS last_mail_at, ml.result AS last_mail_result,
            COALESCE(ms.attempt_count, 0) AS mail_attempt_count
     FROM customer_accounts ca JOIN customers c ON c.id = ca.customer_id
     LEFT JOIN (
       SELECT account_type, account_id, MAX(id) AS last_id, COUNT(*) AS attempt_count
       FROM portal_activation_mail_log GROUP BY account_type, account_id
     ) ms ON ms.account_type = "customer" AND ms.account_id = ca.id
     LEFT JOIN portal_activation_mail_log ml ON ml.id = ms.last_id
     ORDER BY ca.created_at DESC LIMIT 200'
)->fetchAll(PDO::FETCH_ASSOC);
$companyAccounts = $db->query(
    'SELECT cc.id, cc.email, cc.first_name, cc.last_name, cc.portal_role,
            cc.is_verified, cc.is_active, cc.password_initialized, cc.last_login_at,
            cc.verify_expires, c.company_name,
            ml.attempted_at AS last_mail_at, ml.result AS last_mail_result,
            COALESCE(ms.attempt_count, 0) AS mail_attempt_count
     FROM company_contacts cc JOIN companies c ON c.id = cc.company_id
     LEFT JOIN (
       SELECT account_type, account_id, MAX(id) AS last_id, COUNT(*) AS attempt_count
       FROM portal_activation_mail_log GROUP BY account_type, account_id
     ) ms ON ms.account_type = "company_contact" AND ms.account_id = cc.id
     LEFT JOIN portal_activation_mail_log ml ON ml.id = ms.last_id
     ORDER BY cc.created_at DESC LIMIT 200'
)->fetchAll(PDO::FETCH_ASSOC);
$guestAccess = $db->query(
    'SELECT id, scope_type, scope_id, expires_at, one_time, used_at, revoked_at, created_at
     FROM portal_guest_access ORDER BY created_at DESC LIMIT 200'
)->fetchAll(PDO::FETCH_ASSOC);
$portalLog = $db->query(
    'SELECT event_type, actor_type, entity_type, entity_id, ip_address, created_at
     FROM portal_activity_log ORDER BY created_at DESC LIMIT 200'
)->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Portalzugänge';
require_once __DIR__ . '/includes/header.php';
$smtpStatus = function_exists('smtp_configuration_status') ? smtp_configuration_status() : ['configured' => false, 'tls' => false];
?>
<div class="page-header">
  <div><h1 class="page-title">Portalzugänge</h1>
    <p class="page-subtitle">Konten, Einladungen, zeitlich begrenzte Gastfreigaben und Aktivitätsprotokoll</p>
  </div>
</div>

<div class="alert <?= portal_email_delivery_enabled() ? 'alert-success' : 'alert-warning' ?>">
  Aktivierungs-E-Mail: <strong><?= portal_email_delivery_enabled() ? 'aktiv' : 'deaktiviert' ?></strong>
  · SMTP vollständig: <strong><?= !empty($smtpStatus['configured']) ? 'JA' : 'NEIN' ?></strong>
  · TLS: <strong><?= !empty($smtpStatus['tls']) ? 'JA' : 'NEIN' ?></strong>
</div>

<?php if ($guestLinkOnce): ?>
  <div class="alert alert-warning">
    <strong>Gastlink – nur jetzt sichtbar:</strong>
    <input type="text" readonly value="<?= h($guestLinkOnce) ?>" onclick="this.select()" style="width:100%;margin-top:8px;">
  </div>
<?php endif; ?>

<?php if ($activationLinkOnce): ?>
  <div class="alert alert-warning">
    <strong>Aktivierungslink – nur jetzt sichtbar:</strong>
    <p>Der E-Mail-Versand ist derzeit nicht eingerichtet oder der Link wurde zur manuellen Weitergabe erstellt. Bitte kopieren Sie ihn und senden Sie ihn kontrolliert an den Kontoinhaber.</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <input id="activation_link_once" type="text" readonly value="<?= h($activationLinkOnce['link']) ?>" style="flex:1;min-width:280px;">
      <button class="btn btn-outline" type="button" onclick="copyActivationLink()">Link kopieren</button>
    </div>
  </div>
  <script>
  function copyActivationLink() {
    const field = document.getElementById('activation_link_once');
    field.select();
    navigator.clipboard ? navigator.clipboard.writeText(field.value) : document.execCommand('copy');
  }
  </script>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><h2 class="card-title">Gastzugang erstellen</h2></div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_guest">
      <div class="form-grid">
        <div class="form-group"><label>Bereich</label><select name="scope_type" class="form-control">
          <option value="repair">Reparatur</option><option value="ticket">Ticket</option>
        </select></div>
        <div class="form-group"><label>Interne ID</label><input type="number" min="1" name="scope_id" class="form-control" required></div>
        <div class="form-group"><label>Gültigkeit (Stunden)</label><input type="number" min="1" max="720" name="lifetime_hours" value="72" class="form-control"></div>
        <div class="form-group"><label><input type="checkbox" name="one_time" value="1"> Nur einmal verwendbar</label></div>
      </div>
      <button class="btn btn-primary" type="submit">Gastlink erstellen</button>
    </form>
  </div>
</div>

<?php
$sections = [
    'Kundenkonten' => [$customerAccounts, ['Name','E-Mail','Verifiziert','Aktiv','Letzter Login','Letzter Versand','Versand erfolgreich','Link gültig bis','Versuche','Aktionen']],
    'Firmenkonten' => [$companyAccounts, ['Firma','Name','E-Mail','Rolle','Verifiziert','Aktiv','Letzter Versand','Versand erfolgreich','Link gültig bis','Versuche','Aktionen']],
];
foreach ($sections as $title => [$rows, $headers]):
?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><h2 class="card-title"><?= h($title) ?></h2></div>
  <div class="card-body" style="padding:0;"><div class="table-wrap"><table>
    <thead><tr><?php foreach ($headers as $header): ?><th><?= h($header) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?><tr>
      <?php if ($title === 'Kundenkonten'): ?>
        <td><?= h($row['first_name'].' '.$row['last_name']) ?></td><td><?= h($row['email']) ?></td>
        <td><?= $row['is_verified'] ? 'Ja' : 'Nein' ?></td><td><?= $row['is_active'] ? 'Ja' : 'Nein' ?></td>
        <td><?= h($row['last_login_at'] ? fmt_date($row['last_login_at'], true) : '–') ?></td>
      <?php else: ?>
        <td><?= h($row['company_name']) ?></td><td><?= h($row['first_name'].' '.$row['last_name']) ?></td>
        <td><?= h($row['email']) ?></td><td><?= h($row['portal_role']) ?></td>
        <td><?= $row['is_verified'] ? 'Ja' : 'Nein' ?></td><td><?= $row['is_active'] ? 'Ja' : 'Nein' ?></td>
      <?php endif; ?>
      <td><?= h($row['last_mail_at'] ? fmt_date($row['last_mail_at'], true) : '–') ?></td>
      <td><?= $row['last_mail_result'] === 'sent' ? 'JA' : ($row['last_mail_result'] === 'failed' ? 'NEIN' : '–') ?></td>
      <td><?= h($row['verify_expires'] ? fmt_date($row['verify_expires'], true) : '–') ?></td>
      <td><?= (int)$row['mail_attempt_count'] ?></td>
      <td>
        <?php if (is_admin()): ?>
          <?php
            $accountType = $title === 'Kundenkonten' ? 'customer' : 'company_contact';
            $needsActivation = !$row['is_verified'] || !$row['password_initialized'];
            $maskedEmail = portal_mask_email((string)$row['email']);
          ?>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php if ($needsActivation && $row['is_active']): ?>
              <?php if (portal_email_delivery_enabled()): ?>
              <form method="post" onsubmit="return confirm(<?= h(json_encode('Aktivierungslink jetzt an ' . $maskedEmail . ' senden?', JSON_UNESCAPED_UNICODE)) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_activation">
                <input type="hidden" name="account_type" value="<?= $accountType ?>">
                <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn-sm btn-primary" type="submit"><?= (int)$row['mail_attempt_count'] > 0 ? 'Aktivierungslink erneut senden' : 'Aktivierungslink per E-Mail senden' ?></button>
              </form>
              <?php else: ?>
                <button class="btn btn-sm btn-primary" type="button" disabled title="SMTP ist noch nicht vollständig eingerichtet">Aktivierungslink per E-Mail senden</button>
              <?php endif; ?>
              <?php if (!$row['is_verified']): ?>
            <form method="post" onsubmit="return confirm('Möchten Sie dieses Konto wirklich manuell bestätigen?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="verify_account">
              <input type="hidden" name="account_type" value="<?= $accountType ?>">
              <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit">Konto manuell bestätigen</button>
            </form>
              <?php endif; ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="create_activation">
              <input type="hidden" name="account_type" value="<?= $accountType ?>">
              <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit">Aktivierungslink kopieren</button>
            </form>
            <form method="post" onsubmit="return confirm('Vorhandenen Aktivierungslink wirklich widerrufen?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke_activation">
              <input type="hidden" name="account_type" value="<?= $accountType ?>">
              <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit">Aktivierungslink widerrufen</button>
            </form>
            <?php endif; ?>
            <?php if ($row['is_active']): ?>
            <form method="post" onsubmit="return confirm('Konto wirklich deaktivieren und offene Aktivierungslinks widerrufen?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="deactivate_account">
              <input type="hidden" name="account_type" value="<?= $accountType ?>">
              <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit">Konto deaktivieren</button>
            </form>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <span class="text-muted">–</span>
        <?php endif; ?>
      </td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div></div>
</div>
<?php endforeach; ?>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><h2 class="card-title">Gastzugänge</h2></div>
  <div class="card-body" style="padding:0;"><div class="table-wrap"><table>
    <thead><tr><th>Bereich</th><th>ID</th><th>Gültig bis</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($guestAccess as $access): ?><tr>
      <td><?= h($access['scope_type']) ?></td><td><?= (int)$access['scope_id'] ?></td>
      <td><?= h(fmt_date($access['expires_at'], true)) ?></td>
      <td><?= $access['revoked_at'] ? 'Widerrufen' : ($access['used_at'] && $access['one_time'] ? 'Verwendet' : 'Aktiv') ?></td>
      <td><?php if (!$access['revoked_at']): ?><form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="revoke_guest"><input type="hidden" name="id" value="<?= (int)$access['id'] ?>">
        <button class="btn btn-sm btn-outline" type="submit">Widerrufen</button>
      </form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div></div>
</div>

<div class="card">
  <div class="card-header"><h2 class="card-title">Portalprotokoll</h2></div>
  <div class="card-body" style="padding:0;"><div class="table-wrap"><table>
    <thead><tr><th>Zeit</th><th>Ereignis</th><th>Akteur</th><th>Objekt</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($portalLog as $event): ?><tr>
      <td><?= h(fmt_date($event['created_at'], true)) ?></td><td><?= h($event['event_type']) ?></td>
      <td><?= h($event['actor_type']) ?></td><td><?= h(($event['entity_type'] ?? '').' #'.($event['entity_id'] ?? '')) ?></td>
      <td><?= h($event['ip_address'] ?? '') ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
