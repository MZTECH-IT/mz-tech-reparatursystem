<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI-Ausführung erlaubt.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
$mode = $argv[1] ?? '';
$sqlFiles = [
    'preflight' => $projectRoot . '/sql/portal_ticket_preflight.sql',
    'migration' => $projectRoot . '/sql/portal_ticket_migration.sql',
    'postcheck' => $projectRoot . '/sql/portal_ticket_postcheck.sql',
];
if (!isset($sqlFiles[$mode])) {
    fwrite(STDERR, "Ungültiger SQL-Modus.\n");
    exit(2);
}

$requiredEnvironment = [
    'MZTECH_DB_HOST',
    'MZTECH_DB_PORT',
    'MZTECH_DB_NAME',
    'MZTECH_DB_USER',
    'MZTECH_DB_PASSWORD',
];
foreach ($requiredEnvironment as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        fwrite(STDERR, "Erforderliche Prozessvariable fehlt: {$name}\n");
        exit(2);
    }
}

$result = [
    'success' => false,
    'mode' => $mode,
    'result_sets' => [],
];

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('MZTECH_DB_HOST'),
        (int) getenv('MZTECH_DB_PORT'),
        getenv('MZTECH_DB_NAME')
    );
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ];
    $sslCa = getenv('MZTECH_DB_SSL_CA');
    if ($sslCa !== false && $sslCa !== '') {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    }
    $pdo = new PDO(
        $dsn,
        getenv('MZTECH_DB_USER'),
        getenv('MZTECH_DB_PASSWORD'),
        $pdoOptions
    );
    $sql = file_get_contents($sqlFiles[$mode]);
    if ($sql === false) {
        throw new RuntimeException('SQL-Datei konnte nicht gelesen werden.');
    }

    $statement = $pdo->query($sql);
    do {
        if ($statement->columnCount() > 0) {
            $result['result_sets'][] = $statement->fetchAll();
        }
    } while ($statement->nextRowset());

    $result['success'] = true;
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    $result['error'] = preg_replace('/password=[^;\\s]*/i', 'password=[REDACTED]', $error->getMessage());
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(1);
} finally {
    foreach ($requiredEnvironment as $name) {
        putenv($name);
    }
    putenv('MZTECH_DB_SSL_CA');
}
