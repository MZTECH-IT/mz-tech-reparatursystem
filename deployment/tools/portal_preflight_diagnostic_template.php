<?php
declare(strict_types=1);

const DIAGNOSTIC_RUNNER_ID = '__RUNNER_ID__';
const DIAGNOSTIC_STATUS_FILE = '__STATUS_FILE__';
const DIAGNOSTIC_CHECKS_B64 = '__CHECKS_B64__';
const DIAGNOSTIC_CHECKS_HASH = '__CHECKS_HASH__';

$diagnosticPhase = 'bootstrap';
$diagnosticTerminal = false;
$diagnosticStatusPath = dirname(__DIR__) . '/private/' . DIAGNOSTIC_STATUS_FILE;

function diagnostic_write_status(string $state, array $summary): void
{
    global $diagnosticStatusPath;
    $payload = [
        'runner_id' => DIAGNOSTIC_RUNNER_ID,
        'state' => $state,
        'summary' => $summary,
        'updated_at' => gmdate('c'),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        file_put_contents($diagnosticStatusPath, $json, LOCK_EX);
        @chmod($diagnosticStatusPath, 0600);
    }
}

register_shutdown_function(static function (): void {
    global $diagnosticPhase, $diagnosticTerminal;
    if ($diagnosticTerminal || in_array($diagnosticPhase, ['auth', 'ready', 'complete'], true)) {
        return;
    }
    $category = $diagnosticPhase === 'database_connection'
        ? 'database_connection_or_bootstrap'
        : 'application_bootstrap';
    diagnostic_write_status('failed', [
        'database_connection' => $diagnosticPhase === 'database_connection' ? 'NEIN' : 'UNBEKANNT',
        'database_type_version_recognized' => 'NEIN',
        'failed_check' => $diagnosticPhase === 'database_connection' ? 0 : -1,
        'sqlstate' => 'NICHT_VERFUEGBAR',
        'error_category' => $category,
        'affected_object' => 'Bootstrap/Datenbankverbindung',
        'preflight_candidate' => 'FEHLER',
    ]);
});

try {
    require_once __DIR__ . '/init.php';
} catch (Throwable) {
    $diagnosticTerminal = true;
    diagnostic_write_status('failed', [
        'database_connection' => 'UNBEKANNT',
        'database_type_version_recognized' => 'NEIN',
        'failed_check' => -1,
        'sqlstate' => 'NICHT_VERFUEGBAR',
        'error_category' => 'application_bootstrap',
        'affected_object' => 'public/init.php',
        'preflight_candidate' => 'FEHLER',
    ]);
    http_response_code(500);
    exit('Der Anwendungs-Bootstrap ist sicher fehlgeschlagen.');
}

$diagnosticPhase = 'auth';
require_admin();
$diagnosticPhase = 'ready';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$sessionKey = 'mztech_portal_diagnostic_' . DIAGNOSTIC_RUNNER_ID;
if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
    $_SESSION[$sessionKey] = [
        'nonce' => bin2hex(random_bytes(32)),
        'expires_at' => time() + 1800,
        'used' => false,
        'state' => 'ready',
        'summary' => null,
    ];
}
$diagnostic = &$_SESSION[$sessionKey];

function diagnostic_checks(): array
{
    $json = base64_decode(DIAGNOSTIC_CHECKS_B64, true);
    if ($json === false || !hash_equals(DIAGNOSTIC_CHECKS_HASH, hash('sha256', $json))) {
        throw new RuntimeException('diagnostic_integrity');
    }
    $checks = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($checks) || count($checks) !== 9) {
        throw new RuntimeException('diagnostic_integrity');
    }
    return $checks;
}

function diagnostic_read_only_sql(array $check): string
{
    $sql = base64_decode((string) ($check['sql_b64'] ?? ''), true);
    if ($sql === false || !hash_equals((string) ($check['sha256'] ?? ''), hash('sha256', $sql))) {
        throw new RuntimeException('diagnostic_integrity');
    }
    $trimmed = rtrim(trim($sql), ';');
    if (!preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i', $trimmed)) {
        throw new RuntimeException('non_read_only_statement');
    }
    if (str_contains($trimmed, ';') ||
        preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|CALL|DO|SET|LOAD|HANDLER|GRANT|REVOKE|LOCK|UNLOCK)\b/i', $trimmed)) {
        throw new RuntimeException('non_read_only_statement');
    }
    return $trimmed;
}

function diagnostic_sqlstate(PDOException $error): string
{
    $state = (string) ($error->errorInfo[0] ?? $error->getCode());
    return preg_match('/^[A-Z0-9]{5}$/', $state) ? $state : 'NICHT_VERFUEGBAR';
}

function diagnostic_error_category(PDOException $error): string
{
    $driverCode = (int) ($error->errorInfo[1] ?? 0);
    return match ($driverCode) {
        1044, 1045, 1142, 1143 => 'permission_or_authentication',
        1049 => 'database_context',
        1064 => 'sql_syntax',
        1146 => 'missing_table',
        1054 => 'missing_column',
        2002, 2003, 2006, 2013 => 'database_connection',
        default => match (diagnostic_sqlstate($error)) {
            '42S02' => 'missing_table',
            '42S22' => 'missing_column',
            '42000' => 'sql_syntax_or_permission',
            '28000' => 'permission_or_authentication',
            default => 'database_query',
        },
    };
}

