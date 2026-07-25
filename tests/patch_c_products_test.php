<?php
// Isolierter Syntaxtest fuer den neuen Codeblock in import_upsert_offer().
// Stub-Definitionen nur zum Zweck der Syntaxpruefung (php -l), nicht Teil
// der eigentlichen Integration.

function part_find(int $id): ?array { return null; }
function pricing_calculate_sell_price(float $p, array $c = [], ?float $s = null): array { return []; }
function get_db() { return null; }

function import_upsert_offer_TEST(int $partId, int $supplierId, ?array $historyFlag, ?float $price) {
    $db = get_db();
    $offerId = 1;

    if ($price !== null && empty($historyFlag['flagged'])) {
        $part = part_find($partId);
        if ($part) {
            $calc = pricing_calculate_sell_price(
                $price,
                [
                    'part_id'     => $partId,
                    'supplier_id' => $supplierId,
                    'category'    => $part['category'] ?? null,
                ],
                $part['selling_price'] !== null ? (float)$part['selling_price'] : null
            );
            if (!empty($calc['applied']) && isset($calc['price'])) {
                $db->prepare('UPDATE parts SET selling_price = ? WHERE id = ?')
                   ->execute([$calc['price'], $partId]);
            }
        }
    }

    return $offerId;
}
