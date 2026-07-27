<?php
declare(strict_types=1);

/**
 * Foneday-Integration auf Basis der bestehenden Lieferanten-, Artikel- und
 * Angebotsarchitektur. Der API-Token wird ausschließlich zur Laufzeit
 * übergeben und weder gespeichert noch protokolliert.
 */

const FONEDAY_API_BASE_URL = 'https://foneday.shop/api/v1';
const FONEDAY_SUPPLIER_NAME = 'Foneday';
const FONEDAY_TAX_RATE = '19.00';

final class FonedayApiClient
{
    private string $token;
    private string $baseUrl;
    private int $connectTimeout;
    private int $timeout;

    public function __construct(
        string $token,
        string $baseUrl = FONEDAY_API_BASE_URL,
        int $connectTimeout = 10,
        int $timeout = 60
    ) {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('Foneday-Token ist nicht konfiguriert.');
        }
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->connectTimeout = max(1, $connectTimeout);
        $this->timeout = max($this->connectTimeout, $timeout);
    }

    public function __destruct()
    {
        $this->token = '';
    }

    public function get(string $path, array $query = []): array
    {
        $path = '/' . ltrim($path, '/');
        if (!in_array($path, [
            '/products',
            '/products/list/novanl',
            '/orders',
            '/addresses',
            '/shopping-cart',
            '/invoices',
        ], true) && !preg_match('#^/(products|invoices)/[A-Za-z0-9._-]+$#', $path)) {
            throw new InvalidArgumentException('Nicht freigegebener lesender Foneday-Endpunkt.');
        }

        $attempt = 0;
        $maxAttempts = 4;
        do {
            $attempt++;
            $result = $this->requestOnce($path, $query);
            if ($result['success']) {
                return $result;
            }
            $retryable = $result['http_status'] === 429 ||
                $result['http_status'] >= 500 ||
                $result['error_code'] === 'network_error';
            if (!$retryable || $attempt >= $maxAttempts) {
                return $result;
            }
            $delaySeconds = min(8, 2 ** ($attempt - 1));
            if ($result['retry_after'] !== null) {
                $delaySeconds = min(30, max($delaySeconds, $result['retry_after']));
            }
            usleep($delaySeconds * 1_000_000);
        } while ($attempt < $maxAttempts);

        return [
            'success' => false,
            'http_status' => 0,
            'error_code' => 'retry_exhausted',
            'message' => 'Foneday ist vorübergehend nicht erreichbar.',
            'data' => null,
            'headers' => [],
            'retry_after' => null,
        ];
    }

    public function getAllProducts(int $maxPages = 200): array
    {
        $all = [];
        $page = 1;
        $completed = false;
        while ($page <= $maxPages) {
            $response = $this->get('/products', ['page' => $page]);
            if (!$response['success']) {
                return $response + ['products' => [], 'pages' => $page - 1, 'complete' => false];
            }
            $products = foneday_extract_products($response['data']);
            foreach ($products as $product) {
                $all[] = $product;
            }
            $nextPage = foneday_next_page($response['data'], $page, count($products));
            if ($nextPage === null) {
                $completed = true;
                break;
            }
            $page = $nextPage;
        }
        if (!$completed) {
            return [
                'success' => false,
                'http_status' => 0,
                'error_code' => 'pagination_limit',
                'message' => 'Der Foneday-Katalog überschreitet das sichere Seitenlimit.',
                'data' => null,
                'products' => [],
                'pages' => $page,
                'complete' => false,
            ];
        }
        return [
            'success' => true,
            'http_status' => 200,
            'error_code' => null,
            'message' => 'Katalog vollständig gelesen.',
            'data' => null,
            'products' => $all,
            'pages' => $page,
            'complete' => true,
        ];
    }

    private function requestOnce(string $path, array $query): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $responseHeaders = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    if (!in_array($name, ['authorization', 'proxy-authorization', 'set-cookie'], true)) {
                        $responseHeaders[$name] = trim($parts[1]);
                    }
                }
                return $length;
            },
        ]);
        $body = curl_exec($curl);
        $curlError = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false || $curlError !== 0) {
            return [
                'success' => false,
                'http_status' => $status,
                'error_code' => 'network_error',
                'message' => 'Foneday-Netzwerkfehler.',
                'data' => null,
                'headers' => [],
                'retry_after' => null,
            ];
        }
        if ($status < 200 || $status >= 300) {
            return [
                'success' => false,
                'http_status' => $status,
                'error_code' => 'http_' . $status,
                'message' => match ($status) {
                    401, 403 => 'Foneday-Authentifizierung fehlgeschlagen.',
                    404 => 'Foneday-Endpunkt nicht gefunden.',
                    429 => 'Foneday-Rate-Limit erreicht.',
                    default => $status >= 500 ? 'Foneday ist vorübergehend nicht verfügbar.' : 'Foneday-Anfrage fehlgeschlagen.',
                },
                'data' => null,
                'headers' => $responseHeaders,
                'retry_after' => isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after'])
                    ? (int) $responseHeaders['retry-after']
                    : null,
            ];
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'success' => false,
                'http_status' => $status,
                'error_code' => 'invalid_json',
                'message' => 'Foneday lieferte kein gültiges JSON.',
                'data' => null,
                'headers' => $responseHeaders,
                'retry_after' => null,
            ];
        }
        return [
            'success' => true,
            'http_status' => $status,
            'error_code' => null,
            'message' => 'OK',
            'data' => $decoded,
            'headers' => $responseHeaders,
            'retry_after' => null,
        ];
    }
}

