<?php
/**
 * MZ Tech – Rechnungsentwurf, interne Freigabe, Kostenvoranschlags-Status
 * (Dokumentenmodul: "Dokumente, Nummernkreise und Freigaben")
 * ----------------------------------------------------------------------
 * Rechnungen UND Kostenvoranschläge hängen im bestehenden System bewusst
 * weiterhin direkt an `repairs` (repairs.invoice_number / .quote_number) –
 * keine parallele Dokumententabelle für etwas, das strukturell exakt ein
 * Feld auf einem bereits vorhandenen Datensatz ist.
 *
 * Rechnungslebenszyklus:
 *   entwurf  (repairs.invoice_number IS NULL)
 *     -> interne Freigabe (invoice_release()) vergibt EINMALIG die
 *        Rechnungsnummer über den Nummernkreis "RE", friert einen
 *        vollständigen Snapshot ein und markiert die Rechnung als
 *        "freigegeben" – danach gilt sie als unveränderlich (Korrekturen
 *        ausschließlich über Gutschrift/Stornorechnung, siehe
 *        private/invoice_corrections.php).
 *
 * WICHTIG (wie bei generate_document_number() an anderer Stelle im
 * System): invoice_release() darf NUR aufgerufen werden, wenn eine
 * Rechnung tatsächlich verbindlich freigegeben werden soll. Ein reines
 * Anzeigen/Drucken des Entwurfs-PDF verbraucht NIEMALS eine Nummer.
 */

require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/billing.php';

/** Entfernt interne Kalkulations- und Notizdaten rekursiv aus Kundendokumenten. */
function document_snapshot_customer_safe(mixed $value): mixed {
    if (!is_array($value)) return $value;
    $blocked = [
        'internal_notes', 'internal_note', 'purchase_price',
        'purchase_price_at_time', 'markup_percent', 'automatic_selling_price',
        'selling_price_changed_by', 'selling_price_changed_at',
    ];
    $safe = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $blocked, true)) continue;
        $safe[$key] = document_snapshot_customer_safe($item);
    }
    return $safe;
}

/**
 * Baut einen unveränderlichen Dokumenten-Snapshot (Abschnitt 9 der Phase
 * "Dokumente, Nummernkreise und Freigaben"): Firmendaten, Rechnungsadresse,
 * Kundenname, Positionen, Mengen, Preise, Steuersätze, Steuerhinweise,
 * Zahlungsbedingungen, Bankdaten, Nummer, Datum, Benutzer, Freigabezeit-
 * punkt. Wird von JEDEM finalisierbaren Dokumenttyp (Rechnung, Angebot,
 * Lieferschein, Gutschrift/Storno) verwendet – EINE zentrale Funktion statt
 * mehrerer, potenziell inkonsistenter Kopien derselben Logik.
 */
function build_document_snapshot(array $data): array {
    $data = document_snapshot_customer_safe($data);
    if (!isset($data['billing'])) {
        $data['billing'] = billing_snapshot();
    }
    $data['snapshot_created_at'] = date('c');
    return $data;
}

/**
 * Prüft, ob eine Reparatur/Rechnung freigabefähig ist (Abschnitt 2,
 * Schritte 1–6 des Freigabe-Ablaufs). Liefert harte Fehler (blockieren die
 * Freigabe) getrennt von Warnungen (werden angezeigt, blockieren aber
 * nicht).
 */