function diagnostic_allowed_status(int $checkNumber, string $status): bool
{
    $allowed = [
        2 => ['VORHANDEN', 'FEHLT'],
        3 => ['VORHANDEN', 'NEUE_TABELLE'],
        4 => ['VORHANDEN', 'FEHLT'],
        5 => ['VORHANDEN', 'FEHLT'],
        6 => ['VORHANDEN', 'NEUE_TABELLE', 'FEHLT'],
        7 => ['VORHANDEN', 'MIGRATION_ERFORDERLICH'],
        8 => ['VORHANDEN', 'ENGINE_ABWEICHUNG', 'COLLATION_ABWEICHUNG'],
        9 => ['VORHANDEN', 'FEHLT'],
    ];
    return in_array($status, $allowed[$checkNumber] ?? [], true);
}

function diagnostic_run(): array
{
    global $diagnosticPhase;
    $summary = [
        'database_connection' => 'NEIN',
        'database_type_version_recognized' => 'NEIN',
        'database_type' => 'UNBEKANNT',
        'database_version' => 'UNBEKANNT',
        'failed_check' => null,
        'sqlstate' => '00000',
        'error_category' => 'none',
        'affected_object' => null,
        'preflight_candidate' => 'OK',
        'checks_completed' => 0,
        'planned_changes' => 0,
    ];
    $issues = [];

    $diagnosticPhase = 'database_connection';
    try {
        $db = get_db();
        $probe = $db->query('SELECT 1 AS connection_probe');
        $probe->fetch(PDO::FETCH_ASSOC);
        $probe->closeCursor();
        $summary['database_connection'] = 'JA';
    } catch (PDOException $error) {
        $summary['failed_check'] = 0;
        $summary['sqlstate'] = diagnostic_sqlstate($error);
        $summary['error_category'] = diagnostic_error_category($error);
        $summary['affected_object'] = 'Datenbankverbindung';
        $summary['preflight_candidate'] = 'FEHLER';
        return $summary;
    }

    foreach (diagnostic_checks() as $check) {
        $number = (int) ($check['number'] ?? 0);
        $scope = (string) ($check['scope'] ?? 'Unbekannte Prüfung');
        try {
            $sql = diagnostic_read_only_sql($check);
            $statement = $db->query($sql);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
        } catch (PDOException $error) {
            $summary['failed_check'] = $number;
            $summary['sqlstate'] = diagnostic_sqlstate($error);
            $summary['error_category'] = diagnostic_error_category($error);
            $summary['affected_object'] = $scope;
            $summary['preflight_candidate'] = 'FEHLER';
            return $summary;
        } catch (Throwable) {
            $summary['failed_check'] = $number;
            $summary['sqlstate'] = 'NICHT_VERFUEGBAR';
            $summary['error_category'] = 'runner_validation';
            $summary['affected_object'] = $scope;
            $summary['preflight_candidate'] = 'FEHLER';
            return $summary;
        }

        if ($number === 1) {
            $row = $rows[0] ?? [];
            $version = (string) ($row['database_version'] ?? '');
            $comment = (string) ($row['database_comment'] ?? '');
            $type = stripos($version . ' ' . $comment, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
            if (($row['active_schema'] ?? '') === '' ||
                !preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $matches)) {
                $issues[] = [
                    'check' => 1,
                    'sqlstate' => '00000',
                    'category' => 'database_context_or_version',
                    'object' => 'Aktives Schema/Datenbankversion',
                ];
            } else {
                $summary['database_type'] = $type;
                $summary['database_version'] = $matches[1] . '.' . $matches[2] .
                    (isset($matches[3]) ? '.' . $matches[3] : '');
                $summary['database_type_version_recognized'] = 'JA';
            }
            $summary['checks_completed']++;
            continue;
        }

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $object = (string) ($row['object_name'] ?? $scope);
            if (!diagnostic_allowed_status($number, $status) ||
                !preg_match('/^[A-Za-z0-9_. -]{1,190}$/', $object)) {
                $issues[] = [
                    'check' => $number,
                    'sqlstate' => '00000',
                    'category' => 'unexpected_result_shape',
                    'object' => $scope,
                ];
                continue;
            }
            if ($number === 7 && $status === 'MIGRATION_ERFORDERLICH') {
                $summary['planned_changes']++;
                continue;
            }
            $isFailure = ($number === 2 || $number === 4 || $number === 5 || $number === 9) &&
                $status === 'FEHLT';
            $isFailure = $isFailure || ($number === 6 && $status === 'FEHLT');
            $isFailure = $isFailure || ($number === 8 && $status !== 'VORHANDEN');
            if ($isFailure) {
                $issues[] = [
                    'check' => $number,
                    'sqlstate' => '00000',
                    'category' => $status === 'FEHLT' ? 'missing_schema_object' : 'schema_compatibility',
                    'object' => $object,
                ];
            }
        }
        $summary['checks_completed']++;
    }

    if ($issues !== []) {
        $first = $issues[0];
        $summary['failed_check'] = $first['check'];
        $summary['sqlstate'] = $first['sqlstate'];
        $summary['error_category'] = $first['category'];
        $summary['affected_object'] = $first['object'];
        $summary['preflight_candidate'] = 'FEHLER';
        $summary['issue_count'] = count($issues);
    } else {
        $summary['issue_count'] = 0;
    }
    $diagnosticPhase = 'complete';
    return $summary;
}

