<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_admin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

const DEPLOYMENT_RUNNER_ID = '__RUNNER_ID__';
const DEPLOYMENT_SCOPE = '__DEPLOYMENT_SCOPE__';
const DEPLOYMENT_TOKEN_HASH = '__TOKEN_HASH__';
const DEPLOYMENT_STATUS_FILE = '__STATUS_FILE__';
const PORTAL_PREFLIGHT_SQL_B64 = '__PORTAL_PREFLIGHT_B64__';
const PORTAL_PREFLIGHT_SQL_HASH = '__PORTAL_PREFLIGHT_HASH__';
const PORTAL_MIGRATION_SQL_B64 = '__PORTAL_MIGRATION_B64__';
const PORTAL_MIGRATION_SQL_HASH = '__PORTAL_MIGRATION_HASH__';
const PORTAL_POSTCHECK_SQL_B64 = '__PORTAL_POSTCHECK_B64__';
const PORTAL_POSTCHECK_SQL_HASH = '__PORTAL_POSTCHECK_HASH__';
const FONEDAY_PREFLIGHT_SQL_B64 = '__FONEDAY_PREFLIGHT_B64__';
const FONEDAY_PREFLIGHT_SQL_HASH = '__FONEDAY_PREFLIGHT_HASH__';
const FONEDAY_MIGRATION_SQL_B64 = '__FONEDAY_MIGRATION_B64__';
const FONEDAY_MIGRATION_SQL_HASH = '__FONEDAY_MIGRATION_HASH__';
const FONEDAY_POSTCHECK_SQL_B64 = '__FONEDAY_POSTCHECK_B64__';
const FONEDAY_POSTCHECK_SQL_HASH = '__FONEDAY_POSTCHECK_HASH__';

$sessionKey = 'mztech_deployment_' . DEPLOYMENT_RUNNER_ID;
if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
    $_SESSION[$sessionKey] = [
        'state' => 'ready',
        'token_verified' => false,
        'token_attempts' => 0,
        'session_nonce' => bin2hex(random_bytes(32)),
        'session_nonce_used' => false,
        'summary' => null,
    ];
}
$deployment = &$_SESSION[$sessionKey];
$statusPath = dirname(__DIR__) . '/private/' . DEPLOYMENT_STATUS_FILE;

function deployment_write_status(string $path, string $state, array $summary = []): void
{
    $payload = [
        'runner_id' => DEPLOYMENT_RUNNER_ID,
        'state' => $state,
        'summary' => $summary,
        'updated_at' => gmdate('c'),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Der bereinigte Deployment-Status konnte nicht geschrieben werden.');
    }
    @chmod($path, 0600);
}

function deployment_sql(string $step): string
{
    $allowed = [
        'portal_preflight' => [PORTAL_PREFLIGHT_SQL_B64, PORTAL_PREFLIGHT_SQL_HASH],
        'portal_migration' => [PORTAL_MIGRATION_SQL_B64, PORTAL_MIGRATION_SQL_HASH],
        'portal_postcheck' => [PORTAL_POSTCHECK_SQL_B64, PORTAL_POSTCHECK_SQL_HASH],
        'foneday_preflight' => [FONEDAY_PREFLIGHT_SQL_B64, FONEDAY_PREFLIGHT_SQL_HASH],
        'foneday_migration' => [FONEDAY_MIGRATION_SQL_B64, FONEDAY_MIGRATION_SQL_HASH],
        'foneday_postcheck' => [FONEDAY_POSTCHECK_SQL_B64, FONEDAY_POSTCHECK_SQL_HASH],
    ];
    if (!isset($allowed[$step])) {
        throw new RuntimeException('Nicht erlaubter Deployment-Schritt.');
    }
    $sql = base64_decode($allowed[$step][0], true);
    if ($sql === false || !hash_equals($allowed[$step][1], hash('sha256', $sql))) {
        throw new RuntimeException('Integritätsprüfung der freigegebenen SQL-Datei fehlgeschlagen.');
    }
    return $sql;
}

