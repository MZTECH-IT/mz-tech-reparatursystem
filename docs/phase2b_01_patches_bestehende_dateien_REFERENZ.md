# Phase 2b — Patches für 3 bestehende Dateien (SFTP, SOAP, Versandregeln)

**Wichtig — Methodik:** Da diese Sitzung keinen Schreibzugriff auf das echte Repository hat, sind
dies **keine bereits angewendeten Änderungen**, sondern präzise Patch-Vorschläge. Für jeden Patch
wurde der exakte, aktuell im Repository vorhandene Code (verbatim per WebFetch abgerufen und hier
als "VORHER" wiedergegeben) als Grundlage genommen — nicht aus dem Gedächtnis rekonstruiert. Wer
den Patch anwendet, sollte trotzdem vor dem Einfügen kurz gegenprüfen, dass der VORHER-Block exakt
mit dem aktuellen Stand der Datei übereinstimmt (falls sich zwischenzeitlich etwas geändert hat).

Alle drei Patches sind **rein additiv im Sinne der Funktionalität** (sie füllen bestehende, bereits
im Code als unvollständig markierte Stellen — keine funktionierende Logik wird entfernt oder
ersetzt) und ändern an keiner anderen Stelle der jeweiligen Datei etwas.

---

## Patch 1 von 3: `private/supplier_adapters.php` — `SftpAdapter` fertigstellen

### Betroffene Datei
`private/supplier_adapters.php`

### VORHER (exakt wie aktuell im Repository, verbatim abgerufen)

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

### NACHHER (vollständige Implementierung, additiv — ersetzt ausschließlich diese Klasse)

```php
class SftpAdapter implements SupplierAdapterInterface {
    /**
     * Nutzt die PHP-Erweiterung "ssh2", sofern vorhanden. Ist sie nicht
     * installiert (wie auf dem in INSTALLATION.md beschriebenen All-Inkl-
     * Produktivserver zum Zeitpunkt der urspruenglichen Analyse), wird
     * KEIN Fehler geworfen, sondern eine klare, umsetzbare Fehlermeldung
     * zurueckgegeben -- konsistent mit dem bisherigen Verhalten dieser
     * Klasse, das bewusst kein Fatal Error erzeugt.
     */
    public function testConnection(array $profile, array $credentials): array {
        if (!function_exists('ssh2_connect')) {
            return ['success' => false, 'message' => 'SFTP ist auf diesem Server nicht verfügbar (PHP-Erweiterung "ssh2" fehlt). Bitte Hoster kontaktieren oder FTPS/HTTPS-Download als Alternative verwenden.'];
        }
        $host = trim((string)($profile['ftp_host'] ?? ''));
        $port = (int)($profile['ftp_port'] ?? 22) ?: 22;
        if ($host === '') {
            return ['success' => false, 'message' => 'Kein SFTP-Host hinterlegt.'];
        }
        $connection = @ssh2_connect($host, $port);
        if ($connection === false) {
            return ['success' => false, 'message' => 'SFTP-Verbindung zu ' . $host . ':' . $port . ' fehlgeschlagen.'];
        }
        $user = $credentials['username'] ?? '';
        $pass = $credentials['password'] ?? '';
        if ($user === '') {
            return ['success' => false, 'message' => 'Kein SFTP-Benutzername hinterlegt.'];
        }
        $authOk = @ssh2_auth_password($connection, $user, $pass);
        if (!$authOk) {
            return ['success' => false, 'message' => 'SFTP-Anmeldung für Benutzer "' . $user . '" fehlgeschlagen.'];
        }
        $sftp = @ssh2_sftp($connection);
        if ($sftp === false) {
            return ['success' => false, 'message' => 'SFTP-Subsystem konnte nicht initialisiert werden.'];
        }
        return ['success' => true, 'message' => 'SFTP-Verbindung erfolgreich hergestellt.'];
    }

    public function fetchPayload(array $profile, array $credentials): array {
        if (!function_exists('ssh2_connect')) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SFTP ist auf diesem Server aktuell nicht verfügbar (siehe testConnection()).'];
        }
        $host = trim((string)($profile['ftp_host'] ?? ''));
        $port = (int)($profile['ftp_port'] ?? 22) ?: 22;
        $path = trim((string)($profile['ftp_path'] ?? ''));
        if ($host === '' || $path === '') {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SFTP-Host oder Dateipfad fehlt im Schnittstellenprofil.'];
        }
        $connection = @ssh2_connect($host, $port);
        if ($connection === false) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SFTP-Verbindung zu ' . $host . ':' . $port . ' fehlgeschlagen.'];
        }
        $user = $credentials['username'] ?? '';
        $pass = $credentials['password'] ?? '';
        if (!@ssh2_auth_password($connection, $user, $pass)) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SFTP-Anmeldung fehlgeschlagen.'];
        }
        $sftp = @ssh2_sftp($connection);
        if ($sftp === false) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SFTP-Subsystem konnte nicht initialisiert werden.'];
        }
        // ssh2.sftp:// Stream-Wrapper nutzen, analog zum bestehenden
        // FtpAdapter-Muster (temporaeres Lesen ueber PHP-Streams statt
        // manueller Socket-Verwaltung).
        $stream = @fopen('ssh2.sftp://' . intval($sftp) . $path, 'r');
        if ($stream === false) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Datei "' . $path . '" konnte per SFTP nicht geöffnet werden.'];
        }
        $raw = stream_get_contents($stream);
        fclose($stream);
        if ($raw === false) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Datei "' . $path . '" konnte per SFTP nicht gelesen werden.'];
        }
        return ['success' => true, 'raw' => $raw, 'filename' => basename($path), 'message' => 'SFTP-Download erfolgreich.'];
    }
}
```

