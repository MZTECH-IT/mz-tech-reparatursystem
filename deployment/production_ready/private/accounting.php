<?php
/**
 * MZ Tech – Buchhaltungsintegration: Geschäftslogik (Phase 7, Abschnitt 8)
 * ----------------------------------------------------------------------
 * Orchestriert die Adapter aus private/accounting_adapters.php: Zugangs-
 * daten-Speicherung (verschlüsselt, exakt nach dem etablierten Muster von
 * smtp_password_get()/smtp_password_set() in private/functions.php),
 * Kontakt-Mapping (welcher Kunde/Lieferant entspricht welchem externen
 * Kontakt), Beleg-Synchronisation mit Protokoll/Wiederholung/Dublettenschutz
 * und DATEV-Export.
 *
 * DATENGRUNDLAGE (reale, bestehende Strukturen – siehe private/invoicing.php
 * und private/invoice_corrections.php, beide bereits im System vorhanden):
 *   - "Rechnung" = eine bereits INTERN FREIGEGEBENE Reparatur, d. h.
 *     repairs.invoice_number IS NOT NULL UND repairs.invoice_status =
 *     'freigegeben' (vergeben über invoice_release(), Nummernkreis "RE").
 *     Erst ab diesem Zeitpunkt gilt die Rechnung als unveränderlich
 *     (repairs.invoice_snapshot enthält den eingefrorenen Beleg-Snapshot)
 *     und wird hier an den Buchhaltungsanbieter übertragen – Entwürfe
 *     (noch keine invoice_number) werden bewusst NICHT synchronisiert.
 *   - "Gutschrift"/"Stornorechnung" = ein freigegebener Datensatz der
 *     bereits bestehenden Tabelle `invoice_corrections`
 *     (correction_type 'gutschrift'|'storno', vergeben über
 *     invoice_correction_release(), Nummernkreise "GS"/"STO").
 *   - "Eingangsbeleg" (Lieferantenrechnung) = `purchase_orders` +
 *     `purchase_order_items` (real, bestehend).
 * Es wird an keiner Stelle eine neue invoices-/credit_notes-Tabelle
 * angelegt – die Synchronisation liest ausschließlich aus den oben
 * genannten, bereits vorhandenen Strukturen.
 */
require_once __DIR__ . '/accounting_adapters.php';
require_once __DIR__ . '/invoicing.php';
require_once __DIR__ . '/invoice_corrections.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/purchase_orders.php';

const ACCOUNTING_PROVIDERS = ['lexoffice' => 'lexoffice (Lexware Office)', 'sevdesk' => 'sevDesk'];

