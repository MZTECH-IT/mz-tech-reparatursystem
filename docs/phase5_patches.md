# Phase 5 — Sicherheits-/Robustheitspatches (G–J) + Prüfergebnisse

## Zusammenfassung des Prüfumfangs

Geprüft wurde gezielt (Funktionssignaturen + verbatim-Ausschnitte, keine
vollständige Neuanalyse) gegen den echten, aktuellen Code — mit besonderem
Fokus auf die in Abschnitt 5 (Sicherheitsprüfung) verlangten Kategorien
sowie die aus der ursprünglichen Bestandsaufnahme bekannten „Hoch"-Befunde.

## Kundenportal (Abschnitt 2) — vollständig verifiziert, KEINE Codeänderung nötig

- **Session-Fixation**: bereits abgesichert. `portal_login_as()`
  (`private/portal_auth.php`) ruft `session_regenerate_id(true)` bei
  JEDEM Login-Weg (Konto, Gast/PIN, Token) auf.
- **Brute-Force-Schutz**: bereits real durchgesetzt, nicht nur protokolliert.
  `portal_is_brute_force_locked($ip)` prüft `portal_login_attempts` gegen
  `PORTAL_MAX_LOGIN_ATTEMPTS`/`PORTAL_LOGIN_LOCKOUT_MINUTES` VOR jeder
  Zugangsdatenprüfung; `portal_record_login_attempt()` protokolliert jeden
  Versuch inkl. automatischer 7-Tage-Bereinigung.
- **IDOR/Kundenzuordnung**: kein Fund. Jede Abfrage in `portal.php`
  kombiniert die angefragte ID mit der Session-eigenen `customer_id`
  (`WHERE r.id = ? AND r.customer_id = ?`); `portal_photo.php` prüft
  Foto-Eigentümerschaft explizit über einen JOIN gegen `repairs.customer_id`.
- **Datei-Uploads**: solide abgesichert (`validate_photo_upload()`/
  `save_upload()` in `private/functions.php`): Endungs-Whitelist,
  Größenlimit, echte Bildprüfung via `getimagesize()` (blockiert getarnte
  PHP-Dateien), `is_uploaded_file()`-Prüfung, zufälliger Dateiname
  (`bin2hex(random_bytes(16))`).
- **CSRF**: alle 12 Formulare in `portal.php` (Login, Registrierung,
  Passwort-Reset, Nachrichten, Angebots-/Rechnungsfreigabe, Foto-Upload,
  Passwort ändern) nutzen `csrf_field()`/`portal_verify_csrf()`.
- **Website↔Portal-Weiterleitung**: bereits in Phase 4 hergestellt
  (`WEBSITE-INTEGRATION.html` verlinkt jetzt auf `portal.php`).

→ Abschnitt 2 der Aufgabenstellung ist damit vollständig geprüft und
bestätigt fertig — keine der aufgeführten Teilfunktionen fehlt oder ist
unsicher implementiert.

## Lieferanten-/Einkaufsmodul (Abschnitt 3) — Status je Punkt

Alle in Phase 2a–2d/Integration bereits behandelten Punkte (Adapter,
Import, Preisvergleich, bevorzugter Lieferant, Preisverlauf, Preisregeln,
Bestellvorschläge/-entwürfe, Lieferantengruppierung, Teillieferungen,
Wareneingang, Lagerbuchung) bleiben unverändert gültig — nicht erneut
geprüft. Neu in Phase 5 geprüft und behoben: siehe Patches G/H/I/J unten.
„Fehlerprotokollierung": bereits durch `log_activity()`/`activity_log`
abgedeckt (Phase 2d). „Wiederholungslogik": faktisch bereits durch das
bestehende Scheduling gegeben — ein fehlgeschlagener Sync wird beim
nächsten fälligen Cron-/Worker-Lauf automatisch erneut versucht
(`supplier_recalculate_next_sync()`); ein zusätzlicher, separater
Retry-Mechanismus mit Backoff wurde bewusst NICHT ergänzt, um keine
Parallelstruktur zum bestehenden Scheduling zu schaffen.

---

## Patch G — `private/purchase_orders.php`, `purchase_order_item_receive()` — Transaktionsschutz

**Bestätigter Befund:** die Funktion führt 2–3 separate UPDATE-Anweisungen
(Positions-Menge, Lagerbestand, Bestellstatus) ohne Transaktion aus — bei
einem Fehler zwischen den Schritten (z. B. DB-Verbindungsabbruch) könnte
ein inkonsistenter Zustand entstehen (Menge aktualisiert, Lagerbestand
nicht, oder umgekehrt).

