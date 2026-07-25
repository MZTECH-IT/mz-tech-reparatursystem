# Ausgeführte Tests — Phase 2d / Integration + Phase 3

Alle Tests lokal mit PHP 8.4.21 (CLI) ausgeführt. Kein bestehendes
PHPUnit/Testframework im Repository gefunden (nicht erneut gesucht, aus
vorheriger Prüfung übernommen) — daher eigenständige, isolierte
Skript-Tests je Patch, jeweils gegen den echten, verbatim verifizierten
Original-Code aufgebaut (Stubs nur für externe Abhängigkeiten wie `get_db()`,
nicht für die geprüfte Logik selbst).

## 1. Syntaxprüfung (`php -l`) — 7/7 bestanden

| Datei | Ergebnis |
|---|---|
| `private/cli/supplier_sync_worker.php` (korrigiert) | OK |
| Patch A: `SftpAdapter`/`SoapApiAdapter` (vollständige Klassen) | OK |
| Patch B: `purchase_order_calculate_shipping()` (vollständige Funktion) | OK |
| Patch C: `import_upsert_offer()`-Erweiterung | OK |
| Patch D: `procurement_suggestions_low_stock()` | OK |
| Patch E v1: POST-Handler + UI-Karte | OK |
| Patch E v2: POST-Handler + UI-Karte (Mengen-Override, „ohne Angebot") | OK |

## 2. Instanzierbarkeitstest Patch A — bestanden

`SftpAdapter`/`SoapApiAdapter` implementieren `SupplierAdapterInterface`
vollständig (`instanceof`-Prüfung: beide `true`).

## 3. Funktionstests Patch B (Versandregeln) — 5/5 bestanden

Gegen SQLite-Testdatenbank (`suppliers.address_country`,
`product_supplier_offers`):
1. `pro_land` greift bei Länder-Übereinstimmung (CN==CN → 19,90 €).
2. `pro_land` greift nicht bei Nicht-Übereinstimmung (DE≠CN → 0,00 €).
3. `pro_versandklasse` greift bei Schlagwort-Treffer in `notes`, case-insensitiv.
4. `pro_versandklasse` greift nicht ohne Schlagwort-Treffer.
5. Regressionstest: bestehende Regel `fest` weiterhin unverändert korrekt (4,99 €).

## 4. Funktionstest Patch D (`procurement_suggestions_low_stock()`) — bestanden

Gegen SQLite-Testdatenbank mit 5 Testartikeln: korrekt erkannt werden nur
Artikel mit `min_stock > 0`, nicht `is_discontinued` und
`(stock_quantity - reserved_stock) < min_stock`. Ausreichend bevorratete,
eingestellte und Artikel ohne Mindestbestand werden korrekt ausgeschlossen.

## 5. Funktionstests Patch E v2 (UI-Entscheidungslogik) — 6/6 bestanden

1. Gruppierung nach Lieferant korrekt (2 Lieferanten, 1 Artikel ohne Angebot).
2. Automatische Mengenvorschläge werden ohne Override korrekt übernommen.
3. Mengen-Override durch Nutzereingabe wird korrekt übernommen.
4. Artikel anderer Lieferanten werden beim Entwurf-Aufbau korrekt herausgefiltert.
5. Artikel ohne Lieferantenangebot werden nie automatisch in einen Entwurf übernommen.
6. Mengen-Override ≤ 0 wird auf Minimum 1 geklemmt (keine ungültige Bestellmenge).

## Gesamtergebnis

**18 von 18 Prüfungen/Tests bestanden, 0 Fehlschläge.** Keine Fehler
gefunden, die eine Korrektur erforderlich gemacht hätten (die beiden
echten Bugs im CLI-Worker — fehlende `require_once` für `config.php`/
`functions.php` — wurden bereits in der vorherigen Integrationsrunde
gefunden und behoben, hier erneut mitgeprüft: `php -l` weiterhin fehlerfrei).
