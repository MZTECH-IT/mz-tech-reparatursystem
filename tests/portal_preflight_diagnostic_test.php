<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$checkFiles = glob($root . '/sql/portal_preflight_checks/*.sql') ?: [];
sort($checkFiles);
$assert(count($checkFiles) === 9, 'Exakt neun Diagnosedateien');

$numbers = [];
foreach ($checkFiles as $path) {
    $name = basename($path);
    $sql = trim((string) file_get_contents($path));
    $assert((bool) preg_match('/^(\d{3})_/', $name, $match), 'Nummerierter Dateiname: ' . $name);
    if (isset($match[1])) {
        $numbers[] = (int) $match[1];
    }
    $trimmed = rtrim($sql, ';');
    $assert((bool) preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i', $trimmed), 'Lesender Start: ' . $name);
    $assert(!str_contains($trimmed, ';'), 'Genau ein Statement: ' . $name);
    $assert(
        !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|CALL|DO|SET|LOAD|HANDLER|GRANT|REVOKE|LOCK|UNLOCK)\b/i', $trimmed),
        'Keine schreibende Anweisung: ' . $name
    );
    $assert(str_contains($trimmed, 'DATABASE()'), 'Expliziter aktiver Datenbankkontext: ' . $name);
}
$assert($numbers === range(1, 9), 'Lückenlose Prüfungsnummern 001 bis 009');

$runner = (string) file_get_contents(
    $root . '/deployment/tools/portal_preflight_diagnostic_template.php'
);
$assert(str_contains($runner, 'require_admin()'), 'Interne Administratorprüfung');
$assert(str_contains($runner, 'verify_csrf()'), 'CSRF-Prüfung');
$assert(str_contains($runner, "'nonce' => bin2hex(random_bytes(32))"), 'Serverseitiger Sitzungs-Nonce');
$assert(str_contains($runner, "'expires_at' => time() + 1800"), '30 Minuten Verfügbarkeit');
$assert(!str_contains($runner, 'deployment_token'), 'Kein manuelles Deployment-Token');
$assert(!str_contains($runner, 'portal_ticket_migration'), 'Keine Portal-Migration eingebettet');
$assert(!str_contains($runner, 'foneday_migration'), 'Keine Foneday-Migration eingebettet');
$assert(str_contains($runner, 'diagnostic_sqlstate'), 'SQLSTATE wird bereinigt erfasst');
$assert(str_contains($runner, 'diagnostic_error_category'), 'Fehlerkategorie wird bereinigt erfasst');
$assert(str_contains($runner, "'database_connection'"), 'Datenbankverbindung separat ausgewiesen');
$assert(str_contains($runner, "'failed_check'"), 'Prüfungsnummer separat ausgewiesen');
$assert(str_contains($runner, "'affected_object'"), 'Betroffenes Objekt separat ausgewiesen');

$orchestrator = (string) file_get_contents(
    $root . '/deployment/tools/run_portal_preflight_diagnostic.ps1'
);
$assert(str_contains($orchestrator, "AddMinutes(30)"), 'Agent wartet bis zu 30 Minuten');
$assert(str_contains($orchestrator, 'if ($null -eq $status)'), 'Kein Löschen ohne gesicherte Auswertung');
$savePosition = strpos($orchestrator, 'Set-Content -LiteralPath $resultPath');
$deletePosition = strpos($orchestrator, 'Remove-FtpsFile -Credential $Credential -Path $runnerPath');
$assert(
    $savePosition !== false && $deletePosition !== false && $savePosition < $deletePosition,
    'Status wird vor Runner-Entfernung lokal gesichert'
);

if ($failures !== []) {
    fwrite(STDERR, "Portal-Preflight-Diagnosetests fehlgeschlagen:\n- " .
        implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Portal-Preflight-Diagnosetests: OK\n";