**Hinweis:** Bleibt weiterhin abhängig davon, dass die `ssh2`-PHP-Erweiterung auf dem Zielserver
installiert ist (laut ursprünglicher Analyse beim aktuellen Hoster nicht der Fall). Kein Fatal
Error, falls die Erweiterung fehlt — degradiert stattdessen sauber zur bisherigen, verständlichen
Fehlermeldung. Falls `ssh2` dauerhaft nicht verfügbar gemacht werden kann, wäre der nächste
sinnvolle Schritt eine Umstellung auf die reine PHP-Bibliothek `phpseclib/phpseclib` als
Composer-Abhängigkeit — das ist bewusst NICHT Teil dieses Patches, da es eine neue
Composer-Abhängigkeit einführen würde und laut ursprünglicher Bestandsaufnahme eine noch offene
Entscheidung ist (siehe Risiken unten).

---

## Patch 2 von 3: `private/supplier_adapters.php` — `SoapApiAdapter::fetchPayload()` fertigstellen

### VORHER (exakt wie aktuell im Repository, verbatim abgerufen)

```php
class SoapApiAdapter implements SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array {
        if (!extension_loaded('soap')) {
            return ['success' => false, 'message' => 'Die PHP-Erweiterung "soap" ist auf diesem Server nicht aktiviert. Bitte beim Hoster aktivieren lassen, dann ist dieser Adapter sofort einsatzbereit.'];
        }
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'message' => 'Keine WSDL-/Endpunkt-URL hinterlegt.'];
        }
        try {
            $client = new SoapClient($profile['endpoint_url'], ['connection_timeout' => 10, 'exceptions' => true]);
            return ['success' => true, 'message' => 'WSDL erfolgreich geladen.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'SOAP-Verbindung fehlgeschlagen: ' . $e->getMessage()];
        }
    }
    public function fetchPayload(array $profile, array $credentials): array {
        if (!extension_loaded('soap')) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'PHP-Erweiterung "soap" nicht aktiv.'];
        }
        return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SOAP-Methodenaufruf ist lieferantenspezifisch zu konfigurieren (siehe SCHNITTSTELLEN_ADAPTER_DOKUMENTATION.txt).'];
    }
}
```

### NACHHER — nur `fetchPayload()` wird ersetzt, `testConnection()` bleibt unverändert

`testConnection()` ist bereits vollständig (siehe VORHER) und wird **nicht** verändert. Nur die
`fetchPayload()`-Methode wird ersetzt:

```php
    public function fetchPayload(array $profile, array $credentials): array {
        if (!extension_loaded('soap')) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'PHP-Erweiterung "soap" nicht aktiv.'];
        }
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Keine WSDL-/Endpunkt-URL hinterlegt.'];
        }
        // Lieferantenspezifische Methode/Parameter kommen aus den
        // verschluesselten Zugangsdaten (Felder soap_method/soap_params,
        // siehe supplier_interface_credentials_decrypt() -- bereits so
        // in suppliers_form.php vorgesehen).
        $method = trim((string)($credentials['soap_method'] ?? ''));
        if ($method === '') {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Kein SOAP-Methodenname in den Zugangsdaten hinterlegt (Feld "soap_method").'];
        }
        $paramsRaw = $credentials['soap_params'] ?? '[]';
        $params = is_array($paramsRaw) ? $paramsRaw : json_decode((string)$paramsRaw, true);
        if (!is_array($params)) {
            $params = [];
        }
        try {
            $client = new SoapClient($profile['endpoint_url'], ['connection_timeout' => 10, 'exceptions' => true]);
            $result = $client->__soapCall($method, [$params]);
            // SOAP-Antworten sind meist stdClass/Array-Strukturen, keine
            // Rohdatei -- fuer die einheitliche Adapter-Schnittstelle
            // (die ein 'raw'-Byte-Payload erwartet, das anschliessend an
            // import_engine.php uebergeben wird) als JSON serialisiert,
            // damit import_parse_json() es direkt verarbeiten kann.
            $raw = json_encode($result, JSON_UNESCAPED_UNICODE);
            if ($raw === false) {
                return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SOAP-Antwort konnte nicht in JSON umgewandelt werden.'];
            }
            return ['success' => true, 'raw' => $raw, 'filename' => 'soap_response.json', 'message' => 'SOAP-Aufruf "' . $method . '" erfolgreich.'];
        } catch (Throwable $e) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SOAP-Aufruf fehlgeschlagen: ' . $e->getMessage()];
        }
    }
```