function invoice_validate_for_release(int $repairId): array {
    $db = get_db();
    $errors = [];
    $warnings = [];

    $stmt = $db->prepare(
        'SELECT r.*, c.first_name, c.last_name, c.address, c.email, c.phone, c.company_id,
                co.company_name, co.address AS company_address, co.zip AS company_zip,
                co.city AS company_city, co.email AS company_email, co.phone AS company_phone
           FROM repairs r JOIN customers c ON r.customer_id = c.id
           LEFT JOIN companies co ON co.id = c.company_id
          WHERE r.id = ?'
    );
    $stmt->execute([$repairId]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$repair) {
        return ['valid' => false, 'errors' => ['Reparaturauftrag nicht gefunden.'], 'warnings' => [], 'total' => 0.0, 'repair' => null];
    }

    // 1) Validierung aller Pflichtdaten + 2) Kunde/Rechnungsadresse
    if (!empty($repair['invoice_number'])) {
        $errors[] = 'Diese Rechnung wurde bereits freigegeben (Rechnungsnummer ' . $repair['invoice_number'] . '). Korrekturen bitte ausschließlich über Gutschrift oder Stornorechnung.';
    }
    if (!empty($repair['company_id'])) {
        if (trim((string)($repair['company_name'] ?? '')) === '') {
            $errors[] = 'Firmenname für die Rechnung fehlt.';
        }
        if (trim((string)($repair['company_address'] ?? '')) === '') {
            $errors[] = 'Rechnungsadresse der Firma fehlt.';
        }
    } else {
        if (trim(($repair['first_name'] ?? '') . ($repair['last_name'] ?? '')) === '') {
            $errors[] = 'Kundenname fehlt.';
        }
        if (trim((string)($repair['address'] ?? '')) === '') {
            $errors[] = 'Rechnungsadresse des Kunden fehlt.';
        }
    }

    // 3) Positionen und Beträge
    $partsStmt = $db->prepare(
        'SELECT rp.quantity, COALESCE(rp.selling_price_at_time, p.selling_price, 0) AS unit_price
           FROM repair_parts rp JOIN parts p ON rp.part_id = p.id
          WHERE rp.repair_id = ?'
    );
    $partsStmt->execute([$repairId]);
    $partsTotalCents = 0;
    foreach ($partsStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $partsTotalCents += (int)$p['quantity'] * (int)round((float)$p['unit_price'] * 100);
    }
    $repairPriceCents = (int)round((float)($repair['price'] ?? 0) * 100);
    $laborCostCents = (int)round((float)($repair['labor_cost'] ?? 0) * 100);
    $total = ($repairPriceCents + $laborCostCents + $partsTotalCents) / 100;
    if ($total <= 0) {
        $warnings[] = 'Der Rechnungsbetrag ist 0,00 € — bitte prüfen, ob das beabsichtigt ist (z. B. Garantie/Kulanz).';
    }

    // 4) Steuerlogik
    $taxRate = (float)billing_tax_rate();
    if ($taxRate < 0 || $taxRate > 100) {
        $errors[] = 'Der konfigurierte Steuersatz (' . number_format($taxRate, 2, ',', '.') . ' %) ist unplausibel — bitte unter Einstellungen > System prüfen.';
    }

    // 5) Zahlungsbedingungen
    if (trim(get_setting('payment_terms', '')) === '') {
        $warnings[] = 'Es sind keine Zahlungsbedingungen in den Einstellungen hinterlegt.';
    }

    // 6) Nummernkreis
    try {
        $range = number_range_ensure_exists('RE');
        if (empty($range['active'])) {
            $errors[] = 'Der Nummernkreis "Rechnungen" (RE) ist unter Einstellungen > Dokumentennummern deaktiviert.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Der Nummernkreis "Rechnungen" konnte nicht geprüft werden: ' . $e->getMessage();
    }

    return [
        'valid'    => empty($errors),
        'errors'   => $errors,
        'warnings' => $warnings,
        'total'    => $total,
        'repair'   => $repair,
    ];
}

/**
 * Führt die interne Rechnungsfreigabe durch (Abschnitt 2, Schritte 7–11):
 * transaktionssichere Vergabe der nächsten Rechnungsnummer, Speicherung
 * eines unveränderlichen Snapshots, Status auf "freigegeben", Protokoll
 * von Benutzer/Datum/Uhrzeit. Bei jedem Fehler vollständiger Rollback,
 * keine Nummer wird "verbraucht", wenn die Freigabe nicht durchläuft.
 */
function invoice_release(int $repairId, ?int $userId): array {
    $check = invoice_validate_for_release($repairId);
    if (!$check['valid']) {
        return ['success' => false, 'message' => implode(' ', $check['errors']), 'errors' => $check['errors']];
    }

    $db = get_db();
    $db->beginTransaction();
    try {
        // Zeilensperre auf den Reparaturdatensatz selbst: verhindert, dass
        // zwei gleichzeitige Freigabeversuche derselben Rechnung beide
        // durchlaufen (der zweite erkennt nach der Sperre bereits eine
        // vergebene invoice_number und bricht kontrolliert ab, OHNE eine
        // weitere Nummer zu verbrauchen).
        $stmt = $db->prepare('SELECT * FROM repairs WHERE id = ? FOR UPDATE');
        $stmt->execute([$repairId]);
        $repair = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$repair) throw new RuntimeException('Reparaturauftrag nicht gefunden.');
        if (!empty($repair['invoice_number'])) {
            throw new RuntimeException('Diese Rechnung wurde zwischenzeitlich bereits freigegeben (Rechnungsnummer ' . $repair['invoice_number'] . ').');
        }

        $customerStmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
        $customerStmt->execute([$repair['customer_id']]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $customerCompany = null;
        if (!empty($customer['company_id'])) {
            $companyStmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
            $companyStmt->execute([(int)$customer['company_id']]);
            $customerCompany = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $partsStmt = $db->prepare(
            'SELECT rp.quantity, COALESCE(rp.selling_price_at_time, p.selling_price, 0) AS unit_price, p.name AS part_name
               FROM repair_parts rp JOIN parts p ON rp.part_id = p.id
              WHERE rp.repair_id = ?'
        );
        $partsStmt->execute([$repairId]);
        $partsRows = $partsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Nummer wird JETZT, innerhalb derselben Transaktion, vergeben.
        $number = generate_document_number('RE');
        $amounts = billing_amounts($check['total']);
        $deposit = max(0, (float)($repair['advance_payment'] ?? 0));
        $open = max(0, (float)$amounts['total'] - $deposit);

        $snapshot = build_document_snapshot([
            'type'           => 'rechnung',
            'invoice_number' => $number,
            'repair'         => $repair,
            'customer'       => $customer,
            'customer_company' => $customerCompany,
            'parts'          => $partsRows,
            'company'        => function_exists('pdf_company_info') ? pdf_company_info() : [],
            'billing'        => billing_snapshot(),
            'tax_rate'       => (float)billing_tax_rate(),
            'ustg_active'    => billing_is_small_business(),
            'ustg_notice'    => billing_legal_notice(),
            'payment_terms'  => get_setting('payment_terms', ''),
            'service_date'   => $repair['service_date'] ?? null,
            'amounts'        => $amounts,
            'payment'        => [
                'advance_payment' => number_format($deposit, 2, '.', ''),
                'open_amount' => number_format($open, 2, '.', ''),
                'payment_status' => $repair['payment_status'] ?? 'offen',
                'payment_due_date' => $repair['payment_due_date'] ?? null,
            ],
            'released_by'    => $userId,
            'released_at'    => date('Y-m-d H:i:s'),
        ]);

        $db->prepare(
            'UPDATE repairs
                SET invoice_number = ?, invoice_status = "freigegeben",
                    invoice_released_at = NOW(), invoice_released_by = ?,
                    invoice_snapshot = ?
              WHERE id = ?'
        )->execute([$number, $userId, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $repairId]);

        $db->commit();
        log_activity('release_invoice', 'repairs', $repairId, 'Rechnungsnummer ' . $number);
        return ['success' => true, 'message' => "Rechnung $number wurde freigegeben.", 'invoice_number' => $number];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** true, wenn die Rechnung dieser Reparatur bereits final freigegeben ist. */
function invoice_is_released(array $repair): bool {
    return !empty($repair['invoice_number']) && ($repair['invoice_status'] ?? '') === 'freigegeben';
}

/**
 * Vergibt (falls noch nicht geschehen) die Kostenvoranschlagsnummer und
 * setzt den KV-Status auf "freigegeben". Leichter gewichtet als die
 * Rechnungsfreigabe (kein steuerlich bindendes Dokument), aber ebenfalls
 * transaktionssicher und mit Snapshot.
 */
function quote_of_repair_release(int $repairId, ?int $userId): array {
    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM repairs WHERE id = ? FOR UPDATE');
        $stmt->execute([$repairId]);
        $repair = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$repair) throw new RuntimeException('Reparaturauftrag nicht gefunden.');

        $number = $repair['quote_number'];
        if (empty($number)) {
            $number = generate_document_number('KV');
        }
        $customerStmt = $db->prepare('SELECT * FROM customers WHERE id=?');
        $customerStmt->execute([$repair['customer_id']]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $partsStmt = $db->prepare('SELECT rp.quantity,COALESCE(rp.selling_price_at_time,p.selling_price,0) unit_price,p.name part_name FROM repair_parts rp JOIN parts p ON p.id=rp.part_id WHERE rp.repair_id=?');
        $partsStmt->execute([$repairId]);
        $snapshot = build_document_snapshot([
            'type'=>'kostenvoranschlag','quote_number'=>$number,'repair'=>$repair,
            'customer'=>$customer,'parts'=>$partsStmt->fetchAll(PDO::FETCH_ASSOC),
            'company'=>function_exists('pdf_company_info') ? pdf_company_info() : [],
            'billing'=>billing_snapshot(),'released_by'=>$userId,'released_at'=>date('Y-m-d H:i:s'),
        ]);
        $db->prepare('UPDATE repairs SET quote_number=?,quote_status="freigegeben",quote_snapshot=?,quote_released_at=NOW(),quote_released_by=? WHERE id=?')
           ->execute([$number,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,$repairId]);

        $db->commit();
        log_activity('release_quote', 'repairs', $repairId, 'KV-Nummer ' . $number);
        return ['success' => true, 'message' => "Kostenvoranschlag $number wurde freigegeben.", 'quote_number' => $number];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** Setzt den Kostenvoranschlags-Status (z. B. "gesendet"/"abgelaufen"), ohne die Nummer neu zu vergeben. */
function quote_of_repair_set_status(int $repairId, string $status): void {
    $allowed = ['entwurf', 'freigegeben', 'gesendet', 'angenommen', 'abgelehnt', 'abgelaufen', 'storniert'];
    if (!in_array($status, $allowed, true)) return;
    get_db()->prepare('UPDATE repairs SET quote_status = ? WHERE id = ?')->execute([$status, $repairId]);
    log_activity('update_quote_status', 'repairs', $repairId, "quote_status=$status");
}

/**
 * Protokolliert eine Kundenentscheidung (Annahme/Ablehnung) zu einem
 * Kostenvoranschlag ODER einem eigenständigen Angebot (siehe
 * private/quotes.php) im gemeinsamen Entscheidungsprotokoll
 * `quote_decisions` (Abschnitt 5 der Phase "Dokumente, Nummernkreise und
 * Freigaben"). IP-Adresse wird nur gespeichert, wenn dies über die
 * Einstellung "log_decision_ip" datenschutzkonform aktiviert wurde
 * (Standard: aus).
 */
function quote_decision_record(
    string $docType,        // 'kv' | 'angebot'
    ?int $repairId,
    ?int $quoteId,
    string $decision,       // 'angenommen' | 'abgelehnt'
    string $decidedByType,  // 'customer' | 'company_contact' | 'staff'
    ?int $decidedByRef,
    ?string $comment,
    ?string $documentVersion,
    ?string $ipAddress
): void {
    $logIp = get_setting('log_decision_ip', '0') === '1';
    get_db()->prepare(
        'INSERT INTO quote_decisions
            (doc_type, repair_id, quote_id, decision, decided_by_type, decided_by_ref, comment, document_version, ip_address, decided_at)
         VALUES (?,?,?,?,?,?,?,?,?, NOW())'
    )->execute([
        $docType, $repairId, $quoteId, $decision, $decidedByType, $decidedByRef,
        $comment !== null ? trim($comment) : null, $documentVersion,
        $logIp ? $ipAddress : null,
    ]);

    if ($docType === 'kv' && $repairId) {
        quote_of_repair_set_status($repairId, $decision);
    } elseif ($docType === 'angebot' && $quoteId) {
        get_db()->prepare('UPDATE quotes SET status = ? WHERE id = ?')->execute([$decision, $quoteId]);
    }
    log_activity('quote_decision', $docType === 'kv' ? 'repairs' : 'quotes', $repairId ?? $quoteId, "decision=$decision");
}
