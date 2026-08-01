<?php
/** Zentrale Abrechnungs- und Preislogik. Keine Datenbankzugriffe beim Laden. */

const BILLING_MODE_SMALL_BUSINESS = 'small_business_19_ustg';
const BILLING_MODE_STANDARD_TAX   = 'standard_taxation';
const BILLING_SMALL_BUSINESS_NOTICE =
    'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG. Es wird keine Umsatzsteuer berechnet.';

function billing_mode(): string {
    $mode = get_setting('billing_mode', BILLING_MODE_SMALL_BUSINESS);
    return in_array($mode, [BILLING_MODE_SMALL_BUSINESS, BILLING_MODE_STANDARD_TAX], true)
        ? $mode
        : BILLING_MODE_SMALL_BUSINESS;
}

function billing_is_small_business(?string $mode = null): bool {
    return ($mode ?? billing_mode()) === BILLING_MODE_SMALL_BUSINESS;
}

function billing_tax_rate(?string $mode = null, mixed $configuredRate = null): string {
    if (billing_is_small_business($mode)) return '0.00';
    $raw = $configuredRate ?? get_setting('tax_rate', '19.00');
    $rate = repair_decimal_input($raw, 'Umsatzsteuersatz', '100.00');
    return number_format((float)$rate, 2, '.', '');
}

function billing_legal_notice(?string $mode = null): string {
    if (billing_is_small_business($mode)) return BILLING_SMALL_BUSINESS_NOTICE;
    return '';
}

/** Rechnet in Cent, damit Browserwerte niemals maßgeblich sind. */
function billing_amounts(mixed $subtotalInput, ?string $mode = null, mixed $configuredRate = null): array {
    $subtotal = repair_decimal_input($subtotalInput, 'Betrag', '999999999.99');
    $subtotalCents = (int)round((float)$subtotal * 100);
    $rate = billing_tax_rate($mode, $configuredRate);
    $rateHundredths = (int)round((float)$rate * 100);
    $taxCents = billing_is_small_business($mode)
        ? 0
        : (int)round($subtotalCents * $rateHundredths / 10000);
    return [
        'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
        'tax_rate' => number_format($rateHundredths / 100, 2, '.', ''),
        'tax_amount' => number_format($taxCents / 100, 2, '.', ''),
        'total' => number_format(($subtotalCents + $taxCents) / 100, 2, '.', ''),
        'billing_mode' => $mode ?? billing_mode(),
        'legal_notice' => billing_legal_notice($mode),
    ];
}

function billing_snapshot(): array {
    $mode = billing_mode();
    return [
        'billing_mode' => $mode,
        'tax_rate' => billing_tax_rate($mode),
        'tax_amount' => '0.00',
        'legal_notice' => billing_legal_notice($mode),
        'small_business' => billing_is_small_business($mode),
    ];
}

/** Standardverkaufspreis Einkauf + Aufschlag, centgenau. */
function part_sales_price(mixed $purchaseInput, mixed $markupInput = '10.00'): array {
    $purchase = repair_decimal_input($purchaseInput, 'Einkaufspreis', '9999999.99');
    $markup = repair_decimal_input($markupInput, 'Aufschlag', '1000.00');
    $purchaseCents = (int)round((float)$purchase * 100);
    $markupHundredths = (int)round((float)$markup * 100);
    $salesCents = (int)round($purchaseCents * (10000 + $markupHundredths) / 10000);
    return [
        'purchase_price' => number_format($purchaseCents / 100, 2, '.', ''),
        'markup_percent' => number_format($markupHundredths / 100, 2, '.', ''),
        'automatic_selling_price' => number_format($salesCents / 100, 2, '.', ''),
    ];
}