function deployment_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($sql);
    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $next !== '') {
                $buffer .= $next;
                $index++;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }
        if ($char === '#' || ($char === '-' && $next === '-' &&
            ($index + 2 >= $length || ctype_space($sql[$index + 2])))) {
            while ($index < $length && $sql[$index] !== "\n") {
                $index++;
            }
            $buffer .= "\n";
            continue;
        }
        if ($char === '/' && $next === '*') {
            $index += 2;
            while ($index + 1 < $length && !($sql[$index] === '*' && $sql[$index + 1] === '/')) {
                $index++;
            }
            $index++;
            $buffer .= ' ';
            continue;
        }
        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if ($quote !== null) {
        throw new RuntimeException('Nicht abgeschlossene Zeichenkette in der freigegebenen SQL-Datei.');
    }
    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }
    return $statements;
}

function deployment_execute_sql(PDO $db, string $step): array
{
    $rows = [];
    $resultSets = [];
    $statements = deployment_split_sql(deployment_sql($step));
    foreach ($statements as $index => $statement) {
        $checkNumber = $index + 1;
        $keyword = strtoupper((string) strtok(ltrim($statement), " \t\r\n"));
        if ($step === 'portal_preflight' &&
            !in_array($keyword, ['SELECT', 'SHOW', 'DESCRIBE'], true)) {
            throw new RuntimeException(
                'Portal-Preflight-Prüfung ' . $checkNumber .
                ' SQLSTATE NICHT_VERFUEGBAR Kategorie non_read_only_statement'
            );
        }
        try {
            if (in_array($keyword, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'], true)) {
                $query = $db->query($statement);
                $queryRows = $query->fetchAll(PDO::FETCH_ASSOC);
                $query->closeCursor();
                foreach ($queryRows as $row) {
                    $rows[] = $row;
                }
                $resultSets[] = ['check_number' => $checkNumber, 'rows' => $queryRows];
            } else {
                $db->exec($statement);
                $resultSets[] = ['check_number' => $checkNumber, 'rows' => []];
            }
        } catch (PDOException $error) {
            $state = (string) ($error->errorInfo[0] ?? $error->getCode());
            if (!preg_match('/^[A-Z0-9]{5}$/', $state)) {
                $state = 'NICHT_VERFUEGBAR';
            }
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            $category = match ($driverCode) {
                1044, 1045, 1142, 1143 => 'permission_or_authentication',
                1049 => 'database_context',
                1064 => 'sql_syntax',
                1146 => 'missing_table',
                1054 => 'missing_column',
                2002, 2003, 2006, 2013 => 'database_connection',
                default => 'database_query',
            };
            throw new RuntimeException(
                'Portal-Preflight-Prüfung ' . $checkNumber .
                ' SQLSTATE ' . $state . ' Kategorie ' . $category
            );
        }
    }
    return [
        'rows' => $rows,
        'result_sets' => $resultSets,
        'statements' => count($statements),
    ];
}

function deployment_validate_preflight(array $execution): array
{
    $rows = $execution['rows'] ?? [];
    $resultSets = $execution['result_sets'] ?? [];
    if (($execution['statements'] ?? 0) !== 9 || count($resultSets) !== 9) {
        throw new RuntimeException(
            'Portal-Preflight-Prüfung 0 SQLSTATE NICHT_VERFUEGBAR Kategorie resultset_count'
        );
    }
    foreach ($resultSets as $index => $resultSet) {
        if (($resultSet['check_number'] ?? 0) !== $index + 1 ||
            !is_array($resultSet['rows'] ?? null)) {
            throw new RuntimeException(
                'Portal-Preflight-Prüfung ' . ($index + 1) .
                ' SQLSTATE NICHT_VERFUEGBAR Kategorie resultset_mapping'
            );
        }
    }
    $context = array_values(array_filter($rows, static fn(array $row): bool =>
        array_key_exists('context_status', $row)
    ));
    if (count($context) !== 1 || ($context[0]['context_status'] ?? '') !== 'OK' ||
        !hash_equals((string) DB_NAME, (string) ($context[0]['active_schema'] ?? ''))) {
        throw new RuntimeException('Der Datenbankkontext ist nicht eindeutig korrekt.');
    }

    $requiredTables = [
        'users', 'customers', 'repairs', 'settings', 'email_templates',
        'customer_portal_access', 'customer_accounts', 'portal_login_attempts',
    ];
    $missing = [];
    foreach ($requiredTables as $table) {
        $matches = array_values(array_filter($rows, static fn(array $row): bool =>
            ($row['TABLE_NAME'] ?? '') === $table &&
            array_key_exists('status', $row) &&
            !array_key_exists('COLUMN_NAME', $row)
        ));
        if (count($matches) !== 1 || ($matches[0]['status'] ?? '') !== 'VORHANDEN') {
            $missing[] = $table;
        }
    }

    foreach ($rows as $row) {
        if (in_array($row['requirement'] ?? '', ['BASE_COLUMN', 'UNIQUE_INDEX', 'EXISTING_TARGET_COLUMN'], true) &&
            !in_array($row['status'] ?? '', ['VORHANDEN', 'NEUE_TABELLE'], true)) {
            $missing[] = ($row['TABLE_NAME'] ?? 'Tabelle') . '.' . ($row['COLUMN_NAME'] ?? 'Objekt');
        }
        if (($row['requirement'] ?? '') === 'ENGINE_OR_COLLATION') {
            $missing[] = (string) ($row['TABLE_NAME'] ?? 'Engine/Collation');
        }
    }

    $marker = array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['result'] ?? '') === 'PREFLIGHT_READ_ONLY_COMPLETE'
    ));
    if (count($marker) !== 1 || !hash_equals((string) DB_NAME, (string) ($marker[0]['checked_schema'] ?? ''))) {
        $missing[] = 'Preflight-Abschlussmarker';
    }
    if ($missing !== []) {
        throw new RuntimeException('Preflight-Voraussetzungen weichen ab: ' . implode(', ', array_unique($missing)));
    }

    return [
        'check' => 'preflight',
        'status' => 'OK',
        'required_tables' => count($requiredTables),
        'deviations' => 0,
    ];
}

