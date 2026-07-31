<?php
require_once dirname(__DIR__) . '/init.php';

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('Vendor-Verzeichnis fehlt oder ist unvollständig. Bitte die vendor/-Dateien aus dem Projekt-Paket erneut hochladen.');
}
require_once $autoload;
require_once BASE_PATH . '/private/pdf_common.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Ungültige Auftrag-ID.');
}

pdf_authorize_repair_access($id);

$db = get_db();

$stmt = $db->prepare(
    'SELECT r.*, c.first_name, c.last_name, c.phone, c.email
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

$repair_number = !empty($repair['repair_number'])
    ? $repair['repair_number']
    : 'RA-' . str_pad($repair['id'], 4, '0', STR_PAD_LEFT);

$company_info = pdf_company_info();

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Abholschein ' . $repair_number);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// ── Einheitlicher Dokumentkopf (Logo + Firmendaten) ────
$headerBottom = pdf_draw_header($pdf, $company_info);

// ── Titel ─────────────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetY($headerBottom);
$pdf->Cell(0, 10, 'ABHOLSCHEIN', 0, 1, 'C');

// ── Auftragsnummer + Datum ────────────────────────────
$pdf->SetFont('helvetica', '', 10);
$auftrag_datum = !empty($repair['created_at'])
    ? date('d.m.Y', strtotime($repair['created_at']))
    : date('d.m.Y');

$pdf->Cell(95, 6, 'Auftragsnummer: ' . $repair_number, 0, 0, 'L');
$pdf->Cell(95, 6, 'Datum: ' . $auftrag_datum, 0, 1, 'R');
$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// Abschnittsüberschrift
$drawSectionHeader = function(TCPDF $pdf, string $label) {
    $y = $pdf->GetY();
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(50, 50, 50);
    $pdf->Rect(15, $y, 180, 7, 'F');
    $pdf->SetXY(17, $y + 0.5);
    $pdf->Cell(176, 6, $label, 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetY($pdf->GetY() + 1);
};

$drawRow = function(TCPDF $pdf, string $label, string $value) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(17);
    $pdf->Cell(50, 5, $label . ':', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(125, 5, $value, 0, 1, 'L');
};

// ── KUNDENDATEN ───────────────────────────────────────
$drawSectionHeader($pdf, 'KUNDENDATEN');
$drawRow($pdf, 'Name',    trim($repair['first_name'] . ' ' . $repair['last_name']));
$drawRow($pdf, 'Telefon', $repair['phone'] ?? '');

$pdf->SetY($pdf->GetY() + 3);

// ── GERÄTEDATEN ───────────────────────────────────────
$drawSectionHeader($pdf, 'GERÄTEDATEN');
$drawRow($pdf, 'Geräteart',  device_type_label((string)($repair['device_type'] ?? '')));
$drawRow($pdf, 'Hersteller', $repair['manufacturer'] ?? '');
$drawRow($pdf, 'Modell',     $repair['model'] ?? '');

if (!empty($repair['performed_work']) || (float)($repair['labor_cost'] ?? 0) > 0.0) {
    $pdf->SetY($pdf->GetY() + 3);
    $drawSectionHeader($pdf, 'DURCHGEFÜHRTE ARBEITEN');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(17);
    if (!empty($repair['performed_work'])) {
        $pdf->MultiCell(176, 4.5, (string)$repair['performed_work'], 0, 'L');
    }
    if ((float)($repair['working_hours'] ?? 0) > 0.0 || (float)($repair['labor_cost'] ?? 0) > 0.0) {
        $drawRow($pdf, 'Arbeitsleistung netto', repair_labor_description($repair));
    }
}

$imei_sn = '';
if (!empty($repair['imei']) && !empty($repair['serial_number'])) {
    $imei_sn = $repair['imei'] . ' / ' . $repair['serial_number'];
} elseif (!empty($repair['imei'])) {
    $imei_sn = $repair['imei'];
} elseif (!empty($repair['serial_number'])) {
    $imei_sn = $repair['serial_number'];
}
$drawRow($pdf, 'IMEI / SN', $imei_sn);

$pdf->SetY($pdf->GetY() + 3);

// ── STATUS ────────────────────────────────────────────
$drawSectionHeader($pdf, 'STATUS');
$status_y = $pdf->GetY();

$pdf->SetDrawColor(80, 80, 80);
$pdf->SetLineWidth(0.3);

// Checkbox "Repariert"
$pdf->Rect(17, $status_y + 0.8, 4, 4, 'D');
$pdf->SetXY(23, $status_y);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(70, 6, 'Repariert', 0, 0, 'L');

// Checkbox "Nicht reparierbar"
$pdf->Rect(97, $status_y + 0.8, 4, 4, 'D');
$pdf->SetXY(103, $status_y);
$pdf->Cell(70, 6, 'Nicht reparierbar', 0, 1, 'L');

$pdf->SetY($pdf->GetY() + 4);

// ── Garantiehinweis ───────────────────────────────────
$pdf->SetFillColor(240, 248, 255);
$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.4);
$gy = $pdf->GetY();
$pdf->Rect(15, $gy, 180, 14, 'DF');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(17, $gy + 1);
$pdf->Cell(176, 5, 'Garantiehinweis:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(176, 4,
    'Auf alle durchgeführten Reparaturen gewähren wir 3 Monate Garantie auf die verwendeten ' .
    'Ersatzteile und Arbeitsleistung.',
    0, 'L'
);
$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 5);

// ── Bestätigung ───────────────────────────────────────
$kundenname = trim($repair['first_name'] . ' ' . $repair['last_name']);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetX(15);
$pdf->MultiCell(180, 6,
    'Hiermit bestätige ich, ' . $kundenname . ', das oben genannte Gerät in einwandfreiem Zustand erhalten zu haben.',
    0, 'L'
);

$pdf->SetY($pdf->GetY() + 8);

// ── Unterschriften ────────────────────────────────────
$sig_y = $pdf->GetY();
if ($sig_y > 250) {
    $pdf->AddPage();
    $sig_y = 20;
    $pdf->SetY($sig_y);
}

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.4);

$pdf->Line(15, $sig_y + 14, 90, $sig_y + 14);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(15, $sig_y + 15);
$pdf->Cell(75, 5, 'Datum / Unterschrift Kunde', 0, 0, 'C');

$pdf->Line(115, $sig_y + 14, 195, $sig_y + 14);
$pdf->SetXY(115, $sig_y + 15);
$pdf->Cell(80, 5, 'Datum / Unterschrift ' . ($company_info['name'] ?? 'MZ Tech'), 0, 0, 'C');

// ── Kundenportal-Fußbereich (QR-Code + Link) ──────────
$pdf->SetY($sig_y + 24);
pdf_draw_portal_footer($pdf, $repair['customer_id'] ?? null);

$pdf->Output('abholschein.pdf', 'I');
