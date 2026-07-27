<?php
/**
 * Zentrale PDF-Erzeugung für Bestellungen.
 *
 * Wird sowohl vom Browser-Endpunkt als auch vom E-Mail-Versand verwendet,
 * damit Darstellung und Anhang garantiert identisch sind.
 *
 * @return array{content:string,filename:string}
 */
function purchase_order_pdf_build(int $orderId): array {
    require_once __DIR__ . '/pdf_common.php';

    $autoload = BASE_PATH . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Vendor-Verzeichnis fehlt oder ist unvollständig.');
    }
    require_once $autoload;

    $order = purchase_order_find($orderId);
    if (!$order) {
        throw new InvalidArgumentException('Bestellung nicht gefunden.');
    }
    $supplier = supplier_find((int)$order['supplier_id']);
    $items = purchase_order_items_list($orderId);
    $company = pdf_company_info();

    $isDraft = $order['status'] === 'entwurf';
    $orderNumber = $order['order_number'] ?: ('Entwurf #' . $orderId);
    $orderDate = $order['ordered_at']
        ? date('d.m.Y', strtotime($order['ordered_at']))
        : date('d.m.Y', strtotime($order['created_at']));
    $draftLabel = $isDraft
        ? 'ENTWURF – Noch keine verbindliche Bestellung, interne Referenz #' . $orderId
        : null;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('MZ Tech Repair System');
    $pdf->SetAuthor($company['name'] ?? 'MZ Tech');
    $pdf->SetTitle('Bestellung ' . $orderNumber);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    $headerBottom = pdf_draw_header($pdf, $company, $draftLabel);

    $pdf->SetXY(120, $headerBottom);
    $pdf->Cell(75, 5, (string)($supplier['name'] ?? ''), 0, 1, 'L');
    if (!empty($supplier['address_street'])) {
        $pdf->SetX(120);
        $pdf->Cell(75, 5, (string)$supplier['address_street'], 0, 1, 'L');
    }
    if (!empty($supplier['address_zip']) || !empty($supplier['address_city'])) {
        $pdf->SetX(120);
        $pdf->Cell(75, 5, trim(($supplier['address_zip'] ?? '') . ' ' . ($supplier['address_city'] ?? '')), 0, 1, 'L');
    }

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetXY(15, $headerBottom);
    $pdf->Cell(95, 10, $isDraft ? 'BESTELLUNG (ENTWURF)' : 'BESTELLUNG', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetX(15);
    $pdf->Cell(95, 5, ($isDraft ? 'Interne Referenz: ' : 'Bestellnummer: ') . $orderNumber, 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->Cell(95, 5, 'Datum: ' . $orderDate, 0, 1, 'L');
    if (!empty($supplier['customer_number_at_supplier'])) {
        $pdf->SetX(15);
        $pdf->Cell(95, 5, 'Unsere Kundennummer: ' . $supplier['customer_number_at_supplier'], 0, 1, 'L');
    }
    $pdf->SetY(max($pdf->GetY(), $headerBottom + 20) + 5);

    $columnWidths = [15, 30, 65, 15, 25, 25];
    $headers = ['Pos', 'Art.-Nr.', 'Bezeichnung', 'Menge', 'Einzelpreis', 'Gesamt'];
    $alignments = ['C', 'L', 'L', 'C', 'R', 'R'];
    $pdf->SetFillColor(200, 210, 220);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(15);
    foreach ($headers as $index => $header) {
        $pdf->Cell($columnWidths[$index], 7, $header, 0, 0, $alignments[$index], true);
    }
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 9);
    $position = 1;
    $netTotal = 0.0;
    foreach ($items as $item) {
        $quantity = (int)$item['quantity'];
        $unitPrice = $item['purchase_price_at_time'] !== null ? (float)$item['purchase_price_at_time'] : 0.0;
        $lineTotal = $unitPrice * $quantity;
        $netTotal += $lineTotal;
        $shade = $position % 2 === 0 ? 245 : 255;
        $pdf->SetFillColor($shade, $shade === 245 ? 247 : 255, $shade === 245 ? 250 : 255);
        $values = [
            (string)$position,
            (string)($item['sku'] ?? ''),
            (string)$item['part_name'],
            (string)$quantity,
            fmt_money($unitPrice),
            fmt_money($lineTotal),
        ];
        $pdf->SetX(15);
        foreach ($values as $index => $value) {
            $pdf->Cell($columnWidths[$index], 6, $value, 0, 0, $alignments[$index], true);
        }
        $pdf->Ln();
        $position++;
    }
    if (!$items) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetX(15);
        $pdf->Cell(175, 6, 'Keine Positionen erfasst.', 0, 1, 'L');
    }

    $shipping = $order['shipping_cost'] !== null ? (float)$order['shipping_cost'] : 0.0;
    $pdf->SetY($pdf->GetY() + 3);
    foreach ([
        ['Zwischensumme (netto):', $netTotal, false],
        ['Versandkosten' . ($order['shipping_cost_is_estimate'] ? ' (Schätzung)' : '') . ':', $shipping, false],
        ['Gesamt (netto):', $netTotal + $shipping, true],
    ] as [$label, $amount, $bold]) {
        $pdf->SetFont('helvetica', $bold ? 'B' : '', 9);
        $pdf->SetX(120);
        $pdf->Cell(45, 6, $label, 0, 0, 'L');
        $pdf->Cell(20, 6, fmt_money($amount), 0, 1, 'R');
    }

    $pdf->SetY($pdf->GetY() + 10);
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(15);
    $pdf->Cell(0, 6, 'Bitte liefern Sie an ' . ($company['name'] ?? 'MZ Tech') . ' unter Angabe der Bestellnummer.', 0, 1, 'C');

    $filename = 'bestellung_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $orderNumber) . '.pdf';
    return ['content' => $pdf->Output($filename, 'S'), 'filename' => $filename];
}