function foneday_extract_products(mixed $payload): array
{
    if (!is_array($payload)) {
        return [];
    }
    foreach (['products', 'items', 'results'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values(array_filter($payload[$key], 'is_array'));
        }
    }
    if (isset($payload['data']) && is_array($payload['data'])) {
        return foneday_extract_products($payload['data']);
    }
    if (array_is_list($payload)) {
        return array_values(array_filter($payload, 'is_array'));
    }
    return [];
}

function foneday_next_page(mixed $payload, int $currentPage, int $received): ?int
{
    if (!is_array($payload) || $received === 0) {
        return null;
    }
    $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : $payload;
    $current = (int) ($meta['current_page'] ?? $meta['page'] ?? $currentPage);
    $last = isset($meta['last_page']) ? (int) $meta['last_page'] : null;
    if ($last !== null) {
        return $current < $last ? $current + 1 : null;
    }
    $next = $meta['next_page'] ?? $meta['next'] ?? null;
    if (is_int($next) || (is_string($next) && ctype_digit($next))) {
        return (int) $next;
    }
    $perPage = (int) ($meta['per_page'] ?? $meta['limit'] ?? 0);
    return $perPage > 0 && $received >= $perPage ? $current + 1 : null;
}

function foneday_price_to_cents(mixed $value): ?int
{
    if (is_int($value)) {
        return $value < 0 ? null : $value * 100;
    }
    if (is_float($value)) {
        return !is_finite($value) || $value < 0 ? null : (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }
    if (!is_string($value)) {
        return null;
    }
    $normalized = trim(str_replace(',', '.', $value));
    if ($normalized === '' || !preg_match('/^\d+(?:\.\d{1,4})?$/', $normalized)) {
        return null;
    }
    return (int) round((float) $normalized * 100, 0, PHP_ROUND_HALF_UP);
}

function foneday_calculate_prices(mixed $purchasePrice): ?array
{
    $purchaseCents = foneday_price_to_cents($purchasePrice);
    if ($purchaseCents === null) {
        return null;
    }
    $sellingNetCents = intdiv(($purchaseCents * 110) + 50, 100);
    $sellingGrossCents = intdiv(($sellingNetCents * 119) + 50, 100);
    return [
        'purchase_net_cents' => $purchaseCents,
        'selling_net_cents' => $sellingNetCents,
        'selling_gross_cents' => $sellingGrossCents,
        'purchase_net' => number_format($purchaseCents / 100, 2, '.', ''),
        'selling_net' => number_format($sellingNetCents / 100, 2, '.', ''),
        'selling_gross' => number_format($sellingGrossCents / 100, 2, '.', ''),
        'tax_rate' => FONEDAY_TAX_RATE,
    ];
}

function foneday_map_availability(mixed $value): array
{
    $normalized = strtoupper(trim((string) $value));
    return match ($normalized) {
        'Y' => ['valid' => true, 'availability' => 'auf_lager', 'available' => true],
        'N' => ['valid' => true, 'availability' => 'nicht_verfuegbar', 'available' => false],
        default => ['valid' => false, 'availability' => 'unbekannt', 'available' => false],
    };
}

function foneday_normalize_text(mixed $value): string
{
    return trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '') ?? '');
}

