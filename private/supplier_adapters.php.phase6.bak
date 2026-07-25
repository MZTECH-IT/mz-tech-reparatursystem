<?php
/**
 * MZ Tech – Lieferanten-Schnittstellen-Adapter (Phase 6)
 * ----------------------------------------------------------------------
 * Zentrale, austauschbare Adapter-Architektur für den Datenaustausch mit
 * Lieferanten. Jeder Adapter implementiert dasselbe, kleine Interface
 * (SupplierAdapterInterface) und ist ausschließlich für die TRANSPORT-
 * Ebene zuständig (wie komme ich an die Rohdaten heran?). Das eigentliche
 * PARSEN der Rohdaten (CSV/TSV/TXT/XLSX/XML/JSON) übernimmt einheitlich
 * private/import_engine.php – so entsteht keine doppelte Parser-Logik,
 * egal ob die Daten manuell hochgeladen oder automatisch von einem
 * Lieferanten abgerufen wurden.
 *
 * Neuen Lieferanten hinzufügen: NIEMALS nötig, hier Code zu ändern – ein
 * neuer Lieferant wählt in der Oberfläche (public/suppliers_form.php)
 * einfach einen der bereits hier implementierten Schnittstellentypen aus
 * und hinterlegt seine eigenen Zugangsdaten/URLs. Nur ein völlig NEUER
 * Schnittstellentyp (der hier noch nicht existiert) erfordert eine neue
 * Adapter-Klasse – die restliche Anwendung (Sync, Import-Assistent,
 * Rechteprüfung) bleibt davon unberührt, solange die neue Klasse
 * SupplierAdapterInterface implementiert und in supplier_adapter_factory()
 * registriert wird.
 *
 * Sicherheit: Zugangsdaten liegen ausschließlich verschlüsselt in
 * supplier_interface_profiles.credentials_encrypted/credentials_iv
 * (AES-256-CBC über encrypt_passcode()/decrypt_passcode(), siehe
 * private/functions.php – identisches Verfahren wie beim SMTP-Passwort).
 * Adapter erhalten die entschlüsselten Zugangsdaten nur transient im
 * Arbeitsspeicher für die Dauer eines einzelnen Aufrufs; sie werden
 * niemals geloggt oder in Fehlermeldungen ausgegeben.
 */

interface SupplierAdapterInterface {
    /**
     * Prüft, ob eine Verbindung mit den hinterlegten Zugangsdaten
     * grundsätzlich möglich ist (Button "Verbindung testen").
     * @return array{success:bool,message:string}
     */
    public function testConnection(array $profile, array $credentials): array;

    /**
     * Holt die Rohdaten (Bytes als String) von der Quelle. Das Ergebnis
     * wird anschließend von import_format_detect()/import_parse_rows()
     * (private/import_engine.php) geparst – dieser Adapter selbst
     * interpretiert das Dateiformat NICHT.
     * @return array{success:bool,raw:?string,filename:?string,message:string}
     */
    public function fetchPayload(array $profile, array $credentials): array;
}

