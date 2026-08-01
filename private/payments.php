<?php
require_once __DIR__ . '/billing.php';

function payment_invoice_total(array $repair): string {
    $snapshot = json_decode((string)($repair['invoice_snapshot'] ?? ''), true);
    $sourceRepair = is_array($snapshot) ? ($snapshot['repair'] ?? $repair) : $repair;
    $parts = is_array($snapshot) ? ($snapshot['parts'] ?? []) : [];
    $cents = (int)round((float)($sourceRepair['price'] ?? 0) * 100)
        + (int)round((float)($sourceRepair['labor_cost'] ?? 0) * 100);
    if ($parts) {
        foreach ($parts as $part) {
            $cents += (int)round((float)($part['quantity'] ?? 0) * (float)($part['unit_price'] ?? 0) * 100);
        }
    } else {
        $stmt = get_db()->prepare('SELECT rp.quantity,COALESCE(rp.selling_price_at_time,p.selling_price,0) selling_price_at_time FROM repair_parts rp JOIN parts p ON p.id=rp.part_id WHERE rp.repair_id=?');
        $stmt->execute([(int)$repair['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $part) {
            $cents += (int)round((float)$part['quantity'] * (float)$part['selling_price_at_time'] * 100);
        }
    }
    return number_format(max(0, $cents) / 100, 2, '.', '');
}

function payments_for_repair(int $repairId): array {
    $stmt = get_db()->prepare('SELECT p.*, u.full_name AS created_by_name FROM payments p LEFT JOIN users u ON u.id=p.created_by WHERE p.repair_id=? ORDER BY p.payment_date,p.id');
    $stmt->execute([$repairId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payment_summary(array $repair): array {
    $totalCents = (int)round((float)payment_invoice_total($repair) * 100);
    $stmt = get_db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE repair_id=?');
    $stmt->execute([(int)$repair['id']]);
    $recordedCents = (int)round((float)$stmt->fetchColumn() * 100);
    $legacyDepositCents = (int)round((float)($repair['advance_payment'] ?? 0) * 100);
    $paidCents = $recordedCents + $legacyDepositCents;
    $openCents = max(0, $totalCents - $paidCents);
    $status = $paidCents <= 0 ? 'offen' : ($openCents > 0 ? 'teilweise_bezahlt' : 'bezahlt');
    if (($repair['invoice_correction_status'] ?? '') === 'storniert') $status = 'storniert';
    if ($status === 'offen' && !empty($repair['payment_due_date']) && $repair['payment_due_date'] < date('Y-m-d')) $status = 'überfällig';
    return [
        'total' => number_format($totalCents / 100, 2, '.', ''),
        'paid' => number_format($paidCents / 100, 2, '.', ''),
        'open' => number_format($openCents / 100, 2, '.', ''),
        'status' => $status,
    ];
}

function payment_record(int $repairId, array $data, ?int $userId): array {
    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM repairs WHERE id=? FOR UPDATE');
        $stmt->execute([$repairId]);
        $repair = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$repair) throw new RuntimeException('Reparaturauftrag nicht gefunden.');
        $isDeposit = !empty($data['is_deposit']);
        if (empty($repair['invoice_number']) && !$isDeposit) {
            throw new RuntimeException('Vor der Rechnungsfreigabe kann nur eine Anzahlung erfasst werden.');
        }
        $amount = repair_decimal_input($data['amount'] ?? '', 'Zahlungsbetrag', '9999999.99');
        if ((float)$amount <= 0) throw new InvalidArgumentException('Der Zahlungsbetrag muss größer als 0,00 € sein.');
        $summary = payment_summary($repair);
        if ((int)round((float)$amount * 100) > (int)round((float)$summary['open'] * 100)) {
            throw new InvalidArgumentException('Der Zahlungsbetrag darf den offenen Rechnungsbetrag nicht überschreiten.');
        }
        $method = $data['payment_method'] ?? '';
        if (!in_array($method, ['bar','ueberweisung','karte','paypal','sonstiges'], true)) {
            throw new InvalidArgumentException('Bitte eine gültige Zahlungsart auswählen.');
        }
        $date = $data['payment_date'] ?? '';
        $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$validDate || $validDate->format('Y-m-d') !== $date) throw new InvalidArgumentException('Bitte ein gültiges Zahlungsdatum angeben.');
        $db->prepare('INSERT INTO payments (repair_id,payment_date,amount,payment_method,reference,internal_note,is_deposit,created_by) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$repairId,$date,$amount,$method,trim($data['reference'] ?? '') ?: null,trim($data['internal_note'] ?? '') ?: null,$isDeposit ? 1 : 0,$userId]);
        $summary = payment_summary($repair);
        $db->prepare('UPDATE repairs SET payment_status=? WHERE id=?')->execute([$summary['status'],$repairId]);
        $db->commit();
        log_activity('record_payment','repairs',$repairId,'Betrag erfasst; Zahlungsart=' . $method);
        return ['success'=>true,'summary'=>$summary];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success'=>false,'message'=>$e->getMessage()];
    }
}