function foneday_classify_product(array $product): array
{
    $haystack = mb_strtolower(implode(' ', array_filter([
        foneday_normalize_text($product['title'] ?? ''),
        foneday_normalize_text($product['category'] ?? ''),
        foneday_normalize_text($product['suitable_for'] ?? ''),
        foneday_normalize_text($product['product_brand'] ?? ''),
    ])), 'UTF-8');
    $replacementKeywords = [
        'display', 'lcd', 'oled', 'touchscreen', 'touch screen', 'digitizer',
        'akku', 'battery', 'batterie', 'charging port', 'ladebuchse',
        'camera module', 'kameramodul', 'speaker', 'lautsprecher', 'microphone',
        'mikrofon', 'flex cable', 'flexkabel', 'housing', 'gehäuse', 'backcover',
        'back cover', 'small part', 'kleinteil', 'spare part', 'ersatzteil',
    ];
    $excludedKeywords = [
        'smartphone', 'mobile phone', 'tablet', 'laptop', 'notebook',
        'desktop computer', 'complete device', 'werkzeug', 'tool kit',
    ];
    foreach ($excludedKeywords as $keyword) {
        if (str_contains($haystack, $keyword)) {
            return ['decision' => 'queue', 'reason' => 'complete_or_non_repair_product'];
        }
    }
    foreach ($replacementKeywords as $keyword) {
        if (str_contains($haystack, $keyword)) {
            return ['decision' => 'import', 'reason' => 'recognized_repair_part'];
        }
    }
    return ['decision' => 'queue', 'reason' => 'unclear_product'];
}

function foneday_normalize_product(array $raw): array
{
    $sku = foneday_normalize_text($raw['sku'] ?? '');
    $title = foneday_normalize_text($raw['title'] ?? '');
    $prices = foneday_calculate_prices($raw['price'] ?? null);
    $availability = foneday_map_availability($raw['instock'] ?? null);
    $classification = foneday_classify_product($raw);
    $errors = [];
    if ($sku === '') {
        $errors[] = 'missing_sku';
    }
    if ($title === '') {
        $errors[] = 'missing_title';
    }
    if ($prices === null) {
        $errors[] = 'invalid_price';
    }
    if (!$availability['valid']) {
        $errors[] = 'unknown_availability';
    }
    $ean = preg_replace('/\D+/', '', foneday_normalize_text($raw['ean'] ?? '')) ?? '';
    if ($ean !== '' && (strlen($ean) < 8 || strlen($ean) > 14)) {
        $errors[] = 'implausible_ean';
        $ean = '';
    }
    $normalized = [
        'external_product_id' => foneday_normalize_text($raw['id'] ?? $raw['product_id'] ?? ''),
        'sku' => $sku,
        'ean' => $ean,
        'title' => $title,
        'instock' => strtoupper(foneday_normalize_text($raw['instock'] ?? '')),
        'suitable_for' => $raw['suitable_for'] ?? null,
        'category' => foneday_normalize_text($raw['category'] ?? ''),
        'product_brand' => foneday_normalize_text($raw['product_brand'] ?? ''),
        'artcode' => foneday_normalize_text($raw['artcode'] ?? ''),
        'quality' => foneday_normalize_text($raw['quality'] ?? ''),
        'model_brand' => foneday_normalize_text($raw['model_brand'] ?? ''),
        'model_codes' => $raw['model_codes'] ?? null,
        'prices' => $prices,
        'availability' => $availability,
        'classification' => $classification,
        'errors' => $errors,
    ];
    $normalized['fingerprint'] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $normalized;
}