/** Entschlüsselt die Zugangsdaten eines Schnittstellenprofils (JSON-Blob). */
function supplier_interface_credentials_decrypt(array $profile): array {
    if (empty($profile['credentials_encrypted']) || empty($profile['credentials_iv'])) {
        return [];
    }
    $json = decrypt_passcode($profile['credentials_encrypted'], $profile['credentials_iv']);
    if ($json === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/** Verschlüsselt Zugangsdaten (assoziatives Array) für die Speicherung. */
function supplier_interface_credentials_encrypt(array $credentials): array {
    // Leere Werte (Formularfeld gelassen, "Zugangsdaten unverändert lassen")
    // werden herausgefiltert, damit ein leeres Update-Formular nicht
    // versehentlich bereits gespeicherte Zugangsdaten überschreibt.
    $clean = array_filter($credentials, fn($v) => $v !== null && $v !== '');
    if (empty($clean)) return ['encrypted' => null, 'iv' => null];
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $enc  = encrypt_passcode($json);
    return ['encrypted' => $enc['encrypted'], 'iv' => $enc['iv']];
}

/** Baut die für curl nötigen Auth-Header/Optionen aus auth_type + Zugangsdaten. */
function supplier_adapter_curl_auth_options(string $authType, array $credentials): array {
    $headers = [];
    $curlOpts = [];
    switch ($authType) {
        case 'basic':
            $curlOpts[CURLOPT_USERPWD] = ($credentials['username'] ?? '') . ':' . ($credentials['password'] ?? '');
            break;
        case 'bearer_token':
            $headers[] = 'Authorization: Bearer ' . ($credentials['token'] ?? '');
            break;
        case 'api_key':
            $headerName = $credentials['api_key_header'] ?: 'X-API-Key';
            $headers[] = $headerName . ': ' . ($credentials['api_key'] ?? '');
            break;
        case 'oauth2':
            // Vorbereitet: falls ein bereits gültiges Access-Token hinterlegt
            // ist, wird es wie ein Bearer-Token verwendet. Ein vollständiger
            // OAuth2-Token-Refresh-Flow ist bewusst lieferantenspezifisch und
            // wird beim Anlegen eines konkreten Lieferanten ergänzt (siehe
            // SCHNITTSTELLEN_ADAPTER_DOKUMENTATION.txt).
            if (!empty($credentials['access_token'])) {
                $headers[] = 'Authorization: Bearer ' . $credentials['access_token'];
            }
            break;
        case 'custom':
        case 'none':
        default:
            break;
    }
    return ['headers' => $headers, 'curl_opts' => $curlOpts];
}

/** Führt einen curl-HTTP-Request aus (gemeinsam genutzt von mehreren Adaptern). */
function supplier_adapter_http_request(string $url, array $profile, array $credentials, string $method = 'GET', ?string $body = null): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'raw' => null, 'message' => 'Die PHP-Erweiterung curl ist auf diesem Server nicht verfügbar.'];
    }
    $auth = supplier_adapter_curl_auth_options($profile['auth_type'] ?? 'none', $credentials);
    $headers = $auth['headers'];

    $customHeaders = [];
    if (!empty($profile['request_headers_json'])) {
        $decoded = json_decode($profile['request_headers_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) { $customHeaders[] = "$k: $v"; }
        }
    }
    $headers = array_merge($headers, $customHeaders);
    if ($body !== null && !in_array('Content-Type: application/json', $headers, true)) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ] + $auth['curl_opts']);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'raw' => null, 'message' => 'Verbindungsfehler: ' . $error];
    }
    if ($httpCode >= 400) {
        return ['success' => false, 'raw' => $response, 'message' => "Server antwortete mit HTTP-Status $httpCode."];
    }
    return ['success' => true, 'raw' => $response, 'message' => "OK (HTTP $httpCode)"];
}

// ── REST-API-Adapter ─────────────────────────────────────────────────────
class RestApiAdapter implements SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array {
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'message' => 'Keine Endpunkt-URL hinterlegt.'];
        }
        $result = supplier_adapter_http_request($profile['endpoint_url'], $profile, $credentials, 'GET');
        return ['success' => $result['success'], 'message' => $result['message']];
    }
    public function fetchPayload(array $profile, array $credentials): array {
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Keine Endpunkt-URL hinterlegt.'];
        }
        $result = supplier_adapter_http_request($profile['endpoint_url'], $profile, $credentials, 'GET');
        return ['success' => $result['success'], 'raw' => $result['raw'], 'filename' => 'rest_api_response', 'message' => $result['message']];
    }
}

// ── GraphQL-API-Adapter ──────────────────────────────────────────────────
class GraphQLApiAdapter implements SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array {
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'message' => 'Keine GraphQL-Endpunkt-URL hinterlegt.'];
        }
        $query = json_encode(['query' => '{ __typename }']);
        $result = supplier_adapter_http_request($profile['endpoint_url'], $profile, $credentials, 'POST', $query);
        return ['success' => $result['success'], 'message' => $result['message']];
    }
    public function fetchPayload(array $profile, array $credentials): array {
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Keine GraphQL-Endpunkt-URL hinterlegt.'];
        }
        // Die konkrete Query ist lieferantenspezifisch und wird im
        // Zugangsdaten-Feld "graphql_query" hinterlegt (frei editierbar
        // beim Anlegen des Schnittstellenprofils).
        $query = $credentials['graphql_query'] ?? '{ products { sku name price } }';
        $body  = json_encode(['query' => $query]);
        $result = supplier_adapter_http_request($profile['endpoint_url'], $profile, $credentials, 'POST', $body);
        return ['success' => $result['success'], 'raw' => $result['raw'], 'filename' => 'graphql_response.json', 'message' => $result['message']];
    }
}

// ── HTTP-Download / URL-Abruf-Adapter (auch für ZIP-Downloads) ──────────
class HttpDownloadAdapter implements SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array {
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'message' => 'Keine Download-URL hinterlegt.'];
        }
        $result = supplier_adapter_http_request($profile['endpoint_url'], $profile, $credentials, 'GET');
        return ['success' => $result['success'], 'message' => $result['message']];
    }
    public function fetchPayload(array $profile, array $credentials): array {
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Keine Download-URL hinterlegt.'];
        }
        $result = supplier_adapter_http_request($profile['endpoint_url'], $profile, $credentials, 'GET');
        $filename = basename(parse_url($profile['endpoint_url'], PHP_URL_PATH) ?: 'download');
        return ['success' => $result['success'], 'raw' => $result['raw'], 'filename' => $filename, 'message' => $result['message']];
    }
}

