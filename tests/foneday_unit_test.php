<?php
declare(strict_types=1);

require_once __DIR__ . '/../private/foneday.php';

$failures = [];
$assertSame = static function (mixed $expected, mixed $actual, string $label) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $label . ': erwartet ' . var_export($expected, true) .
            ', erhalten ' . var_export($actual, true);
    }
};
$assertTrue = static function (bool $actual, string $label) use (&$failures): void {
    if (!$actual) {
        $failures[] = $label;
    }
};

$prices = foneday_calculate_prices('100.00');
$assertSame('100.00', $prices['purchase_net'] ?? null, 'Einkauf 100,00');
$assertSame('110.00', $prices['selling_net'] ?? null, 'Verkauf netto 100,00');
$assertSame('130.90', $prices['selling_gross'] ?? null, 'Verkauf brutto 100,00');

$zero = foneday_calculate_prices(0);
$assertSame('0.00', $zero['selling_gross'] ?? null, 'Nullpreis');
$cent = foneday_calculate_prices('0.01');
$assertSame('0.01', $cent['selling_net'] ?? null, 'Centbetrag netto');
$assertSame('0.01', $cent['selling_gross'] ?? null, 'Centbetrag brutto');
$high = foneday_calculate_prices(999999);
$assertSame('1099998.90', $high['selling_net'] ?? null, 'Hoher Preis');
$assertSame('131.89', foneday_calculate_prices(100.75)['selling_gross'] ?? null, 'Preis als Zahl');
$assertSame(null, foneday_calculate_prices(null), 'Fehlender Preis');
$assertSame(null, foneday_calculate_prices(-1), 'Negativer Preis');
$assertSame(null, foneday_calculate_prices('nicht-ein-preis'), 'Ungültiger Preis');

$assertSame(true, foneday_map_availability('Y')['available'], 'Bestand Y');
$assertSame(false, foneday_map_availability('N')['available'], 'Bestand N');
$assertSame(false, foneday_map_availability('')['valid'], 'Bestand leer');
$assertSame(false, foneday_map_availability('X')['valid'], 'Bestand unbekannt');

$assertSame('import', foneday_classify_product(['title' => 'OLED Displayeinheit'])['decision'], 'Display');
$assertSame('import', foneday_classify_product(['title' => 'Akku Batterie'])['decision'], 'Akku');
$assertSame('import', foneday_classify_product(['title' => 'Flexkabel Ersatzteil'])['decision'], 'Ersatzteil');
$assertSame('queue', foneday_classify_product(['title' => 'Smartphone komplett'])['decision'], 'Komplettgerät');
$assertSame('queue', foneday_classify_product(['title' => 'Unbekanntes Produkt'])['decision'], 'Unklar');

$list = [['sku' => 'A'], ['sku' => 'B']];
$assertSame($list, foneday_extract_products(['data' => ['products' => $list]]), 'Verschachtelte Produktliste');
$assertSame([], foneday_extract_products(['products' => []]), 'Leere Produktliste');
$assertSame(2, foneday_next_page(['meta' => ['current_page' => 1, 'last_page' => 2]], 1, 2), 'Pagination nächste Seite');
$assertSame(null, foneday_next_page(['meta' => ['current_page' => 2, 'last_page' => 2]], 2, 2), 'Pagination Ende');

$normalized = foneday_normalize_product([
    'sku' => 'FD-1',
    'ean' => '',
    'title' => 'Display',
    'instock' => 'Y',
    'price' => '10.00',
]);
$assertSame('', $normalized['ean'], 'Leere EAN bleibt leer');
$assertSame([], $normalized['errors'], 'Gültiges Produkt');
$assertTrue(strlen($normalized['fingerprint']) === 64, 'Fingerprint');

if ($failures !== []) {
    fwrite(STDERR, "Foneday Unit-Tests fehlgeschlagen:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Foneday Unit-Tests: OK\n";
