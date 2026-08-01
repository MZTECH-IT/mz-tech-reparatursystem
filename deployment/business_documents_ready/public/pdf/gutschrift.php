<?php
/**
 * Gutschrift/Stornorechnung als PDF – EINE Vorlage für beide Dokumentarten
 * (siehe private/invoice_corrections.php, correction_type), da Aufbau und
 * Ablauf (Bezug zur Originalrechnung, eigener Nummernkreis GS/STO,
 * Freigabe, unveränderlicher Snapshot) identisch sind und sich nur die
 * fachliche Bedeutung sowie einzelne Textbausteine unterscheiden.
 *
 * Zugreifbar für Mitarbeiter (Admin-Session) – anders als
 * Rechnung/Kostenvoranschlag ist dieses Dokument aktuell NICHT im
 * Kundenportal sichtbar, daher (analog zu public/pdf/angebot.php und
 * public/pdf/lieferschein.php) NICHT pdf_authorize_repair_access(),
 * sondern eine reine Mitarbeiter-Session-Prüfung.
 *
 * Dokumentenmodul ("Dokumente, Nummernkreise und Freigaben"):
 *   - Solange invoice_corrections.status = "entwurf" ist, zeigt dieses PDF
 *     eine LIVE berechnete Vorschau mit deutlich sichtbarem "ENTWURF"-Band
 *     und interner Referenznummer (#Korrektur-ID) – es handelt sich
 *     ausdrücklich um KEINEN gültigen Beleg und verbraucht KEINE GS/STO-
 *     Nummer.
 *   - Nach der Freigabe wird AUSSCHLIESSLICH aus dem zum Freigabezeitpunkt
 *     eingefrorenen Snapshot (invoice_corrections.snapshot_json) gerendert
 *     – spätere Änderungen an Reparatur/Kunde/Einstellungen können das
 *     bereits ausgestellte Dokument dadurch nicht mehr verändern
 *     (Abschnitt 9, Unveränderlichkeit). Der Snapshot selbst enthält keine
 *     eingefrorene Kundenadresse (nur original_repair.customer_id) – die
 *     Kundendaten werden daher bewusst live nachgeschlagen (siehe
 *     invoice_corrections.php: build_document_snapshot() friert lediglich
 *     Korrektur- und Reparaturdaten ein, keinen Kundendatensatz).
 */
$private = dirname(__DIR__, 2) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/pdf_common.php';
require_once $private . '/invoice_corrections.php';

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('Vendor-Verzeichnis fehlt oder ist unvollständig. Bitte die vendor/-Dateien aus dem Projekt-Paket erneut hochladen.');
}
require_once $autoload;

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Ungültige Korrektur-ID.');
}

// ── Zugriffsprüfung: reine Mitarbeiter-Session (kein Kundenportal-Zugriff) ──
if (!empty($_COOKIE[SESSION_NAME] ?? null)) {
    require_once PRIVATE_PATH . '/auth.php';
    start_secure_session();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    die('Kein Zugriff.');
}

$correction = invoice_correction_find($id);
if (!$correction) {
    http_response_code(404);
    die('Korrekturbeleg nicht gefunden.');
}

$released = $correction['status'] !== 'entwurf';
$snapshot = null;
if ($released && !empty($correction['snapshot_json'])) {
    $decoded = json_decode($correction['snapshot_json'], true);
    if (is_array($decoded)) $snapshot = $decoded;
}

