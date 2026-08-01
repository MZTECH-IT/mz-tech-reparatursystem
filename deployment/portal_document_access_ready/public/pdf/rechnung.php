<?php
/**
 * Rechnung als PDF – zugreifbar für Mitarbeiter (Admin-Session) UND für
 * den betroffenen Kunden über das Kundenportal (Gast-Zugang oder
 * registriertes Konto). Siehe pdf_authorize_repair_access() in
 * pdf_common.php für die Zugriffsprüfung (IDOR-geschützt).
 *
 * Dokumentenmodul ("Dokumente, Nummernkreise und Freigaben"):
 *   - Solange die Rechnung noch NICHT freigegeben ist (repairs.invoice_number
 *     leer bzw. invoice_status != "freigegeben"), zeigt dieses PDF eine
 *     LIVE berechnete Vorschau mit deutlich sichtbarem "ENTWURF"-Band und
 *     interner Referenznummer (#Auftrags-ID) – es handelt sich ausdrücklich
 *     um KEINE gültige Rechnung und verbraucht KEINE Rechnungsnummer.
 *   - Sobald die Rechnung freigegeben wurde, wird AUSSCHLIESSLICH aus dem
 *     zum Freigabezeitpunkt eingefrorenen Snapshot (repairs.invoice_snapshot)
 *     gerendert – spätere Änderungen an Reparatur/Ersatzteilen/Einstellungen
 *     können das bereits ausgestellte Dokument dadurch nicht mehr verändern
 *     (Abschnitt 9, Unveränderlichkeit).
 */
$private = dirname(__DIR__, 2) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/pdf_common.php';
require_once $private . '/invoicing.php';
require_once $private . '/payments.php';

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('Vendor-Verzeichnis fehlt oder ist unvollständig. Bitte die vendor/-Dateien aus dem Projekt-Paket erneut hochladen.');
}
require_once $autoload;

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Ungültige Auftrag-ID.');
}

$pdf_actor = pdf_authorize_repair_access($id);

$db = get_db();

