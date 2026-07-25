<?php
// Funktionaler Test der reinen Entscheidungslogik aus Patch E v2 (public/purchase_orders.php):
// Gruppierung nach Lieferant, Mengen-Override, Trennung "ohne Angebot".
// Extrahiert 1:1 aus dem POST-Handler bzw. der Gruppierungslogik im Patch.

function build_draft_items_from_suggestions(array $suggestions, int $supplierId, array $partIds, array $qtyOverrides): array {
    $items = [];
    foreach ($suggestions as $s) {
        if (!in_array($s['part_id'], $partIds, true)) continue;
        $offer = $s['recommended_offer']['offer'] ?? null;
        if (!$offer || (int)($offer['supplier_id'] ?? 0) !== $supplierId) continue;
        $qty = isset($qtyOverrides[$s['part_id']]) ? max(1, (int)$qtyOverrides[$s['part_id']]) : $s['missing'];
        $items[] = [
            'part_id'           => $s['part_id'],
            'supplier_offer_id' => $offer['id'],
            'quantity'          => $qty,
        ];
    }
    return $items;
}

function group_suggestions_by_supplier(array $suggestions): array {
    $bySupplier = [];
    $withoutOffer = [];
    foreach ($suggestions as $s) {
        $offer = $s['recommended_offer']['offer'] ?? null;
        if (!$offer) { $withoutOffer[] = $s; continue; }
        $bySupplier[(int)$offer['supplier_id']][] = $s;
    }
    return [$bySupplier, $withoutOffer];
}

$suggestions = [
    ['part_id' => 1, 'sku' => 'A', 'missing' => 3, 'recommended_offer' => ['offer' => ['id' => 101, 'supplier_id' => 10]]],
    ['part_id' => 2, 'sku' => 'B', 'missing' => 2, 'recommended_offer' => ['offer' => ['id' => 102, 'supplier_id' => 10]]],
    ['part_id' => 3, 'sku' => 'C', 'missing' => 5, 'recommended_offer' => ['offer' => ['id' => 103, 'supplier_id' => 20]]],
    ['part_id' => 4, 'sku' => 'D', 'missing' => 1, 'recommended_offer' => null],
];

$fails = 0;

// Test 1: Gruppierung nach Lieferant
[$bySupplier, $withoutOffer] = group_suggestions_by_supplier($suggestions);
if (count($bySupplier) === 2 && count($bySupplier[10]) === 2 && count($bySupplier[20]) === 1 && count($withoutOffer) === 1) {
    echo "PASS Test1: Gruppierung nach Lieferant (10=>2, 20=>1, ohne Angebot=1)\n";
} else {
    echo "FAIL Test1: " . json_encode([$bySupplier, $withoutOffer]) . "\n"; $fails++;
}

// Test 2: Entwurf-Positionen für Lieferant 10, beide Teile ausgewählt, keine Mengen-Overrides
$items = build_draft_items_from_suggestions($suggestions, 10, [1, 2], []);
if (count($items) === 2 && $items[0]['quantity'] === 3 && $items[1]['quantity'] === 2) {
    echo "PASS Test2: Automatische Mengenvorschläge übernommen (3, 2)\n";
} else {
    echo "FAIL Test2: " . json_encode($items) . "\n"; $fails++;
}

// Test 3: Mengen-Override durch Nutzer
$items = build_draft_items_from_suggestions($suggestions, 10, [1], [1 => '7']);
if (count($items) === 1 && $items[0]['quantity'] === 7) {
    echo "PASS Test3: Mengen-Override wird übernommen (7 statt Vorschlag 3)\n";
} else {
    echo "FAIL Test3: " . json_encode($items) . "\n"; $fails++;
}

// Test 4: Falscher Lieferant wird herausgefiltert (Teil 3 gehört zu Lieferant 20, nicht 10)
$items = build_draft_items_from_suggestions($suggestions, 10, [1, 3], []);
if (count($items) === 1 && $items[0]['part_id'] === 1) {
    echo "PASS Test4: Teile anderer Lieferanten werden korrekt ausgefiltert\n";
} else {
    echo "FAIL Test4: " . json_encode($items) . "\n"; $fails++;
}

// Test 5: Teil ohne Angebot wird nie in Entwurf-Items aufgenommen, selbst wenn part_id mitgeschickt würde
$items = build_draft_items_from_suggestions($suggestions, 10, [4], []);
if (count($items) === 0) {
    echo "PASS Test5: Teile ohne Angebot werden nie automatisch bestellt\n";
} else {
    echo "FAIL Test5: " . json_encode($items) . "\n"; $fails++;
}

// Test 6: Mengen-Override <= 0 wird auf Minimum 1 geklemmt
$items = build_draft_items_from_suggestions($suggestions, 10, [1], [1 => '0']);
if (count($items) === 1 && $items[0]['quantity'] === 1) {
    echo "PASS Test6: Mengen-Override wird auf Minimum 1 geklemmt\n";
} else {
    echo "FAIL Test6: " . json_encode($items) . "\n"; $fails++;
}

exit($fails > 0 ? 1 : 0);
