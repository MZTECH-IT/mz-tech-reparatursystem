<?php
/**
 * Lieferschein als PDF – zugreifbar für Mitarbeiter (Admin-Session). Ein
 * Lieferschein (siehe private/delivery_notes.php) ist – wie das Angebot
 * (public/pdf/angebot.php) – NICHT zwingend an einen Reparaturauftrag
 * gebunden (repair_id ist optional), daher kommt hier NICHT
 * pdf_authorize_repair_access() zum Einsatz (das würde eine repair_id
 * voraussetzen), sondern eine reine Mitarbeiter-Session-Prüfung (erster
 * Zweig von pdf_authorize_repair_access(), ohne den Kundenportal-Zweig –
 * Lieferscheine sind aktuell noch nicht im Kundenportal sichtbar).
 *
 * Dokumentenmodul ("Dokumente, Nummernkreise und Freigaben"):
 *   - Solange delivery_notes.status = "entwurf" ist, zeigt dieses PDF eine
 *     LIVE berechnete Vorschau mit deutlich sichtbarem "ENTWURF"-Band und
 *     interner Referenznummer (#Lieferschein-ID) – es handelt sich
 *     ausdrücklich um KEINEN gültigen Lieferschein und verbraucht KEINE
 *     Lieferscheinnummer.
 *   - Nach der Erstellung (delivery_note_finalize()) werden die Positionen
 *     AUSSCHLIESSLICH aus dem zum Erstellungszeitpunkt eingefrorenen
 *     Snapshot (delivery_notes.snapshot_json) gerendert – spätere
 *     Änderungen können das bereits ausgestellte Dokument dadurch nicht
 *     mehr verändern (Abschnitt 9, Unveränderlichkeit). Der Snapshot
 *     friert bewusst nur die Kopfdaten-Zeile und die Positionen ein
 *     (siehe delivery_note_finalize() in private/delivery_notes.php);
 *     Kunde/Firma werden – wie im Snapshot der Zeile selbst auch –
 *     weiterhin per delivery_note_find() (Join auf customers/companies)
 *     angezeigt, da es dort (anders als bei Angeboten) keinen separaten
 *     eingefrorenen Kunden-Schnappschuss gibt.
 */
$private = dirname(__DIR__, 2) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/pdf_common.php';
require_once $private . '/delivery_notes.php';

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('Vendor-Verzeichnis fehlt oder ist unvollständig. Bitte die vendor/-Dateien aus dem Projekt-Paket erneut hochladen.');
}
require_once $autoload;

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Ungültige Lieferschein-ID.');
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

$dn = delivery_note_find($id);
if (!$dn) {
    http_response_code(404);
    die('Lieferschein nicht gefunden.');
}

$released = $dn['status'] !== 'entwurf';
$snapshot = null;
if ($released && !empty($dn['snapshot_json'])) {
    $decoded = json_decode($dn['snapshot_json'], true);
    if (is_array($decoded)) $snapshot = $decoded;
}

if ($snapshot !== null) {
    // ── Erstellter Lieferschein: Nummer + Positionen aus dem eingefrorenen Snapshot ──
    $delivery_note_number = $snapshot['delivery_note_number'];
    $company_info = $snapshot['company'] ?? pdf_company_info();
    $items_rows   = $snapshot['items'] ?? [];
    $dn_date      = !empty($snapshot['finalized_at']) ? date('d.m.Y', strtotime($snapshot['finalized_at'])) : date('d.m.Y');
    $draftLabel   = null;
} else {
    // ── Entwurf: live Vorschau, KEIN gültiger Lieferschein ──
    $company_info = pdf_company_info();
    $items_rows   = delivery_note_items_list($id);
    $delivery_note_number = 'Entwurf #' . $id;
    $dn_date      = date('d.m.Y');
    $draftLabel   = 'ENTWURF – Vorläufiger Lieferschein, keine gültige Nummer, interne Referenz #' . $id;
}

