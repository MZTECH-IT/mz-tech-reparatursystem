# Integrationspatches — alle byte-genau an real gelesenem Originalcode verankert

Jeder Patch zeigt VORHER (exakt wie im echten Repository, Branch `main`,
per Rohtext-Abruf verifiziert) und NACHHER. Anwenden mit `git apply` (Diff
siehe `integration_02_patches.diff`) oder manuell per Suchen/Ersetzen anhand
des VORHER-Blocks.

---

## Patch A — `private/supplier_adapters.php` (unverändert aus Phase 2b, hier erneut geprüft)

Verifiziert: `SftpAdapter`/`SoapApiAdapter` befinden sich in der echten
Datei nach wie vor exakt im selben Stub-Zustand wie beim ersten Fetch in
Phase 2b — keine Änderung am Patch nötig, unverändert übernommen.

VORHER:
```php
class SftpAdapter implements SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array {
        if (function_exists('ssh2_connect')) {
            return ['success' => false, 'message' => 'SFTP-Erweiterung (ssh2) erkannt, Verbindungslogik muss lieferantenspezifisch ergänzt werden (siehe SCHNITTSTELLEN_ADAPTER_DOKUMENTATION.txt).'];
        }
        return ['success' => false, 'message' => 'SFTP ist auf diesem Server nicht verfügbar (PHP-Erweiterung "ssh2" bzw. phpseclib fehlt). Bitte Hoster kontaktieren oder FTPS/HTTPS-Download als Alternative verwenden.'];
    }
    public function fetchPayload(array $profile, array $credentials): array {
        return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SFTP ist auf diesem Server aktuell nicht verfügbar (siehe testConnection()).'];
    }
}
```

NACHHER: siehe `phase2b_01_patches_bestehende_dateien.md` (bereits ausgeliefert,
inhaltlich unverändert gültig) — vollständige `SftpAdapter`-Klasse mit
`ssh2_connect`/`ssh2_auth_password`/`ssh2_sftp`-Anbindung und sauberem
Fallback, sowie `SoapApiAdapter::fetchPayload()` mit `soap_method`/`soap_params`
aus den entschlüsselten Zugangsdaten und `$client->__soapCall(...)`.

---

## Patch B — `private/purchase_orders.php`, Funktion `purchase_order_calculate_shipping()`

Verifiziert: identischer Stub-Zustand wie in Phase 2b (`pro_versandklasse`/
`pro_land` setzen nur `$amount = (float)$rule['amount'];` ohne Land-/Klassen-
Abgleich). Spalten `suppliers.address_country` und
`purchase_order_items.notes` gegen die eigene Phase-2a-Migration
gegengeprüft — beide dort korrekt definiert (`address_country VARCHAR(2)`,
`notes VARCHAR(500)`).

VORHER:
```php
            case 'pro_versandklasse':
            case 'pro_land':
                $amount = (float)$rule['amount'];
                break;
```

NACHHER: siehe `phase2b_01_patches_bestehende_dateien.md` (Patch 3) —
inhaltlich unverändert gültig: `pro_land` vergleicht `condition_text` gegen
`suppliers.address_country` des Lieferanten, `pro_versandklasse` gleicht
`condition_text` case-insensitiv gegen `notes` jeder Position ab.

---

## Patch C — `private/products.php`, Funktion `import_upsert_offer()` — NEU (Integrationsphase)

Verankert automatische Verkaufspreis-Neuberechnung über die BEREITS
VORHANDENEN Funktionen `pricing_rule_match()`/`pricing_calculate_sell_price()`
aus `private/pricing_rules.php`. `private/products.php` bindet diese Datei
bereits am Dateianfang ein (`require_once __DIR__ . '/pricing_rules.php';`,
verifiziert) — **kein neuer `require_once` nötig**.

VORHER (letzte Zeilen der echten Funktion, verbatim verifiziert):
```php
    product_recalculate_price_flag($partId);
    return $offerId;
}
```

NACHHER:
```php
    product_recalculate_price_flag($partId);

    // Integrationsphase: automatische Verkaufspreis-Neuberechnung ueber die
    // bereits vorhandenen Funktionen pricing_rule_match()/pricing_calculate_sell_price()
    // (private/pricing_rules.php, hier bereits eingebunden). Diese Funktionen
    // existierten bereits vollstaendig fertig, wurden aber bisher von keiner
    // Stelle im Code aufgerufen. Wird NUR angewendet, wenn eine passende Regel
    // existiert UND price_guard den Preis nicht als verdaechtig markiert hat.
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
```

---

## Patch D — `private/purchase_orders.php` — NEUE Funktion `procurement_suggestions_low_stock()`