function deployment_validate_migration(array $rows, int $statementCount): array
{
    $marker = array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['result'] ?? '') === 'PORTAL_TICKET_MIGRATION_COMPLETE'
    ));
    if (count($marker) !== 1 || !hash_equals((string) DB_NAME, (string) ($marker[0]['active_schema'] ?? ''))) {
        throw new RuntimeException('Der Migrations-Abschlussmarker fehlt.');
    }
    return [
        'check' => 'migration',
        'status' => 'OK',
        'statements' => $statementCount,
    ];
}

function deployment_validate_postcheck(array $rows): array
{
    $missing = [];
    $violations = 0;
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'FEHLT') {
            $missing[] = ($row['TABLE_NAME'] ?? 'Tabelle') . '.' .
                ($row['COLUMN_NAME'] ?? $row['INDEX_NAME'] ?? 'Objekt');
        }
        if (array_key_exists('violations', $row) && $row['violations'] !== null) {
            $violations += (int) $row['violations'];
        }
    }
    $context = array_values(array_filter($rows, static fn(array $row): bool =>
        array_key_exists('context_status', $row)
    ));
    $marker = array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['result'] ?? '') === 'POSTCHECK_READ_ONLY_COMPLETE'
    ));
    if (count($context) !== 1 || ($context[0]['context_status'] ?? '') !== 'OK' ||
        !hash_equals((string) DB_NAME, (string) ($context[0]['active_schema'] ?? ''))) {
        $missing[] = 'Datenbankkontext';
    }
    if (count($marker) !== 1 || !hash_equals((string) DB_NAME, (string) ($marker[0]['checked_schema'] ?? ''))) {
        $missing[] = 'Postcheck-Abschlussmarker';
    }
    if ($missing !== [] || $violations !== 0) {
        throw new RuntimeException('Postcheck meldet Abweichungen: ' . implode(', ', array_unique($missing)));
    }
    return [
        'check' => 'postcheck',
        'status' => 'OK',
        'missing_objects' => 0,
        'token_violations' => 0,
    ];
}