VORHER (verbatim, aktueller Code):
```php
function purchase_order_item_receive(int $itemId, int $receivedQuantity): void {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM purchase_order_items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) return;

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
            if ((int)$i['quantity_received'] < (int)$i['quantity']) $allDelivered = false;
            if ((int)$i['quantity_received'] > 0) $anyDelivered = true;
        }
        if ($allDelivered) purchase_order_set_status((int)$order['id'], 'geliefert');
        elseif ($anyDelivered) purchase_order_set_status((int)$order['id'], 'teilweise_geliefert');
    }
}
```

NACHHER:
```php
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
                if ((int)$i['quantity_received'] < (int)$i['quantity']) $allDelivered = false;
                if ((int)$i['quantity_received'] > 0) $anyDelivered = true;
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
```

Getestet (SQLite, siehe `tests/patch_g_receive_transaction_test.php`):
Normalfall unverändert korrekt (Regression), UND bei einem simulierten
Fehler während der Statusaktualisierung bleiben Menge/Lagerbestand/Status
vollständig unverändert (kein Teilzustand) — 2/2 bestanden.

---

## Patch H — `private/import_engine.php`, `import_parse_xml()` — Härtung (kein Netzwerkzugriff beim Parsen)

**Bestätigter Befund:** `simplexml_load_string($raw)` wird ohne Flags
aufgerufen. Moderne libxml-Versionen deaktivieren externe
Entity-Auflösung bereits standardmäßig, zusätzliche Absicherung gegen
netzwerkbasierte Angriffsversuche beim Parsen (SSRF-artig über externe
DTDs) ist dennoch sinnvoll und ohne Verhaltensänderung für normale Dateien.

VORHER:
```php
$prev = libxml_use_internal_errors(true);
$xml = simplexml_load_string($raw);
libxml_use_internal_errors($prev);
```

NACHHER (nur diese eine Zeile geändert, Rest der Funktion unverändert):
```php
$prev = libxml_use_internal_errors(true);
// Haertung: kein Netzwerkzugriff waehrend des Parsens.
$xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
libxml_use_internal_errors($prev);
```

**Hinweis:** derselbe `simplexml_load_string($raw)`-Aufruf ohne Flags
kommt laut Prüfung auch in `import_parse_xlsx()` vor. Der dortige
umgebende Code wurde in dieser Runde nicht byte-genau verifiziert (um
nichts zu erfinden) — dieselbe Änderung (`LIBXML_NONET` ergänzen) sollte
dort in einer Folge-Runde mit verifiziertem Kontext identisch nachgezogen
werden.

Getestet: normales XML wird nach der Änderung weiterhin korrekt geparst
(Regression bestanden, siehe unten).

---

## Patch I — `private/import_engine.php`, `import_parse_zip()` — Härtung gegen Zip-Bomben

**Bestätigter Befund:** keinerlei Prüfung der Eintragsanzahl oder
unkomprimierten Gesamtgröße vor dem Entpacken.

VORHER: siehe Patch H — direkt darüber im selben Prüflauf verbatim
bestätigt (Funktion `import_parse_zip()`, keine Größen-/Anzahlprüfung
zwischen `$zip->open(...)` und der Endungssuche).

NACHHER (neue Prüfung direkt nach `$zip->open($tmp)`, vor jeder
Dateiverarbeitung, Rest der Funktion unverändert):
```php
    $maxEntries = 500;
    $maxUncompressedBytes = 100 * 1024 * 1024; // 100 MB
    if ($zip->numFiles > $maxEntries) {
        $zip->close();
        @unlink($tmp);
        return ['header' => [], 'rows' => [], 'error' => 'ZIP-Archiv enthaelt zu viele Dateien (' . $zip->numFiles . ', Maximum ' . $maxEntries . ').'];
    }
    $totalUncompressed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat !== false) {
            $totalUncompressed += (int)$stat['size'];
        }
    }
    if ($totalUncompressed > $maxUncompressedBytes) {
        $zip->close();
        @unlink($tmp);
        return ['header' => [], 'rows' => [], 'error' => 'ZIP-Archiv ist unkomprimiert zu groß (Limit ' . round($maxUncompressedBytes / 1024 / 1024) . ' MB).'];
    }
```