// Kunde/Firma – wird stets live aus dem Join von delivery_note_find()
// angezeigt (siehe Hinweis oben: die Kopfzeile im Snapshot friert keinen
// eigenen Kunden-Schnappschuss ein).
$cust_name    = $dn['company_name'] ?: trim(($dn['first_name'] ?? '') . ' ' . ($dn['last_name'] ?? ''));
$cust_address = $dn['address'] ?? '';

// PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MZ Tech Repair System');
$pdf->SetAuthor($company_info['name'] ?? 'MZ Tech');
$pdf->SetTitle('Lieferschein ' . $delivery_note_number);
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

// ── Titel ─────────────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(15, $headerBottom);
$pdf->Cell(95, 10, $released ? 'LIEFERSCHEIN' : 'LIEFERSCHEIN-ENTWURF', 0, 1, 'L');

// Metadaten
$pdf->SetFont('helvetica', '', 10);
$pdf->SetX(15);
$pdf->Cell(95, 5, ($released ? 'Nummer: ' : 'Interne Referenz: ') . $delivery_note_number, 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(95, 5, 'Datum: ' . $dn_date, 0, 1, 'L');
if (!empty($dn['repair_id'])) {
    $pdf->SetX(15);
    $pdf->Cell(95, 5, 'Reparaturauftrag: #' . (int)$dn['repair_id'], 0, 1, 'L');
}
if (!empty($dn['purchase_order_id'])) {
    $pdf->SetX(15);
    $pdf->Cell(95, 5, 'Bestellung: #' . (int)$dn['purchase_order_id'], 0, 1, 'L');
}

$pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

// ── Positionstabelle (ohne Preise – ein Lieferschein weist keine Preise aus) ──
$col_w = [15, 135, 30]; // Pos | Beschreibung | Menge

$pdf->SetFillColor(200, 210, 220);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$headers = ['Pos', 'Beschreibung', 'Menge'];
$aligns  = ['C',   'L',            'C'];
$x_start = 15;
$pdf->SetX($x_start);
foreach ($headers as $i => $hh) {
    $pdf->Cell($col_w[$i], 7, $hh, 0, 0, $aligns[$i], true);
}
$pdf->Ln();

$pdf->SetFont('helvetica', '', 9);
$pos = 1;
$row_colors = [[255, 255, 255], [245, 247, 250]];

foreach ($items_rows as $item) {
    $qty  = (int)($item['quantity'] ?? 1);
    $desc = is_array($item) ? ($item['description'] ?? '') : '';
    $ci   = ($pos % 2 === 1) ? 0 : 1;
    $pdf->SetFillColor(...$row_colors[$ci]);
    $pdf->SetX($x_start);
    $pdf->Cell($col_w[0], 6, (string)$pos, 0, 0, 'C', true);
    $pdf->Cell($col_w[1], 6, $desc,        0, 0, 'L', true);
    $pdf->Cell($col_w[2], 6, (string)$qty, 0, 0, 'C', true);
    $pdf->Ln();
    $pos++;
}

if (count($items_rows) === 0) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetX($x_start);
    $pdf->Cell(180, 6, 'Keine Positionen erfasst.', 0, 1, 'L');
}

$pdf->SetY($pdf->GetY() + 8);

// ── Notizen ─────────────────────────────────────────────
$dn_notes = $dn['notes'] ?? '';
if (!empty($dn_notes)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(15);
    $pdf->Cell(180, 5, 'Notizen', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4, (string)$dn_notes, 0, 'L');
    $pdf->SetY($pdf->GetY() + 4);
}

$pdf->SetY($pdf->GetY() + 5);

// ── Unterschrift ──────────────────────────────────────
$sig_y = $pdf->GetY();
if ($sig_y > 250) {
    $pdf->AddPage();
    $sig_y = 20;
    $pdf->SetY($sig_y);
}

$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(15);
$pdf->Cell(180, 5, 'Übernommen durch (Name, Unterschrift): ______________________________________  Datum: ______________', 0, 1, 'L');

// ── Footer ────────────────────────────────────────────
$pdf->SetY($pdf->GetY() + 10);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(15);
$pdf->Cell(0, 6, 'Vielen Dank für Ihr Vertrauen in ' . ($company_info['name'] ?? 'MZ Tech') . '!', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

$pdf->Output('lieferschein.pdf', 'I');
