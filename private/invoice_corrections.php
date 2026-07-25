<?php
/**
 * MZ Tech – Gutschriften und Stornorechnungen (Dokumentenmodul)
 * ----------------------------------------------------------------------
 * EINE Tabelle (`invoice_corrections`) für beide Korrekturarten
 * (correction_type: 'gutschrift' | 'storno') – Struktur und Ablauf
 * (Bezug zur Originalrechnung, eigener Nummernkreis, Freigabe,
 * unveränderlicher Snapshot) sind identisch, nur die fachliche Bedeutung
 * unterscheidet sich. Vermeidet zwei parallele, praktisch identische
 * Tabellen für denselben Zweck.
 *
 * Die Originalrechnung (repairs) wird NIEMALS gelöscht oder direkt
 * verändert – lediglich das Sichtbarkeits-Flag
 * repairs.invoice_correction_status wird zur Anzeige aktualisiert.
 */

require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/invoicing.php'; // build_document_snapshot()

function invoice_corrections_list(?int $repairId = null): array {
    $db = get_db();
    $sql = 'SELECT ic.*, r.repair_number, r.invoice_number AS original_invoice_number
              FROM invoice_corrections ic
              JOIN repairs r ON r.id = ic.repair_id';
    $params = [];
    if ($repairId !== null) {
        $sql .= ' WHERE ic.repair_id = ?';
        $params[] = $repairId;
    }
    $sql .= ' ORDER BY ic.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function invoice_correction_find(int $id): ?array {
    $stmt = get_db()->prepare(
        'SELECT ic.*, r.repair_number, r.invoice_number AS original_invoice_number, r.customer_id
           FROM invoice_corrections ic JOIN repairs r ON r.id = ic.repair_id
          WHERE ic.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Legt eine neue Korrektur (Gutschrift oder Stornorechnung) im Status
 * "entwurf" an. Nur für Reparaturen mit bereits freigegebener
 * (finalisierter) Rechnung möglich — eine Rechnung im Entwurf wird
 * stattdessen einfach direkt bearbeitet, dafür braucht es keine
 * Korrektur.
 */
function invoice_correction_create_draft(string $type, int $repairId, float $amount, string $reason, ?int $userId): int {
    if (!in_array($type, ['gutschrift', 'storno'], true)) {
        throw new InvalidArgumentException('Ungültiger Korrekturtyp.');
    }
    $db = get_db();
    $repairStmt = $db->prepare('SELECT invoice_number, invoice_status FROM repairs WHERE id = ?');
    $repairStmt->execute([$repairId]);
    $repair = $repairStmt->fetch(PDO::FETCH_ASSOC);
    if (!$repair || empty($repair['invoice_number']) || $repair['invoice_status'] !== 'freigegeben') {
        throw new RuntimeException('Zu dieser Reparatur liegt keine freigegebene Rechnung vor — eine Korrektur ist nur zu bereits finalisierten Rechnungen möglich.');
    }

    $db->prepare(
        'INSERT INTO invoice_corrections (correction_type, repair_id, amount, reason, status, created_by)
         VALUES (?, ?, ?, ?, "entwurf", ?)'
    )->execute([$type, $repairId, $amount, trim($reason) ?: null, $userId]);
    $id = (int)$db->lastInsertId();
    log_activity('create', 'invoice_corrections', $id, $type);
    return $id;
}

/**
 * Gibt eine Korrektur verbindlich frei: vergibt die Nummer (GS/STO),
 * speichert einen Snapshot, markiert die Korrektur als freigegeben und
 * aktualisiert das Sichtbarkeits-Flag der Originalrechnung — OHNE die
 * Originalrechnung selbst zu verändern oder zu löschen.
 */
function invoice_correction_release(int $id, ?int $userId): array {
    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM invoice_corrections WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $ic = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ic) throw new RuntimeException('Korrekturbeleg nicht gefunden.');
        if ($ic['status'] === 'freigegeben') {
            throw new RuntimeException('Dieser Beleg wurde bereits freigegeben (Nummer ' . $ic['correction_number'] . ').');
        }

        $docType = $ic['correction_type'] === 'gutschrift' ? 'GS' : 'STO';
        $number = generate_document_number($docType);

        $repairStmt = $db->prepare('SELECT * FROM repairs WHERE id = ?');
        $repairStmt->execute([$ic['repair_id']]);
        $repair = $repairStmt->fetch(PDO::FETCH_ASSOC);

        $snapshot = build_document_snapshot([
            'type'               => $ic['correction_type'],
            'correction_number'  => $number,
            'correction'         => $ic,
            'original_repair'    => $repair,
            'company'            => function_exists('pdf_company_info') ? pdf_company_info() : [],
            'released_by'        => $userId,
            'released_at'        => date('Y-m-d H:i:s'),
        ]);

        $db->prepare('UPDATE invoice_corrections SET correction_number = ?, status = "freigegeben", released_by = ?, released_at = NOW(), snapshot_json = ? WHERE id = ?')
           ->execute([$number, $userId, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);

        // Sichtbarkeits-Flag auf der Originalrechnung aktualisieren (rein
        // informativ, verändert weder Beträge noch bestehende Felder der
        // Rechnung selbst).
        $flag = $ic['correction_type'] === 'storno' ? 'storniert' : 'gutschrift_erstellt';
        $db->prepare('UPDATE repairs SET invoice_correction_status = ? WHERE id = ?')->execute([$flag, $ic['repair_id']]);

        $db->commit();
        log_activity('release', 'invoice_corrections', $id, $number);
        $label = $ic['correction_type'] === 'storno' ? 'Stornorechnung' : 'Gutschrift';
        return ['success' => true, 'message' => "$label $number wurde freigegeben.", 'correction_number' => $number];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