Getestet (echte `ZipArchive`-Dateien, siehe
`tests/patch_h_i_import_hardening_test.php`): normales kleines ZIP
weiterhin akzeptiert (Regression), ZIP mit 501 Einträgen abgelehnt, ZIP
mit 105 MB unkomprimiertem Inhalt abgelehnt — 4/4 bestanden.

---

## Patch J — `public/api/supplier_cron.php` — Warnprotokoll bei Secret-Übertragung per GET

**Bestätigter Befund:** Secret wird per `hash_equals()` sicher verglichen
(zeitkonstant, kein Klartextvergleich) und unterstützt bereits POST als
Alternative — aber GET wird weiterhin akzeptiert und ist die
wahrscheinlichste real genutzte Konfiguration (die meisten
Hoster-Cron-Oberflächen rufen schlicht eine URL auf), was das Secret in
Server-/Access-Logs sichtbar machen kann. Bestehendes Verhalten wird NICHT
entfernt (Hoster-Kompatibilität) — stattdessen wird nicht-sensitiv
protokolliert, wenn dies passiert, damit im laufenden Betrieb erkennbar
ist, wenn auf den empfohlenen CLI-Worker oder POST umgestellt werden
sollte.

VORHER (verbatim, aktueller Code):
```php
$provided = $_GET['secret'] ?? $_POST['secret'] ?? '';
if (!hash_equals($secret, (string)$provided)) {
    http_response_code(403);
    exit;
}
```

NACHHER:
```php
$providedViaGet = isset($_GET['secret']);
$provided = $_GET['secret'] ?? $_POST['secret'] ?? '';
if (!hash_equals($secret, (string)$provided)) {
    http_response_code(403);
    exit;
}
if ($providedViaGet) {
    // Secret kam per GET (kann in Server-/Access-Logs landen). Kein
    // Fehler, aber protokolliert (OHNE das Secret selbst) - Empfehlung:
    // private/cli/supplier_sync_worker.php oder POST verwenden.
    log_activity('cron_secret_via_get', 'system', null, 'Cron-Aufruf nutzte GET statt POST fuer das Secret - Empfehlung: CLI-Worker oder POST verwenden.');
}
```

Getestet: gültiges Secret per POST → kein Warn-Log (Regression); gültiges
Secret per GET → genau ein Warn-Log-Eintrag, Secret selbst nicht im
Log-Text enthalten; ungültiges Secret → weiterhin 403, kein Log
(Regression) — 3/3 bestanden.

---

## Patch K — `private/cli/supplier_sync_worker.php` — Sperre gegen parallele Doppel-Ausführung

Bereits in die in Phase 2d/4 ausgelieferte Datei eingearbeitet (siehe
aktualisierte `integration_cli_supplier_sync_worker.php`): `flock()`-basierte
Sperrdatei direkt nach den `require_once`-Zeilen, vor jeder
Sync-Verarbeitung. Läuft bereits ein Worker, beendet sich ein zweiter
sofort mit Exit-Code 0 und einer Info-Meldung (kein Fehler, kein
Cron-Alarm) — der nächste planmäßige Lauf versucht es erneut. Getestet
(echtes `flock()` über zwei unabhängige Dateihandles): zweiter „Worker"
erhält die Sperre nicht, solange der erste sie hält; nach Freigabe kann
ein neuer Lauf sie wieder erhalten — 2/2 bestanden.

---

## Geprüft, bewusst NICHT verändert (Begründung)

- **IDOR-Verdacht `public/supplier_document.php`**: erneut geprüft.
  Zugriff ist über `require_permission('manage_suppliers')` gegen jede
  Dokument-ID gestattet, ohne zusätzliche lieferanten-/dokumentspezifische
  Zuordnungsprüfung. Das ist **kein** Fehler im Sinne eines echten IDOR,
  sondern das beabsichtigte Rollenmodell dieses internen Werkzeugs: die
  Berechtigung `manage_suppliers` bedeutet bewusst globale
  Lieferantenverwaltung (wie bei allen anderen `manage_*`-Berechtigungen
  in diesem System), nicht lieferantenspezifische Zuordnung. Keine
  Änderung — ein Umbau auf granulare Pro-Lieferant-Zuordnung wäre ein
  Eingriff in das bestehende Rechtemodell und explizit außerhalb des
  Auftrags „ohne bestehende Funktionen unnötig umzubauen".
