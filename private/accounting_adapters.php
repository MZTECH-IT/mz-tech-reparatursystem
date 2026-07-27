<?php
/**
 * MZ Tech – Buchhaltungsintegration: Adapter (Phase 7, Auftragsabschnitt 8)
 * ----------------------------------------------------------------------
 * WICHTIGER HINWEIS ZUR VERLÄSSLICHKEIT DIESER DATEI (bitte vor Live-Einsatz
 * lesen): die Basis-Fakten unten (Basis-URLs, Auth-Header, Endpunkt-Pfade)
 * stammen aus einer Recherche der jeweils offiziellen Entwickler-Doku
 * (developers.lexware.io bzw. hilfe.sevdesk.de/tech.sevdesk.com) zum
 * Zeitpunkt der Erstellung dieser Datei. Für lexoffice/Lexware Office
 * konnten Basis-URL, Auth-Header und die Kernendpunkte /v1/contacts,
 * /v1/invoices, /v1/credit-notes, /v1/files direkt aus der offiziellen
 * Doku bestätigt werden; das exakte Feldschema für /v1/vouchers (Eingangs-
 * belege) sowie ein Status-Polling-Endpunkt konnten NICHT bestätigt werden
 * (lexoffice nutzt für Status-Änderungen offenbar Webhooks statt Polling).
 * Für sevDesk konnte die Basis-URL/der Auth-Header-Name nur über aktiv
 * gepflegte Drittanbieter-Clients plausibilisiert werden, NICHT direkt aus
 * der offiziellen (clientseitig gerenderten) Doku – dies ist unten an jeder
 * betroffenen Stelle als "UNVERIFIZIERT" markiert.
 *
 * Da diesem System aktuell KEINE echten lexoffice-/sevdesk-Zugangsdaten
 * vorliegen, konnte KEINE der beiden Integrationen gegen einen echten
 * Account getestet werden. Vor dem ersten produktiven Einsatz MUSS daher:
 *   1. ein echter API-Schlüssel/Token in den Buchhaltungseinstellungen
 *      hinterlegt und "Verbindung testen" erfolgreich ausgeführt werden,
 *   2. mindestens EIN echter Testbeleg über die Weboberfläche des
 *      jeweiligen Anbieters mit dem Ergebnis dieser Adapter verglichen
 *      werden, BEVOR "Automatische Übertragung" aktiviert wird (siehe
 *      accounting_auto_sync_enabled – standardmäßig deaktiviert).
 *
 * KEINE erfundenen Endpunkte: jeder hier verwendete Pfad ist entweder
 * (a) mit Quellenangabe im jeweiligen Klassenkommentar als bestätigt
 * markiert, oder (b) explizit als unverifiziert gekennzeichnet.
 */

interface AccountingAdapterInterface {
    /** @return array{success:bool,message:string} */
    public function testConnection(array $credentials): array;

    /**
     * Legt einen Kontakt (Kunde/Firma/Lieferant) beim Anbieter an oder
     * aktualisiert ihn, sofern bereits eine externe ID bekannt ist.
     * @return array{success:bool,external_id:?string,message:string}
     */
    public function upsertContact(array $credentials, array $contactData, ?string $existingExternalId): array;

    /** @return array{success:bool,external_id:?string,message:string} */
    public function pushInvoice(array $credentials, array $invoiceData): array;

    /** @return array{success:bool,external_id:?string,message:string} */
    public function pushCreditNote(array $credentials, array $creditNoteData): array;

    /**
     * Übermittelt einen Eingangsbeleg (z. B. eine Lieferantenrechnung/
     * Bestellung) als "Voucher"/"Beleg".
     * @return array{success:bool,external_id:?string,message:string}
     */
    public function pushIncomingDocument(array $credentials, array $documentData): array;
}

