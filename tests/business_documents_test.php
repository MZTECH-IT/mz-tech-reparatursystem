<?php
declare(strict_types=1);

$failures = [];
function check_business(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}
function get_setting(string $key, mixed $default = null): mixed {
    $values = ['billing_mode'=>'small_business_19_ustg','tax_rate'=>'0.00'];
    return $values[$key] ?? $default;
}
function repair_decimal_input(mixed $value, string $label, string $max): string {
    $normalized = str_replace(',', '.', trim((string)$value));
    if ($normalized === '' || !preg_match('/^\d+(?:\.\d+)?$/', $normalized)) throw new InvalidArgumentException($label);
    if ((float)$normalized < 0 || (float)$normalized > (float)$max) throw new InvalidArgumentException($label);
    return number_format((float)$normalized, 2, '.', '');
}

require_once dirname(__DIR__) . '/private/billing.php';
require_once dirname(__DIR__) . '/private/invoicing.php';

$part = part_sales_price('80,00', '10');
check_business($part['automatic_selling_price'] === '88.00', '80,00 + 10 % muss 88,00 ergeben.');
$amounts = billing_amounts('186,75');
check_business($amounts['subtotal'] === '186.75', 'Gesamtbetrag wurde verändert.');
check_business($amounts['tax_amount'] === '0.00', 'Kleinunternehmermodus darf keine Steuer berechnen.');
check_business($amounts['total'] === '186.75', 'Kleinunternehmer-Endbetrag muss 186,75 sein.');
check_business($amounts['legal_notice'] === BILLING_SMALL_BUSINESS_NOTICE, 'Rechtshinweis weicht ab.');

$safe = build_document_snapshot([
    'repair'=>['performed_work'=>'sichtbar','internal_notes'=>'geheim'],
    'parts'=>[['description'=>'Teil','unit_price'=>'88.00','purchase_price_at_time'=>'80.00','markup_percent'=>'10.00']],
]);
$json = json_encode($safe, JSON_UNESCAPED_UNICODE);
check_business(!str_contains($json, 'geheim'), 'Interne Notiz gelangt in Kundensnapshot.');
check_business(!str_contains($json, 'purchase_price'), 'Einkaufspreis gelangt in Kundensnapshot.');
check_business(!str_contains($json, 'markup_percent'), 'Aufschlag gelangt in Kundensnapshot.');
check_business(str_contains($json, 'sichtbar'), 'Durchgeführte Arbeiten fehlen im Snapshot.');

$root = dirname(__DIR__);
foreach (['business_documents_preflight.sql','business_documents_postcheck.sql','business_documents_rollback.sql'] as $file) {
    $sql = file_get_contents($root . '/sql/' . $file);
    $withoutComments = preg_replace('/--[^\r\n]*/', '', $sql);
    check_business(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b/i', $withoutComments), "$file ist nicht rein lesend.");
}
$migration = file_get_contents($root . '/sql/business_documents_migration.sql');
foreach (['billing_mode','markup_percent','quote_snapshot','invoice_conversion_history','payments'] as $required) {
    check_business(str_contains($migration, $required), "Migration enthält $required nicht.");
}

$customerFiles = ['public/pdf/angebot.php','public/pdf/rechnung.php','public/pdf/kostenvoranschlag.php','public/portal.php','public/portal_business.php','public/portal_guest.php'];
foreach ($customerFiles as $file) {
    $source = file_get_contents($root . '/' . $file);
    check_business(!preg_match('/\bh\s*\([^\r\n;]*(internal_notes|purchase_price|markup_percent)/i', $source), "$file gibt interne Daten sichtbar aus.");
}
check_business(str_contains(file_get_contents($root.'/private/pdf_common.php'),'pdf_authorize_quote_access'), 'Angebots-PDF besitzt keine Portal-Eigentümerprüfung.');
check_business(str_contains(file_get_contents($root.'/public/portal.php'),'pdf/angebot.php'), 'Kundenportal verlinkt Angebote nicht.');
check_business(str_contains(file_get_contents($root.'/public/portal_business.php'),'pdf/angebot.php'), 'Firmenportal verlinkt Angebote nicht.');
check_business(str_contains(file_get_contents($root.'/private/payments.php'),'Der Zahlungsbetrag darf den offenen Rechnungsbetrag nicht überschreiten'), 'Zahlungen verhindern Überzahlung nicht.');
$invoicePdfSource = file_get_contents($root.'/public/pdf/rechnung.php');
check_business(str_contains($invoicePdfSource, 'payment_summary($repair)'), 'Rechnungs-PDF berücksichtigt nachträgliche Zahlungen nicht.');
check_business(str_contains($invoicePdfSource, "'Bereits bezahlt:'"), 'Rechnungs-PDF weist bereits bezahlte Beträge nicht aus.');
check_business(!str_contains($invoicePdfSource, 'internal_note'), 'Rechnungs-PDF darf interne Zahlungsnotizen nicht laden.');
check_business(str_contains(file_get_contents($root.'/private/invoicing.php'), "'customer_company' => \$customerCompany"), 'Rechnung friert Firmen-Rechnungsempfänger nicht ein.');
check_business(str_contains($invoicePdfSource, "'z. Hd. '"), 'Firmenrechnung weist den Ansprechpartner nicht aus.');
check_business(str_contains($invoicePdfSource, 'company_address'), 'Firmenrechnung verwendet die Firmenanschrift nicht.');
$quoteSource = file_get_contents($root.'/private/quotes.php');
check_business(substr_count($quoteSource, 'stock_quantity = stock_quantity - ?') >= 2, 'Angebots-Konvertierungen reduzieren den Lagerbestand nicht in beiden Pfaden.');
check_business(substr_count($quoteSource, 'FOR UPDATE') >= 4, 'Angebots-Konvertierungen sperren Bestandszeilen nicht transaktionssicher.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n");
    exit(1);
}
echo "BUSINESS_DOCUMENTS_TEST_OK\n";
