<?php
/**
 * Kostenvoranschlag als PDF – zugreifbar für Mitarbeiter (Admin-Session)
 * UND für den betroffenen Kunden über das Kundenportal (Gast-Zugang oder
 * registriertes Konto). Siehe pdf_authorize_repair_access() in
 * pdf_common.php für die Zugriffsprüfung (IDOR-geschützt).
 *
 * Solange repairs.quote_status = "entwurf" ist, zeigt dieses PDF ein
 * deutliches "ENTWURF"-Band (Auftragsabschnitt 1) und verwendet eine
 * interne Referenz statt einer echten KV-Nummer. Erst nach Freigabe
 * (quote_of_repair_release(), siehe private/invoicing.php) wird die
 * tatsächlich vergebene Nummer (repairs.quote_number) verwendet.
 */
$private = dirname(__DIR__, 2) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/pdf_common.php';
require_once $private . '/invoicing.php';

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

pdf_authorize_repair_access($id);

$db = get_db();

// Reparatur + Kunde laden
$stmt = $db->prepare(
    'SELECT r.*, c.first_name, c.last_name, c.address, c.phone, c.email, c.id as customer_id
     FROM repairs r
     JOIN customers c ON r.customer_id = c.id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$repair = $stmt->fetch();

if (!$repair) {
    http_response_code(404);
    die('Reparatur nicht gefunden.');
}

// Reparaturteile laden
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

$repair_number = !empty($repair['repair_number'])
    ? $repair['repair_number']
    : 'RA-' . str_pad($repair['id'], 4, '0', STR_PAD_LEFT);

$quote_status   = $repair['quote_status'] ?? 'entwurf';
$quote_released = !empty($repair['quote_number']) && $quote_status !== 'entwurf';
$kv_number      = $quote_released ? $repair['quote_number'] : ('Entwurf #' . $id);
$draftLabel     = $quote_released ? null : ('ENTWURF – Vorläufige Vorschau, kein verbindlicher Kostenvoranschlag, interne Referenz #' . $id);

// Firmeninfo
$company_info = pdf_company_info();

// Berechnung
$repair_price = isset($repair['price']) ? (float)$repair['price'] : 0.0;

$parts_total = 0.0;
foreach ($parts_rows as $part) {
    $parts_total += (float)$part['quantity'] * (float)$part['unit_price'];
}

$subtotal = $repair_price + $parts_total;
$ustg     = pdf_ustg_active();
$tax_rate = (float)str_replace(',', '.', get_setting('tax_rate', '0'));
$mwst     = $ustg ? 0.0 : $subtotal * ($tax_rate / 100);
$gesamt   = $subtotal + $mwst;

// PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Kostenvoranschlag ' . $kv_number);
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
$full_name = trim($repair['first_name'] . ' ' . $repair['last_name']);
$pdf->Cell(75, 5, $full_name, 0, 1, 'L');
if ($repair['address']) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, $repair['address'], 0, 1, 'L');
}
if ($repair['phone']) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, 'Tel: ' . $repair['phone'], 0, 1, 'L');
}
if ($repair['email']) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, $repair['email'], 0, 1, 'L');
}

// ── Titel ─────────────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(15, $headerBottom);
$pdf->Cell(95, 10, 'KOSTENVORANSCHLAG', 0, 1, 'L');

// Metadaten
$kv_date = date('d.m.Y');
$kv_valid_until = date('d.m.Y', strtotime('+30 days'));
$pdf->SetFont('helvetica', '', 10);
$pdf->SetX(15);
$pdf->Cell(95, 5, ($quote_released ? 'Nummer: ' : 'Interne Referenz: ') . $kv_number, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Datum: ' . $kv_date, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Gültig bis: ' . $kv_valid_until, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Auftragsnummer: ' . $repair_number, 0, 1, 'L');

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// ── Positionstabelle ──────────────────────────────────
$col_w = [10, 90, 20, 30, 30];

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

$problem_short = mb_strlen($repair['problem_description'] ?? '') > 55
    ? mb_substr($repair['problem_description'], 0, 52) . '...'
    : ($repair['problem_description'] ?? '');
$repair_desc = ($repair['device_type'] ?? 'Gerät') . ' – ' . $problem_short;
$drawRow($pdf, $col_w, $pos, $row_colors, $repair_desc, '1', $repair_price, $repair_price);

foreach ($parts_rows as $part) {
    $qty        = (int)$part['quantity'];
    $unit_price = (float)$part['unit_price'];
    $line_total = $qty * $unit_price;
    $drawRow($pdf, $col_w, $pos, $row_colors, $part['part_name'], (string)$qty, $unit_price, $line_total);
}

if (count($parts_rows) === 0 && $repair_price <= 0.0) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetX($x_start);
    $pdf->Cell(180, 6, 'Kosten werden nach Diagnose ermittelt.', 0, 1, 'L');
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

$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.6);
$pdf->Line($sum_x, $pdf->GetY(), 195, $pdf->GetY());
$pdf->SetLineWidth(0.2);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(41, 128, 185);
$pdf->SetX($sum_x);
$pdf->Cell($sum_lw, 8, $ustg ? 'Voraussichtl. Gesamt:' : 'Voraussichtl. Gesamt (brutto):', 0, 0, 'R');
$pdf->Cell($sum_rw, 8, number_format($gesamt, 2, ',', '.') . ' €', 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 8);

// ── § 19 UStG-Hinweis ──────────────────────────────────
if ($ustg) {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, pdf_ustg_notice_text(), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($pdf->GetY() + 4);
}

// ── Unverbindlichkeitshinweis ──────────────────────────
$pdf->SetFillColor(240, 248, 255);
$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.4);
$gy = $pdf->GetY();
$pdf->Rect(15, $gy, 180, 20, 'DF');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(17, $gy + 1.5);
$pdf->Cell(176, 5, 'Hinweis:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(
    176, 4,
    'Dieser Kostenvoranschlag ist unverbindlich und freibleibend. Er gilt bis zum oben genannten Datum. ' .
    'Sollten sich bei der Reparatur zusätzliche, hier nicht erkennbare Mängel zeigen, informieren wir Sie ' .
    'vor Durchführung weiterer, kostenpflichtiger Arbeiten.',
    0, 'L'
);
$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 6);

// ── Footer ────────────────────────────────────────────
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(15);
$pdf->Cell(0, 6, 'Vielen Dank für Ihr Vertrauen in ' . ($company_info['name'] ?? 'MZ Tech') . '!', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

// ── Kundenportal-Fußbereich (QR-Code + Link) ──────────
pdf_draw_portal_footer($pdf, $repair['customer_id'] ?? null);

$pdf->Output('kostenvoranschlag.pdf', 'I');
