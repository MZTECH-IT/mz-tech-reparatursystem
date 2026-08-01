<?php
declare(strict_types=1);

const RUNNER_TOKEN_HASH = '__TOKEN_HASH__';
const PREFLIGHT_SQL_B64 = '__PREFLIGHT_B64__';
const PREFLIGHT_SQL_HASH = '__PREFLIGHT_HASH__';
const MIGRATION_SQL_B64 = '__MIGRATION_B64__';
const MIGRATION_SQL_HASH = '__MIGRATION_HASH__';
const POSTCHECK_SQL_B64 = '__POSTCHECK_B64__';
const POSTCHECK_SQL_HASH = '__POSTCHECK_HASH__';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function runner_reply(bool $success, string $step, string $category = 'ok', array $extra = []): never {
    http_response_code($success ? 200 : 400);
    echo json_encode(array_merge(['success' => $success, 'step' => $step, 'category' => $category], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function runner_split_sql(string $sql): array {
    $statements = []; $buffer = ''; $quote = null; $length = strlen($sql);
    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index]; $next = $index + 1 < $length ? $sql[$index + 1] : '';
        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $next !== '') { $buffer .= $next; $index++; continue; }
            if ($char === $quote) {
                if ($next === $quote) { $buffer .= $next; $index++; } else { $quote = null; }
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $buffer .= $char; continue; }
        if ($char === '#' || ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2])))) {
            while ($index < $length && $sql[$index] !== "\n") $index++;
            $buffer .= "\n"; continue;
        }
        if ($char === '/' && $next === '*') {
            $index += 2;
            while ($index + 1 < $length && !($sql[$index] === '*' && $sql[$index + 1] === '/')) $index++;
            $index++; $buffer .= ' '; continue;
        }
        if ($char === ';') {
            $statement = trim($buffer); if ($statement !== '') $statements[] = $statement;
            $buffer = ''; continue;
        }
        $buffer .= $char;
    }
    if ($quote !== null) throw new RuntimeException('sql_parse');
    $statement = trim($buffer); if ($statement !== '') $statements[] = $statement;
    return $statements;
}

function runner_sql(string $step): string {
    $allowed = [
        'preflight' => [PREFLIGHT_SQL_B64, PREFLIGHT_SQL_HASH],
        'migration' => [MIGRATION_SQL_B64, MIGRATION_SQL_HASH],
        'postcheck' => [POSTCHECK_SQL_B64, POSTCHECK_SQL_HASH],
    ];
    if (!isset($allowed[$step])) throw new RuntimeException('step_invalid');
    $sql = base64_decode($allowed[$step][0], true);
    if ($sql === false || !hash_equals($allowed[$step][1], hash('sha256', $sql))) throw new RuntimeException('sql_integrity');
    return $sql;
}

function runner_execute_sql(PDO $db, string $step): array {
    $rows = []; $statements = runner_split_sql(runner_sql($step));
    foreach ($statements as $statement) {
        $keyword = strtoupper((string)strtok(ltrim($statement), " \t\r\n"));
        if ($step !== 'migration' && !in_array($keyword, ['SELECT','SHOW','DESCRIBE'], true)) throw new RuntimeException('non_read_only');
        if (in_array($keyword, ['SELECT','SHOW','DESCRIBE'], true)) {
            $query = $db->query($statement); $rows = array_merge($rows, $query->fetchAll(PDO::FETCH_ASSOC)); $query->closeCursor();
        } else {
            $db->exec($statement);
        }
    }
    return ['rows' => $rows, 'statements' => count($statements)];
}

function runner_validate_rows(string $step, array $execution): void {
    $rows = $execution['rows'];
    foreach ($rows as $row) {
        if (in_array(($row['status'] ?? ''), ['FEHLT','FEHLER','ABWEICHUNG'], true)) throw new RuntimeException('schema_deviation');
        foreach ($row as $key => $value) {
            if (str_starts_with((string)$key, 'plaintext_') && (int)$value !== 0) throw new RuntimeException('plaintext_token_present');
        }
    }
    $expected = [
        'preflight' => 'ACTIVATION_EMAIL_PREFLIGHT_COMPLETE',
        'migration' => 'ACTIVATION_EMAIL_MIGRATION_COMPLETE',
        'postcheck' => 'ACTIVATION_EMAIL_POSTCHECK_COMPLETE',
    ][$step];
    foreach ($rows as $row) if (($row['result'] ?? '') === $expected) return;
    throw new RuntimeException('completion_marker_missing');
}

function runner_smtp_configured(): bool {
    $port = (int)get_setting('smtp_port', '0');
    return trim(get_setting('smtp_host', '')) !== ''
        && trim(get_setting('smtp_user', '')) !== ''
        && smtp_password_get() !== ''
        && filter_var(get_setting('smtp_from_email', ''), FILTER_VALIDATE_EMAIL)
        && in_array(get_setting('smtp_encryption', ''), ['starttls','smtps'], true)
        && $port > 0 && $port <= 65535;
}

