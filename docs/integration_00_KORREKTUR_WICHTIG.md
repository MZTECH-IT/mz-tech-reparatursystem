# Integrationsphase — wichtige Korrektur gegenüber Phase 2b

Bei der tatsächlichen Integration wurden die betroffenen echten Dateien erneut
und diesmal **funktionsscharf** (jede einzelne Funktionsdeklaration einzeln
verifiziert, nicht nur zusammengefasst) aus dem Repository gelesen. Dabei
wurde festgestellt, dass **zwei der drei in Phase 2b als „neu" gebauten
Dateien tatsächlich bereits vollständig vorhandene, funktionierende Logik
duplizieren** — das widerspricht deiner ausdrücklichen Vorgabe „keine
Parallelstruktur" / „nur echte Lücken schließen". Diese beiden Dateien wurden
daher **nicht integriert**:

## 1. `private/pricing_engine.php` — VERWORFEN

`private/pricing_rules.php` (bereits vollständig vorhanden, 146 Zeilen)
enthält bereits:
- `pricing_rule_match(array $context): ?array` — exakt dieselbe
  Prioritätslogik Einzelprodukt → Lieferant → Kategorie → Global (per
  `ORDER BY FIELD(scope_type, "einzelprodukt","lieferant","kategorie","global"), priority ASC, id ASC`
  in `pricing_rules_list()`), die Phase 2b neu nachgebaut hatte.
- `pricing_calculate_sell_price(float $purchasePrice, array $context = [], ?float $currentSellingPrice = null): array`
  — berechnet bereits Aufschlag/Festpreis, Mindestmarge/-gewinn/-preis,
  Rundung und Preisendung, inkl. sauberem Fallback (`applied => false`,
  aktueller Preis bleibt erhalten), wenn keine Regel passt.

Der **einzige echte Befund**: Diese beiden Funktionen werden nirgendwo im
Code aufgerufen (verifiziert: kein Treffer in `import_engine.php`,
`products.php`, `product_offers.php`, `pricing_rules.php`-UI,
`purchase_orders.php`). Die eigentliche Lücke ist also nicht „Funktion
fehlt", sondern „Funktion ist nicht verdrahtet" — siehe Integrationspatch 1
unten.

## 2. `private/supplier_error_log.php` + Tabelle `supplier_sync_error_log` — VERWORFEN

`private/functions.php` enthält bereits `log_activity()`, die in die
bestehende Tabelle `activity_log` (Teil der Basis-`schema.sql`) schreibt.
`private/suppliers.php::supplier_sync_now()` ruft diese bei **jedem**
Sync-Fehler bereits auf: `log_activity('sync_error', 'suppliers', $supplierId, $result['message']);`
sowie bei Erfolg `log_activity('sync', ...)`. Eine zusätzliche, neue
Fehlerprotokoll-Tabelle mit eigener API wäre eine unbenutzte Parallelstruktur
gewesen. Der CLI-Worker (siehe unten) nutzt jetzt stattdessen ausschließlich
die bestehende `log_activity()`.

## 3. `private/cli/supplier_sync_worker.php` — BLEIBT, aber korrigiert

Das ist die einzige der drei Phase-2b-Dateien, für die tatsächlich keine
Entsprechung existiert (verifiziert: keine `private/cli/`-, `private/cron/`-
oder ähnliche Datei im Repository vorhanden, nur der bestehende
HTTP-Endpunkt `public/api/supplier_cron.php`). Beim Integrationsversuch
wurden dabei zwei echte Bugs im ursprünglichen Entwurf gefunden und
behoben (fehlendes `require_once config.php`/`functions.php` — siehe
Kommentar in der korrigierten Datei).

## Zusätzlicher Befund zu „Phase 3"

Bevor neuer Code für Phase 3 geschrieben wurde, wurden dieselben
Funktionen ebenso funktionsscharf geprüft. Ergebnis: **4 der 5 angeforderten
Punkte existieren bereits vollständig, inklusive UI:**

- **Lieferantenbestellung**: `purchase_order_create_draft()`,
  `purchase_order_validate_for_release()`, `purchase_order_release()`,
  `purchase_order_confirm()` — vollständiger Lebenszyklus vorhanden.
- **Wareneingang**: `purchase_order_item_receive()` — vorhanden.
- **Teillieferungen**: dieselbe Funktion berechnet die Differenzmenge und
  setzt automatisch `teilweise_geliefert` bzw. `geliefert` — vorhanden.
- **Automatische Lagerbuchungen**: dieselbe Funktion erhöht
  `parts.stock_quantity` um genau die neu eingegangene Differenzmenge
  (kein doppeltes Zählen bei wiederholten Teil-Wareneingängen) — vorhanden.
- Alle vier Punkte sind zusätzlich bereits über eine echte UI erreichbar:
  `public/purchase_order_form.php`, Aktion `action=receive`, mit
  Mengeneingabefeld pro Position — verifiziert per Suche nach dem
  tatsächlichen Aufruf im POST-Handler.

**Einziger echter, verifizierter Rest-Punkt: „Bestellvorschläge".** Es gibt
bereits `procurement_suggestion_for_repair(int $repairId): array`, aber die
ist auf eine einzelne Reparatur beschränkt (fehlende Teile *für diese
Reparatur*). Eine bestandsweite Funktion, die alle Artikel unter
Mindestbestand ermittelt, existiert nicht (explizit geprüft, kein Treffer).
Das ist die einzige tatsächlich neue Phase-3-Funktion, die gebaut wurde —
siehe Integrationspatch 2/3 unten.

Diese Datei ist bewusst **keine neue Analyse-/Zusammenfassungsdatei** im
Sinne deiner Vorgabe, sondern die notwendige Fehlerkorrektur, um „keine
Parallelstruktur" tatsächlich einzuhalten — ohne sie hätte ich unbemerkt
doppelten Code integriert.