// Reparatur + Kunde laden
$stmt = $db->prepare(
    'SELECT r.*, c.first_name, c.last_name, c.address, c.zip, c.city, c.phone, c.email,
            c.company_id, c.id AS customer_id,
            co.company_name, co.address AS company_address, co.zip AS company_zip,
            co.city AS company_city, co.phone AS company_phone, co.email AS company_email
     FROM repairs r
     JOIN customers c ON r.customer_id = c.id
     LEFT JOIN companies co ON co.id = c.company_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$repair = $stmt->fetch();

if (!$repair) {
    http_response_code(404);
    die('Reparatur nicht gefunden.');
}

$released = invoice_is_released($repair);
if ($pdf_actor !== 'staff' && !$released) {
    http_response_code(403);
    die('Diese Rechnung ist noch nicht für Kunden freigegeben.');
}
$snapshot = null;
if ($released && !empty($repair['invoice_snapshot'])) {
    $decoded = json_decode($repair['invoice_snapshot'], true);
    if (is_array($decoded)) $snapshot = $decoded;
}

// Reparaturnummer (unverändert, wie bisher)
$repair_number = !empty($repair['repair_number'])
    ? $repair['repair_number']
    : 'RA-' . str_pad($repair['id'], 4, '0', STR_PAD_LEFT);

if ($snapshot !== null) {
    // ── Freigegebene Rechnung: ausschließlich aus dem eingefrorenen Snapshot ──
    $invoice_number = $snapshot['invoice_number'];
    $company_info   = $snapshot['company'] ?? pdf_company_info();
    $parts_rows     = $snapshot['parts'] ?? [];
    $repair_snap    = $snapshot['repair'] ?? $repair;
    $customer_snap  = $snapshot['customer'] ?? $repair;
    $customer_company_snap = $snapshot['customer_company'] ?? null;
    $repair_price   = (float)($repair_snap['price'] ?? 0);
    $labor_cost     = (float)($repair_snap['labor_cost'] ?? 0);
    $working_hours  = (float)($repair_snap['working_hours'] ?? 0);
    $hourly_rate    = (float)($repair_snap['hourly_rate'] ?? 0);
    $performed_work = trim((string)($repair_snap['performed_work'] ?? ''));
    $deposit        = (float)($repair_snap['advance_payment'] ?? 0);
    $billing_snap   = $snapshot['billing'] ?? [];
    $tax_rate       = (float)($billing_snap['tax_rate'] ?? $snapshot['tax_rate'] ?? 0);
    $ustg           = (bool)($billing_snap['small_business'] ?? $snapshot['ustg_active'] ?? false);
    $ustg_notice    = $billing_snap['legal_notice'] ?? $snapshot['ustg_notice'] ?? pdf_ustg_notice_text();
    $service_date   = $snapshot['service_date'] ?? ($repair_snap['service_date'] ?? null);
    $payment_terms  = (string)($snapshot['payment_terms'] ?? '');
    $payment_status = (string)($snapshot['payment']['payment_status'] ?? 'offen');
    $payment_due    = $snapshot['payment']['payment_due_date'] ?? null;
    $invoice_date   = !empty($snapshot['released_at']) ? date('d.m.Y', strtotime($snapshot['released_at'])) : date('d.m.Y');
    $cust_first     = $customer_snap['first_name'] ?? $repair['first_name'];
    $cust_last      = $customer_snap['last_name']  ?? $repair['last_name'];
    $recipient_company = is_array($customer_company_snap) ? trim((string)($customer_company_snap['company_name'] ?? '')) : '';
    $cust_address   = $recipient_company !== '' ? ($customer_company_snap['address'] ?? '') : ($customer_snap['address'] ?? $repair['address']);
    $cust_zip       = $recipient_company !== '' ? ($customer_company_snap['zip'] ?? '') : ($customer_snap['zip'] ?? $repair['zip'] ?? '');
    $cust_city      = $recipient_company !== '' ? ($customer_company_snap['city'] ?? '') : ($customer_snap['city'] ?? $repair['city'] ?? '');
    $cust_phone     = $recipient_company !== '' ? ($customer_company_snap['phone'] ?? $customer_snap['phone'] ?? '') : ($customer_snap['phone'] ?? $repair['phone']);
    $cust_email     = $recipient_company !== '' ? ($customer_company_snap['email'] ?? $customer_snap['email'] ?? '') : ($customer_snap['email'] ?? $repair['email']);
    $draftLabel     = null;
} else {
    // ── Entwurf: live berechnete Vorschau, KEINE gültige Rechnung ──
    $company_info = pdf_company_info();

    $stmt2 = $db->prepare(
        'SELECT rp.quantity,
                COALESCE(rp.selling_price_at_time, p.selling_price, 0) AS unit_price,
                p.name AS part_name
         FROM repair_parts rp
         JOIN parts p ON rp.part_id = p.id
         WHERE rp.repair_id = ?'
    );
    $stmt2->execute([$id]);
    $parts_rows = $stmt2->fetchAll();

    $invoice_number = 'Entwurf #' . $id;
    $repair_price   = isset($repair['price']) ? (float)$repair['price'] : 0.0;
    $labor_cost     = isset($repair['labor_cost']) ? (float)$repair['labor_cost'] : 0.0;
    $working_hours  = isset($repair['working_hours']) ? (float)$repair['working_hours'] : 0.0;
    $hourly_rate    = isset($repair['hourly_rate']) ? (float)$repair['hourly_rate'] : 0.0;
    $performed_work = trim((string)($repair['performed_work'] ?? ''));
    $deposit        = isset($repair['advance_payment']) ? (float)$repair['advance_payment'] : 0.0;
    $ustg           = pdf_ustg_active();
    $tax_rate       = (float)str_replace(',', '.', get_setting('tax_rate', '0'));
    $ustg_notice    = pdf_ustg_notice_text();
    $service_date   = $repair['service_date'] ?? null;
    $payment_terms  = (string)get_setting('payment_terms', '');
    $payment_status = (string)($repair['payment_status'] ?? 'offen');
    $payment_due    = $repair['payment_due_date'] ?? null;
    $invoice_date   = date('d.m.Y');
    $cust_first     = $repair['first_name'];
    $cust_last      = $repair['last_name'];
    $recipient_company = trim((string)($repair['company_name'] ?? ''));
    $cust_address   = $recipient_company !== '' ? ($repair['company_address'] ?? '') : $repair['address'];
    $cust_zip       = $recipient_company !== '' ? ($repair['company_zip'] ?? '') : ($repair['zip'] ?? '');
    $cust_city      = $recipient_company !== '' ? ($repair['company_city'] ?? '') : ($repair['city'] ?? '');
    $cust_phone     = $recipient_company !== '' ? ($repair['company_phone'] ?: $repair['phone']) : $repair['phone'];
    $cust_email     = $recipient_company !== '' ? ($repair['company_email'] ?: $repair['email']) : $repair['email'];
    $draftLabel     = 'ENTWURF – Vorläufige Vorschau, keine gültige Rechnung, interne Referenz #' . $id;
}

// Berechnung
$parts_total = 0.0;
foreach ($parts_rows as $part) {
    $parts_total += (float)$part['quantity'] * (float)$part['unit_price'];
}
$amounts = $snapshot['amounts'] ?? billing_amounts($repair_price + $labor_cost + $parts_total, $ustg ? BILLING_MODE_SMALL_BUSINESS : BILLING_MODE_STANDARD_TAX, $tax_rate);
$subtotal = (float)$amounts['subtotal'];
$mwst     = (float)$amounts['tax_amount'];
$gesamt   = (float)$amounts['total'];
// Positionen, Steuermodus und Gesamtbetrag bleiben im Freigabe-Snapshot
// unveränderlich. Der Zahlungsstand entsteht dagegen nach der Freigabe und
// muss deshalb aktuelle Zahlungen berücksichtigen. Interne Zahlungsnotizen
// werden hierfür nicht geladen.
if ($released) {
    $payment_summary = payment_summary($repair);
    $paid = (float)$payment_summary['paid'];
    $offen = (float)$payment_summary['open'];
    $payment_status = (string)$payment_summary['status'];
} else {
    $paid = $deposit;
    $offen = max(0, $gesamt - $paid);
}

// PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Rechnung ' . $invoice_number);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// ── Einheitlicher Dokumentkopf (Logo + Firmendaten + ggf. Entwurfs-Band) ──
$headerBottom = pdf_draw_header($pdf, $company_info, $draftLabel);

// ── Kundenadresse (Brieffenster rechts) ──────────────
$pdf->SetFont('helvetica', '', 10);
$pdf->SetXY(120, $headerBottom);
$full_name = trim((string)$cust_first . ' ' . (string)$cust_last);
if ($recipient_company !== '') {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(75, 5, $recipient_company, 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    if ($full_name !== '') {
        $pdf->SetX(120);
        $pdf->Cell(75, 5, 'z. Hd. ' . $full_name, 0, 1, 'L');
    }
} else {
    $pdf->Cell(75, 5, $full_name, 0, 1, 'L');
}
if ($cust_address) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, $cust_address, 0, 1, 'L');
}
$location = trim((string)$cust_zip . ' ' . (string)$cust_city);
if ($location !== '') {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, $location, 0, 1, 'L');
}
if ($cust_phone) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, 'Tel: ' . $cust_phone, 0, 1, 'L');
}
if ($cust_email) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, $cust_email, 0, 1, 'L');
}

