<?php
// Isolierter Syntax-/Strukturtest für Patch A (SftpAdapter, SoapApiAdapter::fetchPayload).
// Stub-Interface nur für den Test, nicht Teil der Integration.

interface SupplierAdapterInterface {
    public function testConnection(array $profile, array $credentials): array;
    public function fetchPayload(array $profile, array $credentials): array;
}

class SftpAdapter implements SupplierAdapterInterface {
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
        if (empty($profile['endpoint_url'])) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'Keine WSDL-/Endpunkt-URL hinterlegt.'];
        }
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
            $raw = json_encode($result, JSON_UNESCAPED_UNICODE);
            if ($raw === false) {
                return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SOAP-Antwort konnte nicht in JSON umgewandelt werden.'];
            }
            return ['success' => true, 'raw' => $raw, 'filename' => 'soap_response.json', 'message' => 'SOAP-Aufruf "' . $method . '" erfolgreich.'];
        } catch (Throwable $e) {
            return ['success' => false, 'raw' => null, 'filename' => null, 'message' => 'SOAP-Aufruf fehlgeschlagen: ' . $e->getMessage()];
        }
    }
}

// Instanzierbarkeitstest: beide Klassen muessen das Interface vollstaendig erfuellen.
$sftp = new SftpAdapter();
$soap = new SoapApiAdapter();
var_dump($sftp instanceof SupplierAdapterInterface);
var_dump($soap instanceof SupplierAdapterInterface);
