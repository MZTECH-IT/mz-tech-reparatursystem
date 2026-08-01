<?php
/**
 * Angebot als PDF – zugreifbar für Mitarbeiter (Admin-Session). Anders als
 * Rechnung/Kostenvoranschlag ist ein Angebot (siehe private/quotes.php)
 * NICHT zwingend an einen Reparaturauftrag gebunden, daher kommt hier
 * NICHT pdf_authorize_repair_access() zum Einsatz (das würde eine
 * repair_id voraussetzen), sondern eine reine Mitarbeiter-Session-Prüfung
 * (erster Zweig von pdf_authorize_repair_access(), ohne den Kundenportal-
 * Zweig – Angebote sind aktuell noch nicht im Kundenportal sichtbar).
 *
 * Dokumentenmodul ("Dokumente, Nummernkreise und Freigaben"):
 *   - Solange quotes.status = "entwurf" ist, zeigt dieses PDF eine LIVE
 *     berechnete Vorschau mit deutlich sichtbarem "ENTWURF"-Band und
 *     interner Referenznummer (#Angebots-ID) – es handelt sich
 *     ausdrücklich um KEIN gültiges Angebot und verbraucht KEINE
 *     Angebotsnummer.
 *   - Nach der Freigabe wird AUSSCHLIESSLICH aus dem zum Freigabezeitpunkt
 *     eingefrorenen Snapshot (quotes.snapshot_json) gerendert – spätere
 *     Änderungen an Kunde/Einstellungen können das bereits ausgestellte
 *     Dokument dadurch nicht mehr verändern (Abschnitt 9, Unveränderlichkeit).
 */
$private = dirname(__DIR__, 2) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/pdf_common.php';
require_once $private . '/quotes.php';

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('Vendor-Verzeichnis fehlt oder ist unvollständig. Bitte die vendor/-Dateien aus dem Projekt-Paket erneut hochladen.');
}
require_once $autoload;

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Ungültige Angebots-ID.');
}

// ── Zugriffsprüfung: reine Mitarbeiter-Session (kein Kundenportal-Zugriff) ──
if (!empty($_COOKIE[SESSION_NAME] ?? null)) {
    require_once PRIVATE_PATH . '/auth.php';
    start_secure_session();
}
$quote = quote_find($id);
if (!$quote) {
    http_response_code(404);
    die('Angebot nicht gefunden.');
}
pdf_authorize_quote_access($quote);

$released = $quote['status'] !== 'entwurf';
$snapshot = null;
if ($released && !empty($quote['snapshot_json'])) {
    $decoded = json_decode($quote['snapshot_json'], true);
    if (is_array($decoded)) $snapshot = $decoded;
}

if ($snapshot !== null) {
    // ── Freigegebenes Angebot: ausschließlich aus dem eingefrorenen Snapshot ──
    $quote_number = $snapshot['quote_number'];
    $company_info = $snapshot['company'] ?? pdf_company_info();
    $items_rows   = $snapshot['items'] ?? [];
    $quote_snap   = $snapshot['quote'] ?? $quote;
    $customer_snap = $snapshot['customer'] ?? null;
    $customer_company_snap = $snapshot['customer_company'] ?? null;

    $subtotal   = (float)($quote_snap['subtotal'] ?? 0);
    $tax_amount = (float)($quote_snap['tax_amount'] ?? 0);
    $total      = (float)($quote_snap['total'] ?? 0);
    $tax_rate   = (float)($quote_snap['tax_rate'] ?? 0);
    $billing_snap = $snapshot['billing'] ?? [];
    $ustg       = !empty($billing_snap['small_business']) || (($quote_snap['billing_mode'] ?? '') === BILLING_MODE_SMALL_BUSINESS);
    $legal_notice = (string)($billing_snap['legal_notice'] ?? ($quote_snap['legal_notice'] ?? BILLING_SMALL_BUSINESS_NOTICE));
    $quote_date = !empty($snapshot['released_at']) ? date('d.m.Y', strtotime($snapshot['released_at'])) : date('d.m.Y');
    $valid_until_raw = $quote_snap['valid_until'] ?? null;

    $title = $quote_snap['title'] ?? '';
    $notes = $quote_snap['notes'] ?? '';
    $planned_work = $quote_snap['planned_work'] ?? '';

    if ($customer_snap) {
        $cust_name = trim(($customer_snap['first_name'] ?? '') . ' ' . ($customer_snap['last_name'] ?? ''));
        $cust_address = $customer_snap['address'] ?? '';
        $cust_phone   = $customer_snap['phone']   ?? '';
        $cust_email   = $customer_snap['email']   ?? '';
    } else {
        $cust_name    = $customer_company_snap['company_name'] ?? '';
        $cust_address = $customer_company_snap['address'] ?? '';
        $cust_phone   = $customer_company_snap['phone'] ?? '';
        $cust_email   = $customer_company_snap['email'] ?? '';
    }
    if (!empty($customer_company_snap['company_name'])) {
        $cust_name = $customer_company_snap['company_name'];
    }

    $draftLabel = null;
} else {
    // ── Entwurf: live berechnete Vorschau, KEIN gültiges Angebot ──
    $company_info = pdf_company_info();
    $items_rows   = quote_items_list($id);

    $subtotal = 0.0;
    foreach ($items_rows as $it) {
        $subtotal += (float)$it['quantity'] * (float)$it['unit_price'];
    }
    $amounts = billing_amounts($subtotal);
    $tax_rate   = (float)$amounts['tax_rate'];
    $ustg       = billing_is_small_business();
    $tax_amount = (float)$amounts['tax_amount'];
    $total      = (float)$amounts['total'];
    $legal_notice = $amounts['legal_notice'];

    $quote_number    = 'Entwurf #' . $id;
    $quote_date      = date('d.m.Y');
    $valid_until_raw = $quote['valid_until'] ?? null;
    $title = $quote['title'] ?? '';
    $notes = $quote['notes'] ?? '';
    $planned_work = $quote['planned_work'] ?? '';

    $cust_name    = $quote['company_name'] ?: trim(($quote['first_name'] ?? '') . ' ' . ($quote['last_name'] ?? ''));
    $cust_address = $quote['address'] ?? '';
    $cust_phone   = $quote['phone']   ?? '';
    $cust_email   = $quote['email']   ?? '';

    $draftLabel = 'ENTWURF – Vorläufiges Angebot, keine verbindliche Nummer, interne Referenz #' . $id;
}