function deployment_validate_foneday_preflight(array $rows): array
{
    $problems = [];
    $context = array_values(array_filter($rows, static fn(array $row): bool =>
        array_key_exists('context_status', $row)
    ));
    if (count($context) !== 1 || ($context[0]['context_status'] ?? '') !== 'OK' ||
        !hash_equals((string) DB_NAME, (string) ($context[0]['active_schema'] ?? ''))) {
        $problems[] = 'Datenbankkontext';
    }
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'FEHLT') {
            $problems[] = ($row['TABLE_NAME'] ?? 'Tabelle') . '.' .
                ($row['COLUMN_NAME'] ?? 'Objekt');
        }
        if (isset($row['duplicate_count']) && (int) $row['duplicate_count'] > 1) {
            $problems[] = 'Doppelte Foneday-Lieferanten-SKU';
        }
        if (isset($row['ENGINE']) && isset($row['TABLE_COLLATION'])) {
            $problems[] = 'Engine/Collation:' . ($row['TABLE_NAME'] ?? 'Tabelle');
        }
    }
    $supplierCounts = array_values(array_filter($rows, static fn(array $row): bool =>
        array_key_exists('foneday_supplier_count', $row)
    ));
    if (count($supplierCounts) !== 1 || (int) $supplierCounts[0]['foneday_supplier_count'] !== 1) {
        $problems[] = 'Bestehender Foneday-Lieferant nicht eindeutig';
    }
    $marker = array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['result'] ?? '') === 'FONEDAY_PREFLIGHT_READ_ONLY_COMPLETE'
    ));
    if (count($marker) !== 1 ||
        !hash_equals((string) DB_NAME, (string) ($marker[0]['checked_schema'] ?? ''))) {
        $problems[] = 'Foneday-Preflight-Abschlussmarker';
    }
    if ($problems !== []) {
        throw new RuntimeException('Preflight-Voraussetzungen weichen ab: ' .
            implode(', ', array_unique($problems)));
    }
    return ['check' => 'foneday_preflight', 'status' => 'OK', 'deviations' => 0];
}

function deployment_validate_foneday_migration(array $rows, int $statementCount): array
{
    $marker = array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['result'] ?? '') === 'FONEDAY_MIGRATION_COMPLETE'
    ));
    if (count($marker) !== 1 ||
        !hash_equals((string) DB_NAME, (string) ($marker[0]['active_schema'] ?? ''))) {
        throw new RuntimeException('Der Foneday-Migrations-Abschlussmarker fehlt.');
    }
    return ['check' => 'foneday_migration', 'status' => 'OK', 'statements' => $statementCount];
}

function deployment_validate_foneday_postcheck(array $rows): array
{
    $problems = [];
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'FEHLT') {
            $problems[] = ($row['TABLE_NAME'] ?? 'Tabelle') . '.' .
                ($row['COLUMN_NAME'] ?? $row['INDEX_NAME'] ?? 'Objekt');
        }
    }
    $context = array_values(array_filter($rows, static fn(array $row): bool =>
        array_key_exists('context_status', $row)
    ));
    if (count($context) !== 1 || ($context[0]['context_status'] ?? '') !== 'OK' ||
        !hash_equals((string) DB_NAME, (string) ($context[0]['active_schema'] ?? ''))) {
        $problems[] = 'Datenbankkontext';
    }
    $supplierCounts = array_values(array_filter($rows, static fn(array $row): bool =>
        array_key_exists('foneday_supplier_count', $row)
    ));
    if (count($supplierCounts) !== 1 || (int) $supplierCounts[0]['foneday_supplier_count'] !== 1) {
        $problems[] = 'Bestehender Foneday-Lieferant nicht eindeutig';
    }
    $marker = array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['result'] ?? '') === 'FONEDAY_POSTCHECK_READ_ONLY_COMPLETE'
    ));
    if (count($marker) !== 1 ||
        !hash_equals((string) DB_NAME, (string) ($marker[0]['checked_schema'] ?? ''))) {
        $problems[] = 'Foneday-Postcheck-Abschlussmarker';
    }
    if ($problems !== []) {
        throw new RuntimeException('Postcheck meldet Abweichungen: ' .
            implode(', ', array_unique($problems)));
    }
    return ['check' => 'foneday_postcheck', 'status' => 'OK', 'missing_objects' => 0];
}