/** Gemeinsamer, einfacher curl-JSON-Request-Helfer für die Buchhaltungsadapter. */
function accounting_adapter_http_request(string $url, array $headers, string $method = 'GET', ?array $jsonBody = null): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'http_code' => 0, 'body' => null, 'message' => 'PHP-Erweiterung curl nicht verfügbar.'];
    }
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'http_code' => 0, 'body' => null, 'message' => 'Verbindungsfehler: ' . $error];
    }
    $decoded = json_decode($response, true);
    if ($httpCode === 429) {
        return ['success' => false, 'http_code' => 429, 'body' => $decoded, 'message' => 'Rate-Limit überschritten (HTTP 429) – bitte später erneut versuchen.'];
    }
    if ($httpCode >= 400) {
        $msg = is_array($decoded) ? (($decoded['message'] ?? $decoded['error'] ?? json_encode($decoded))) : $response;
        return ['success' => false, 'http_code' => $httpCode, 'body' => $decoded, 'message' => "HTTP $httpCode: " . (is_string($msg) ? $msg : json_encode($msg))];
    }
    return ['success' => true, 'http_code' => $httpCode, 'body' => $decoded, 'message' => "OK (HTTP $httpCode)"];
}

/**
 * lexoffice / Lexware Office (seit 26.05.2025 umbenannt; die frühere
 * *.lexoffice.io-API-Domain gilt inzwischen als abgekündigt).
 * Quelle: https://developers.lexware.io/docs/ (Stand der Recherche für
 * diese Datei). Bestätigt: Basis-URL, Bearer-Token-Auth, /v1/contacts,
 * /v1/invoices, /v1/credit-notes, /v1/files, Rate-Limit 2 Anfragen/Sek.
 * NICHT bestätigt (siehe Dateikopf): exaktes /v1/vouchers-Schema.
 */
class LexofficeAdapter implements AccountingAdapterInterface {
    private const BASE_URL = 'https://api.lexware.io';

