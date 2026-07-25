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
    die('Ungültige Anfrage-ID.');
}

// Kein pdf_authorize_repair_access() hier: $id bezeichnet eine
// booking_requests.id, keine repairs.id (dessen Kunden-Zugriffsprüfung wäre
// hier fachlich falsch). Der Zugriffsschutz erfolgt bereits vollständig
// durch require_auth() in init.php oben (Mitarbeiter-Login erforderlich) –
// dieses Dokument ist aktuell nicht über das Kundenportal erreichbar.
$db = get_db();

$stmt = $db->prepare('SELECT * FROM booking_requests WHERE id = ?');
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    die('Terminanfrage nicht gefunden.');
}

$company_info = pdf_company_info();

$geraet = trim(($booking['manufacturer'] ?? '') . ' ' . ($booking['model'] ?? ''));
if ($geraet === '') {
    $geraet = function_exists('device_type_label') ? device_type_label($booking['device_type']) : $booking['device_type'];
}

$termin_dt = $booking['confirmed_datetime'] ?: ($booking['preferred_date'] . ' ' . $booking['preferred_time']);
$termin_ts = strtotime($termin_dt);

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Terminbestätigung ' . $booking['booking_number']);
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
$pdf->Cell(0, 10, 'TERMINBESTÄTIGUNG', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(95, 6, 'Anfragenummer: ' . $booking['booking_number'], 0, 0, 'L');
$pdf->Cell(95, 6, 'Datum: ' . date('d.m.Y'), 0, 1, 'R');

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// ── Termin-Hervorhebung ────────────────────────────────
$gy = $pdf->GetY();
$pdf->SetFillColor(240, 248, 255);
$pdf->SetDrawColor(41, 128, 185);
$pdf->SetLineWidth(0.4);
$pdf->Rect(15, $gy, 180, 22, 'DF');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(41, 128, 185);
$pdf->SetXY(17, $gy + 2);
$pdf->Cell(176, 5, $booking['confirmed_datetime'] ? 'Ihr bestätigter Termin:' : 'Ihr Wunschtermin:', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 15);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetX(17);
$pdf->Cell(176, 9, $termin_ts ? date('d.m.Y \u\m H:i', $termin_ts) . ' Uhr' : '–', 0, 1, 'L');
$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);

$pdf->SetY($gy + 26);

// Hilfsfunktion: Abschnittsüberschrift
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
$drawRow($pdf, 'Name',    trim($booking['first_name'] . ' ' . $booking['last_name']));
if ($booking['company']) { $drawRow($pdf, 'Firma', $booking['company']); }
$drawRow($pdf, 'Telefon', $booking['phone'] ?? '');
$drawRow($pdf, 'E-Mail',  $booking['email'] ?? '');

$pdf->SetY($pdf->GetY() + 3);

// ── GERÄT & ANLIEGEN ───────────────────────────────────
$drawSectionHeader($pdf, 'GERÄT & ANLIEGEN');
$drawRow($pdf, 'Gerät', $geraet ?: '–');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(176, 4.5, $booking['issue_description'] ?: 'Keine Angabe.', 0, 'L');

$pdf->SetY($pdf->GetY() + 5);

// ── Hinweise ────────────────────────────────────────────
$pdf->SetFillColor(255, 251, 235);
$pdf->SetDrawColor(217, 164, 6);
$pdf->SetLineWidth(0.4);
$gy2 = $pdf->GetY();
$pdf->Rect(15, $gy2, 180, 18, 'DF');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(17, $gy2 + 1.5);
$pdf->Cell(176, 5, 'Bitte beachten Sie:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(17);
$pdf->MultiCell(
    176, 4,
    'Bitte bringen Sie Ihr Gerät pünktlich zum genannten Termin mit. Bei Verhinderung informieren Sie ' .
    'uns bitte rechtzeitig telefonisch oder per E-Mail, damit wir den Termin anderweitig vergeben können.',
    0, 'L'
);
$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);

$pdf->SetY($pdf->GetY() + 8);

// ── Footer ────────────────────────────────────────────
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(15);
$pdf->Cell(0, 6, 'Wir freuen uns auf Ihren Besuch!', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

// ── Kundenportal-Fußbereich (QR-Code + Link), falls Kunde bereits verknüpft ──
pdf_draw_portal_footer($pdf, isset($booking['customer_id']) ? (int)$booking['customer_id'] : null);

$pdf->Output('terminbestaetigung.pdf', 'I');