// ── Rechnungstitel ────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(15, $headerBottom);
$pdf->Cell(95, 10, $released ? 'RECHNUNG' : 'RECHNUNGSENTWURF', 0, 1, 'L');

// Metadaten
$pdf->SetFont('helvetica', '', 10);
$pdf->SetX(15);
$pdf->Cell(95, 5, ($released ? 'Rechnungsnummer: ' : 'Interne Referenz: ') . $invoice_number, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Datum: ' . $invoice_date, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Leistungsdatum: ' . ($service_date ? date('d.m.Y', strtotime((string)$service_date)) : $invoice_date), 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Kundennummer: K-' . str_pad($repair['customer_id'], 4, '0', STR_PAD_LEFT), 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Auftragsnummer: ' . $repair_number, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Zahlungsstatus: ' . ucfirst(str_replace('_', ' ', $payment_status)), 0, 1, 'L');
if ($payment_due) {
    $pdf->SetX(15);
    $pdf->Cell(95, 5, 'Zahlbar bis: ' . date('d.m.Y', strtotime((string)$payment_due)), 0, 1, 'L');
}

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// ── Rechnungstabelle ──────────────────────────────────
$col_w = [10, 90, 20, 30, 30]; // Pos | Beschreibung | Menge | Einzelpreis | Gesamt

// Tabellenkopf
$pdf->SetFillColor(200, 210, 220);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$headers = ['Pos', 'Beschreibung', 'Menge', 'Einzelpreis', 'Gesamt'];
$aligns  = ['C',   'L',            'C',     'R',            'R'];
$x_start = 15;
$pdf->SetX($x_start);
foreach ($headers as $i => $h) {
    $pdf->Cell($col_w[$i], 7, $h, 0, 0, $aligns[$i], true);
}
$pdf->Ln();

// Tabellenzeilen
$pdf->SetFont('helvetica', '', 9);
$pos = 1;
$row_colors = [[255, 255, 255], [245, 247, 250]];

$drawRow = function(
    TCPDF $pdf,
    array $col_w,
    int &$pos,
    array $row_colors,
    string $desc,
    string $qty,
    float $unit,
    float $total
) use ($x_start) {
    $ci = ($pos % 2 === 1) ? 0 : 1;
    $pdf->SetFillColor(...$row_colors[$ci]);
    $pdf->SetX($x_start);
    $pdf->Cell($col_w[0], 6, (string)$pos,                           0, 0, 'C', true);
    $pdf->Cell($col_w[1], 6, $desc,                                   0, 0, 'L', true);
    $pdf->Cell($col_w[2], 6, $qty,                                    0, 0, 'C', true);
    $pdf->Cell($col_w[3], 6, number_format($unit,  2, ',', '.') . ' €', 0, 0, 'R', true);
    $pdf->Cell($col_w[4], 6, number_format($total, 2, ',', '.') . ' €', 0, 0, 'R', true);
    $pdf->Ln();
    $pos++;
};

// Zeile 1: Reparaturdienstleistung
$problem_source = $snapshot !== null ? ($repair_snap['problem_description'] ?? '') : ($repair['problem_description'] ?? '');
$device_source  = device_type_label((string)($snapshot !== null ? ($repair_snap['device_type'] ?? 'Gerät') : ($repair['device_type'] ?? 'Gerät')));
$problem_short = mb_strlen($problem_source) > 55
    ? mb_substr($problem_source, 0, 52) . '...'
    : $problem_source;
$repair_desc = $device_source . ' – ' . $problem_short;
$drawRow($pdf, $col_w, $pos, $row_colors, $repair_desc, '1', $repair_price, $repair_price);

if ($working_hours > 0.0 || $labor_cost > 0.0) {
    $labor_desc = sprintf(
        'Arbeitsleistung: %s Stunden × %s € netto',
        number_format($working_hours, 2, ',', '.'),
        number_format($hourly_rate, 2, ',', '.')
    );
    $drawRow($pdf, $col_w, $pos, $row_colors, $labor_desc, '1', $labor_cost, $labor_cost);
}

// Weitere Zeilen: Ersatzteile
foreach ($parts_rows as $part) {
    $qty        = (int)$part['quantity'];
    $unit_price = (float)$part['unit_price'];
    $line_total = $qty * $unit_price;
    $part_name  = is_array($part) ? ($part['part_name'] ?? '') : '';
    $drawRow($pdf, $col_w, $pos, $row_colors, $part_name, (string)$qty, $unit_price, $line_total);
}

if ($performed_work !== '') {
    $pdf->SetY($pdf->GetY() + 4);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Durchgeführte Arbeiten', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(0, 4.5, $performed_work, 0, 'L');
}

$pdf->SetY($pdf->GetY() + 3);

// ── Summenblock ───────────────────────────────────────
$sum_x = 120;
$sum_lw = 45;
$sum_rw = 30;

$pdf->SetFont('helvetica', '', 10);
if (!$ustg) {
    $pdf->SetX($sum_x);
    $pdf->Cell($sum_lw, 6, 'Zwischensumme (netto):', 0, 0, 'R');
    $pdf->Cell($sum_rw, 6, number_format($subtotal, 2, ',', '.') . ' €', 0, 1, 'R');

    $mwst_label = 'MwSt. ' . rtrim(rtrim(number_format($tax_rate, 1, ',', '.'), '0'), ',') . ' %:';
    $pdf->SetX($sum_x);
    $pdf->Cell($sum_lw, 6, $mwst_label, 0, 0, 'R');
    $pdf->Cell($sum_rw, 6, number_format($mwst, 2, ',', '.') . ' €', 0, 1, 'R');
}

$pdf->SetDrawColor(100, 100, 100);
$pdf->SetLineWidth(0.3);
$pdf->Line($sum_x, $pdf->GetY(), 195, $pdf->GetY());

$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetX($sum_x);
$pdf->Cell($sum_lw, 7, $ustg ? 'Gesamtbetrag:' : 'Gesamtbetrag (brutto):', 0, 0, 'R');
$pdf->Cell($sum_rw, 7, number_format($gesamt, 2, ',', '.') . ' €', 0, 1, 'R');

if ($paid > 0) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetX($sum_x);
    $pdf->Cell($sum_lw, 6, $released ? 'Bereits bezahlt:' : 'Anzahlung:', 0, 0, 'R');
    $pdf->Cell($sum_rw, 6, '- ' . number_format($paid, 2, ',', '.') . ' €', 0, 1, 'R');
}

