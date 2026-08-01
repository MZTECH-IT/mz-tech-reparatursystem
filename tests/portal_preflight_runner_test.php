<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$template = (string) file_get_contents(
    $root . '/deployment/tools/server_runner_template.php'
);
$preflight = (string) file_get_contents($root . '/sql/portal_ticket_preflight.sql');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

if (!defined('DB_NAME')) {
    define('DB_NAME', 'simulation_schema');
}

if (!preg_match(
    '/function deployment_split_sql\(string \$sql\): array[\s\S]*?(?=\nfunction deployment_execute_sql)/',
    $template,
    $splitMatch
)) {
    $failures[] = 'SQL-Splitter nicht extrahierbar';
} else {
    eval($splitMatch[0]);
}

if (!preg_match(
    '/function deployment_validate_preflight\(array \$execution\): array\s*\{.*?\R\}\R\Rfunction deployment_validate_migration/s',
    $template,
    $validatorMatch
)) {
    $failures[] = 'Preflight-Validator nicht extrahierbar';
} else {
    $validatorSource = preg_replace(
        '/\R\Rfunction deployment_validate_migration[\s\S]*$/',
        '',
        $validatorMatch[0]
    );
    eval((string) $validatorSource);
}

if (function_exists('deployment_split_sql')) {
    $statements = deployment_split_sql($preflight);
    $assert(count($statements) === 9, 'Genau neun Preflight-Statements');
    foreach ($statements as $index => $statement) {
        $assert(
            (bool) preg_match('/^SELECT\b/i', ltrim($statement)),
            'Prüfung ' . ($index + 1) . ' beginnt mit SELECT'
        );
    }
    $assert(!preg_match('/^\s*SET\b/im', $preflight), 'Keine SET-Anweisung');
    $assert(!str_contains($preflight, '@portal_schema'), 'Keine Sitzungsvariable');
}

if (function_exists('deployment_validate_preflight')) {
    $rows = [[
        'check_number' => 1,
        'active_schema' => 'simulation_schema',
        'context_status' => 'OK',
    ]];
    foreach ([
        'users', 'customers', 'repairs', 'settings', 'email_templates',
        'customer_portal_access', 'customer_accounts', 'portal_login_attempts',
    ] as $table) {
        $rows[] = ['check_number' => 3, 'TABLE_NAME' => $table, 'status' => 'VORHANDEN'];
    }
    $rows[] = [
        'check_number' => 9,
        'result' => 'PREFLIGHT_READ_ONLY_COMPLETE',
        'checked_schema' => 'simulation_schema',
    ];
    $execution = [
        'rows' => $rows,
        'result_sets' => array_map(
            static fn(int $number): array => ['check_number' => $number, 'rows' => []],
            range(1, 9)
        ),
        'statements' => 9,
    ];
    try {
        $result = deployment_validate_preflight($execution);
        $assert(($result['status'] ?? '') === 'OK', 'Erfolgssimulation akzeptiert');
    } catch (Throwable $error) {
        $failures[] = 'Erfolgssimulation abgelehnt: ' . $error->getMessage();
    }

    $missingExecution = $execution;
    $missingExecution['rows'][] = [
        'check_number' => 4,
        'requirement' => 'BASE_COLUMN',
        'TABLE_NAME' => 'repairs',
        'COLUMN_NAME' => 'customer_id',
        'status' => 'FEHLT',
    ];
    try {
        deployment_validate_preflight($missingExecution);
        $failures[] = 'Fehlende Basisspalte wurde nicht abgelehnt';
    } catch (RuntimeException $error) {
        $assert(
            str_contains($error->getMessage(), 'repairs.customer_id'),
            'Fehlendes Objekt wird bereinigt benannt'
        );
    }

    $badMapping = $execution;
    $badMapping['result_sets'][3]['check_number'] = 99;
    try {
        deployment_validate_preflight($badMapping);
        $failures[] = 'Falsche Resultset-Zuordnung wurde nicht abgelehnt';
    } catch (RuntimeException $error) {
        $assert(
            str_contains($error->getMessage(), 'resultset_mapping'),
            'Resultset-Zuordnungsfehler kategorisiert'
        );
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Portal-Preflight-Runner-Tests fehlgeschlagen:\n- " .
        implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Portal-Preflight-Runner-Tests: OK\n";