if ($snapshot !== null) {
    // ── Freigegebene Korrektur: ausschließlich aus dem eingefrorenen Snapshot ──
    $type               = $snapshot['type'] ?? $correction['correction_type'];
    $correction_number  = $snapshot['correction_number'] ?? $correction['correction_number'];
    $correction_snap    = $snapshot['correction'] ?? $correction;
    $original_repair    = $snapshot['original_repair'] ?? null;
    $company_info       = $snapshot['company'] ?? pdf_company_info();
    $customer_snapshot  = $snapshot['customer'] ?? null;
    $billing_snapshot   = $snapshot['billing'] ?? [];

    $amount        = (float)($correction_snap['amount'] ?? $correction['amount']);
    $reason        = $correction_snap['reason'] ?? $correction['reason'];
    $repair_number = $original_repair['repair_number'] ?? ($correction['repair_number'] ?? ('#' . $correction['repair_id']));
    $original_invoice_number = $original_repair['invoice_number'] ?? ($correction['original_invoice_number'] ?? '');
    $customer_id   = $original_repair['customer_id'] ?? ($correction['customer_id'] ?? null);
    $doc_date      = !empty($snapshot['released_at']) ? date('d.m.Y', strtotime($snapshot['released_at'])) : date('d.m.Y');

    $draftLabel = null;
} else {
    // ── Entwurf: live berechnete Vorschau, KEIN gültiger Beleg ──
    $type               = $correction['correction_type'];
    $correction_number  = 'Entwurf #' . $id;
    $company_info       = pdf_company_info();
    $customer_snapshot  = null;
    $billing_snapshot   = billing_snapshot();

    $amount        = (float)$correction['amount'];
    $reason        = $correction['reason'];
    $repair_number = $correction['repair_number'] ?? ('#' . $correction['repair_id']);
    $original_invoice_number = $correction['original_invoice_number'] ?? '';
    $customer_id   = $correction['customer_id'] ?? null;
    $doc_date      = date('d.m.Y');

    $typeLabel  = $type === 'storno' ? 'Stornorechnung' : 'Gutschrift';
    $draftLabel = 'ENTWURF – Vorläufige ' . $typeLabel . ', keine gültige Nummer, interne Referenz #' . $id;
}

$isStorno = $type === 'storno';

