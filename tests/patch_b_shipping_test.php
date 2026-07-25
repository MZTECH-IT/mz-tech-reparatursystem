<?php
// Isolierter Test für Patch B (purchase_order_calculate_shipping, pro_land/pro_versandklasse).

function get_db() { global $pdo; return $pdo; }
function supplier_shipping_rules_list(int $supplierId): array { global $rules; return $rules; }

function purchase_order_calculate_shipping(int $supplierId, array $items): array {
    $orderValue = 0.0;
    $allExact = !empty($items);
    $exactTotal = 0.0;
    foreach ($items as $item) {
        $offerId = $item['supplier_offer_id'] ?? null;
        if (!$offerId) { $allExact = false; continue; }
        $stmt = get_db()->prepare('SELECT purchase_price, shipping_cost_estimate, shipping_cost_is_estimate FROM product_supplier_offers WHERE id = ?');
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$offer) { $allExact = false; continue; }
        $orderValue += (float)($offer['purchase_price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1));
        if ($offer['shipping_cost_is_estimate'] || $offer['shipping_cost_estimate'] === null) {
            $allExact = false;
        } else {
            $exactTotal = max($exactTotal, (float)$offer['shipping_cost_estimate']);
        }
    }
    if ($allExact) {
        return [$exactTotal, false];
    }

    $rules = supplier_shipping_rules_list($supplierId);
    $amount = 0.0;
    foreach ($rules as $rule) {
        switch ($rule['rule_type']) {
            case 'fest':
                $amount = (float)$rule['amount'];
                break;
            case 'kostenlos_ab':
                if ($rule['condition_value'] !== null && $orderValue >= (float)$rule['condition_value']) {
                    $amount = 0.0;
                } else {
                    $amount = (float)$rule['amount'];
                }
                break;
            case 'express_zuschlag':
            case 'sperrgut_zuschlag':
            case 'gefahrgut_zuschlag':
                $amount += (float)$rule['amount'];
                break;
            case 'pro_versandklasse':
                $needle = trim((string)($rule['condition_text'] ?? ''));
                if ($needle !== '') {
                    foreach ($items as $item) {
                        $noteHaystack = mb_strtolower((string)($item['notes'] ?? ''));
                        if ($noteHaystack !== '' && str_contains($noteHaystack, mb_strtolower($needle))) {
                            $amount = (float)$rule['amount'];
                            break;
                        }
                    }
                } else {
                    $amount = (float)$rule['amount'];
                }
                break;
            case 'pro_land':
                $needle = trim((string)($rule['condition_text'] ?? ''));
                if ($needle !== '') {
                    $stmtCountry = get_db()->prepare('SELECT address_country FROM suppliers WHERE id = ?');
                    $stmtCountry->execute([$supplierId]);
                    $supplierCountry = (string)($stmtCountry->fetchColumn() ?: '');
                    if (strcasecmp(trim($supplierCountry), $needle) === 0) {
                        $amount = (float)$rule['amount'];
                    }
                } else {
                    $amount = (float)$rule['amount'];
                }
                break;
        }
    }
    return [round($amount, 2), true];
}

// --- Funktionaler Testlauf gegen SQLite ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE product_supplier_offers (id INTEGER PRIMARY KEY, purchase_price REAL, shipping_cost_estimate REAL, shipping_cost_is_estimate INTEGER)');
$pdo->exec('CREATE TABLE suppliers (id INTEGER PRIMARY KEY, address_country TEXT)');
$pdo->exec("INSERT INTO suppliers (id, address_country) VALUES (1, 'CN')");

$fails = 0;

// Test 1: pro_land greift bei Uebereinstimmung
$rules = [['rule_type' => 'pro_land', 'condition_text' => 'CN', 'amount' => 19.90]];
[$amount, $isEstimate] = purchase_order_calculate_shipping(1, [['supplier_offer_id' => null, 'notes' => '']]);
if (abs($amount - 19.90) > 0.001) { echo "FAIL Test1: erwartet 19.90, erhalten $amount\n"; $fails++; } else { echo "PASS Test1: pro_land CN==CN -> 19.90\n"; }

// Test 2: pro_land greift NICHT bei Nicht-Uebereinstimmung
$rules = [['rule_type' => 'pro_land', 'condition_text' => 'DE', 'amount' => 19.90]];
[$amount, $isEstimate] = purchase_order_calculate_shipping(1, [['supplier_offer_id' => null, 'notes' => '']]);
if (abs($amount - 0.0) > 0.001) { echo "FAIL Test2: erwartet 0.0, erhalten $amount\n"; $fails++; } else { echo "PASS Test2: pro_land DE!=CN -> 0.0\n"; }

// Test 3: pro_versandklasse greift bei Schlagwort in notes (case-insensitive)
$rules = [['rule_type' => 'pro_versandklasse', 'condition_text' => 'sperrgut', 'amount' => 9.50]];
[$amount, $isEstimate] = purchase_order_calculate_shipping(1, [['supplier_offer_id' => null, 'notes' => 'Achtung SPERRGUT, vorsichtig']]);
if (abs($amount - 9.50) > 0.001) { echo "FAIL Test3: erwartet 9.50, erhalten $amount\n"; $fails++; } else { echo "PASS Test3: pro_versandklasse Schlagwort-Treffer (case-insensitive) -> 9.50\n"; }

// Test 4: pro_versandklasse greift NICHT ohne Schlagwort
$rules = [['rule_type' => 'pro_versandklasse', 'condition_text' => 'sperrgut', 'amount' => 9.50]];
[$amount, $isEstimate] = purchase_order_calculate_shipping(1, [['supplier_offer_id' => null, 'notes' => 'normales Teil']]);
if (abs($amount - 0.0) > 0.001) { echo "FAIL Test4: erwartet 0.0, erhalten $amount\n"; $fails++; } else { echo "PASS Test4: pro_versandklasse ohne Schlagwort -> 0.0\n"; }

// Test 5: bestehende Regel 'fest' weiterhin unveraendert funktionsfaehig (Regressionstest)
$rules = [['rule_type' => 'fest', 'amount' => 4.99]];
[$amount, $isEstimate] = purchase_order_calculate_shipping(1, [['supplier_offer_id' => null, 'notes' => '']]);
if (abs($amount - 4.99) > 0.001) { echo "FAIL Test5: erwartet 4.99, erhalten $amount\n"; $fails++; } else { echo "PASS Test5 (Regression 'fest'): -> 4.99\n"; }

exit($fails > 0 ? 1 : 0);