function runner_smtp_test(string $recipient): array {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !runner_smtp_configured()) return ['success' => false, 'category' => 'configuration'];
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) return ['success' => false, 'category' => 'library_missing'];
    require_once $autoload;
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $port = (int)get_setting('smtp_port', '587');
        $mail->isSMTP();
        $mail->Host = get_setting('smtp_host', '');
        $mail->SMTPAuth = true;
        $mail->Username = get_setting('smtp_user', '');
        $mail->Password = smtp_password_get();
        $mail->SMTPSecure = get_setting('smtp_encryption', 'starttls') === 'smtps'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $port;
        $mail->Timeout = 20;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(get_setting('smtp_from_email', ''), get_setting('smtp_from_name', 'MZ Tech'));
        $mail->addAddress($recipient, 'MZ Tech TEST');
        $mail->Subject = 'MZ Tech – sicherer SMTP-Test';
        $mail->Body = '<p>Dies ist eine ausdrücklich angeforderte TEST-E-Mail für den MZ-Tech-Aktivierungsversand.</p>';
        $mail->AltBody = 'Dies ist eine ausdrücklich angeforderte TEST-E-Mail für den MZ-Tech-Aktivierungsversand.';
        $mail->isHTML(true);
        $mail->send();
        return ['success' => true, 'category' => 'ok'];
    } catch (Throwable $e) {
        $text = strtolower($mail->ErrorInfo . ' ' . $e->getMessage());
        $category = str_contains($text, 'authenticate') ? 'authentication'
            : (str_contains($text, 'connect') ? 'connection'
            : (str_contains($text, 'certificate') || str_contains($text, 'crypto') ? 'tls' : 'smtp'));
        return ['success' => false, 'category' => $category];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') runner_reply(false, 'request', 'https_required');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) runner_reply(false, 'request', 'request_too_large');
$token = (string)($_POST['deployment_token'] ?? '');
if ($token === '' || !hash_equals(RUNNER_TOKEN_HASH, hash('sha256', $token))) runner_reply(false, 'authentication', 'forbidden');
$action = (string)($_POST['action'] ?? '');

$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';

try {
    if (in_array($action, ['preflight','migration','postcheck'], true)) {
        $execution = runner_execute_sql(get_db(), $action);
        runner_validate_rows($action, $execution);
        runner_reply(true, $action, 'ok', ['statements' => $execution['statements']]);
    }
    if ($action === 'configure') {
        $metadata = json_decode((string)($_POST['metadata'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
        $password = (string)($_POST['smtp_password'] ?? '');
        $required = ['host','port','encryption','username','from_email','from_name'];
        foreach ($required as $key) if (!isset($metadata[$key]) || trim((string)$metadata[$key]) === '') throw new RuntimeException('configuration_invalid');
        $port = (int)$metadata['port']; $mode = strtolower((string)$metadata['encryption']);
        if ($password === '' || $port < 1 || $port > 65535 || !in_array($mode, ['starttls','smtps'], true)
            || !filter_var($metadata['from_email'], FILTER_VALIDATE_EMAIL)
            || empty($metadata['smtp_auth']) || empty($metadata['certificate_validation'])) throw new RuntimeException('configuration_invalid');
        set_setting('smtp_host', trim((string)$metadata['host']));
        set_setting('smtp_port', (string)$port);
        set_setting('smtp_user', trim((string)$metadata['username']));
        set_setting('smtp_from_email', trim((string)$metadata['from_email']));
        set_setting('smtp_from_name', trim((string)$metadata['from_name']));
        set_setting('smtp_encryption', $mode);
        smtp_password_set($password);
        set_setting('smtp_pass', '');
        set_setting('portal_email_delivery_enabled', '0');
        $password = '';
        unset($_POST['smtp_password']);
        if (!runner_smtp_configured()) throw new RuntimeException('configuration_validation_failed');
        runner_reply(true, 'configure');
    }
    if ($action === 'test') {
        $result = runner_smtp_test(trim((string)($_POST['test_recipient'] ?? '')));
        if (!$result['success']) runner_reply(false, 'test', $result['category']);
        set_setting('smtp_last_test_success_at', gmdate('Y-m-d H:i:s'));
        runner_reply(true, 'test');
    }
    if ($action === 'enable') {
        $lastTest = strtotime(get_setting('smtp_last_test_success_at', ''));
        if (!runner_smtp_configured() || !$lastTest || $lastTest < time() - 86400 || ($_POST['test_confirmed'] ?? '') !== '1') {
            runner_reply(false, 'enable', 'confirmed_test_required');
        }
        set_setting('portal_email_delivery_enabled', '1');
        runner_reply(true, 'enable');
    }
    if ($action === 'disable') {
        set_setting('portal_email_delivery_enabled', '0');
        runner_reply(true, 'disable');
    }
    if ($action === 'status') {
        $table = get_db()->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_activation_mail_log'"
        )->fetchColumn();
        $plainCustomer = (int)get_db()->query('SELECT COUNT(*) FROM customer_accounts WHERE verify_token IS NOT NULL')->fetchColumn();
        $plainCompany = (int)get_db()->query('SELECT COUNT(*) FROM company_contacts WHERE verify_token IS NOT NULL')->fetchColumn();
        runner_reply(true, 'status', 'ok', [
            'migration_complete' => (int)$table === 1,
            'smtp_configured' => runner_smtp_configured(),
            'delivery_enabled' => get_setting('portal_email_delivery_enabled', '0') === '1',
            'plaintext_tokens' => $plainCustomer + $plainCompany,
        ]);
    }
    runner_reply(false, 'request', 'action_not_allowed');
} catch (PDOException $e) {
    runner_reply(false, $action ?: 'database', 'database');
} catch (Throwable $e) {
    $allowed = ['sql_parse','step_invalid','sql_integrity','non_read_only','schema_deviation','plaintext_token_present','completion_marker_missing','configuration_invalid','configuration_validation_failed'];
    $category = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'application';
    runner_reply(false, $action ?: 'application', $category);
} finally {
    if (isset($password)) $password = '';
    unset($_POST['smtp_password']);
}
