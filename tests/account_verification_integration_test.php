<?php
declare(strict_types=1);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW', static fn(): string => '2026-07-31 12:00:00');

function get_db(): PDO {
    global $db;
    return $db;
}
function get_client_ip(): string { return '127.0.0.1'; }
function portal_audit(...$args): void {}
function log_activity(...$args): void {}

$db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
$db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)');
$db->exec('CREATE TABLE customer_accounts (
    id INTEGER PRIMARY KEY, customer_id INTEGER, email TEXT, password_hash TEXT,
    is_verified INTEGER, is_active INTEGER, verify_token TEXT, verify_token_hash TEXT,
    verify_expires TEXT, verified_at TEXT, verified_by INTEGER
)');
$db->exec('CREATE TABLE company_contacts (
    id INTEGER PRIMARY KEY, company_id INTEGER, email TEXT, first_name TEXT, last_name TEXT,
    password_hash TEXT, password_initialized INTEGER, portal_role TEXT, is_verified INTEGER, is_active INTEGER,
    verify_token TEXT, verify_token_hash TEXT, verify_expires TEXT,
    verified_at TEXT, verified_by INTEGER
)');
$db->exec('CREATE TABLE portal_invitations (
    id INTEGER PRIMARY KEY, recipient_type TEXT, recipient_id INTEGER, token_hash TEXT,
    accepted_at TEXT, revoked_at TEXT
)');
$db->exec('CREATE TABLE portal_admin_notifications (
    id INTEGER PRIMARY KEY, account_type TEXT, account_id INTEGER, event_type TEXT,
    status TEXT, resolution TEXT, resolved_at TEXT, resolved_by INTEGER, updated_at TEXT
)');

$db->exec("INSERT INTO customers VALUES (1, 'TEST', 'Kunde')");
$db->exec("INSERT INTO companies VALUES (1, 'TEST Firma')");
$db->exec("INSERT INTO customer_accounts VALUES
    (10,1,'test@example.invalid','hash-bleibt',0,1,NULL,'tokenhash','2026-08-03 12:00:00',NULL,NULL)");
$db->exec("INSERT INTO company_contacts VALUES
    (20,1,'firma@example.invalid','TEST','Kontakt','hash-bleibt',0,'admin',0,1,NULL,'tokenhash2','2026-08-03 12:00:00',NULL,NULL)");
$db->exec("INSERT INTO portal_invitations VALUES (1,'customer',10,'tokenhash',NULL,NULL)");
$db->exec("INSERT INTO portal_invitations VALUES (2,'company_contact',20,'tokenhash2',NULL,NULL)");
$db->exec("INSERT INTO portal_admin_notifications VALUES (1,'customer',10,'account_pending_verification','pending',NULL,NULL,NULL,NULL)");
$db->exec("INSERT INTO portal_admin_notifications VALUES (2,'company_contact',20,'account_pending_verification','pending',NULL,NULL,NULL,NULL)");

require_once dirname(__DIR__) . '/private/account_verification.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'OK: ' : 'FEHLER: ') . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$customerResult = portal_account_manual_verify('customer', 10, 99);
$companyResult = portal_account_manual_verify('company_contact', 20, 99);
$check($customerResult['success'], 'Kundenkonto kann manuell bestätigt werden');
$check($companyResult['success'], 'Firmenkonto kann manuell bestätigt werden');

$customer = $db->query('SELECT * FROM customer_accounts WHERE id=10')->fetch(PDO::FETCH_ASSOC);
$company = $db->query('SELECT * FROM company_contacts WHERE id=20')->fetch(PDO::FETCH_ASSOC);
$check((int)$customer['is_verified'] === 1 && (int)$customer['verified_by'] === 99, 'Kundenkonto speichert verified_at/verified_by');
$check((int)$company['is_verified'] === 1 && (int)$company['verified_by'] === 99, 'Firmenkonto speichert verified_at/verified_by');
$check($customer['password_hash'] === 'hash-bleibt' && $customer['email'] === 'test@example.invalid', 'Kundenpasswort und E-Mail bleiben unverändert');
$check($company['password_hash'] === 'hash-bleibt' && $company['portal_role'] === 'admin', 'Firmenpasswort und Rolle bleiben unverändert');
$check($customer['verify_token_hash'] === null && $company['verify_token_hash'] === null, 'Aktivierungstokens werden ungültig gemacht');
$check((int)$db->query("SELECT COUNT(*) FROM portal_invitations WHERE revoked_at IS NOT NULL")->fetchColumn() === 2, 'Offene Einladungen werden widerrufen');
$check((int)$db->query("SELECT COUNT(*) FROM portal_admin_notifications WHERE status='done'")->fetchColumn() === 2, 'Administratorbenachrichtigungen werden erledigt');

echo 'ERGEBNIS: ' . (count($failures) ? count($failures) . ' fehlgeschlagen' : 'alle bestanden') . PHP_EOL;
exit($failures ? 1 : 0);