function foneday_supplier(): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM suppliers WHERE LOWER(name) = LOWER(?) ORDER BY id');
    $stmt->execute([FONEDAY_SUPPLIER_NAME]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

function foneday_find_part_match(int $supplierId, array $product): array
{
    $db = get_db();
    if ($product['sku'] !== '') {
        $stmt = $db->prepare(
            'SELECT part_id FROM product_supplier_offers
             WHERE supplier_id = ? AND supplier_sku = ? LIMIT 2'
        );
        $stmt->execute([$supplierId, $product['sku']]);
        $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        if (count($ids) === 1) {
            return ['part_id' => $ids[0], 'method' => 'supplier_sku', 'ambiguous' => false];
        }
        if (count($ids) > 1) {
            return ['part_id' => null, 'method' => 'supplier_sku', 'ambiguous' => true];
        }
    }
    if ($product['ean'] !== '') {
        $stmt = $db->prepare(
            'SELECT id FROM parts WHERE ean = ?
             UNION
             SELECT part_id AS id FROM product_supplier_offers WHERE ean = ?'
        );
        $stmt->execute([$product['ean'], $product['ean']]);
        $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        if (count($ids) === 1) {
            return ['part_id' => $ids[0], 'method' => 'ean', 'ambiguous' => false];
        }
        if (count($ids) > 1) {
            return ['part_id' => null, 'method' => 'ean', 'ambiguous' => true];
        }
    }
    if ($product['artcode'] !== '') {
        $stmt = $db->prepare(
            'SELECT part_id FROM product_supplier_offers
             WHERE supplier_id = ? AND external_artcode = ? LIMIT 2'
        );
        $stmt->execute([$supplierId, $product['artcode']]);
        $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        if (count($ids) === 1) {
            return ['part_id' => $ids[0], 'method' => 'artcode', 'ambiguous' => false];
        }
        if (count($ids) > 1) {
            return ['part_id' => null, 'method' => 'artcode', 'ambiguous' => true];
        }
    }
    return ['part_id' => null, 'method' => null, 'ambiguous' => false];
}

function foneday_dry_run(array $rawProducts): array
{
    $supplier = foneday_supplier();
    if (!$supplier) {
        return ['success' => false, 'message' => 'Der bestehende Lieferant Foneday wurde nicht eindeutig gefunden.'];
    }
    $stats = [
        'received' => count($rawProducts),
        'importable' => 0,
        'queue' => 0,
        'invalid' => 0,
        'matched' => 0,
        'new' => 0,
        'unavailable' => 0,
    ];
    foreach ($rawProducts as $raw) {
        if (!is_array($raw)) {
            $stats['invalid']++;
            continue;
        }
        $product = foneday_normalize_product($raw);
        if ($product['errors'] !== []) {
            $stats['invalid']++;
            continue;
        }
        if (!$product['availability']['available']) {
            $stats['unavailable']++;
        }
        if ($product['classification']['decision'] !== 'import') {
            $stats['queue']++;
            continue;
        }
        $stats['importable']++;
        $match = foneday_find_part_match((int) $supplier['id'], $product);
        if ($match['ambiguous']) {
            $stats['queue']++;
        } elseif ($match['part_id']) {
            $stats['matched']++;
        } else {
            $stats['new']++;
        }
    }
    return ['success' => true, 'stats' => $stats];
}

function foneday_queue_product(int $supplierId, array $product, string $reason): void
{
    get_db()->prepare(
        'INSERT INTO foneday_import_queue
            (supplier_id, external_product_id, supplier_sku, ean, artcode, title,
             reason_code, payload_hash, payload_json, status)
         VALUES (?,?,?,?,?,?,?,?,?, "offen")
         ON DUPLICATE KEY UPDATE
            ean = VALUES(ean), artcode = VALUES(artcode), title = VALUES(title),
            reason_code = VALUES(reason_code), payload_hash = VALUES(payload_hash),
            payload_json = VALUES(payload_json), updated_at = NOW()'
    )->execute([
        $supplierId,
        $product['external_product_id'] ?: null,
        $product['sku'] ?: null,
        $product['ean'] ?: null,
        $product['artcode'] ?: null,
        $product['title'] ?: '(ohne Titel)',
        $reason,
        $product['fingerprint'],
        json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function foneday_acquire_sync_lock(int $ttlSeconds = 3600): ?string
{
    $hash = hash('sha256', bin2hex(random_bytes(32)));
    $ttlSeconds = max(60, min(7200, $ttlSeconds));
    $stmt = get_db()->prepare(
        'INSERT INTO foneday_sync_lock (lock_name, lock_token_hash, acquired_at, expires_at)
         VALUES ("catalog", ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
         ON DUPLICATE KEY UPDATE
           lock_token_hash = IF(expires_at < NOW(), VALUES(lock_token_hash), lock_token_hash),
           acquired_at = IF(expires_at < NOW(), VALUES(acquired_at), acquired_at),
           expires_at = IF(expires_at < NOW(), VALUES(expires_at), expires_at)'
    );
    $stmt->execute([$hash, $ttlSeconds]);
    $check = get_db()->query(
        'SELECT lock_token_hash FROM foneday_sync_lock WHERE lock_name = "catalog"'
    );
    return hash_equals($hash, (string) $check->fetchColumn()) ? $hash : null;
}

function foneday_release_sync_lock(string $tokenHash): void
{
    if (!preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
        return;
    }
    $stmt = get_db()->prepare(
        'DELETE FROM foneday_sync_lock WHERE lock_name = "catalog" AND lock_token_hash = ?'
    );
    $stmt->execute([$tokenHash]);
}

function foneday_apply_catalog(array $rawProducts, string $mode = 'full_import', ?int $userId = null): array
{
    if (!in_array($mode, ['full_import', 'price_stock'], true)) {
        return ['success' => false, 'message' => 'Nicht erlaubter Foneday-Importmodus.'];
    }
    $supplier = foneday_supplier();
    if (!$supplier) {
        return ['success' => false, 'message' => 'Der bestehende Lieferant Foneday wurde nicht eindeutig gefunden.'];
    }
    $lockHash = foneday_acquire_sync_lock();
    if ($lockHash === null) {
        return ['success' => false, 'message' => 'Eine andere Foneday-Synchronisierung läuft bereits.'];
    }
    $startedAt = microtime(true);
    $supplierId = (int) $supplier['id'];
    $db = get_db();
    try {
        $db->prepare(
            'INSERT INTO foneday_sync_runs (supplier_id, run_mode, status, started_at, products_received)
             VALUES (?, ?, "running", NOW(), ?)'
        )->execute([$supplierId, $mode, count($rawProducts)]);
    } catch (Throwable) {
        foneday_release_sync_lock($lockHash);
        return ['success' => false, 'message' => 'Foneday-Synchronisierung konnte nicht gestartet werden.'];
    }
    $runId = (int) $db->lastInsertId();
    $stats = [
        'received' => count($rawProducts),
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'unavailable' => 0,
        'queued' => 0,
        'errors' => 0,
    ];

    try {
        foreach ($rawProducts as $raw) {
            if (!is_array($raw)) {
                $stats['errors']++;
                continue;
            }
            $product = foneday_normalize_product($raw);
            if ($product['errors'] !== []) {
                foneday_queue_product($supplierId, $product, implode(',', $product['errors']));
                $stats['queued']++;
                continue;
            }
            if ($product['classification']['decision'] !== 'import') {
                foneday_queue_product($supplierId, $product, $product['classification']['reason']);
                $stats['queued']++;
                continue;
            }
            $match = foneday_find_part_match($supplierId, $product);
            if ($match['ambiguous']) {
                foneday_queue_product($supplierId, $product, 'ambiguous_' . $match['method']);
                $stats['queued']++;
                continue;
            }

            $db->beginTransaction();
            try {
                $partId = $match['part_id'];
                $created = false;
                if (!$partId) {
                    if ($mode === 'price_stock') {
                        foneday_queue_product($supplierId, $product, 'new_product_during_price_stock_run');
                        $db->commit();
                        $stats['queued']++;
                        continue;
                    }
                    $partSku = 'FD-' . substr(preg_replace('/[^A-Za-z0-9._-]/', '-', $product['sku']), 0, 47);
                    $db->prepare(
                        'INSERT INTO parts
                            (sku, ean, name, category, manufacturer, brand, model_compatibility,
                             purchase_price, selling_price, selling_price_locked, price_source,
                             last_price_check_at, is_discontinued)
                         VALUES (?,?,?,?,?,?,?,?,?,0,"Foneday",NOW(),0)'
                    )->execute([
                        $partSku,
                        $product['ean'] ?: null,
                        $product['title'],
                        $product['category'] ?: null,
                        $product['product_brand'] ?: null,
                        $product['product_brand'] ?: null,
                        is_array($product['suitable_for'])
                            ? implode(', ', array_map('strval', $product['suitable_for']))
                            : foneday_normalize_text($product['suitable_for']),
                        $product['prices']['purchase_net'],
                        $product['prices']['selling_net'],
                    ]);
                    $partId = (int) $db->lastInsertId();
                    $created = true;
                }

                $partStmt = $db->prepare('SELECT selling_price_locked FROM parts WHERE id = ? FOR UPDATE');
                $partStmt->execute([$partId]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
                if (!$part) {
                    throw new RuntimeException('Zugeordneter Artikel existiert nicht.');
                }

                $offerStmt = $db->prepare(
                    'SELECT * FROM product_supplier_offers
                     WHERE supplier_id = ? AND supplier_sku = ? LIMIT 1 FOR UPDATE'
                );
                $offerStmt->execute([$supplierId, $product['sku']]);
                $existingOffer = $offerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $offerChanged = !$existingOffer ||
                    !hash_equals((string) ($existingOffer['source_fingerprint'] ?? ''), $product['fingerprint']);

                $db->prepare(
                    'INSERT INTO product_supplier_offers
                        (part_id, supplier_id, supplier_sku, external_product_id,
                         supplier_product_name, ean, external_artcode, quality,
                         supplier_category, product_brand, model_brand, model_codes_json,
                         suitable_for_json, purchase_price, purchase_price_net,
                         selling_price_net, selling_price_gross, currency, tax_rate,
                         is_net_price, availability, stock_quantity_at_supplier,
                         price_source, source_fingerprint, last_price_check_at,
                         last_stock_check_at, last_full_sync_at, last_seen_at,
                         missing_successful_runs, last_foneday_sync_run_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, "EUR",19.00,1,?,?, "Foneday",?,
                             NOW(),NOW(),NOW(),NOW(),0,?)
                     ON DUPLICATE KEY UPDATE
                        part_id = VALUES(part_id),
                        external_product_id = VALUES(external_product_id),
                        supplier_product_name = VALUES(supplier_product_name),
                        ean = VALUES(ean),
                        external_artcode = VALUES(external_artcode),
                        quality = VALUES(quality),
                        supplier_category = VALUES(supplier_category),
                        product_brand = VALUES(product_brand),
                        model_brand = VALUES(model_brand),
                        model_codes_json = VALUES(model_codes_json),
                        suitable_for_json = VALUES(suitable_for_json),
                        purchase_price = VALUES(purchase_price),
                        purchase_price_net = VALUES(purchase_price_net),
                        selling_price_net = VALUES(selling_price_net),
                        selling_price_gross = VALUES(selling_price_gross),
                        tax_rate = VALUES(tax_rate),
                        availability = VALUES(availability),
                        stock_quantity_at_supplier = VALUES(stock_quantity_at_supplier),
                        price_source = VALUES(price_source),
                        source_fingerprint = VALUES(source_fingerprint),
                        last_price_check_at = NOW(),
                        last_stock_check_at = NOW(),
                        last_full_sync_at = NOW(),
                        last_seen_at = NOW(),
                        missing_successful_runs = 0,
                        last_foneday_sync_run_id = VALUES(last_foneday_sync_run_id)'
                )->execute([
                    $partId,
                    $supplierId,
                    $product['sku'],
                    $product['external_product_id'] ?: null,
                    $product['title'],
                    $product['ean'] ?: null,
                    $product['artcode'] ?: null,
                    $product['quality'] ?: null,
                    $product['category'] ?: null,
                    $product['product_brand'] ?: null,
                    $product['model_brand'] ?: null,
                    json_encode($product['model_codes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($product['suitable_for'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $product['prices']['purchase_net'],
                    $product['prices']['purchase_net'],
                    $product['prices']['selling_net'],
                    $product['prices']['selling_gross'],
                    $product['availability']['availability'],
                    null,
                    $product['fingerprint'],
                    $runId,
                ]);

                if ((int) $part['selling_price_locked'] === 1) {
                    $db->prepare(
                        'UPDATE parts SET purchase_price = ?, last_price_check_at = NOW(),
                            price_source = "Foneday" WHERE id = ?'
                    )->execute([$product['prices']['purchase_net'], $partId]);
                    $db->prepare(
                        'INSERT INTO foneday_price_conflicts
                            (part_id, supplier_id, proposed_selling_price_net, reason, sync_run_id)
                         VALUES (?,?,?,"manual_price_lock",?)'
                    )->execute([$partId, $supplierId, $product['prices']['selling_net'], $runId]);
                } else {
                    $db->prepare(
                        'UPDATE parts SET purchase_price = ?, selling_price = ?,
                            last_price_check_at = NOW(), price_source = "Foneday" WHERE id = ?'
                    )->execute([
                        $product['prices']['purchase_net'],
                        $product['prices']['selling_net'],
                        $partId,
                    ]);
                }
                product_record_price_history(
                    $partId,
                    $supplierId,
                    (float) $product['prices']['purchase_net'],
                    'EUR',
                    'einkauf',
                    'Foneday'
                );
                product_record_availability_history(
                    $partId,
                    $supplierId,
                    $product['availability']['availability'],
                    null
                );
                $db->commit();

                if (!$product['availability']['available']) {
                    $stats['unavailable']++;
                }
                if ($created) {
                    $stats['created']++;
                } elseif ($offerChanged) {
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $stats['errors']++;
            }
        }

        if ($mode === 'full_import') {
            $db->prepare(
                'UPDATE product_supplier_offers
                 SET missing_successful_runs = missing_successful_runs + 1,
                     availability = CASE
                         WHEN missing_successful_runs + 1 >= 3 THEN "nicht_verfuegbar"
                         ELSE availability
                     END
                 WHERE supplier_id = ?
                   AND (last_foneday_sync_run_id IS NULL OR last_foneday_sync_run_id <> ?)'
            )->execute([$supplierId, $runId]);
        }

        $status = $stats['errors'] > 0 ? 'partial' : 'success';
        $db->prepare(
            'UPDATE foneday_sync_runs SET status = ?, finished_at = NOW(),
                products_created = ?, products_updated = ?, products_unchanged = ?,
                products_unavailable = ?, queue_count = ?, error_count = ?, duration_ms = ?
             WHERE id = ?'
        )->execute([
            $status,
            $stats['created'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['unavailable'],
            $stats['queued'],
            $stats['errors'],
            (int) round((microtime(true) - $startedAt) * 1000),
            $runId,
        ]);
        $db->prepare(
            'UPDATE suppliers SET last_sync_at = NOW(), last_sync_status = ?,
                last_sync_message = ?, next_scheduled_sync_at = DATE_ADD(NOW(), INTERVAL 6 HOUR)
             WHERE id = ?'
        )->execute([
            $status === 'success' ? 'erfolgreich' : 'teilweise',
            sprintf(
                'Foneday: %d neu, %d aktualisiert, %d unverändert, %d Warteschlange, %d Fehler.',
                $stats['created'],
                $stats['updated'],
                $stats['unchanged'],
                $stats['queued'],
                $stats['errors']
            ),
            $supplierId,
        ]);
        log_activity('foneday_sync', 'suppliers', $supplierId, 'run=' . $runId . ';status=' . $status);
        foneday_release_sync_lock($lockHash);
        return ['success' => $status === 'success', 'partial' => $status === 'partial', 'run_id' => $runId, 'stats' => $stats];
    } catch (Throwable) {
        $db->prepare(
            'UPDATE foneday_sync_runs SET status = "failed", finished_at = NOW(),
                error_count = error_count + 1, error_code = "global_failure" WHERE id = ?'
        )->execute([$runId]);
        $db->prepare(
            'UPDATE suppliers SET last_sync_at = NOW(), last_sync_status = "fehler",
                last_sync_message = "Foneday-Synchronisierung global fehlgeschlagen."
             WHERE id = ?'
        )->execute([$supplierId]);
        foneday_release_sync_lock($lockHash);
        return ['success' => false, 'message' => 'Foneday-Synchronisierung fehlgeschlagen.', 'run_id' => $runId];
    }
}

function foneday_sync_runs(int $limit = 50): array
{
    $stmt = get_db()->prepare(
        'SELECT * FROM foneday_sync_runs ORDER BY started_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function foneday_queue_list(string $status = 'offen', int $limit = 200): array
{
    $stmt = get_db()->prepare(
        'SELECT * FROM foneday_import_queue WHERE status = ? ORDER BY updated_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $status);
    $stmt->bindValue(2, max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