function deployment_safe_message(Throwable $error): string
{
    $message = $error->getMessage();
    if (str_contains($message, 'Preflight-Voraussetzungen') ||
        str_contains($message, 'Postcheck meldet Abweichungen') ||
        str_contains($message, 'Integritätsprüfung') ||
        str_contains($message, 'Datenbankkontext') ||
        str_contains($message, 'Abschlussmarker') ||
        str_starts_with($message, 'Portal-Preflight-Prüfung ')) {
        return htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return 'Der Schritt ist fehlgeschlagen. Es wurden keine weiteren Schritte ausgeführt.';
}

function deployment_safe_failure_summary(Throwable $error, string $step): array
{
    $message = $error->getMessage();
    $diagnostic = 'Nicht näher offengelegter sicherer Fehler';
    foreach ([
        'Preflight-Voraussetzungen weichen ab:',
        'Postcheck meldet Abweichungen:',
        'Der Datenbankkontext ist nicht eindeutig korrekt.',
        'Der Migrations-Abschlussmarker fehlt.',
        'Der Foneday-Migrations-Abschlussmarker fehlt.',
        'Portal-Preflight-Prüfung ',
    ] as $allowedPrefix) {
        if (str_starts_with($message, $allowedPrefix)) {
            $diagnostic = $message;
            break;
        }
    }
    return ['status' => 'FEHLER', 'step' => $step, 'diagnostic' => $diagnostic];
}

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (($_POST['confirm'] ?? '') !== 'YES') {
        http_response_code(400);
        $notice = 'Der Schritt wurde nicht ausdrücklich bestätigt.';
    } else {
        try {
            if ($action === 'portal_preflight' && $deployment['state'] === 'ready') {
                if (in_array(DEPLOYMENT_SCOPE, ['portal', 'preflight'], true)) {
                    $providedNonce = (string) ($_POST['deployment_nonce'] ?? '');
                    if ($deployment['session_nonce_used'] ||
                        $providedNonce === '' ||
                        !hash_equals((string) $deployment['session_nonce'], $providedNonce)) {
                        throw new RuntimeException('Der einmalige Sitzungs-Nonce ist ungültig.');
                    }
                    $deployment['session_nonce_used'] = true;
                    $deployment['session_nonce'] = '';
                    unset($providedNonce);
                } else {
                    if ((int) $deployment['token_attempts'] >= 5) {
                        throw new RuntimeException('Deployment-Token ist gesperrt.');
                    }
                    $providedToken = (string) ($_POST['deployment_token'] ?? '');
                    $deployment['token_attempts']++;
                    if ($providedToken === '' ||
                        !hash_equals(DEPLOYMENT_TOKEN_HASH, hash('sha256', $providedToken))) {
                        unset($providedToken);
                        throw new RuntimeException('Deployment-Token ist ungültig.');
                    }
                    unset($providedToken);
                }
                $deployment['token_verified'] = true;
                $result = deployment_execute_sql(get_db(), 'portal_preflight');
                $deployment['summary'] = deployment_validate_preflight($result);
                $deployment['state'] = DEPLOYMENT_SCOPE === 'preflight'
                    ? 'complete'
                    : 'portal_preflight_ok';
                deployment_write_status($statusPath, $deployment['state'], $deployment['summary']);
                $notice = DEPLOYMENT_SCOPE === 'preflight'
                    ? 'Portal-/Ticket-Preflight erfolgreich. Der Diagnose-Runner kann entfernt werden.'
                    : 'Portal-/Ticket-Preflight erfolgreich und ausschließlich lesend abgeschlossen.';
            } elseif ($action === 'portal_migration' && $deployment['state'] === 'portal_preflight_ok' &&
                $deployment['token_verified'] === true) {
                $result = deployment_execute_sql(get_db(), 'portal_migration');
                $deployment['summary'] = deployment_validate_migration($result['rows'], $result['statements']);
                $deployment['state'] = 'portal_migration_ok';
                deployment_write_status($statusPath, $deployment['state'], $deployment['summary']);
                $notice = 'Portal-/Ticket-Migration erfolgreich abgeschlossen.';
            } elseif ($action === 'portal_postcheck' && $deployment['state'] === 'portal_migration_ok' &&
                $deployment['token_verified'] === true) {
                $result = deployment_execute_sql(get_db(), 'portal_postcheck');
                $deployment['summary'] = deployment_validate_postcheck($result['rows']);
                $deployment['state'] = DEPLOYMENT_SCOPE === 'portal'
                    ? 'complete'
                    : 'portal_postcheck_ok';
                deployment_write_status($statusPath, $deployment['state'], $deployment['summary']);
                $notice = DEPLOYMENT_SCOPE === 'portal'
                    ? 'Portal-/Ticket-Postcheck erfolgreich. Der Runner kann jetzt entfernt werden.'
                    : 'Portal-/Ticket-Postcheck erfolgreich abgeschlossen.';
            } elseif ($action === 'foneday_preflight' && $deployment['state'] === 'portal_postcheck_ok' &&
                $deployment['token_verified'] === true) {
                $result = deployment_execute_sql(get_db(), 'foneday_preflight');
                $deployment['summary'] = deployment_validate_foneday_preflight($result['rows']);
                $deployment['state'] = 'foneday_preflight_ok';
                deployment_write_status($statusPath, $deployment['state'], $deployment['summary']);
                $notice = 'Foneday-Preflight erfolgreich und ausschließlich lesend abgeschlossen.';
            } elseif ($action === 'foneday_migration' && $deployment['state'] === 'foneday_preflight_ok' &&
                $deployment['token_verified'] === true) {
                $result = deployment_execute_sql(get_db(), 'foneday_migration');
                $deployment['summary'] = deployment_validate_foneday_migration(
                    $result['rows'],
                    $result['statements']
                );
                $deployment['state'] = 'foneday_migration_ok';
                deployment_write_status($statusPath, $deployment['state'], $deployment['summary']);
                $notice = 'Foneday-Migration erfolgreich abgeschlossen.';
            } elseif ($action === 'foneday_postcheck' && $deployment['state'] === 'foneday_migration_ok' &&
                $deployment['token_verified'] === true) {
                $result = deployment_execute_sql(get_db(), 'foneday_postcheck');
                $deployment['summary'] = deployment_validate_foneday_postcheck($result['rows']);
                $deployment['state'] = 'complete';
                deployment_write_status($statusPath, $deployment['state'], $deployment['summary']);
                $notice = 'Alle Postchecks sind erfolgreich. Der Runner kann jetzt entfernt werden.';
            } else {
                throw new RuntimeException('Schritt oder Reihenfolge ist nicht erlaubt.');
            }
        } catch (Throwable $error) {
            $deployment['state'] = 'failed';
            $deployment['summary'] = deployment_safe_failure_summary($error, $action);
            try {
                deployment_write_status($statusPath, 'failed', $deployment['summary']);
            } catch (Throwable) {
                // Keine Ausgabe interner Pfade oder Datenbankdetails.
            }
            http_response_code(500);
            $notice = deployment_safe_message($error);
        }
    }
}