**Hinweis:** Setzt voraus, dass beim Anlegen eines SOAP-Schnittstellenprofils in
`suppliers_form.php` die Felder `soap_method`/`soap_params` tatsächlich als Formularfelder
angeboten werden — laut Bestandsaufnahme sind `soap_method`/`soap_params` bereits als
Credential-Feldnamen im Code vorgesehen (`SoapApiAdapter`-Konfiguration), die konkrete
Formularoberfläche dafür wurde nicht Byte-genau geprüft und sollte vor dem Produktiveinsatz
einmal kurz verifiziert werden.

---

## Patch 3 von 3: `private/purchase_orders.php` — Versandregeln nach Land/Versandklasse

### VORHER (exakt wie aktuell im Repository, verbatim abgerufen)

```php
function purchase_order_calculate_shipping(int $supplierId, array $items): array {
    // Exakte API-Versandkosten haben Vorrang, falls für JEDE Position
    // vorhanden und nicht als Schätzung markiert.
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
                // Zuschläge werden additiv oben auf den Grundbetrag gelegt,
                // sofern der jeweilige Positions-Hinweis (notes) das
                // entsprechende Schlagwort enthält (einfache, transparente
                // Heuristik, die je Installation angepasst werden kann).
                $amount += (float)$rule['amount'];
                break;
            case 'pro_versandklasse':
            case 'pro_land':
                // Zuordnung erfolgt über condition_text (z. B. Ländercode) –
                // Basisbetrag hier nur als Fallback, konkrete Zuordnung
                // erfolgt in der aufrufenden Stelle bei Bedarf.
                $amount = (float)$rule['amount'];
                break;
        }
    }
    return [round($amount, 2), true];
}
```

### NACHHER — nur der `switch`-Block wird erweitert, der Rest der Funktion bleibt identisch

Begründung der Entscheidung, WOGEGEN `condition_text` geprüft wird (bewusst **keine** neue
Datenbankspalte erfunden, siehe Vorgabe „keine Felder erfinden"):

- **`pro_land`**: Dieses System ist für einen einzelnen Betrieb (MZ Tech) ausgelegt, der Waren
  von Lieferanten bestellt — es gibt keine „Ziel-Länder" pro Bestellung, wohl aber
  unterschiedliche Herkunftsländer je Lieferant. `condition_text` wird daher gegen das bereits
  bestehende Feld `suppliers.address_country` (aus Phase 2a) verglichen.
- **`pro_versandklasse`**: Es existiert aktuell kein Datenbankfeld für eine Versandklasse pro
  Artikel/Position. Um keine neue Spalte einzuführen, wird — konsistent mit der bereits
  bestehenden Zuschlags-Heuristik direkt darüber im selben Switch-Block („Schlagwort in `notes`")
  — `condition_text` als Schlagwort gegen `purchase_order_items.notes` der jeweiligen Position
  geprüft.

```php
            case 'pro_versandklasse':
                // Konsistent mit der Zuschlags-Heuristik oben: condition_text
                // als Schlagwort gegen die Positions-Notiz (notes) geprüft,
                // da es aktuell kein eigenes Versandklassen-Feld je Artikel
                // gibt (bewusst keine neue Spalte eingeführt).
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
                // condition_text wird gegen das Herkunftsland des Lieferanten
                // (suppliers.address_country, bereits vorhanden seit Phase 2a)
                // verglichen -- nicht gegen ein Zielland, da dieses System
                // Bestellungen fuer einen einzelnen Betrieb abbildet.
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
```

**Integration:** Dieser Block ersetzt exakt die beiden bisherigen `case 'pro_versandklasse':` /
`case 'pro_land':`-Zeilen (die bisher beide identisch auf den reinen Fallback-Betrag liefen) — alle
anderen `case`-Blöcke (`fest`, `kostenlos_ab`, `express_zuschlag`/`sperrgut_zuschlag`/`gefahrgut_zuschlag`)
sowie der komplette Rest der Funktion bleiben **byte-identisch** zum bisherigen Code.