Einzige echte Phase-3-Lücke (siehe `integration_00_KORREKTUR_WICHTIG.md`).
Einzufügen direkt nach der bestehenden Funktion `procurement_suggestion_for_repair()`.
Nutzt `product_suggest_best_supplier()` aus `private/products.php`
(bereits per `require_once __DIR__ . '/products.php';` am Dateianfang
eingebunden, verifiziert).

VORHER (Ende der bestehenden, echten Funktion, verbatim verifiziert):
```php
        $suggestions[] = $suggestion;
    }
    return $suggestions;
}
```

NACHHER (unverändertes Ende + neue Funktion direkt danach):
```php
        $suggestions[] = $suggestion;
    }
    return $suggestions;
}

/**
 * Ermittelt ALLE Artikel, deren verfuegbarer Bestand (stock_quantity -
 * reserved_stock) den hinterlegten Mindestbestand (min_stock) unterschreitet,
 * unabhaengig von einer konkreten Reparatur - im Unterschied zu
 * procurement_suggestion_for_repair() oben. Nutzt fuer die
 * Lieferantenempfehlung dieselbe, bereits vorhandene Funktion
 * product_suggest_best_supplier().
 */
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
```

Hinweis: `min_stock` ist eine Basis-Spalte aus `sql/schema.sql` (bereits
vorhanden). `reserved_stock` und `is_discontinued` sind Spalten aus der
Phase-2a-Migration (`sql/update.sql`-Ergänzung) — diese Migration muss vor
Nutzung dieser Funktion eingespielt sein, exakt wie bei allen anderen
Supplier-Modul-Funktionen auch.

---

## Patch E — `public/purchase_orders.php` — NEU: POST-Aktion + UI-Karte für Bestellvorschläge