// ── FTP- und FTPS-Adapter (native PHP-ftp-Erweiterung) ──────────────────
class FtpAdapter implements SupplierAdapterInterface {
    private bool $useTls;
    public function __construct(bool $useTls = false) { $this->useTls = $useTls; }

    private function connect(array $profile, array $credentials) {
        if (!function_exists('ftp_connect')) return null;
        $host = $profile['ftp_host'] ?? '';
        $port = (int)($profile['ftp_port'] ?? 21) ?: 21;
        if ($host === '') return null;
        $conn = $this->useTls
            ? (function_exists('ftp_ssl_connect') ? @ftp_ssl_connect($host, $port, 10) : false)
            : @ftp_connect($host, $port, 10);
        if (!$conn) return null;
        $user = $credentials['username'] ?? 'anonymous';
        $pass = $credentials['password'] ?? '';
        if (!@ftp_login($conn, $user, $pass)) { @ftp_close($conn); return null; }
        @ftp_pasv($conn, !empty($profile['ftp_passive']));
        return $conn;
    }

    public function testConnection(array $profile, array $credentials): array {
        $conn = $this->connect($profile, $credentials);
        if (!$conn) {
            return ['success' => false, 'message' => 'FTP' . ($this->useTls ? 'S' : '') . '-Verbindung/Login fehlgeschlagen.'];
        }
        @ftp_close($conn);
        return ['success' => true, 'message' => 'FTP' . ($this->useTls ? 'S' : '') . '-Verbindung erfolgreich.'];
    }

    public function fetchPayload(array $profile, array $credentials): array {
        $conn = $this->connect($profile, $credentials);
        if (!$conn) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'FTP-Verbindung/Login fehlgeschlagen.'];
        }
        $path = $profile['ftp_path'] ?? '';
        if ($path === '') {
            @ftp_close($conn);
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Kein Dateipfad im Schnittstellenprofil hinterlegt.'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ftpdl');
        $ok = @ftp_get($conn, $tmp, $path, FTP_BINARY);
        @ftp_close($conn);
        if (!$ok) {
            @unlink($tmp);
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => "Datei konnte nicht abgerufen werden: $path"];
        }
        $raw = file_get_contents($tmp);
        @unlink($tmp);
        return ['success' => true, 'raw' => $raw, 'filename' => basename($path), 'message' => 'Datei erfolgreich abgerufen.'];
    }
}

// ── SFTP-Adapter (vorbereitet, benötigt ssh2-Erweiterung oder phpseclib) ─
// Auf dem aktuellen Produktivserver ist die PHP-Erweiterung "ssh2" (bzw.
// eine reine PHP-Bibliothek wie phpseclib) nicht vorinstalliert. Die
// Adapter-Schnittstelle ist vollständig vorbereitet: sobald eine der
// beiden Optionen verfügbar ist, muss lediglich der Rumpf dieser Klasse
// ergänzt werden – keine andere Stelle im System muss geändert werden.
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

// ── SOAP-API-Adapter (vorbereitet, benötigt ext-soap) ───────────────────
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
        // Die konkrete SOAP-Methode/Parameter sind lieferantenspezifisch
        // (siehe credentials['soap_method']/['soap_params']) und werden
        // beim Anlegen eines konkreten Lieferanten ergänzt.
        return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SOAP-Methodenaufruf ist lieferantenspezifisch zu konfigurieren (siehe SCHNITTSTELLEN_ADAPTER_DOKUMENTATION.txt).'];
    }
}

// ── EDI-Adapter (Basis-Unterstützung für getrennte Flachdatei-Segmente) ──
// EDI ist kein einheitliches Format, sondern eine Familie von Standards
// (EDIFACT, VDA, etc.). Diese Basis-Implementierung unterstützt
// segment-getrennte Flachdateien (Standard-Trennzeichen "+"/"'"), wie sie
// bei vielen deutschen Großhändlern vorkommen, und liefert die Rohdaten
// zur Weiterverarbeitung durch den generischen Text-Parser. Für
// firmenspezifische EDI-Subsets kann bei Bedarf ein eigener, abgeleiteter
// Adapter ergänzt werden, ohne den Rest des Systems zu berühren.
class EdiAdapter implements SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array {
        if (!empty($profile['ftp_host'])) {
            return (new FtpAdapter())->testConnection($profile, $credentials);
        }
        if (!empty($profile['endpoint_url'])) {
            return (new HttpDownloadAdapter())->testConnection($profile, $credentials);
        }
        return ['success' => false, 'message' => 'Für EDI ist weder ein FTP-Host noch eine Abruf-URL hinterlegt.'];
    }
    public function fetchPayload(array $profile, array $credentials): array {
        if (!empty($profile['ftp_host'])) {
            return (new FtpAdapter())->fetchPayload($profile, $credentials);
        }
        if (!empty($profile['endpoint_url'])) {
            return (new HttpDownloadAdapter())->fetchPayload($profile, $credentials);
        }
        return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Für EDI ist weder ein FTP-Host noch eine Abruf-URL hinterlegt.'];
    }
}

