<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$read = static fn(string $path): string => (string) file_get_contents($path);
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$client = $read($root . '/private/foneday.php');
$admin = $read($root . '/public/foneday.php');
$preflight = strtoupper($read($root . '/sql/foneday_preflight.sql'));
$postcheck = strtoupper($read($root . '/sql/foneday_postcheck.sql'));
$migration = strtoupper($read($root . '/sql/foneday_migration.sql'));

$assert(str_contains($client, 'CURLOPT_HTTPGET => true'), 'API-Client erzwingt GET');
$assert(!preg_match('/CURLOPT_(POST|CUSTOMREQUEST)|\\bPOST\\s+\\//', $client), 'Kein schreibender API-Aufruf');
$assert(str_contains($client, 'CURLOPT_SSL_VERIFYPEER => true'), 'TLS-Zertifikatsprüfung');
$assert(str_contains($client, 'CURLOPT_SSL_VERIFYHOST => 2'), 'TLS-Hostprüfung');
$assert(str_contains($admin, "require_permission('manage_suppliers')"), 'Adminberechtigung');
$assert(str_contains($admin, 'verify_csrf()') && str_contains($admin, 'csrf_field()'), 'CSRF-Schutz');
$assert(!preg_match('/Bearer\\s+[A-Za-z0-9._~-]{20,}/', $client . $admin), 'Kein Tokenliteral');

foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE ', 'DROP ', 'TRUNCATE '] as $verb) {
    $assert(!str_contains($preflight, $verb), 'Preflight rein lesend: ' . trim($verb));
    $assert(!str_contains($postcheck, $verb), 'Postcheck rein lesend: ' . trim($verb));
}
$assert(!preg_match('/^\\s*DELETE\\b/m', $migration), 'Migration löscht keine Daten');
$assert(!preg_match('/^\\s*DROP\\b/m', $migration), 'Migration entfernt keine Struktur');
$assert(!preg_match('/^\\s*TRUNCATE\\b/m', $migration), 'Migration leert keine Daten');
$assert(!str_contains($migration, 'INSERT INTO `SUPPLIERS`'), 'Migration legt Foneday nicht doppelt an');

if ($failures !== []) {
    fwrite(STDERR, "Foneday Static-Tests fehlgeschlagen:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Foneday Static-Tests: OK\n";
