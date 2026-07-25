<?php
/**
 * MZ Tech – Konfigurierbare Verkaufspreis-/Kalkulationsregeln (Phase 6,
 * Auftragsabschnitt 9 "Konfigurierbare Verkaufspreis-/Kalkulationsregeln").
 * ----------------------------------------------------------------------
 * Regeln werden nach Geltungsbereich (global < Kategorie < Lieferant <
 * Einzelprodukt) und Priorität sortiert; die erste zutreffende Regel
 * gewinnt (spezifischere Regeln können über eine niedrigere priority
 * Vorrang vor globalen Regeln erhalten). Mindestmarge/-gewinn/-preis
 * werden IMMER als zusätzliche, harte Untergrenze angewendet, unabhängig
 * davon, welche Regel den Grundpreis berechnet hat.
 */

function pricing_rules_list(bool $onlyActive = false): array {
    $db = get_db();
    $sql = 'SELECT * FROM pricing_rules';
    if ($onlyActive) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY FIELD(scope_type, "einzelprodukt","lieferant","kategorie","global"), priority ASC, id ASC';
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function pricing_rule_find(int $id): ?array {
    $stmt = get_db()->prepare('SELECT * FROM pricing_rules WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pricing_rule_save(array $data, ?int $id, ?int $userId): int {
    $db = get_db();
    $fields = [
        'name' => trim($data['name'] ?? ''),
        'scope_type' => $data['scope_type'] ?? 'global',
        'scope_value' => trim($data['scope_value'] ?? '') ?: null,
        'calculation_type' => $data['calculation_type'] ?? 'prozent_aufschlag',
        'value' => (float)str_replace(',', '.', (string)($data['value'] ?? 0)),
        'min_margin_percent' => ($data['min_margin_percent'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$data['min_margin_percent']) : null,
        'min_profit_amount' => ($data['min_profit_amount'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$data['min_profit_amount']) : null,
        'min_price' => ($data['min_price'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$data['min_price']) : null,
        'rounding_mode' => $data['rounding_mode'] ?? 'keine',
        'price_ending' => $data['price_ending'] ?? 'keine',
        'priority' => (int)($data['priority'] ?? 100),
        'is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $db->prepare("UPDATE pricing_rules SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
        return $id;
    }
    $fields['created_by'] = $userId;
    $cols = implode(', ', array_keys($fields));
    $qs = implode(', ', array_fill(0, count($fields), '?'));
    $db->prepare("INSERT INTO pricing_rules ($cols) VALUES ($qs)")->execute(array_values($fields));
    return (int)$db->lastInsertId();
}

function pricing_rule_delete(int $id): void {
    get_db()->prepare('DELETE FROM pricing_rules WHERE id = ?')->execute([$id]);
}

/** Rundet gemäß rounding_mode. */
function pricing_apply_rounding(float $price, string $mode): float {
    $step = match ($mode) {
        'auf_0_05' => 0.05,
        'auf_0_10' => 0.10,
        'auf_0_50' => 0.50,
        'auf_1_00' => 1.00,
        default    => null,
    };
    if ($step === null) return round($price, 2);
    return round(round($price / $step) * $step, 2);
}

/** Erzwingt eine Preisendung (z. B. X,99 €). */
function pricing_apply_ending(float $price, string $ending): float {
    if ($ending === 'keine') return $price;
    $whole = floor($price);
    $fraction = match ($ending) {
        '_99' => 0.99,
        '_95' => 0.95,
        '_00' => 0.00,
        default => null,
    };
    if ($fraction === null) return $price;
    $result = $whole + $fraction;
    // Niemals unter den ursprünglichen (bereits gerundeten) Preis fallen.
    return $result < $price - 0.5 ? $result + 1 : $result;
}

/**
 * Findet die zutreffende Regel für ein Produkt (Kontext enthält
 * part_id/category/supplier_id) — spezifischste Übereinstimmung zuerst.
 */
function pricing_rule_match(array $context): ?array {
    foreach (pricing_rules_list(true) as $rule) {
        $matches = match ($rule['scope_type']) {
            'einzelprodukt' => (string)($context['part_id'] ?? '') === (string)$rule['scope_value'],
            'lieferant'     => (string)($context['supplier_id'] ?? '') === (string)$rule['scope_value'],
            'kategorie'     => strcasecmp((string)($context['category'] ?? ''), (string)$rule['scope_value']) === 0,
            'global'        => true,
            default         => false,
        };
        if ($matches) return $rule;
    }
    return null;
}

/**
 * Berechnet den Verkaufspreis aus einem Einkaufspreis unter Anwendung der
 * passenden Kalkulationsregel (falls vorhanden) sowie aller
 * Mindestwert-Untergrenzen. Gibt niemals automatisch einen Preis <= 0
 * zurück; ohne passende Regel wird der bestehende Verkaufspreis
 * unverändert vorgeschlagen (rein additiv, kein Zwang zur Neukalkulation).
 *
 * @return array{price: float, rule_id: ?int, applied: bool}
 */
function pricing_calculate_sell_price(float $purchasePrice, array $context = [], ?float $currentSellingPrice = null): array {
    $rule = pricing_rule_match($context);
    if (!$rule) {
        return ['price' => $currentSellingPrice ?? $purchasePrice, 'rule_id' => null, 'applied' => false];
    }

    $price = match ($rule['calculation_type']) {
        'prozent_aufschlag' => $purchasePrice * (1 + ((float)$rule['value'] / 100)),
        'fester_aufschlag'  => $purchasePrice + (float)$rule['value'],
        'fixer_preis'       => (float)$rule['value'],
        default             => $purchasePrice,
    };

    // Mindestmarge/-gewinn/-preis als harte Untergrenzen.
    if ($rule['min_margin_percent'] !== null && $purchasePrice > 0) {
        $minByMargin = $purchasePrice / (1 - ((float)$rule['min_margin_percent'] / 100));
        $price = max($price, $minByMargin);
    }
    if ($rule['min_profit_amount'] !== null) {
        $price = max($price, $purchasePrice + (float)$rule['min_profit_amount']);
    }
    if ($rule['min_price'] !== null) {
        $price = max($price, (float)$rule['min_price']);
    }

    $price = pricing_apply_rounding($price, $rule['rounding_mode']);
    $price = pricing_apply_ending($price, $rule['price_ending']);

    return ['price' => round($price, 2), 'rule_id' => (int)$rule['id'], 'applied' => true];
}