// ── UGL/UGS-Adapter (Basis, vorbereitet) ────────────────────────────────
// UGL/UGS ist ein in der Unterhaltungs-/PC-Handelsbranche verbreitetes,
// firmenspezifisches Austauschformat ähnlich EDI. Da keine einheitliche
// öffentliche Spezifikation existiert, wird dieser Adapter als
// Basis-Variante (identisch zum EDI-Adapter: Abruf per FTP oder HTTP,
// Parsing als getrennte Flachdatei) bereitgestellt und kann beim Anlegen
// eines konkreten UGL/UGS-Lieferanten gezielt angepasst werden.
class UglUgsAdapter extends EdiAdapter {}

// ── Manueller Datei-Upload ───────────────────────────────────────────────
// Kein "Abruf" im eigentlichen Sinne – die Datei liegt bereits lokal vor
// (siehe public/supplier_import.php, Schritt 1 "Quelle wählen"). Der
// Adapter existiert trotzdem, damit der Import-Assistent unabhängig vom
// gewählten Schnittstellentyp immer denselben Aufrufweg
// (supplier_adapter_factory()->fetchPayload()) verwenden kann.
class ManualUploadAdapter implements SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array {
        return ['success' => true, 'message' => 'Manueller Upload benötigt keinen Verbindungstest.'];
    }
    public function fetchPayload(array $profile, array $credentials): array {
        $path = $credentials['local_file_path'] ?? '';
        if ($path === '' || !is_file($path)) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Keine hochgeladene Datei gefunden.'];
        }
        return ['success' => true, 'raw' => file_get_contents($path), 'filename' => basename($path), 'message' => 'Datei geladen.'];
    }
}

/**
 * Adapter-Fabrik: liefert die passende Adapter-Instanz für einen
 * Schnittstellentyp. Dies ist die EINZIGE Stelle im System, die weiß,
 * welche Klasse zu welchem interface_type gehört – ein neuer Adapter wird
 * ausschließlich hier registriert.
 */
function supplier_adapter_factory(string $interfaceType): SupplierAdapterInterface {
    return match ($interfaceType) {
        'rest_api'                                    => new RestApiAdapter(),
        'graphql_api'                                 => new GraphQLApiAdapter(),
        'http_download', 'url_fetch'                   => new HttpDownloadAdapter(),
        'ftp'                                          => new FtpAdapter(false),
        'ftps'                                         => new FtpAdapter(true),
        'sftp'                                         => new SftpAdapter(),
        'soap_api'                                     => new SoapApiAdapter(),
        'edi'                                          => new EdiAdapter(),
        'ugl_ugs'                                      => new UglUgsAdapter(),
        'manual_upload', 'csv', 'tsv', 'txt', 'xls',
        'xlsx', 'xml', 'json', 'zip'                    => new ManualUploadAdapter(),
        default                                        => new ManualUploadAdapter(),
    };
}

/** Liste aller unterstützten Schnittstellentypen mit sprechendem Label (für Dropdowns). */
function supplier_interface_type_labels(): array {
    return [
        'rest_api'      => 'REST-API',
        'soap_api'      => 'SOAP-API',
        'graphql_api'   => 'GraphQL-API',
        'edi'           => 'EDI',
        'ugl_ugs'       => 'UGL/UGS',
        'csv'           => 'CSV-Datei',
        'tsv'           => 'TSV-Datei',
        'txt'           => 'TXT-Datei',
        'xls'           => 'XLS-Datei (Legacy Excel)',
        'xlsx'          => 'XLSX-Datei (Excel)',
        'xml'           => 'XML-Datei',
        'json'          => 'JSON-Datei',
        'zip'           => 'ZIP-Archiv',
        'ftp'           => 'FTP',
        'ftps'          => 'FTPS',
        'sftp'          => 'SFTP',
        'http_download' => 'HTTP(S)-Download',
        'manual_upload' => 'Manueller Upload',
        'url_fetch'     => 'URL-Abruf',
    ];
}
