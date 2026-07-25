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

// Reparatur laden
$stmt = $db->prepare(
    'SELECT r.*, c.first_name, c.last_name, c.address, c.phone, c.email
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

// Reparaturnummer ermitteln
$repair_number = !empty($repair['repair_number'])
    ? $repair['repair_number']
    : 'RA-' . str_pad($repair['id'], 4, '0', STR_PAD_LEFT);

// Firmeninfo
$company_info = pdf_company_info();

// PDF erstellen
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Reparaturauftrag ' . $repair_number);
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
$pdf->Cell(0, 10, 'REPARATURAUFTRAG', 0, 1, 'C');

// ── Auftragsnummer + Datum ────────────────────────────
$pdf->SetFont('helvetica', '', 10);
$auftrag_datum = !empty($repair['created_at'])
    ? date('d.m.Y', strtotime($repair['created_at']))
    : date('d.m.Y');

$pdf->Cell(95, 6, 'Auftragsnummer: ' . $repair_number, 0, 0, 'L');
$pdf->Cell(95, 6, 'Datum: ' . $auftrag_datum, 0, 1, 'R');

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// Hilfsfunktion: Abschnittsüberschrift zeichnen
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

// Hilfsfunktion: Zeile mit Label + Wert
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
$drawRow($pdf, 'Adresse', $repair['address'] ?? '');
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
$drawRow($pdf, 'Farbe',        $repair['color'] ?? '');

$pdf->SetY($pdf->GetY() + 3);

// ── REPARATURDETAILS ──────────────────────────────────
$drawSectionHeader($pdf, 'REPARATURDETAILS');
$drawRow($pdf, 'Fehlerbeschreibung',         $repair['problem_description'] ?? '');
$drawRow($pdf, 'Fehlertyp',                  $repair['problem_type'] ?? '');

$est = '';
if (!empty($repair['estimated_ready'])) {
    $ts = strtotime($repair['estimated_ready']);
    $est = $ts ? date('d.m.Y', $ts) : '';
}
$drawRow($pdf, 'Voraussichtliche Fertigstellung', $est);

$price_val = isset($repair['price']) ? (float)$repair['price'] : 0.0;
$drawRow($pdf, 'Kostenvoranschlag', number_format($price_val, 2, ',', '.') . ' €');

$warranty = isset($repair['warranty_months']) ? $repair['warranty_months'] . ' Monate' : '3 Monate';
$drawRow($pdf, 'Garantie', $warranty);

$pdf->SetY($pdf->GetY() + 3);

// ── GERÄTEZUSTAND BEI ANNAHME ─────────────────────────
$drawSectionHeader($pdf, 'GERÄTEZUSTAND BEI ANNAHME');
$checkItems = ['Kratzer', 'Displayschaden', 'Gehäuseschaden', 'Funktionsmängel'];
foreach ($checkItems as $item) {
    $cy = $pdf->GetY();
    $pdf->SetDrawColor(80, 80, 80);
    $pdf->Rect(17, $cy + 0.8, 4, 4, 'D');
    $pdf->SetXY(23, $cy);
    $pdf->Cell(0, 6, $item, 0, 1, 'L');
}

$pdf->SetY($pdf->GetY() + 3);

// ── DSGVO-Hinweis ─────────────────────────────────────
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetX(15);
$pdf->MultiCell(180, 4,
    'Datenschutzhinweis: Ihre personenbezogenen Daten (Name, Adresse, Telefon, E-Mail) sowie die Gerätedaten werden ' .
    'ausschließlich zur Abwicklung des Reparaturauftrags verarbeitet und gespeichert. Die Daten werden nicht an Dritte ' .
    'weitergegeben und nach gesetzlicher Aufbewahrungsfrist gelöscht. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO ' .
    '(Vertragserfüllung). Weitere Informationen entnehmen Sie unserer Datenschutzerklärung.',
    0, 'L'
);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 5);

// ── Unterschriften ────────────────────────────────────
$sig_y = $pdf->GetY();
if ($sig_y > 250) {
    $pdf->AddPage();
    $sig_y = 20;
    $pdf->SetY($sig_y);
}

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.4);

// Linke Signatur
$pdf->Line(15, $sig_y + 14, 90, $sig_y + 14);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(15, $sig_y + 15);
$pdf->Cell(75, 5, 'Datum / Unterschrift Kunde', 0, 0, 'C');

// Rechte Signatur
$pdf->Line(115, $sig_y + 14, 195, $sig_y + 14);
$pdf->SetXY(115, $sig_y + 15);
$pdf->Cell(80, 5, 'Datum / Unterschrift ' . ($company_info['name'] ?? 'MZ Tech'), 0, 0, 'C');

// ── Kundenportal-Fußbereich (QR-Code + Link) ──────────
$pdf->SetY($sig_y + 24);
pdf_draw_portal_footer($pdf, $repair['customer_id'] ?? null);

$pdf->Output('auftrag.pdf', 'I');