// ── Zugangsdaten (verschlüsselt, je Anbieter) ─────────────────────────
// Mirrort exakt das etablierte smtp_password_get()/_set()-Muster
// (private/functions.php): AES-256-CBC über encrypt_passcode()/
// decrypt_passcode(), niemals Klartext in der Datenbank oder im Log.
function accounting_credentials_get(string $provider): array {
    $envNames = [
        'lexoffice' => ['api_key' => 'MZTECH_LEXOFFICE_API_KEY'],
        'sevdesk'   => ['api_token' => 'MZTECH_SEVDESK_API_TOKEN'],
    ];
    if (isset($envNames[$provider])) {
        foreach ($envNames[$provider] as $key => $envName) {
            $value = getenv($envName);
            if (is_string($value) && trim($value) !== '') {
                return [$key => trim($value)];
            }
        }
    }
    $enc = get_setting("accounting_{$provider}_credentials_encrypted", '');
    $iv  = get_setting("accounting_{$provider}_credentials_iv", '');
    if ($enc === '' || $iv === '') {
        return [];
    }
    $json = decrypt_passcode($enc, $iv);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function accounting_credentials_set(string $provider, array $credentials): void {
    $enc = encrypt_passcode(json_encode($credentials, JSON_UNESCAPED_UNICODE));
    set_setting("accounting_{$provider}_credentials_encrypted", $enc['encrypted']);
    set_setting("accounting_{$provider}_credentials_iv", $enc['iv']);
}

function accounting_credentials_configured(string $provider): bool {
    $creds = accounting_credentials_get($provider);
    return !empty($creds['api_key']) || !empty($creds['api_token']);
}

// ── Allgemeine Einstellungen ───────────────────────────────────────────
function accounting_active_provider(): string {
    $p = get_setting('accounting_active_provider', '');
    return isset(ACCOUNTING_PROVIDERS[$p]) ? $p : '';
}

function accounting_active_provider_set(string $provider): void {
    set_setting('accounting_active_provider', isset(ACCOUNTING_PROVIDERS[$provider]) ? $provider : '');
}

// Automatische Übertragung ist standardmäßig AUS – siehe Hinweis in
// private/accounting_adapters.php: erst nach erfolgreichem manuellem Test
// mit echten Zugangsdaten aktivieren.
function accounting_auto_sync_enabled(): bool {
    return get_setting('accounting_auto_sync_enabled', '0') === '1';
}

function accounting_auto_sync_enabled_set(bool $enabled): void {
    set_setting('accounting_auto_sync_enabled', $enabled ? '1' : '0');
}

function accounting_datev_settings(): array {
    return [
        'advisor_number'     => get_setting('accounting_datev_advisor_number', ''),
        'client_number'      => get_setting('accounting_datev_client_number', ''),
        'account_length'     => (int)get_setting('accounting_datev_account_length', '4'),
        'revenue_account'    => get_setting('accounting_datev_revenue_account', '8400'),
        'expense_account'    => get_setting('accounting_datev_expense_account', '3300'),
        'fiscal_year_start'  => get_setting('accounting_datev_fiscal_year_start', date('Y') . '-01-01'),
    ];
}

function accounting_datev_settings_set(array $data): void {
    set_setting('accounting_datev_advisor_number', (string)($data['advisor_number'] ?? ''));
    set_setting('accounting_datev_client_number', (string)($data['client_number'] ?? ''));
    set_setting('accounting_datev_account_length', (string)($data['account_length'] ?? '4'));
    set_setting('accounting_datev_revenue_account', (string)($data['revenue_account'] ?? '8400'));
    set_setting('accounting_datev_expense_account', (string)($data['expense_account'] ?? '3300'));
    set_setting('accounting_datev_fiscal_year_start', (string)($data['fiscal_year_start'] ?? (date('Y') . '-01-01')));
}

// ── Verbindungstest ────────────────────────────────────────────────────
function accounting_test_connection(string $provider): array {
    $adapter = accounting_adapter_factory($provider);
    if (!$adapter) {
        return ['success' => false, 'message' => 'Unbekannter Anbieter.'];
    }
    $result = $adapter->testConnection(accounting_credentials_get($provider));
    log_activity('accounting_test_connection', 'accounting', null, "Anbieter=$provider, Erfolg=" . ($result['success'] ? 'ja' : 'nein') . ', ' . $result['message']);
    return $result;
}

// ── Kontakt-Mapping (welcher Kunde/Lieferant = welcher externe Kontakt) ─
// Tabelle accounting_contact_mapping (neu, siehe sql/phase7_complete_integrations.sql).
function accounting_contact_mapping_get(string $provider, string $entityType, int $entityId): ?array {
    $stmt = get_db()->prepare(
        'SELECT * FROM accounting_contact_mapping WHERE provider = ? AND entity_type = ? AND entity_id = ? LIMIT 1'
    );
    $stmt->execute([$provider, $entityType, $entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function accounting_contact_mapping_set(string $provider, string $entityType, int $entityId, string $externalId): void {
    $stmt = get_db()->prepare(
        'INSERT INTO accounting_contact_mapping (provider, entity_type, entity_id, external_id, synced_at)
         VALUES (?,?,?,?, NOW())
         ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), synced_at = NOW()'
    );
    $stmt->execute([$provider, $entityType, $entityId, $externalId]);
}

function accounting_contact_mappings_list(string $provider = ''): array {
    $db = get_db();
    if ($provider !== '') {
        $stmt = $db->prepare('SELECT * FROM accounting_contact_mapping WHERE provider = ? ORDER BY synced_at DESC');
        $stmt->execute([$provider]);
    } else {
        $stmt = $db->query('SELECT * FROM accounting_contact_mapping ORDER BY synced_at DESC');
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kunde als Kontakt beim aktiven Anbieter anlegen/aktualisieren (idempotent über das Mapping). */
function accounting_sync_customer_contact(int $customerId, string $provider): array {
    $adapter = accounting_adapter_factory($provider);
    if (!$adapter) return ['success' => false, 'external_id' => null, 'message' => 'Unbekannter Anbieter.'];

    $stmt = get_db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) return ['success' => false, 'external_id' => null, 'message' => 'Kunde nicht gefunden.'];

    $existing = accounting_contact_mapping_get($provider, 'customer', $customerId);
    $result = $adapter->upsertContact(accounting_credentials_get($provider), [
        'is_supplier' => false,
        'first_name'  => $customer['first_name'],
        'last_name'   => $customer['last_name'],
    ], $existing['external_id'] ?? null);

    if ($result['success'] && $result['external_id']) {
        accounting_contact_mapping_set($provider, 'customer', $customerId, $result['external_id']);
    }
    log_activity('accounting_sync_contact', 'customers', $customerId, "Anbieter=$provider, Erfolg=" . ($result['success'] ? 'ja' : 'nein'));
    return $result;
}

/** Lieferant als Kontakt (Rolle "Lieferant") beim aktiven Anbieter anlegen/aktualisieren. */
function accounting_sync_supplier_contact(int $supplierId, string $provider): array {
    $adapter = accounting_adapter_factory($provider);
    if (!$adapter) return ['success' => false, 'external_id' => null, 'message' => 'Unbekannter Anbieter.'];

    $supplier = supplier_find($supplierId);
    if (!$supplier) return ['success' => false, 'external_id' => null, 'message' => 'Lieferant nicht gefunden.'];

    $existing = accounting_contact_mapping_get($provider, 'supplier', $supplierId);
    $result = $adapter->upsertContact(accounting_credentials_get($provider), [
        'is_supplier'  => true,
        'company_name' => $supplier['name'],
    ], $existing['external_id'] ?? null);

    if ($result['success'] && $result['external_id']) {
        accounting_contact_mapping_set($provider, 'supplier', $supplierId, $result['external_id']);
    }
    log_activity('accounting_sync_contact', 'suppliers', $supplierId, "Anbieter=$provider, Erfolg=" . ($result['success'] ? 'ja' : 'nein'));
    return $result;
}

// ── Beleg-Synchronisationsprotokoll (Dublettenschutz + Wiederholung) ───
// Tabelle accounting_document_sync (neu). Ein (provider, document_type,
// reference_id)-Tupel darf laut UNIQUE-Index nur EINMAL erfolgreich
// übertragen werden (Dublettenschutz) – ein erneuter Aufruf bei bereits
// vorhandenem "erfolgreich"-Eintrag wird als No-Op behandelt, siehe
// accounting_document_already_synced().
function accounting_document_already_synced(string $provider, string $documentType, int $referenceId): bool {
    $stmt = get_db()->prepare(
        "SELECT id FROM accounting_document_sync
          WHERE provider = ? AND document_type = ? AND reference_id = ? AND status = 'erfolgreich' LIMIT 1"
    );
    $stmt->execute([$provider, $documentType, $referenceId]);
    return (bool)$stmt->fetchColumn();
}

function accounting_document_sync_record(string $provider, string $documentType, int $referenceId, string $status, ?string $externalId, string $message): void {
    $stmt = get_db()->prepare(
        'INSERT INTO accounting_document_sync
            (provider, document_type, reference_id, status, external_id, message, attempts, last_attempt_at, created_at)
         VALUES (?,?,?,?,?,?,1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            status = VALUES(status), external_id = COALESCE(VALUES(external_id), external_id),
            message = VALUES(message), attempts = attempts + 1, last_attempt_at = NOW()'
    );
    $stmt->execute([$provider, $documentType, $referenceId, $status, $externalId, $message]);
}

function accounting_document_sync_list(array $filters = []): array {
    $where = [];
    $params = [];
    if (!empty($filters['provider']))      { $where[] = 'provider = ?'; $params[] = $filters['provider']; }
    if (!empty($filters['document_type'])) { $where[] = 'document_type = ?'; $params[] = $filters['document_type']; }
    if (!empty($filters['status']))        { $where[] = 'status = ?'; $params[] = $filters['status']; }
    $sql = 'SELECT * FROM accounting_document_sync';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY last_attempt_at DESC LIMIT 500';
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Überträgt eine bereits FREIGEGEBENE Rechnung (repairs.invoice_number
 * gesetzt, invoice_status='freigegeben' – siehe invoice_release() in
 * private/invoicing.php) an den Buchhaltungsanbieter. Der eingefrorene
 * Snapshot (repairs.invoice_snapshot) ist die maßgebliche Datenquelle für
 * Positionen/Beträge, nicht der ggf. inzwischen veränderte Live-Datensatz.
 */
function accounting_sync_repair_invoice(int $repairId, string $provider): array {
    $adapter = accounting_adapter_factory($provider);
    if (!$adapter) return ['success' => false, 'message' => 'Unbekannter Anbieter.'];

    if (accounting_document_already_synced($provider, 'invoice_repair', $repairId)) {
        return ['success' => true, 'message' => 'Bereits erfolgreich übertragen (Dublettenschutz), keine erneute Übertragung.'];
    }

    $stmt = get_db()->prepare('SELECT r.*, c.first_name, c.last_name, c.email FROM repairs r JOIN customers c ON c.id = r.customer_id WHERE r.id = ?');
    $stmt->execute([$repairId]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$repair) return ['success' => false, 'message' => 'Reparatur nicht gefunden.'];
    if (!invoice_is_released($repair)) {
        return ['success' => false, 'message' => 'Diese Rechnung ist noch nicht freigegeben (invoice_release()) – nur freigegebene, unveränderliche Rechnungen werden an die Buchhaltung übertragen.'];
    }

    $snapshot = json_decode((string)($repair['invoice_snapshot'] ?? ''), true) ?: [];
    $total = (float)($snapshot['total'] ?? $repair['price'] ?? 0);
    // Falls der Snapshot (wie in invoice_validate_for_release() berechnet)
    // keinen eigenen 'total'-Schlüssel enthält, aus Reparaturpreis +
    // Ersatzteilen neu ermitteln (identische Formel wie beim Freigabe-
    // Check, siehe invoice_validate_for_release()).
    if (!isset($snapshot['total'])) {
        $partsStmt = get_db()->prepare(
            'SELECT rp.quantity, COALESCE(rp.selling_price_at_time, p.selling_price, 0) AS unit_price
               FROM repair_parts rp JOIN parts p ON rp.part_id = p.id WHERE rp.repair_id = ?'
        );
        $partsStmt->execute([$repairId]);
        $partsTotal = 0.0;
        foreach ($partsStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $partsTotal += (float)$p['quantity'] * (float)$p['unit_price'];
        }
        $total = (float)$repair['price'] + $partsTotal;
    }

    $contactSync = accounting_sync_customer_contact((int)$repair['customer_id'], $provider);
    $externalContactId = $contactSync['external_id'] ?? (accounting_contact_mapping_get($provider, 'customer', (int)$repair['customer_id'])['external_id'] ?? null);

    $result = $adapter->pushInvoice(accounting_credentials_get($provider), [
        'date'     => $repair['invoice_released_at'] ?? date('c'),
        'currency' => 'EUR',
        'is_net'   => false,
        'address'  => ['name' => trim($repair['first_name'] . ' ' . $repair['last_name']), 'contactId' => $externalContactId],
        'line_items' => [[
            'type' => 'custom',
            'name' => 'Rechnung ' . $repair['invoice_number'] . ' – Reparatur ' . $repair['repair_number'] . ' (' . $repair['device_type'] . ')',
            'quantity' => 1,
            'unitPrice' => ['currency' => 'EUR', 'grossAmount' => $total],
        ]],
    ]);

    accounting_document_sync_record($provider, 'invoice_repair', $repairId, $result['success'] ? 'erfolgreich' : 'fehler', $result['external_id'] ?? null, $result['message']);
    log_activity('accounting_sync_invoice', 'repairs', $repairId, "Anbieter=$provider, Rechnungsnr.=" . $repair['invoice_number'] . ', Erfolg=' . ($result['success'] ? 'ja' : 'nein'));
    return $result;
}

/**
 * Überträgt eine bereits freigegebene Gutschrift/Stornorechnung (Tabelle
 * `invoice_corrections`, siehe invoice_correction_release() in
 * private/invoice_corrections.php) an den Buchhaltungsanbieter.
 */
function accounting_sync_invoice_correction(int $correctionId, string $provider): array {
    $adapter = accounting_adapter_factory($provider);
    if (!$adapter) return ['success' => false, 'message' => 'Unbekannter Anbieter.'];

    if (accounting_document_already_synced($provider, 'invoice_correction', $correctionId)) {
        return ['success' => true, 'message' => 'Bereits erfolgreich übertragen (Dublettenschutz), keine erneute Übertragung.'];
    }

    $ic = invoice_correction_find($correctionId);
    if (!$ic) return ['success' => false, 'message' => 'Korrekturbeleg nicht gefunden.'];
    if ($ic['status'] !== 'freigegeben') {
        return ['success' => false, 'message' => 'Dieser Korrekturbeleg ist noch nicht freigegeben (invoice_correction_release()) – nur freigegebene Belege werden übertragen.'];
    }

    $customerStmt = get_db()->prepare('SELECT * FROM customers WHERE id = ?');
    $customerStmt->execute([$ic['customer_id']]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $contactSync = accounting_sync_customer_contact((int)$ic['customer_id'], $provider);
    $externalContactId = $contactSync['external_id'] ?? (accounting_contact_mapping_get($provider, 'customer', (int)$ic['customer_id'])['external_id'] ?? null);

    $label = $ic['correction_type'] === 'storno' ? 'Stornorechnung' : 'Gutschrift';
    $result = $adapter->pushCreditNote(accounting_credentials_get($provider), [
        'date'     => $ic['released_at'] ?? date('c'),
        'currency' => 'EUR',
        'is_net'   => false,
        'address'  => ['name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), 'contactId' => $externalContactId],
        'line_items' => [[
            'type' => 'custom',
            'name' => $label . ' ' . $ic['correction_number'] . ' zu Rechnung ' . $ic['original_invoice_number'] . ($ic['reason'] ? ' – ' . $ic['reason'] : ''),
            'quantity' => 1,
            'unitPrice' => ['currency' => 'EUR', 'grossAmount' => (float)$ic['amount']],
        ]],
    ]);

    accounting_document_sync_record($provider, 'invoice_correction', $correctionId, $result['success'] ? 'erfolgreich' : 'fehler', $result['external_id'] ?? null, $result['message']);
    log_activity('accounting_sync_credit_note', 'invoice_corrections', $correctionId, "Anbieter=$provider, Belegnr.=" . $ic['correction_number'] . ', Erfolg=' . ($result['success'] ? 'ja' : 'nein'));
    return $result;
}

/** Bestellung (purchase_orders) als Eingangsbeleg (Lieferantenrechnung) übertragen. */
function accounting_sync_purchase_order_document(int $poId, string $provider): array {
    $adapter = accounting_adapter_factory($provider);
    if (!$adapter) return ['success' => false, 'message' => 'Unbekannter Anbieter.'];

    if (accounting_document_already_synced($provider, 'incoming_purchase_order', $poId)) {
        return ['success' => true, 'message' => 'Bereits erfolgreich übertragen (Dublettenschutz), keine erneute Übertragung.'];
    }

    $po = purchase_order_find($poId);
    if (!$po) return ['success' => false, 'message' => 'Bestellung nicht gefunden.'];
    $items = purchase_order_items_list($poId);
    if (empty($items)) return ['success' => false, 'message' => 'Bestellung hat keine Positionen.'];

    $contactSync = accounting_sync_supplier_contact((int)$po['supplier_id'], $provider);
    $externalContactId = $contactSync['external_id'] ?? (accounting_contact_mapping_get($provider, 'supplier', (int)$po['supplier_id'])['external_id'] ?? null);

    $grossAmount = 0.0;
    foreach ($items as $it) {
        $grossAmount += (float)($it['purchase_price_at_time'] ?? 0) * (int)$it['quantity'];
    }
    $grossAmount += (float)($po['shipping_cost'] ?? 0);

    $result = $adapter->pushIncomingDocument(accounting_credentials_get($provider), [
        'date'         => $po['ordered_at'] ?? date('c'),
        'gross_amount' => round($grossAmount, 2),
        'tax_amount'   => 0,
        'contactId'    => $externalContactId,
        'line_items'   => array_map(fn($it) => [
            'name'     => $it['part_id'],
            'quantity' => (int)$it['quantity'],
            'unitPrice'=> ['currency' => $it['currency'] ?? 'EUR', 'grossAmount' => (float)($it['purchase_price_at_time'] ?? 0)],
        ], $items),
    ]);

    accounting_document_sync_record($provider, 'incoming_purchase_order', $poId, $result['success'] ? 'erfolgreich' : 'fehler', $result['external_id'] ?? null, $result['message']);
    log_activity('accounting_sync_incoming_document', 'purchase_orders', $poId, "Anbieter=$provider, Erfolg=" . ($result['success'] ? 'ja' : 'nein'));
    return $result;
}

/** Wiederholt alle fehlgeschlagenen Übertragungen eines Anbieters (begrenzte Anzahl je Aufruf). */
function accounting_retry_failed_syncs(string $provider, int $limit = 20): array {
    $stmt = get_db()->prepare(
        "SELECT * FROM accounting_document_sync WHERE provider = ? AND status = 'fehler' ORDER BY last_attempt_at ASC LIMIT ?"
    );
    $stmt->bindValue(1, $provider);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $failed = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($failed as $row) {
        $result = match ($row['document_type']) {
            'invoice_repair'           => accounting_sync_repair_invoice((int)$row['reference_id'], $provider),
            'invoice_correction'       => accounting_sync_invoice_correction((int)$row['reference_id'], $provider),
            'incoming_purchase_order'  => accounting_sync_purchase_order_document((int)$row['reference_id'], $provider),
            default                    => ['success' => false, 'message' => 'Unbekannter Dokumenttyp – kann nicht automatisch wiederholt werden, siehe Protokoll.'],
        };
        $results[] = ['id' => $row['id'], 'document_type' => $row['document_type'], 'reference_id' => $row['reference_id'], 'result' => $result];
    }
    return $results;
}

// ── DATEV-Export ────────────────────────────────────────────────────────
/**
 * Exportiert freigegebene Rechnungen (Erlöse), freigegebene Gutschriften/
 * Stornorechnungen (Erlösminderung) und bestellte/gelieferte Bestellungen
 * (Aufwand) im Zeitraum als DATEV-EXTF-CSV. Nur FREIGEGEBENE (unveränder-
 * liche) Belege werden gebucht – siehe invoice_release()/
 * invoice_correction_release(). Kontonummern sind über
 * accounting_datev_settings() konfigurierbar (Platzhalter-Standardwerte
 * 8400/3300 – vor Verwendung mit dem tatsächlichen Kontenrahmen des
 * Steuerberaters abgleichen).
 */
function accounting_datev_export(string $fromDate, string $toDate): array {
    $settings = accounting_datev_settings();
    $db = get_db();

    $bookings = [];

    $stmt = $db->prepare(
        "SELECT id, repair_number, price, invoice_number, invoice_released_at, invoice_snapshot
           FROM repairs
          WHERE invoice_number IS NOT NULL AND invoice_status = 'freigegeben'
            AND invoice_released_at BETWEEN ? AND ?"
    );
    $stmt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $snapshot = json_decode((string)($r['invoice_snapshot'] ?? ''), true) ?: [];
        $amount = (float)($snapshot['total'] ?? $r['price'] ?? 0);
        if ($amount <= 0) continue;
        $bookings[] = [
            'amount'           => $amount,
            'is_debit'         => true,
            'account'          => $settings['revenue_account'],
            'contra_account'   => '10000',
            'date'             => $r['invoice_released_at'],
            'document_field_1' => $r['invoice_number'],
            'text'             => 'Rechnung ' . $r['invoice_number'] . ' (Reparatur ' . $r['repair_number'] . ')',
        ];
    }

    $stmt = $db->prepare(
        "SELECT ic.correction_number, ic.correction_type, ic.amount, ic.released_at, r.invoice_number
           FROM invoice_corrections ic JOIN repairs r ON r.id = ic.repair_id
          WHERE ic.status = 'freigegeben' AND ic.released_at BETWEEN ? AND ?"
    );
    $stmt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ic) {
        if ((float)$ic['amount'] <= 0) continue;
        $bookings[] = [
            'amount'           => (float)$ic['amount'],
            'is_debit'         => false, // Erlösminderung: Gegenbuchung zu Soll-Erlösen
            'account'          => $settings['revenue_account'],
            'contra_account'   => '10000',
            'date'             => $ic['released_at'],
            'document_field_1' => $ic['correction_number'],
            'text'             => ($ic['correction_type'] === 'storno' ? 'Storno' : 'Gutschrift') . ' ' . $ic['correction_number'] . ' zu ' . $ic['invoice_number'],
        ];
    }

    $stmt = $db->prepare(
        "SELECT po.id, po.order_number, po.ordered_at, po.shipping_cost,
                COALESCE(SUM(i.purchase_price_at_time * i.quantity), 0) AS items_total
           FROM purchase_orders po
           LEFT JOIN purchase_order_items i ON i.purchase_order_id = po.id
          WHERE po.status IN ('bestellt','teilweise_geliefert','geliefert')
            AND po.ordered_at BETWEEN ? AND ?
          GROUP BY po.id"
    );
    $stmt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $po) {
        $total = (float)$po['items_total'] + (float)($po['shipping_cost'] ?? 0);
        if ($total <= 0) continue;
        $bookings[] = [
            'amount'           => $total,
            'is_debit'         => false,
            'account'          => $settings['expense_account'],
            'contra_account'   => '70000',
            'date'             => $po['ordered_at'],
            'document_field_1' => $po['order_number'] ?? ('PO-' . $po['id']),
            'text'             => 'Bestellung ' . ($po['order_number'] ?? $po['id']),
        ];
    }

    $exporter = new DatevExporter();
    $meta = $settings + ['period_from' => $fromDate, 'period_to' => $toDate];
    $csv = $exporter->export($bookings, $meta);
    $filename = $exporter->filename($meta);

    log_activity('accounting_datev_export', 'accounting', null, "Zeitraum=$fromDate..$toDate, Buchungen=" . count($bookings));

    return ['csv' => $csv, 'filename' => $filename, 'booking_count' => count($bookings)];
}
