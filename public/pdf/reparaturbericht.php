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

// Verwendete Ersatzteile (rein technisch, ohne Preise)
$stmt2 = $db->prepare(
    'SELECT rp.quantity, p.name AS part_name
     FROM repair_parts rp
     JOIN parts p ON rp.part_id = p.id
     WHERE rp.repair_id = ?'
);
$stmt2->execute([$id]);
$parts_rows = $stmt2->fetchAll();

// Status-Verlauf
$stmt3 = $db->prepare(
    'SELECT sh.status, sh.note, sh.created_at, u.full_name
     FROM repair_status_history sh
     LEFT JOIN users u ON sh.user_id = u.id
     WHERE sh.repair_id = ?
     ORDER BY sh.created_at ASC, sh.id ASC'
);
$stmt3->execute([$id]);
$history_rows = $stmt3->fetchAll();

$repair_number = !empty($repair['repair_number'])
    ? $repair['repair_number']
    : 'RA-' . str_pad($repair['id'], 4, '0', STR_PAD_LEFT);

$company_info = pdf_company_info();

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Reparaturbericht ' . $repair_number);
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
$pdf->Cell(0, 10, 'REPARATURBERICHT', 0, 1, 'C');

// ── Auftragsnummer + Datum ────────────────────────────
$pdf->SetFont('helvetica', '', 10);
$bericht_datum = date('d.m.Y');

$pdf->Cell(95, 6, 'Auftragsnummer: ' . $repair_number, 0, 0, 'L');
$pdf->Cell(95, 6, 'Datum: ' . $bericht_datum, 0, 1, 'R');

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// Hilfsfunktion: Abschnittsüberschrift zeichnen
$drawSectionHeader = function(TCPDF $pdf, string $label) {
    $y = $pdf->GetY();
    if ($y > 255) {
        $pdf->AddPage();
        $y = 20;
        $pdf->SetY($y);
    }
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
$drawRow($pdf, 'E-Mail',  $repair['email'] ?? '');

$pdf->SetY($pdf->GetY() + 3);

// ── GERÄTEDATEN ───────────────────────────────────────
$drawSectionHeader($pdf, 'GERÄTEDATEN');
$drawRow($pdf, 'Geräteart',    $repair['device_type'] ?? '');
$drawRow($pdf, 'Hersteller',   $repair['manufacturer'] ?? '');
$drawRow($pdf, 'Modell',       $repair['model'] ?? '');
$drawRow($pdf, 'IMEI',         $repair['imei'] ?? '');
$drawRow($pdf, 'Seriennummer', $repair['serial_number'] ?? '');

$pdf->SetY($pdf->GetY() + 3);

// ── DIAGNOSE / FEHLERBESCHREIBUNG ─────────────────────
$drawSectionHeader($pdf, 'DIAGNOSE / FEHLERBESCHREIBUNG');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(176, 4.5, $repair['problem_description'] ?: '–', 0, 'L');
if (!empty($repair['is_water_damage'])) {
    $pdf->SetX(17);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(176, 5, 'Hinweis: Wasser-/Flüssigkeitsschaden festgestellt.', 0, 1, 'L');
}

$pdf->SetY($pdf->GetY() + 3);

// ── DURCHGEFÜHRTE ARBEITEN ────────────────────────────
$drawSectionHeader($pdf, 'DURCHGEFÜHRTE ARBEITEN');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(176, 4.5, $repair['internal_notes'] ?: 'Siehe Statusverlauf.', 0, 'L');

$pdf->SetY($pdf->GetY() + 3);

// ── VERWENDETE ERSATZTEILE ────────────────────────────
$drawSectionHeader($pdf, 'VERWENDETE ERSATZTEILE');
if (count($parts_rows) > 0) {
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(17);
    $pdf->Cell(20, 6, 'Menge', 0, 0, 'C', true);
    $pdf->Cell(156, 6, 'Bezeichnung', 0, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    foreach ($parts_rows as $part) {
        $pdf->SetX(17);
        $pdf->Cell(20, 5.5, (string)(int)$part['quantity'], 0, 0, 'C');
        $pdf->Cell(156, 5.5, $part['part_name'], 0, 1, 'L');
    }
} else {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetX(17);
    $pdf->Cell(176, 6, 'Keine Ersatzteile verwendet.', 0, 1, 'L');
}

$pdf->SetY($pdf->GetY() + 3);

// ── STATUSVERLAUF ─────────────────────────────────────
$drawSectionHeader($pdf, 'STATUSVERLAUF');
if (count($history_rows) > 0) {
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(17);
    $pdf->Cell(32, 6, 'Datum', 0, 0, 'L', true);
    $pdf->Cell(50, 6, 'Status', 0, 0, 'L', true);
    $pdf->Cell(94, 6, 'Bearbeiter / Notiz', 0, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 8.5);
    foreach ($history_rows as $h) {
        $y0 = $pdf->GetY();
        if ($y0 > 265) {
            $pdf->AddPage();
            $y0 = 20;
            $pdf->SetY($y0);
        }
        $note = trim(($h['full_name'] ? $h['full_name'] . ' — ' : '') . ($h['note'] ?? ''));
        $pdf->SetX(17);
        $pdf->Cell(32, 5.5, date('d.m.Y H:i', strtotime($h['created_at'])), 0, 0, 'L');
        $pdf->Cell(50, 5.5, repair_status_label($h['status']), 0, 0, 'L');
        $pdf->Cell(94, 5.5, $note !== '' ? $note : '–', 0, 1, 'L');
    }
} else {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetX(17);
    $pdf->Cell(176, 6, 'Kein Statusverlauf vorhanden.', 0, 1, 'L');
}

$pdf->SetY($pdf->GetY() + 3);

// ── GARANTIE ───────────────────────────────────────────
$warranty = isset($repair['warranty_months']) && (int)$repair['warranty_months'] > 0
    ? (int)$repair['warranty_months'] . ' Monate'
    : 'Keine Garantie hinterlegt';
$drawSectionHeader($pdf, 'GARANTIE');
$drawRow($pdf, 'Garantiezeitraum', $warranty);

$pdf->SetY($pdf->GetY() + 5);

// ── Footer ────────────────────────────────────────────
$pdf->SetFont('helvetica', 'I', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(15);
$pdf->Cell(0, 6, 'Dieser Bericht dient der technischen Dokumentation. Preisangaben entnehmen Sie bitte der Rechnung.', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

// ── Kundenportal-Fußbereich (QR-Code + Link) ──────────
pdf_draw_portal_footer($pdf, $repair['customer_id'] ?? null);

$pdf->Output('reparaturbericht.pdf', 'I');