// ── Kundendaten (live nachgeschlagen, siehe Hinweis oben) ─────────────────
$cust_name    = '';
$cust_address = '';
$cust_phone   = '';
$cust_email   = '';
if (is_array($customer_snapshot)) {
    $cust_name    = trim(($customer_snapshot['first_name'] ?? '') . ' ' . ($customer_snapshot['last_name'] ?? ''));
    $cust_address = $customer_snapshot['address'] ?? '';
    $cust_phone   = $customer_snapshot['phone'] ?? '';
    $cust_email   = $customer_snapshot['email'] ?? '';
} elseif ($customer_id) {
    $custStmt = get_db()->prepare('SELECT first_name, last_name, address, phone, email FROM customers WHERE id = ?');
    $custStmt->execute([$customer_id]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if ($customer) {
        $cust_name    = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        $cust_address = $customer['address'] ?? '';
        $cust_phone   = $customer['phone']   ?? '';
        $cust_email   = $customer['email']   ?? '';
    }
}

$smallBusiness = !empty($billing_snapshot['small_business']);
$legalNotice = (string)($billing_snapshot['legal_notice'] ?? billing_legal_notice());

// PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle(($isStorno ? 'Stornorechnung ' : 'Gutschrift ') . $correction_number);
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
$titleText = $isStorno ? 'STORNORECHNUNG' : 'GUTSCHRIFT';
if (!$released) $titleText .= '-ENTWURF';

$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(15, $headerBottom);
$pdf->Cell(95, 10, $titleText, 0, 1, 'L');

// Metadaten
$pdf->SetFont('helvetica', '', 10);
$pdf->SetX(15);
$pdf->Cell(95, 5, ($released ? 'Nummer: ' : 'Interne Referenz: ') . $correction_number, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Datum: ' . $doc_date, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Bezug: Rechnung ' . ($original_invoice_number !== '' ? $original_invoice_number : '—'), 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Auftragsnummer: ' . $repair_number, 0, 1, 'L');
if ($customer_id) {
    $pdf->SetX(15);
    $pdf->Cell(95, 5, 'Kundennummer: K-' . str_pad((string)$customer_id, 4, '0', STR_PAD_LEFT), 0, 1, 'L');
}

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// ── Erläuternder Hinweis (Gutschrift vs. vollständige Stornierung) ────────
$pdf->SetFillColor(240, 248, 255);
$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.4);
$gy = $pdf->GetY();
$noticeText = $isStorno
    ? 'Diese Stornorechnung storniert die oben genannte Rechnung ' . ($original_invoice_number !== '' ? $original_invoice_number : '') .
      '. Der nachfolgend ausgewiesene Betrag entfällt rückwirkend vollständig; er wird der ursprünglichen Rechnung nicht mehr in Rechnung gestellt.'
    : 'Mit dieser Gutschrift wird Ihnen der nachfolgend ausgewiesene Betrag zur oben genannten Rechnung ' . ($original_invoice_number !== '' ? $original_invoice_number : '') .
      ' gutgeschrieben und mit offenen bzw. künftigen Forderungen verrechnet oder erstattet.';
$noticeH = 18;
$pdf->Rect(15, $gy, 180, $noticeH, 'DF');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(17, $gy + 1.5);
$pdf->Cell(176, 5, 'Hinweis:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(176, 4, $noticeText, 0, 'L');
$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetY(max($pdf->GetY(), $gy + $noticeH) + 6);

// ── Positionstabelle (ein Korrekturbetrag) ─────────────
$col_w = [10, 110, 30, 30]; // Pos | Beschreibung | (leer) | Betrag
$pdf->SetFillColor(200, 210, 220);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$headers = ['Pos', 'Beschreibung', '', 'Betrag'];
$aligns  = ['C',   'L',            'C', 'R'];
$x_start = 15;
$pdf->SetX($x_start);
foreach ($headers as $i => $hh) {
    $pdf->Cell($col_w[$i], 7, $hh, 0, 0, $aligns[$i], true);
}
$pdf->Ln();

$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(245, 247, 250);
$desc = $isStorno
    ? 'Stornierter Betrag zu Rechnung ' . ($original_invoice_number !== '' ? $original_invoice_number : '—')
    : 'Korrekturbetrag (Gutschrift) zu Rechnung ' . ($original_invoice_number !== '' ? $original_invoice_number : '—');
$pdf->SetX($x_start);
$pdf->Cell($col_w[0], 6, '1', 0, 0, 'C', true);
$pdf->Cell($col_w[1], 6, $desc, 0, 0, 'L', true);
$pdf->Cell($col_w[2], 6, '', 0, 0, 'C', true);
$pdf->Cell($col_w[3], 6, ($isStorno ? '- ' : '') . number_format($amount, 2, ',', '.') . ' €', 0, 0, 'R', true);
$pdf->Ln();

$pdf->SetY($pdf->GetY() + 3);

// ── Summenblock ───────────────────────────────────────
$sum_x  = 120;
$sum_lw = 45;
$sum_rw = 30;

$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.6);
$pdf->Line($sum_x, $pdf->GetY(), 195, $pdf->GetY());
$pdf->SetLineWidth(0.2);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(41, 128, 185);
$pdf->SetX($sum_x);
$pdf->Cell($sum_lw, 8, $isStorno ? 'Stornierter Betrag:' : 'Gutschriftbetrag:', 0, 0, 'R');
$pdf->Cell($sum_rw, 8, ($isStorno ? '- ' : '') . number_format($amount, 2, ',', '.') . ' €', 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 8);

// ── Grund ─────────────────────────────────────────────
if (!empty($reason)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(15);
    $pdf->Cell(180, 5, 'Grund', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, (string)$reason, 0, 'L');
    $pdf->SetY($pdf->GetY() + 4);
}

if ($smallBusiness && $legalNotice !== '') {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100,100,100);
    $pdf->SetX(15);
    $pdf->MultiCell(180,4,$legalNotice,0,'L');
    $pdf->SetTextColor(0,0,0);
    $pdf->SetY($pdf->GetY()+4);
}

// ── Footer ────────────────────────────────────────────
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(15);
$pdf->Cell(0, 6, 'Vielen Dank für Ihr Vertrauen in ' . ($company_info['name'] ?? 'MZ Tech') . '!', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

$pdf->Output('gutschrift.pdf', 'I');
