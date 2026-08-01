<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/private/functions.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "OK: {$message}\n";
    } else {
        $failed++;
        echo "FEHLER: {$message}\n";
    }
};

$cases = [
    ['1,25', '79,00', '98.75'],
    ['1.25', '79.00', '98.75'],
    ['0,50', '79,00', '39.50'],
    ['0', '79,00', '0.00'],
    ['', '79,00', '0.00'],
    ['1,25', '', '0.00'],
];
foreach ($cases as [$hours, $rate, $expected]) {
    $actual = repair_labor_values($hours, $rate);
    $assert($actual['labor_cost'] === $expected, "{$hours} × {$rate} = {$expected}");
}

foreach ([['-1', '79'], ['abc', '79'], ['10000', '79'], ['1', '-1']] as [$hours, $rate]) {
    try {
        repair_labor_values($hours, $rate);
        $assert(false, "Ungültiger Wert {$hours}/{$rate} wird abgelehnt");
    } catch (InvalidArgumentException) {
        $assert(true, "Ungültiger Wert {$hours}/{$rate} wird abgelehnt");
    }
}

$records = device_type_default_records();
$labels = array_column($records, 'display_name');
foreach (['Smartphone','Tablet','PC','Laptop','Fernseher','Monitor','HiFi-Anlage','Verstärker','Radio','DVD-/Blu-ray-Player','Spielkonsole','Sonstiges'] as $label) {
    $assert(in_array($label, $labels, true), "Geräteart {$label} ist zentral vorhanden");
}
$assert(end($labels) === 'Sonstiges', 'Sonstiges ist die letzte Standardauswahl');
$keys = array_column($records, 'technical_key');
$assert(count($keys) === count(array_unique($keys)), 'Technische Geräteart-Schlüssel sind eindeutig');

$customerOutputs = [
    'public/pdf/rechnung.php',
    'public/pdf/kostenvoranschlag.php',
    'public/pdf/reparaturbericht.php',
    'public/pdf/auftrag.php',
    'public/pdf/abholschein.php',
    'public/portal.php',
    'public/portal_business.php',
    'public/portal_guest.php',
];
foreach ($customerOutputs as $relative) {
    $content = file_get_contents($root . '/' . $relative);
    $assert($content !== false && !str_contains($content, "\$repair['internal_notes']"), "{$relative} gibt interne Reparaturnotizen nicht aus");
}

foreach (['public/pdf/rechnung.php','public/pdf/kostenvoranschlag.php'] as $relative) {
    $content = (string)file_get_contents($root . '/' . $relative);
    $assert(str_contains($content, '$labor_cost') && str_contains($content, '$performed_work'), "{$relative} enthält Arbeitskosten und Leistungsbeschreibung");
}

$form = (string)file_get_contents($root . '/public/repairs_form.php');
$assert(str_contains($form, 'repair_labor_values('), 'Serverseitige Arbeitskostenberechnung ist im Formular aktiv');
$assert(str_contains($form, "name=\"labor_cost\"") && str_contains($form, 'readonly'), 'Browser-Arbeitskostenfeld ist schreibgeschützt');
$assert(!str_contains($form, "\$_POST['labor_cost']"), 'Übermittelte Arbeitskosten werden nicht vertraut');

$verification = (string)file_get_contents($root . '/private/account_verification.php');
foreach ([
    'portal_account_manual_verify',
    'portal_account_create_activation',
    'portal_account_revoke_activation',
    'portal_account_send_activation',
    'portal_account_deactivate',
    'portal_activation_delivery_log',
    'portal_activation_resend_wait_seconds',
    'portal_token_hash',
    'portal_activation_lifetime_hours',
] as $needle) {
    $assert(str_contains($verification, $needle), "Konto-Verifizierung enthält {$needle}");
}
$assert(!str_contains($verification, 'verify_token = ?'), 'Neue Aktivierungstokens werden nicht im Klartext gespeichert');

foreach ([
    'sql/repair_device_work_preflight.sql',
    'sql/account_verification_preflight.sql',
    'sql/activation_email_preflight.sql',
] as $relative) {
    $sql = (string)file_get_contents($root . '/' . $relative);
    $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
    $assert(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b/i', (string)$withoutComments), "{$relative} ist rein lesend");
}

foreach ([
    'sql/repair_device_work_migration.sql',
    'sql/account_verification_migration.sql',
    'sql/activation_email_migration.sql',
] as $relative) {
    $sql = (string)file_get_contents($root . '/' . $relative);
    $assert(!preg_match('/\bDROP\s+(TABLE|COLUMN)\b/i', $sql), "{$relative} enthält keinen destruktiven DROP");
}

echo "ERGEBNIS: {$passed} bestanden, {$failed} fehlgeschlagen\n";
exit($failed === 0 ? 0 : 1);