$valid_until = $valid_until_raw ? date('d.m.Y', strtotime((string)$valid_until_raw)) : '—';

// PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Angebot ' . $quote_number);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// ── Einheitlicher Dokumentkopf (Logo + Firmendaten + ggf. Entwurfs-Band) ──
$headerBottom = pdf_draw_header($pdf, $company_info, $draftLabel);

// ── Kunden-/Firmenadresse (Brieffenster rechts) ──────────────
$pdf->SetFont('helvetica', '', 10);
$pdf->SetXY(120, $headerBottom);
$pdf->Cell(75, 5, (string)$cust_name, 0, 1, 'L');
if (!empty($cust_address)) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, (string)$cust_address, 0, 1, 'L');
}
if (!empty($cust_phone)) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, 'Tel: ' . $cust_phone, 0, 1, 'L');
}
if (!empty($cust_email)) {
    $pdf->SetX(120);
    $pdf->Cell(75, 5, (string)$cust_email, 0, 1, 'L');
}

// ── Titel ─────────────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(15, $headerBottom);
$pdf->Cell(95, 10, $released ? 'ANGEBOT' : 'ANGEBOTSENTWURF', 0, 1, 'L');

// Metadaten
$pdf->SetFont('helvetica', '', 10);
$pdf->SetX(15);
$pdf->Cell(95, 5, ($released ? 'Nummer: ' : 'Interne Referenz: ') . $quote_number, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Datum: ' . $quote_date, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Gültig bis: ' . $valid_until, 0, 1, 'L');
if (!empty($title)) {
    $pdf->SetX(15);
    $pdf->Cell(95, 5, 'Titel: ' . $title, 0, 1, 'L');
}

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// ── Positionstabelle ──────────────────────────────────
$col_w = [10, 90, 20, 30, 30]; // Pos | Beschreibung | Menge | Einzelpreis | Gesamt

$pdf->SetFillColor(200, 210, 220);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$headers = ['Pos', 'Beschreibung', 'Menge', 'Einzelpreis', 'Gesamt'];
$aligns  = ['C',   'L',            'C',     'R',            'R'];
$x_start = 15;
$pdf->SetX($x_start);
foreach ($headers as $i => $hh) {
    $pdf->Cell($col_w[$i], 7, $hh, 0, 0, $aligns[$i], true);
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

foreach ($items_rows as $item) {
    $qty        = (float)$item['quantity'];
    $unit_price = (float)$item['unit_price'];
    $line_total = $qty * $unit_price;
    $desc       = is_array($item) ? ($item['description'] ?? '') : '';
    $qtyLabel = rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',');
    $drawRow($pdf, $col_w, $pos, $row_colors, $desc, $qtyLabel, $unit_price, $line_total);
}

if (count($items_rows) === 0) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetX($x_start);
    $pdf->Cell(180, 6, 'Keine Positionen erfasst.', 0, 1, 'L');
}

$pdf->SetY($pdf->GetY() + 3);

// ── Summenblock ───────────────────────────────────────
$sum_x  = 120;
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
    $pdf->Cell($sum_rw, 6, number_format($tax_amount, 2, ',', '.') . ' €', 0, 1, 'R');
}

$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.6);
$pdf->Line($sum_x, $pdf->GetY(), 195, $pdf->GetY());
$pdf->SetLineWidth(0.2);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(41, 128, 185);
$pdf->SetX($sum_x);
$pdf->Cell($sum_lw, 8, $ustg ? 'Gesamt:' : 'Gesamt (brutto):', 0, 0, 'R');
$pdf->Cell($sum_rw, 8, number_format($total, 2, ',', '.') . ' €', 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 8);

// ── § 19 UStG-Hinweis ──────────────────────────────────
if ($ustg) {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, $legal_notice, 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($pdf->GetY() + 4);
}
if (!empty($planned_work)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(15);
    $pdf->Cell(180, 5, 'Geplante Arbeiten', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, (string)$planned_work, 0, 'L');
    $pdf->SetY($pdf->GetY() + 4);
}

// ── Notizen ─────────────────────────────────────────────
if (!empty($notes)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(15);
    $pdf->Cell(180, 5, 'Notizen', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, (string)$notes, 0, 'L');
    $pdf->SetY($pdf->GetY() + 4);
}

// ── Unverbindlichkeitshinweis ──────────────────────────
$pdf->SetFillColor(240, 248, 255);
$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.4);
$gy = $pdf->GetY();
$pdf->Rect(15, $gy, 180, 16, 'DF');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(17, $gy + 1.5);
$pdf->Cell(176, 5, 'Hinweis:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(
    176, 4,
    'Dieses Angebot ist freibleibend und gilt bis zum oben genannten Datum („Gültig bis“).',
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

$pdf->Output('angebot.pdf', 'I');