$stateLabels = [
    'ready' => 'Bereit für den lesenden Portal-/Ticket-Preflight',
    'portal_preflight_ok' => 'Portal-/Ticket-Preflight erfolgreich',
    'portal_migration_ok' => 'Portal-/Ticket-Migration erfolgreich',
    'portal_postcheck_ok' => 'Portal-/Ticket-Postcheck erfolgreich',
    'foneday_preflight_ok' => 'Foneday-Preflight erfolgreich',
    'foneday_migration_ok' => 'Foneday-Migration erfolgreich',
    'complete' => 'Alle SQL-Schritte erfolgreich',
    'failed' => 'Gestoppt wegen eines Fehlers',
];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Temporärer MZ-Tech-Deployment-Runner</title>
  <style>
    body{font-family:system-ui,sans-serif;background:#111827;color:#e5e7eb;margin:0;padding:2rem}
    main{max-width:760px;margin:auto;background:#1f2937;padding:2rem;border-radius:12px}
    h1{font-size:1.4rem} .state,.notice{padding:1rem;border-radius:8px;background:#111827;margin:1rem 0}
    label{display:block;margin:.75rem 0} input[type=password]{width:100%;box-sizing:border-box;padding:.7rem}
    button{padding:.8rem 1.2rem;background:#2563eb;color:white;border:0;border-radius:7px;font-weight:700}
    .warning{color:#fbbf24}.failed{color:#fca5a5} code{color:#93c5fd}
  </style>
</head>
<body>
<main>
  <h1>Temporärer Portal-/Ticket-/Foneday-Deployment-Runner</h1>
  <p class="warning">Nur für die aktuelle, überwachte Produktivmigration. Keine freie SQL-Eingabe vorhanden.</p>
  <div class="state">Status: <strong><?= h($stateLabels[$deployment['state']] ?? 'Unbekannt') ?></strong></div>
  <?php if ($notice !== ''): ?><div class="notice <?= $deployment['state'] === 'failed' ? 'failed' : '' ?>"><?= $notice ?></div><?php endif; ?>

  <?php if ($deployment['state'] === 'ready'): ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="portal_preflight">
      <input type="hidden" name="confirm" value="YES">
      <?php if (in_array(DEPLOYMENT_SCOPE, ['portal', 'preflight'], true)): ?>
        <input type="hidden" name="deployment_nonce" value="<?= h((string) $deployment['session_nonce']) ?>">
      <?php else: ?>
        <label>Einmaliges Deployment-Token
          <input type="password" name="deployment_token" required autocomplete="one-time-code">
        </label>
      <?php endif; ?>
      <p>Dieser Schritt führt ausschließlich <code>portal_ticket_preflight.sql</code> lesend aus.</p>
      <button type="submit">Lesenden Preflight ausdrücklich ausführen</button>
    </form>
  <?php elseif ($deployment['state'] === 'portal_preflight_ok'): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="portal_migration">
      <input type="hidden" name="confirm" value="YES">
      <p>Preflight: OK. Der nächste Schritt führt ausschließlich <code>portal_ticket_migration.sql</code> aus.</p>
      <button type="submit">Migration ausdrücklich ausführen</button>
    </form>
  <?php elseif ($deployment['state'] === 'portal_migration_ok'): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="portal_postcheck">
      <input type="hidden" name="confirm" value="YES">
      <p>Migration: OK. Der nächste Schritt führt ausschließlich den lesenden <code>portal_ticket_postcheck.sql</code> aus.</p>
      <button type="submit">Postcheck ausdrücklich ausführen</button>
    </form>
  <?php elseif ($deployment['state'] === 'portal_postcheck_ok'): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="foneday_preflight">
      <input type="hidden" name="confirm" value="YES">
      <p>Portal-/Ticket-Postcheck: OK. Der nächste Schritt führt ausschließlich <code>foneday_preflight.sql</code> lesend aus.</p>
      <button type="submit">Foneday-Preflight ausdrücklich ausführen</button>
    </form>
  <?php elseif ($deployment['state'] === 'foneday_preflight_ok'): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="foneday_migration">
      <input type="hidden" name="confirm" value="YES">
      <p>Foneday-Preflight: OK. Der nächste Schritt führt ausschließlich <code>foneday_migration.sql</code> aus.</p>
      <button type="submit">Foneday-Migration ausdrücklich ausführen</button>
    </form>
  <?php elseif ($deployment['state'] === 'foneday_migration_ok'): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="foneday_postcheck">
      <input type="hidden" name="confirm" value="YES">
      <p>Foneday-Migration: OK. Der nächste Schritt führt ausschließlich <code>foneday_postcheck.sql</code> lesend aus.</p>
      <button type="submit">Foneday-Postcheck ausdrücklich ausführen</button>
    </form>
  <?php elseif ($deployment['state'] === 'complete'): ?>
    <p><?= DEPLOYMENT_SCOPE === 'preflight'
        ? 'Der ausschließlich lesende Preflight wurde geprüft.'
        : (DEPLOYMENT_SCOPE === 'portal'
            ? 'Alle drei Portal-/Ticket-SQL-Schritte wurden erfolgreich geprüft.'
            : 'Alle sechs SQL-Schritte wurden erfolgreich geprüft.') ?>
      Dieses temporäre Werkzeug muss jetzt entfernt werden.</p>
  <?php else: ?>
    <p>Der Ablauf ist gesperrt. Es werden keine weiteren SQL- oder Uploadschritte ausgeführt.</p>
  <?php endif; ?>
</main>
</body>
</html>
