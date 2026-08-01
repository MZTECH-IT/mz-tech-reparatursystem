<?php
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/payments.php';
require_permission('manage_payments');
$db = get_db();
$repairId = (int)($_GET['repair_id'] ?? $_POST['repair_id'] ?? 0);
$repair = null;
if ($repairId) {
    $stmt=$db->prepare('SELECT r.*,c.first_name,c.last_name FROM repairs r JOIN customers c ON c.id=r.customer_id WHERE r.id=?');
    $stmt->execute([$repairId]); $repair=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $result=payment_record($repairId,$_POST,$_SESSION['user_id'] ?? null);
    flash($result['success']?'success':'error',$result['success']?'Zahlung wurde sicher erfasst.':$result['message']);
    header('Location: '.url('payments.php').'?repair_id='.$repairId); exit;
}
$rows=$repair ? payments_for_repair($repairId) : [];
$summary=$repair ? payment_summary($repair) : null;
$page_title='Zahlungen';
require_once __DIR__.'/includes/header.php';
?>
<?php show_flash(); ?>
<div class="card"><div class="card-header"><h2 class="card-title"><?= svg_icon('euro',20) ?> Zahlungen und Anzahlungen</h2></div><div class="card-body">
<form method="get" class="form-grid" style="margin-bottom:1rem"><div class="form-group"><label>Reparaturauftrag-ID</label><input type="number" name="repair_id" min="1" value="<?= $repairId ?: '' ?>" required></div><div class="form-actions"><button class="btn btn-outline">Auftrag laden</button></div></form>
<?php if ($repair): ?>
<p><strong><?= h($repair['repair_number']) ?></strong> · <?= h(trim($repair['first_name'].' '.$repair['last_name'])) ?></p>
<div class="stats-grid" style="margin-bottom:1rem"><div class="stat-card"><span>Rechnungsbetrag</span><strong><?= h(fmt_money((float)$summary['total'])) ?></strong></div><div class="stat-card"><span>Bezahlt inkl. bisheriger Anzahlung</span><strong><?= h(fmt_money((float)$summary['paid'])) ?></strong></div><div class="stat-card"><span>Offen</span><strong><?= h(fmt_money((float)$summary['open'])) ?></strong></div><div class="stat-card"><span>Status</span><strong><?= h($summary['status']) ?></strong></div></div>
<?php if ((float)$summary['open']>0): ?><form method="post"><input type="hidden" name="repair_id" value="<?= $repairId ?>"><?= csrf_field() ?><div class="form-grid">
<div class="form-group"><label>Zahlungsdatum</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
<div class="form-group"><label>Betrag (€)</label><input type="text" inputmode="decimal" name="amount" required placeholder="0,00"></div>
<div class="form-group"><label>Zahlungsart</label><select name="payment_method"><option value="bar">Bar</option><option value="ueberweisung">Überweisung</option><option value="karte">Karte</option><option value="paypal">PayPal</option><option value="sonstiges">Sonstiges</option></select></div>
<div class="form-group"><label>Referenz</label><input name="reference"></div><div class="form-group full"><label>Interne Notiz – nicht für Kunden sichtbar</label><textarea name="internal_note" rows="3"></textarea></div>
<div class="form-group"><label><input type="checkbox" name="is_deposit" value="1"> Als Anzahlung kennzeichnen</label></div></div><button class="btn btn-primary">Zahlung erfassen</button></form><?php endif; ?>
<div class="table-wrap" style="margin-top:1.5rem"><table class="table"><thead><tr><th>Datum</th><th>Betrag</th><th>Art</th><th>Referenz</th><th>Erfasst von</th></tr></thead><tbody><?php foreach($rows as $p): ?><tr><td><?= h(fmt_date($p['payment_date'])) ?></td><td><?= h(fmt_money((float)$p['amount'])) ?></td><td><?= h($p['payment_method']) ?><?= $p['is_deposit']?' (Anzahlung)':'' ?></td><td><?= h($p['reference'] ?? '') ?></td><td><?= h($p['created_by_name'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?></div></div>
<?php require_once __DIR__.'/includes/footer.php'; ?>
