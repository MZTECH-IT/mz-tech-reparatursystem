<?php
// Funktionaler Testlauf von procurement_suggestions_low_stock() gegen eine
// In-Memory-SQLite-DB mit demselben Spaltenschema wie die echte parts-Tabelle
// (nur die hier relevanten Spalten). Reine Logikpruefung, kein Teil der
// eigentlichen Integration.

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE parts (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, stock_quantity INTEGER, reserved_stock INTEGER, min_stock INTEGER, is_discontinued INTEGER)');

$rows = [
    [1, 'SKU-A', 'Display A', 2, 0, 5, 0],   // unter Mindestbestand -> sollte erscheinen
    [2, 'SKU-B', 'Akku B', 10, 0, 5, 0],     // genug Bestand -> sollte NICHT erscheinen
    [3, 'SKU-C', 'Kabel C', 5, 3, 5, 0],     // reserved_stock zieht ab -> unter Mindestbestand -> sollte erscheinen
    [4, 'SKU-D', 'Auslauf D', 0, 0, 5, 1],   // is_discontinued -> sollte NICHT erscheinen
    [5, 'SKU-E', 'Kein Mindestbestand', 0, 0, 0, 0], // min_stock = 0 -> sollte NICHT erscheinen
];
$stmt = $pdo->prepare('INSERT INTO parts (id, sku, name, stock_quantity, reserved_stock, min_stock, is_discontinued) VALUES (?,?,?,?,?,?,?)');
foreach ($rows as $r) { $stmt->execute($r); }

function get_db() { global $pdo; return $pdo; }
function product_suggest_best_supplier(int $partId): ?array { return null; }

function procurement_suggestions_low_stock(): array {
    $db = get_db();
    $stmt = $db->query(
        'SELECT id, sku, name, stock_quantity, reserved_stock, min_stock
           FROM parts
          WHERE min_stock > 0
            AND is_discontinued = 0
            AND (stock_quantity - COALESCE(reserved_stock, 0)) < min_stock'
    );
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suggestions = [];
    foreach ($parts as $part) {
        $available = max(0, (int)$part['stock_quantity'] - (int)($part['reserved_stock'] ?? 0));
        $missing = max(0, (int)$part['min_stock'] - $available);
        if ($missing <= 0) continue;
        $suggestions[] = [
            'part_id'   => (int)$part['id'],
            'sku'       => $part['sku'],
            'name'      => $part['name'],
            'available' => $available,
            'min_stock' => (int)$part['min_stock'],
            'missing'   => $missing,
            'recommended_offer' => product_suggest_best_supplier((int)$part['id']),
        ];
    }
    return $suggestions;
}

$result = procurement_suggestions_low_stock();
$skus = array_column($result, 'sku');
sort($skus);

$expected = ['SKU-A', 'SKU-C'];
if ($skus === $expected) {
    echo "PASS: erwartete SKUs {SKU-A, SKU-C} korrekt ermittelt.\n";
    foreach ($result as $r) {
        echo "  - {$r['sku']}: verfuegbar={$r['available']}, min={$r['min_stock']}, fehlend={$r['missing']}\n";
    }
    exit(0);
} else {
    echo "FAIL: erwartet " . json_encode($expected) . ", erhalten " . json_encode($skus) . "\n";
    exit(1);
}