    private function headers(array $credentials): array {
        return [
            'Authorization: Bearer ' . ($credentials['api_key'] ?? ''),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    public function testConnection(array $credentials): array {
        if (empty($credentials['api_key'])) {
            return ['success' => false, 'message' => 'Kein API-Schlüssel hinterlegt.'];
        }
        // /v1/profile ist laut Doku ein einfacher, lesender Endpunkt zur
        // Verbindungs-/Berechtigungsprüfung.
        $result = accounting_adapter_http_request(self::BASE_URL . '/v1/profile', $this->headers($credentials));
        return ['success' => $result['success'], 'message' => $result['success'] ? 'Verbindung erfolgreich.' : $result['message']];
    }

    public function upsertContact(array $credentials, array $contactData, ?string $existingExternalId): array {
        $body = [
            'version' => 0,
            'roles' => $contactData['is_supplier'] ?? false ? ['vendor' => new stdClass()] : ['customer' => new stdClass()],
            'company' => !empty($contactData['company_name']) ? ['name' => $contactData['company_name']] : null,
            'person' => empty($contactData['company_name']) ? [
                'firstName' => $contactData['first_name'] ?? '',
                'lastName'  => $contactData['last_name'] ?? '',
            ] : null,
        ];
        $body = array_filter($body, fn($v) => $v !== null);

        $url = self::BASE_URL . '/v1/contacts' . ($existingExternalId ? '/' . $existingExternalId : '');
        $method = $existingExternalId ? 'PUT' : 'POST';
        $result = accounting_adapter_http_request($url, $this->headers($credentials), $method, $body);
        if (!$result['success']) {
            return ['success' => false, 'external_id' => null, 'message' => $result['message']];
        }
        $newId = $result['body']['id'] ?? $existingExternalId;
        return ['success' => true, 'external_id' => $newId, 'message' => 'Kontakt übertragen.'];
    }

    public function pushInvoice(array $credentials, array $invoiceData): array {
        return $this->pushDocument($credentials, '/v1/invoices', $invoiceData, 'Rechnung');
    }

    public function pushCreditNote(array $credentials, array $creditNoteData): array {
        return $this->pushDocument($credentials, '/v1/credit-notes', $creditNoteData, 'Gutschrift');
    }

    private function pushDocument(array $credentials, string $path, array $data, string $label): array {
        // Feldschema entspricht dem lexoffice-Grundmuster (voucherDate,
        // address, lineItems, totalPrice) gemäß offizieller Doku-Struktur;
        // im Einzelfall (z. B. abweichende USt.-Sätze/Rundungsregeln)
        // gegen die Live-Doku prüfen.
        $body = [
            'voucherDate'  => $data['date'] ?? date('c'),
            'address'      => $data['address'] ?? [],
            'lineItems'    => $data['line_items'] ?? [],
            'totalPrice'   => ['currency' => $data['currency'] ?? 'EUR'],
            'taxConditions'=> ['taxType' => $data['is_net'] ?? true ? 'net' : 'gross'],
        ];
        $result = accounting_adapter_http_request(self::BASE_URL . $path . '?finalize=true', $this->headers($credentials), 'POST', $body);
        if (!$result['success']) {
            return ['success' => false, 'external_id' => null, 'message' => "$label-Übertragung fehlgeschlagen: " . $result['message']];
        }
        return ['success' => true, 'external_id' => $result['body']['id'] ?? null, 'message' => "$label erfolgreich übertragen."];
    }

    public function pushIncomingDocument(array $credentials, array $documentData): array {
        return [
            'success' => false,
            'external_id' => null,
            'message' => 'Eingangsbelege werden nicht automatisch übertragen: Das erforderliche Lexware-Office-Schema ist noch nicht gegen die Anbieterdokumentation und einen Testmandanten verifiziert. Bitte den sicheren DATEV-/CSV-Export verwenden.',
        ];
    }
}

/**
 * sevDesk. UNVERIFIZIERT (siehe Dateikopf): Basis-URL und exakter
 * Auth-Header-Name konnten nicht direkt aus der offiziellen, clientseitig
 * gerenderten Doku (api.sevdesk.de) bestätigt werden, sondern stammen aus
 * mehreren unabhängigen, aktiv gepflegten Drittanbieter-Clients sowie
 * einem offiziellen sevDesk-Breaking-Change-Hinweis (Header-Auth seit
 * 29.04.2025 statt URL-Parameter). VOR Produktiveinsatz gegen die Live-
 * Doku unter https://api.sevdesk.de/ verifizieren.
 */
class SevdeskAdapter implements AccountingAdapterInterface {
    // UNVERIFIZIERT - siehe Klassenkommentar.
    private const BASE_URL = 'https://my.sevdesk.de/api/v1';

    private function headers(array $credentials): array {
        return [
            // UNVERIFIZIERT: Header-Name/-Format gemäß Drittanbieter-Clients
            // und dem sevDesk-Breaking-Change-Hinweis von 02/2025 (Wechsel
            // von URL-Parameter auf Header-Auth) - vor Live-Einsatz prüfen.
            'Authorization: ' . ($credentials['api_token'] ?? ''),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    public function testConnection(array $credentials): array {
        if (empty($credentials['api_token'])) {
            return ['success' => false, 'message' => 'Kein API-Token hinterlegt.'];
        }
        return ['success' => false, 'message' => 'sevDesk-Livezugriff ist deaktiviert, bis Basis-URL, Authentifizierung und Feldschema mit der aktuellen offiziellen API-Dokumentation verifiziert wurden.'];
    }

    public function upsertContact(array $credentials, array $contactData, ?string $existingExternalId): array {
        return $this->disabledResult();
    }

    public function pushInvoice(array $credentials, array $invoiceData): array {
        return $this->pushDocument($credentials, '/Invoice', $invoiceData, 'Rechnung');
    }

    public function pushCreditNote(array $credentials, array $creditNoteData): array {
        return $this->pushDocument($credentials, '/CreditNote', $creditNoteData, 'Gutschrift');
    }

    private function pushDocument(array $credentials, string $path, array $data, string $label): array {
        return $this->disabledResult();
    }

    public function pushIncomingDocument(array $credentials, array $documentData): array {
        return $this->disabledResult();
    }

    private function disabledResult(): array {
        return [
            'success' => false,
            'external_id' => null,
            'message' => 'sevDesk-Schreibzugriffe sind sicher deaktiviert, bis Endpunkte, Authentifizierung und Feldschema mit der aktuellen offiziellen Anbieter-API verifiziert wurden. Bitte den DATEV-/CSV-Export verwenden.',
        ];
    }
}

function accounting_adapter_live_write_status(string $provider): array {
    return match ($provider) {
        'lexoffice' => [
            'enabled' => true,
            'message' => 'Rechnungen, Gutschriften und Kontakte sind vorbereitet. Eingangsbelege bleiben bis zur Schema-Verifikation deaktiviert.',
        ],
        'sevdesk' => [
            'enabled' => false,
            'message' => 'Livezugriffe sind bis zur Verifikation der aktuellen offiziellen sevDesk-API deaktiviert. DATEV-/CSV-Export ist verfügbar.',
        ],
        default => ['enabled' => false, 'message' => 'Kein externer Anbieter ausgewählt.'],
    };
}

/**
 * DATEV-Export (EXTF-Format, "Buchungsstapel"/Kategorie 21) – KEINE Live-
 * API, sondern eine CSV-Datei zur manuellen Übergabe an den Steuerberater/
 * dessen DATEV-Software. Quelle für Grundstruktur (Kopfzeile, Trennzeichen,
 * Kernspalten): https://developer.datev.de/de/file-format/details/datev-format/
 * (öffentliche DATEV-Entwicklerportal-Seiten, Inhalt clientseitig gerendert
 * und daher nicht vollständig maschinell auslesbar - Kernstruktur über
 * mehrere unabhängige, mit dieser Seite konsistente Quellen abgeglichen).
 * UNVERIFIZIERT (vor Live-Einsatz gegen die offizielle Spezifikation
 * prüfen): exakte Zeichenkodierung (hier CP1252 verwendet, gängige
 * Konvention für DATEV-Exporte) und exaktes Datumsformat für "Belegdatum"
 * (hier TTMMJJJJ, 8-stellig, verwendet).
 */
class DatevExporter {
    /**
     * @param array $bookings Liste von ['amount'=>float,'is_debit'=>bool,'account'=>string,'contra_account'=>string,'date'=>'YYYY-MM-DD','document_field_1'=>string,'text'=>string]
     * @param array $meta ['advisor_number'=>string,'client_number'=>string,'fiscal_year_start'=>'YYYY-MM-DD','account_length'=>int,'period_from'=>'YYYY-MM-DD','period_to'=>'YYYY-MM-DD']
     */
    public function export(array $bookings, array $meta): string {
        $headerLine1 = implode(';', [
            '"EXTF"', '700', '21', '"Buchungsstapel"', '13',
            date('YmdHis'), '', 'RE', '', '',
            (string)($meta['advisor_number'] ?? ''),
            (string)($meta['client_number'] ?? ''),
            date('Ymd', strtotime($meta['fiscal_year_start'] ?? date('Y') . '-01-01')),
            (string)($meta['account_length'] ?? 4),
            date('Ymd', strtotime($meta['period_from'] ?? date('Y') . '-01-01')),
            date('Ymd', strtotime($meta['period_to'] ?? date('Y') . '-12-31')),
            '', '', '1', '0', '', '', '', '', '', '',
        ]);

        $columns = [
            'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz',
            'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum',
            'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext',
        ];
        $headerLine2 = implode(';', array_map(fn($c) => '"' . $c . '"', $columns));

        $rows = [$headerLine1, $headerLine2];
        foreach ($bookings as $b) {
            $amount = number_format(abs((float)($b['amount'] ?? 0)), 2, ',', '');
            $rows[] = implode(';', [
                $amount,
                $b['is_debit'] ?? true ? 'S' : 'H',
                'EUR', '', '', '',
                (string)($b['account'] ?? ''),
                (string)($b['contra_account'] ?? ''),
                '',
                date('dmY', strtotime($b['date'] ?? date('Y-m-d'))),
                '"' . str_replace('"', '""', (string)($b['document_field_1'] ?? '')) . '"',
                '',
                '',
                '"' . str_replace('"', '""', (string)($b['text'] ?? '')) . '"',
            ]);
        }
        $csv = implode("\r\n", $rows) . "\r\n";

        // CP1252 gemäß gängiger DATEV-Exportkonvention (UNVERIFIZIERT, siehe
        // Klassenkommentar) - Konvertierung defensiv mit Fallback, falls die
        // iconv-Erweiterung auf dem Zielserver einmal fehlen sollte.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $csv);
            if ($converted !== false) return $converted;
        }
        return $csv;
    }

    public function filename(array $meta): string {
        return 'EXTF_Buchungsstapel_' . date('Ymd_His') . '.csv';
    }
}

/** Adapter-Fabrik – analog zu supplier_adapter_factory() in private/supplier_adapters.php. */
function accounting_adapter_factory(string $provider): ?AccountingAdapterInterface {
    return match ($provider) {
        'lexoffice' => new LexofficeAdapter(),
        'sevdesk'   => new SevdeskAdapter(),
        default     => null,
    };
}
