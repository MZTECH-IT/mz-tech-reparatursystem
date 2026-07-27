<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'module' => $root . '/deployment/tools/MzTechCredentialManager.psm1',
    'setup' => $root . '/deployment/tools/setup_credentials.ps1',
    'setup_gui' => $root . '/deployment/tools/setup_credentials_gui.ps1',
    'manage' => $root . '/deployment/tools/manage_credentials.ps1',
    'deploy' => $root . '/deployment/tools/deploy_portal_ticket.ps1',
    'runner' => $root . '/deployment/tools/run_portal_sql.php',
    'docs' => $root . '/deployment/CREDENTIAL_WORKFLOW.md',
    'gitignore' => $root . '/.gitignore',
];
$contents = [];
$errors = [];
$checks = 0;

foreach ($files as $name => $path) {
    $checks++;
    if (!is_file($path)) {
        $errors[] = "Datei fehlt: {$path}";
        continue;
    }
    $contents[$name] = (string) file_get_contents($path);
}

function expectContains(array &$errors, int &$checks, string $haystack, string $needle, string $label): void
{
    $checks++;
    if (!str_contains($haystack, $needle)) {
        $errors[] = "Fehlt: {$label}";
    }
}

if (count($contents) === count($files)) {
    foreach (['CredWriteW', 'CredReadW', 'CredDeleteW', 'ZeroFreeBSTR'] as $needle) {
        expectContains($errors, $checks, $contents['module'], $needle, "Credential-API {$needle}");
    }
    foreach ([
        'MZTech.Reparatursystem.ProductionDB.v1',
        'MZTech.Reparatursystem.ProductionFTPS.v1',
        'MZTech.Reparatursystem.FONEDAY_API_TOKEN.v1',
    ] as $target) {
        expectContains($errors, $checks, $contents['setup'] . $contents['deploy'], $target, $target);
    }
    expectContains($errors, $checks, $contents['setup'], '-AsSecureString', 'verdeckte Eingabe');
    expectContains($errors, $checks, $contents['setup_gui'], 'UseSystemPasswordChar = $true', 'maskierter Windows-Dialog');
    expectContains($errors, $checks, $contents['deploy'], 'Test-MzTechCredential', 'Existenzprüfung');
    expectContains($errors, $checks, $contents['deploy'], '$request.EnableSsl = $true', 'explizites FTPS');
    expectContains($errors, $checks, $contents['deploy'], '/mztech-it.de/repair_neu/', 'begrenzter Zielpfad');
    expectContains($errors, $checks, $contents['deploy'], 'SHA256', 'Upload-Hashprüfung');
    expectContains($errors, $checks, $contents['deploy'], "'private'), (Join-Path \$packageRoot 'public')", 'begrenzte Uploadquellen');
    expectContains($errors, $checks, $contents['deploy'], "private\\config.php", 'config.php-Sicherheitsabbruch');
    expectContains($errors, $checks, $contents['runner'], "getenv('MZTECH_DB_PASSWORD')", 'Kennwort nur aus Prozessumgebung');
    expectContains($errors, $checks, $contents['runner'], 'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true', 'DB-Zertifikatsprüfung');
    expectContains($errors, $checks, $contents['gitignore'], '/deployment/runtime_secure/', 'Runtime-Git-Ausschluss');

    $preflight = strrpos($contents['deploy'], 'Invoke-PortalSql -Mode preflight');
    $migration = strrpos($contents['deploy'], 'Invoke-PortalSql -Mode migration');
    $postcheck = strrpos($contents['deploy'], 'Invoke-PortalSql -Mode postcheck');
    $upload = strrpos($contents['deploy'], 'Invoke-FtpsDeployment');
    $checks++;
    if ($preflight === false || $migration === false || $postcheck === false || $upload === false ||
        !($preflight < $migration && $migration < $postcheck && $postcheck < $upload)) {
        $errors[] = 'Deployment-Reihenfolge ist nicht eindeutig abgesichert.';
    }

    $checks++;
    if (preg_match('/--password\\s*=/', $contents['deploy'] . $contents['runner'])) {
        $errors[] = 'Kennwort würde als Kommandozeilenargument übergeben.';
    }
}

echo "CREDENTIAL_WORKFLOW_CHECKS={$checks}\n";
echo "CREDENTIAL_WORKFLOW_ERRORS=" . count($errors) . "\n";
foreach ($errors as $error) {
    echo "ERROR: {$error}\n";
}
exit($errors ? 1 : 0);
