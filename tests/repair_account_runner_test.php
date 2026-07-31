<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$templatePath = $root . '/deployment/tools/repair_account_runner_template.php';
$template = (string)file_get_contents($templatePath);
$start = strpos($template, 'function deployment_split_sql');
$end = strpos($template, 'function deployment_execute_sql', $start ?: 0);
if ($start === false || $end === false) {
    fwrite(STDERR, "Runner-SQL-Parser nicht gefunden.\n");
    exit(1);
}
eval(substr($template, $start, $end - $start));

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'OK: ' : 'FEHLER: ') . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$files = [
    'repair_device_work_preflight.sql' => true,
    'repair_device_work_migration.sql' => false,
    'repair_device_work_postcheck.sql' => true,
    'account_verification_preflight.sql' => true,
    'account_verification_migration.sql' => false,
    'account_verification_postcheck.sql' => true,
];
foreach ($files as $name => $readOnly) {
    $sql = (string)file_get_contents($root . '/sql/' . $name);
    try {
        $statements = deployment_split_sql($sql);
        $check(count($statements) > 0, "{$name} wird in Einzelstatements zerlegt");
        if ($readOnly) {
            $bad = array_filter($statements, static function (string $statement): bool {
                $keyword = strtoupper((string)strtok(ltrim($statement), " \t\r\n"));
                return !in_array($keyword, ['SELECT','SHOW','DESCRIBE'], true);
            });
            $check($bad === [], "{$name} enthält ausschließlich lesende Statements");
        }
    } catch (Throwable $e) {
        $check(false, "{$name} Parserfehler: " . $e->getMessage());
    }
}

foreach ([
    'REPAIR_DEVICE_WORK_MIGRATION_COMPLETE',
    'REPAIR_DEVICE_WORK_POSTCHECK_COMPLETE',
    'ACCOUNT_VERIFICATION_MIGRATION_COMPLETE',
    'ACCOUNT_VERIFICATION_POSTCHECK_COMPLETE',
] as $marker) {
    $check(str_contains($template, $marker), "Runner validiert Marker {$marker}");
}
$check(str_contains($template, "['portal_preflight', 'portal_postcheck', 'foneday_preflight', 'foneday_postcheck']"),
    'Runner erzwingt Lesemodus für beide Preflights und Postchecks');
$check(!str_contains($template, 'name="deployment_token"'), 'Runner verlangt kein manuell kopiertes Deployment-Token');
$check(str_contains($template, 'session_nonce'), 'Runner verwendet einen serverseitig gebundenen Sitzungs-Nonce');

echo 'ERGEBNIS: ' . (count($failures) ? count($failures) . ' fehlgeschlagen' : 'alle bestanden') . PHP_EOL;
exit($failures ? 1 : 0);
