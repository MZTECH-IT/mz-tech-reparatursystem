<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur als lokales CLI-Werkzeug erlaubt.\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/private/foneday.php';

$argument = $argv[1] ?? '';
$connectionTestOnly = $argument === '--connection-test';
$outputPath = $connectionTestOnly ? '' : $argument;
if (!$connectionTestOnly && ($outputPath === '' || !str_starts_with(
    strtolower(str_replace('/', '\\', $outputPath)),
    strtolower(str_replace('/', '\\', dirname(__DIR__) . '\\runtime_secure\\'))
))) {
    fwrite(STDERR, "Ungültiger sicherer Ausgabepfad.\n");
    exit(2);
}

$token = getenv('MZTECH_FONEDAY_TOKEN');
if (!is_string($token) || $token === '') {
    fwrite(STDERR, "Der lokale Foneday-Token ist nicht verfügbar.\n");
    exit(3);
}

try {
    $client = new FonedayApiClient($token);
    putenv('MZTECH_FONEDAY_TOKEN');
    $token = '';
    if ($connectionTestOnly) {
        $result = $client->get('/products', ['page' => 1]);
        $validJson = $result['data'] !== null;
        echo 'HTTP_STATUS=' . (int) ($result['http_status'] ?? 0) . PHP_EOL;
        echo 'GUELTIGE_JSON_ANTWORT=' . ($validJson ? 'JA' : 'NEIN') . PHP_EOL;
        echo 'API_VERBINDUNG_ERFOLGREICH=' .
            (!empty($result['success']) ? 'JA' : 'NEIN') . PHP_EOL;
        exit(!empty($result['success']) ? 0 : 4);
    }
    $result = $client->getAllProducts();
    if (!$result['success']) {
        fwrite(STDERR, ($result['message'] ?? 'Foneday-Abruf fehlgeschlagen.') . "\n");
        exit(4);
    }
    $json = json_encode([
        'source' => 'Foneday',
        'endpoint' => 'GET /products',
        'fetched_at' => gmdate('c'),
        'pages' => (int) $result['pages'],
        'products' => $result['products'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $directory = dirname($outputPath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Sicheres Laufzeitverzeichnis konnte nicht erstellt werden.');
    }
    if (file_put_contents($outputPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('Katalog konnte nicht gespeichert werden.');
    }
    echo "Foneday-Katalog wurde ausschließlich lesend abgerufen.\n";
} catch (Throwable) {
    fwrite(STDERR, "Der lesende Foneday-Katalogabruf ist sicher fehlgeschlagen.\n");
    exit(5);
} finally {
    putenv('MZTECH_FONEDAY_TOKEN');
    $token = '';
}
