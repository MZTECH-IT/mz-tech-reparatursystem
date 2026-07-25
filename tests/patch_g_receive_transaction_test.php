<?php
// Test für Fix C: purchase_order_item_receive() mit Transaktionsschutz.
// Prüft insbesondere: bei einer Exception NACH der ersten UPDATE-Anweisung
// darf KEINE der Änderungen (quantity_received, stock_quantity, Status)
// dauerhaft übernommen werden (Rollback).

function get_db() { global $pdo; return $pdo; }
function purchase_order_find(int $id): ?array {
    global $pdo;
    $s = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function purchase_order_items_list(int $orderId): array {
    global $pdo;
    $s = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = ?');
    $s->execute([$orderId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function purchase_order_set_status(int $orderId, string $status): void {
    global $pdo, $simulateFailureOnStatusUpdate;
    if ($simulateFailureOnStatusUpdate) {
        throw new RuntimeException('Simulierter Fehler beim Statusupdate');
    }
    $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
}

function purchase_order_item_receive(int $itemId, int $receivedQuantity): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) { $db->rollBack(); return; }

        $newReceived = max(0, min($receivedQuantity, (int)$item['quantity']));
        $db->prepare('UPDATE purchase_order_items SET quantity_received = ? WHERE id = ?')->execute([$newReceived, $itemId]);

        $delta = $newReceived - (int)$item['quantity_received'];
        if ($delta !== 0) {
            $db->prepare('UPDATE parts SET stock_quantity = stock_quantity + ? WHERE id = ?')->execute([$delta, $item['part_id']]);
        }

        $order = purchase_order_find((int)$item['purchase_order_id']);
        if ($order) {
            $items = purchase_order_items_list((int)$order['id']);
            $allDelivered = true; $anyDelivered = false;
            foreach ($items as $i) {
                $receivedForRow = ((int)$i['id'] === $itemId) ? $newReceived : (int)$i['quantity_received'];
                if ($receivedForRow < (int)$i['quantity']) $allDelivered = false;
                if ($receivedForRow > 0) $anyDelivered = true;
            }
            if ($allDelivered) purchase_order_set_status((int)$order['id'], 'geliefert');
            elseif ($anyDelivered) purchase_order_set_status((int)$order['id'], 'teilweise_geliefert');
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// --- Testaufbau ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE purchase_orders (id INTEGER PRIMARY KEY, status TEXT)');
$pdo->exec('CREATE TABLE purchase_order_items (id INTEGER PRIMARY KEY, purchase_order_id INTEGER, part_id INTEGER, quantity INTEGER, quantity_received INTEGER)');
$pdo->exec('CREATE TABLE parts (id INTEGER PRIMARY KEY, stock_quantity INTEGER)');

$fails = 0;

// Test 1: Erfolgreicher Wareneingang (Regression - Normalfall muss weiter funktionieren)
$pdo->exec('DELETE FROM purchase_orders'); $pdo->exec('DELETE FROM purchase_order_items'); $pdo->exec('DELETE FROM parts');
$pdo->exec("INSERT INTO purchase_orders (id, status) VALUES (1, 'bestellt')");
$pdo->exec("INSERT INTO purchase_order_items (id, purchase_order_id, part_id, quantity, quantity_received) VALUES (1, 1, 100, 5, 0)");
$pdo->exec("INSERT INTO parts (id, stock_quantity) VALUES (100, 10)");
$simulateFailureOnStatusUpdate = false;
purchase_order_item_receive(1, 5);
$stock = $pdo->query('SELECT stock_quantity FROM parts WHERE id = 100')->fetchColumn();
$status = $pdo->query('SELECT status FROM purchase_orders WHERE id = 1')->fetchColumn();
if ((int)$stock === 15 && $status === 'geliefert') {
    echo "PASS Test1 (Regression): Normaler Wareneingang funktioniert weiterhin (Bestand 10->15, Status->geliefert)\n";
} else {
    echo "FAIL Test1: stock=$stock status=$status\n"; $fails++;
}

// Test 2: Fehler WÄHREND der Verarbeitung -> vollständiger Rollback, kein Teilzustand
$pdo->exec('DELETE FROM purchase_orders'); $pdo->exec('DELETE FROM purchase_order_items'); $pdo->exec('DELETE FROM parts');
$pdo->exec("INSERT INTO purchase_orders (id, status) VALUES (2, 'bestellt')");
$pdo->exec("INSERT INTO purchase_order_items (id, purchase_order_id, part_id, quantity, quantity_received) VALUES (2, 2, 200, 3, 0)");
$pdo->exec("INSERT INTO parts (id, stock_quantity) VALUES (200, 7)");
$simulateFailureOnStatusUpdate = true;
$threw = false;
try {
    purchase_order_item_receive(2, 3);
} catch (Throwable $e) {
    $threw = true;
}
$stock = $pdo->query('SELECT stock_quantity FROM parts WHERE id = 200')->fetchColumn();
$qtyReceived = $pdo->query('SELECT quantity_received FROM purchase_order_items WHERE id = 2')->fetchColumn();
$status = $pdo->query('SELECT status FROM purchase_orders WHERE id = 2')->fetchColumn();
if ($threw && (int)$stock === 7 && (int)$qtyReceived === 0 && $status === 'bestellt') {
    echo "PASS Test2 (Transaktionsschutz): Bei Fehler bleibt ALLES unverändert (kein Teil-Update von Bestand/Menge/Status)\n";
} else {
    echo "FAIL Test2: threw=" . ($threw?'yes':'no') . " stock=$stock qtyReceived=$qtyReceived status=$status\n"; $fails++;
}

exit($fails > 0 ? 1 : 0);