$notice = '';
if (time() > (int) $diagnostic['expires_at'] && $diagnostic['state'] === 'ready') {
    $diagnostic['state'] = 'expired';
    $diagnostic['summary'] = [
        'database_connection' => 'UNBEKANNT',
        'database_type_version_recognized' => 'NEIN',
        'failed_check' => null,
        'sqlstate' => 'NICHT_AUSGEFUEHRT',
        'error_category' => 'runner_expired',
        'affected_object' => null,
        'preflight_candidate' => 'NICHT_AUSGEFUEHRT',
    ];
    diagnostic_write_status('expired', $diagnostic['summary']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $diagnostic['state'] === 'ready') {
    verify_csrf();
    $nonce = (string) ($_POST['diagnostic_nonce'] ?? '');
    if ($diagnostic['used'] || $nonce === '' ||
        !hash_equals((string) $diagnostic['nonce'], $nonce)) {
        http_response_code(400);
        $notice = 'Der einmalige Diagnose-Nonce ist ungültig oder bereits verbraucht.';
    } else {
        $diagnostic['used'] = true;
        $diagnostic['nonce'] = '';
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'abort') {
            $diagnostic['state'] = 'aborted';
            $diagnostic['summary'] = [
                'database_connection' => 'NICHT_AUSGEFUEHRT',
                'database_type_version_recognized' => 'NEIN',
                'failed_check' => null,
                'sqlstate' => 'NICHT_AUSGEFUEHRT',
                'error_category' => 'user_abort',
                'affected_object' => null,
                'preflight_candidate' => 'NICHT_AUSGEFUEHRT',
            ];
            diagnostic_write_status('aborted', $diagnostic['summary']);
            $notice = 'Die Diagnose wurde ausdrücklich abgebrochen.';
        } elseif ($action === 'diagnose') {
            diagnostic_write_status('running', [
                'database_connection' => 'UNBEKANNT',
                'database_type_version_recognized' => 'NEIN',
                'failed_check' => null,
                'sqlstate' => '00000',
                'error_category' => 'running',
                'affected_object' => null,
                'preflight_candidate' => 'UNBEKANNT',
            ]);
            $diagnostic['summary'] = diagnostic_run();
            $diagnostic['state'] = $diagnostic['summary']['preflight_candidate'] === 'OK'
                ? 'diagnostic_complete'
                : 'failed';
            $diagnosticTerminal = true;
            diagnostic_write_status($diagnostic['state'], $diagnostic['summary']);
            $notice = $diagnostic['state'] === 'diagnostic_complete'
                ? 'Alle neun rein lesenden Diagnoseprüfungen wurden ausgeführt.'
                : 'Die Diagnose wurde mit einer bereinigten Fehlerursache beendet.';
        } else {
            http_response_code(400);
            $notice = 'Nicht erlaubte Diagnoseaktion.';
        }
    }
}

$remainingMinutes = max(0, (int) ceil(((int) $diagnostic['expires_at'] - time()) / 60));
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Portal-Preflight-Diagnose</title>
  <style>
    body{font-family:system-ui,sans-serif;background:#111827;color:#e5e7eb;margin:0;padding:2rem}
    main{max-width:760px;margin:auto;background:#1f2937;padding:2rem;border-radius:12px}
    .box{padding:1rem;background:#111827;border-radius:8px;margin:1rem 0}
    button{padding:.8rem 1.2rem;border:0;border-radius:7px;font-weight:700;margin:.3rem}
    .run{background:#2563eb;color:#fff}.abort{background:#4b5563;color:#fff}
  </style>
</head>
<body>
<main>
  <h1>Rein lesende Portal-Preflight-Diagnose</h1>
  <div class="box">Status: <strong><?= h((string) $diagnostic['state']) ?></strong></div>
  <p>Der Runner enthält genau neun nummerierte Prüfungen und erlaubt technisch nur SELECT, SHOW oder DESCRIBE.</p>
  <p>Verbleibende Verfügbarkeit: mindestens <?= (int) $remainingMinutes ?> Minuten.</p>
  <?php if ($notice !== ''): ?><div class="box"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($diagnostic['state'] === 'ready'): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="diagnostic_nonce" value="<?= h((string) $diagnostic['nonce']) ?>">
      <button class="run" type="submit" name="action" value="diagnose">Neun rein lesende Prüfungen ausführen</button>
      <button class="abort" type="submit" name="action" value="abort">Ausdrücklich abbrechen</button>
    </form>
  <?php else: ?>
    <p>Die Diagnose ist beendet. Es sind keine weiteren Aktionen verfügbar.</p>
  <?php endif; ?>
</main>
</body>
</html>