**Version 2 (Phase 3, „UI vollständig fertigstellen"):** gegenüber der
ersten Integrationsfassung um editierbare Mengenvorschläge je Position
sowie eine sichtbare Liste der Vorschläge ohne automatische
Lieferantenzuordnung erweitert (nichts wird mehr still verworfen).
Funktional per Unit-Tests abgedeckt (Gruppierung, Mengen-Override,
Lieferanten-Filterung, Ausschluss ohne Angebot, Minimum-Klemmung) — siehe
`integration_02_tests_ausgefuehrt.md`.

Rein additiv. Verifiziert: diese Seite hatte bisher **keinen** eigenen
POST-Handler (reine Listenansicht) — der neue Block wird direkt nach
`require_permission('manage_purchase_orders');` eingefügt, vor jeder
HTML-Ausgabe.

VORHER (echter Dateianfang, verbatim verifiziert):
```php
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');
```

NACHHER:
```php
require_once __DIR__ . '/init.php';
require_permission('manage_purchase_orders');

// Integrationsphase / Phase 3: Bestell-Entwurf aus Nachbestell-Vorschlaegen
// anlegen. Rein additiv - bestehende GET-Listenansicht dieser Seite bleibt
// unveraendert.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_draft_from_suggestion') {
    verify_csrf();
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $partIds = array_map('intval', (array)($_POST['part_id'] ?? []));
    $qtyOverrides = (array)($_POST['qty'] ?? []);
    if ($supplierId > 0 && !empty($partIds)) {
        $items = [];
        foreach (procurement_suggestions_low_stock() as $s) {
            if (!in_array($s['part_id'], $partIds, true)) continue;
            $offer = $s['recommended_offer']['offer'] ?? null;
            if (!$offer || (int)($offer['supplier_id'] ?? 0) !== $supplierId) continue;
            $qty = isset($qtyOverrides[$s['part_id']]) ? max(1, (int)$qtyOverrides[$s['part_id']]) : $s['missing'];
            $items[] = [
                'part_id'           => $s['part_id'],
                'supplier_offer_id' => $offer['id'],
                'quantity'          => $qty,
                'assigned_stock'    => 1,
                'notes'             => 'Automatischer Bestellvorschlag (Mindestbestand unterschritten)',
            ];
        }
        if ($items) {
            $orderId = purchase_order_create_draft($supplierId, $items, $_SESSION['user_id'] ?? null);
            flash('success', count($items) . ' Position(en) als Bestell-Entwurf #' . $orderId . ' angelegt.');
        } else {
            flash('error', 'Keine passenden Vorschlaege fuer diesen Lieferanten gefunden.');
        }
    }
    header('Location: purchase_orders.php');
    exit;
}
```

Und die HTML-Karte, einzufügen direkt vor dem bestehenden
`<div class="card">` der Bestellübersicht (nach dem schließenden `</div>`
der Toolbar, verifiziert als sichere Einfügestelle):

```php
<?php $lowStockSuggestions = procurement_suggestions_low_stock(); ?>
<?php if (!empty($lowStockSuggestions)): ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h2 class="card-title">Bestellvorschläge (Mindestbestand unterschritten) <span class="badge-secondary"><?= count($lowStockSuggestions) ?></span></h2></div>
    <div class="card-body">
        <?php
        $bySupplier = [];
        $withoutOffer = [];
        foreach ($lowStockSuggestions as $s) {
            $offer = $s['recommended_offer']['offer'] ?? null;
            if (!$offer) { $withoutOffer[] = $s; continue; }
            $bySupplier[(int)$offer['supplier_id']][] = $s;
        }
        ?>
        <?php foreach ($bySupplier as $supplierId => $items): ?>
            <?php $supplier = supplier_find($supplierId); ?>
            <form method="post" style="margin-bottom:1rem;border-bottom:1px solid #e2e2e2;padding-bottom:.75rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_draft_from_suggestion">
                <input type="hidden" name="supplier_id" value="<?= (int)$supplierId ?>">
                <strong><?= htmlspecialchars($supplier['name'] ?? ('Lieferant #' . $supplierId)) ?></strong>
                <table class="table" style="margin-top:.5rem;">
                    <thead><tr><th></th><th>SKU</th><th>Artikel</th><th>Verfügbar</th><th>Mindestbestand</th><th>Menge</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><input type="checkbox" name="part_id[]" value="<?= (int)$it['part_id'] ?>" checked></td>
                            <td><?= htmlspecialchars((string)$it['sku']) ?></td>
                            <td><?= htmlspecialchars((string)$it['name']) ?></td>
                            <td><?= (int)$it['available'] ?></td>
                            <td><?= (int)$it['min_stock'] ?></td>
                            <td><input type="number" name="qty[<?= (int)$it['part_id'] ?>]" value="<?= (int)$it['missing'] ?>" min="1" style="width:70px;"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-sm btn-primary">Entwurf für diesen Lieferanten anlegen</button>
            </form>
        <?php endforeach; ?>

        <?php if (!empty($withoutOffer)): ?>
            <div style="margin-top:1rem;">
                <strong>Ohne automatische Lieferantenzuordnung (<?= count($withoutOffer) ?>):</strong>
                <table class="table" style="margin-top:.5rem;">
                    <thead><tr><th>SKU</th><th>Artikel</th><th>Verfügbar</th><th>Mindestbestand</th><th>Fehlend</th></tr></thead>
                    <tbody>
                    <?php foreach ($withoutOffer as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$it['sku']) ?></td>
                            <td><?= htmlspecialchars((string)$it['name']) ?></td>
                            <td><?= (int)$it['available'] ?></td>
                            <td><?= (int)$it['min_stock'] ?></td>
                            <td><?= (int)$it['missing'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="text-muted">Für diese Artikel liegt kein automatisch auswertbares Lieferantenangebot vor — bitte manuell über die Bestellübersicht anlegen.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
```

Verwendete Konventionen (`verify_csrf()`, `csrf_field()`, `flash()`,
`$_SESSION['user_id']`, CSS-Klassen `card`/`card-header`/`card-title`/
`card-body`/`badge-secondary`/`btn btn-sm btn-primary`/`table`) wurden
gegen `public/purchase_order_form.php` bzw. `public/purchase_orders.php`
selbst verifiziert, nicht neu erfunden. Bewusst **kein** `svg_icon(...)`-
Aufruf mit einem nicht verifizierten Icon-Namen, um keine Annahme über den
Icon-Namensraum zu treffen.

---

## Nicht integriert (siehe `integration_00_KORREKTUR_WICHTIG.md`)

- `private/pricing_engine.php` (Phase 2b) — verworfen, Duplikat.
- `private/supplier_error_log.php` (Phase 2b) — verworfen, Duplikat.
- SQL-Tabelle `supplier_sync_error_log` (Phase 2b) — verworfen, unbenutzte Parallelstruktur.

## Neu integriert

- `private/cli/supplier_sync_worker.php` — korrigierte Fassung, siehe
  separat ausgelieferte Datei `integration_cli_supplier_sync_worker.php`
  (nach Anwendung als `private/cli/supplier_sync_worker.php` einspielen).
- `sql/update.sql` — Anhang: unverändert die vollständige Phase-2a-Migration
  (12 Tabellen + `parts`-Spalten), siehe `phase2a_02_append_to_update.sql`
  (bereits ausgeliefert, inhaltlich unverändert gültig). **Kein**
  Phase-2b-SQL mehr (siehe oben).
