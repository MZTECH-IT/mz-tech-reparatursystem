<?php
/**
 * MZ Tech – Admin: Kundennachrichten (Konversation & Antwort)
 *
 * Zeigt den vollständigen Nachrichtenverlauf mit einem Kunden aus dem
 * Kundenportal und erlaubt es Mitarbeitern, direkt zu antworten
 * (schreibt in `customer_messages` mit sender='staff').
 */
require_once __DIR__ . '/init.php';

$db = get_db();

$customer_id = (int)($_GET['customer_id'] ?? 0);
if ($customer_id <= 0) {
    flash('error', 'Ungültiger Kunde.');
    header('Location: ' . url('customer_messages.php'));
    exit;
}

$stmt = $db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    flash('error', 'Kunde nicht gefunden.');
    header('Location: ' . url('customer_messages.php'));
    exit;
}

// ── Antwort senden ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $message   = trim($_POST['message']   ?? '');
    $repair_id = (int)($_POST['repair_id'] ?? 0);

    if ($message === '') {
        flash('error', 'Bitte eine Nachricht eingeben.');
    } elseif (mb_strlen($message) > 2000) {
        flash('error', 'Die Nachricht darf maximal 2000 Zeichen lang sein.');
    } else {
        // Nur Reparaturen dieses Kunden dürfen referenziert werden (IDOR-Schutz).
        $repair_id_to_store = null;
        if ($repair_id > 0) {
            $rstmt = $db->prepare('SELECT id FROM repairs WHERE id = ? AND customer_id = ? LIMIT 1');
            $rstmt->execute([$repair_id, $customer_id]);
            if ($rstmt->fetch()) {
                $repair_id_to_store = $repair_id;
            }
        }

        $ins = $db->prepare(
            'INSERT INTO customer_messages (customer_id, repair_id, sender, user_id, message)
             VALUES (?, ?, "staff", ?, ?)'
        );
        $ins->execute([$customer_id, $repair_id_to_store, (int)$_SESSION['user_id'], $message]);

        log_activity('customer_message_reply', 'customer', $customer_id, 'Antwort an Kunde gesendet');

        // Kunde per E-Mail über die neue Nachricht informieren (Inhalt wird
        // aus Datenschutzgründen nicht in die E-Mail übernommen, nur ein
        // Hinweis + Link zum Kundenportal).
        if (!empty($customer['email'])) {
            $repair_number_for_mail = '';
            if ($repair_id_to_store !== null) {
                $rn = $db->prepare('SELECT repair_number FROM repairs WHERE id = ? LIMIT 1');
                $rn->execute([$repair_id_to_store]);
                $repair_number_for_mail = (string)($rn->fetchColumn() ?: '');
            }
            send_customer_account_email(
                'konto_nachricht',
                $customer,
                [
                    'auftragsnummer' => $repair_number_for_mail,
                    'portal_link'    => portal_link_for_customer($customer_id),
                ]
            );
        }

        flash('success', 'Antwort wurde gesendet.');
    }

    header('Location: ' . url('customer_message_view.php') . '?customer_id=' . $customer_id . '#reply');
    exit;
}

// Ungelesene Kundennachrichten als gelesen markieren
$db->prepare("UPDATE customer_messages SET is_read = 1 WHERE customer_id = ? AND sender = 'customer' AND is_read = 0")
   ->execute([$customer_id]);

// Verlauf laden
$stmt = $db->prepare(
    'SELECT m.*, r.repair_number, u.full_name AS staff_name
     FROM customer_messages m
     LEFT JOIN repairs r ON r.id = m.repair_id
     LEFT JOIN users   u ON u.id = m.user_id
     WHERE m.customer_id = ?
     ORDER BY m.created_at ASC'
);
$stmt->execute([$customer_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Reparaturen des Kunden für das optionale Zuordnungs-Dropdown
$stmt = $db->prepare('SELECT id, repair_number, device_type FROM repairs WHERE customer_id = ? ORDER BY created_at DESC');
$stmt->execute([$customer_id]);
$repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Nachrichten – ' . trim($customer['first_name'] . ' ' . $customer['last_name']);
$extra_css  = '<style>
    .msg-thread { display:flex; flex-direction:column; gap:12px; max-height:520px; overflow-y:auto; padding:4px 2px; }
    .msg-bubble { max-width:70%; padding:10px 14px; border-radius:12px; font-size:.9rem; line-height:1.45; }
    .msg-bubble.customer { align-self:flex-start; background:#f0f2f5; color:#1f2937; border-bottom-left-radius:3px; }
    .msg-bubble.staff { align-self:flex-end; background:#0057B8; color:#fff; border-bottom-right-radius:3px; }
    .msg-meta { font-size:.72rem; opacity:.8; margin-bottom:4px; }
    .msg-empty { text-align:center; color:#9CA3AF; font-size:.9rem; padding:24px 0; }
  </style>';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= svg_icon('message-circle') ?> <?= h(trim($customer['first_name'] . ' ' . $customer['last_name'])) ?></h1>
    <p class="page-subtitle"><?= h($customer['email'] ?? '') ?></p>
  </div>
  <div>
    <a href="<?= url('customer_messages.php') ?>" class="btn btn-outline"><?= svg_icon('chevron-left', 16) ?> Zurück zur Übersicht</a>
    <a href="<?= url('customers_form.php') ?>?id=<?= (int)$customer_id ?>" class="btn btn-outline"><?= svg_icon('user', 16) ?> Kundendaten</a>
  </div>
</div>

<?php show_flash(); ?>

<div class="card">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('inbox', 20) ?> Verlauf</h2>
  </div>
  <div class="card-body">
    <div class="msg-thread">
      <?php if (empty($messages)): ?>
        <p class="msg-empty">Noch keine Nachrichten vorhanden.</p>
      <?php else: ?>
        <?php foreach ($messages as $m): ?>
          <div class="msg-bubble <?= $m['sender'] === 'staff' ? 'staff' : 'customer' ?>">
            <div class="msg-meta">
              <strong><?= $m['sender'] === 'staff' ? h($m['staff_name'] ?? 'MZ Tech') : h(trim($customer['first_name'] . ' ' . $customer['last_name'])) ?></strong>
              <?php if (!empty($m['repair_number'])): ?>
                &middot; <span class="text-muted">Auftrag <?= h($m['repair_number']) ?></span>
              <?php endif; ?>
              &middot; <span class="text-muted"><?= h(fmt_date($m['created_at'], true)) ?></span>
            </div>
            <div><?= nl2br(h($m['message'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card" id="reply" style="margin-top:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('mail', 20) ?> Antworten</h2>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <?php if (!empty($repairs)): ?>
        <div class="form-group">
          <label for="repair_id">Bezug zu Reparaturauftrag (optional)</label>
          <select id="repair_id" name="repair_id" class="form-control">
            <option value="0">– Kein Bezug –</option>
            <?php foreach ($repairs as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= h($r['repair_number']) ?> (<?= h(device_type_label($r['device_type'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="form-group">
        <label for="message">Nachricht</label>
        <textarea id="message" name="message" class="form-control" rows="4" maxlength="2000" required placeholder="Ihre Antwort an den Kunden …"></textarea>
      </div>
      <button type="submit" class="btn btn-primary"><?= svg_icon('mail', 16) ?> Antwort senden</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