$pdf->SetDrawColor(0, 87, 184);
$pdf->SetLineWidth(0.6);
$pdf->Line($sum_x, $pdf->GetY(), 195, $pdf->GetY());
$pdf->SetLineWidth(0.2);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(0, 87, 184);
$pdf->SetX($sum_x);
$pdf->Cell($sum_lw, 8, 'Offener Betrag:', 0, 0, 'R');
$pdf->Cell($sum_rw, 8, number_format($offen, 2, ',', '.') . ' €', 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 8);

// ── § 19 UStG-Hinweis (Kleinunternehmerregelung) ──────
if ($ustg) {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, $ustg_notice, 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($pdf->GetY() + 4);
}

if ($payment_terms !== '') {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(15);
    $pdf->Cell(180, 5, 'Zahlungsbedingungen', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, $payment_terms, 0, 'L');
    $pdf->SetY($pdf->GetY() + 4);
}

// ── Bankdaten ─────────────────────────────────────────
$company_iban   = $company_info['iban']   ?? '';
$company_bic    = $company_info['bic']    ?? '';
$company_tax_id = $company_info['steuernummer'] ?? ($company_info['tax_id'] ?? '');
if ($company_iban || $company_bic || $company_tax_id) {
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(15);
    $pdf->Cell(180, 6, 'Bankverbindung', 0, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    if ($company_iban) {
        $pdf->SetX(15);
        $pdf->Cell(40, 5, 'IBAN:', 0, 0, 'L');
        $pdf->Cell(140, 5, $company_iban, 0, 1, 'L');
    }
    if ($company_bic) {
        $pdf->SetX(15);
        $pdf->Cell(40, 5, 'BIC:', 0, 0, 'L');
        $pdf->Cell(140, 5, $company_bic, 0, 1, 'L');
    }
    if ($company_tax_id) {
        $pdf->SetX(15);
        $pdf->Cell(40, 5, 'Steuer-Nr.:', 0, 0, 'L');
        $pdf->Cell(140, 5, $company_tax_id, 0, 1, 'L');
    }
    $pdf->SetY($pdf->GetY() + 5);
}

// ── Footer ────────────────────────────────────────────
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(15);
$pdf->Cell(0, 6, 'Vielen Dank für Ihr Vertrauen in ' . ($company_info['name'] ?? 'MZ Tech') . '!', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

// ── Kundenportal-Fußbereich (QR-Code + Link) – nur bei freigegebener Rechnung sinnvoll,
//    wird aber auch im Entwurf angezeigt, damit der Kunde ohnehin nur eigene Aufträge sieht ──
pdf_draw_portal_footer($pdf, $repair['customer_id'] ?? null);

$pdf->Output('rechnung.pdf', 'I');
