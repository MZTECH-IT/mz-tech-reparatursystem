<?php
declare(strict_types=1);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$deliverySuccess = true;
function get_db(): PDO { global $db; return $db; }
function get_setting(string $key, string $default = ''): string {
    return ['portal_activation_lifetime_hours'=>'72','portal_activation_resend_minutes'=>'5'][$key] ?? $default;
}
function portal_new_token(): array { $plain = bin2hex(random_bytes(32)); return ['plain'=>$plain,'hash'=>hash('sha256',$plain)]; }
function portal_token_hash(string $token): string { return hash('sha256',$token); }
function base_app_url(): string { return 'https://example.invalid/repair_neu/public'; }
function portal_email_delivery_enabled(): bool { return true; }
function send_portal_activation_email(string $type, array $account, string $link, int $hours): array {
    global $deliverySuccess;
    return ['success'=>$deliverySuccess,'error_category'=>$deliverySuccess ? null : 'smtp'];
}
function portal_audit(...$args): void {}
function log_activity(...$args): void {}

$db->exec('CREATE TABLE customers(id INTEGER PRIMARY KEY,first_name TEXT,last_name TEXT)');
$db->exec('CREATE TABLE companies(id INTEGER PRIMARY KEY,company_name TEXT)');
$db->exec('CREATE TABLE customer_accounts(id INTEGER PRIMARY KEY,customer_id INTEGER,email TEXT,is_verified INTEGER,is_active INTEGER,verify_token TEXT,verify_token_hash TEXT,verify_expires TEXT)');
$db->exec('CREATE TABLE company_contacts(id INTEGER PRIMARY KEY,company_id INTEGER,email TEXT,first_name TEXT,last_name TEXT,is_verified INTEGER,is_active INTEGER,password_initialized INTEGER,verify_token TEXT,verify_token_hash TEXT,verify_expires TEXT)');
$db->exec('CREATE TABLE portal_invitations(id INTEGER PRIMARY KEY AUTOINCREMENT,recipient_type TEXT,recipient_id INTEGER,email TEXT,token_hash TEXT,expires_at TEXT,accepted_at TEXT,revoked_at TEXT,created_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE portal_admin_notifications(id INTEGER PRIMARY KEY,account_type TEXT,account_id INTEGER,event_type TEXT,status TEXT,resolution TEXT,resolved_at TEXT,resolved_by INTEGER,updated_at TEXT)');
$db->exec('CREATE TABLE portal_activation_mail_log(id INTEGER PRIMARY KEY AUTOINCREMENT,account_type TEXT,account_id INTEGER,recipient_email TEXT,delivery_method TEXT,result TEXT,error_category TEXT,admin_id INTEGER,template_key TEXT,invitation_id INTEGER,attempted_at TEXT)');
$db->exec("INSERT INTO customers VALUES(1,'TEST','Kunde'),(2,'TEST','Fehler')");
$db->exec("INSERT INTO customer_accounts VALUES(10,1,'test@example.invalid',0,1,NULL,NULL,NULL),(11,2,'fail@example.invalid',0,1,NULL,NULL,NULL)");
$db->exec("INSERT INTO portal_invitations(recipient_type,recipient_id,email,token_hash,expires_at,created_by) VALUES('customer',10,'test@example.invalid','oldhash','2030-01-01',1)");

require_once dirname(__DIR__) . '/private/account_verification.php';
$errors = [];
$check = static function (bool $ok, string $message) use (&$errors): void {
    echo ($ok ? 'OK: ' : 'FEHLER: ') . $message . PHP_EOL;
    if (!$ok) $errors[] = $message;
};

$sent = portal_account_send_activation('customer', 10, 99);
$check($sent['success'] && $sent['message'] === 'Der Aktivierungslink wurde erfolgreich per E-Mail versendet.', 'Erfolgreicher Versand meldet exakt Erfolg');
$account = $db->query('SELECT * FROM customer_accounts WHERE id=10')->fetch(PDO::FETCH_ASSOC);
$check($account['verify_token'] === null && preg_match('/^[a-f0-9]{64}$/', (string)$account['verify_token_hash']) === 1, 'Nur der Token-Hash wird gespeichert');
$check((int)$db->query("SELECT COUNT(*) FROM portal_invitations WHERE recipient_id=10 AND revoked_at IS NOT NULL")->fetchColumn() === 1, 'Alter Aktivierungslink wird widerrufen');
$check($db->query("SELECT result FROM portal_activation_mail_log WHERE account_id=10")->fetchColumn() === 'sent', 'Erfolgreicher Versand wird protokolliert');
$second = portal_account_send_activation('customer', 10, 99);
$check(!$second['success'] && str_contains($second['message'], 'Bitte warten'), 'Fünf-Minuten-Rate-Limit blockiert erneuten Versand');

$deliverySuccess = false;
$failed = portal_account_send_activation('customer', 11, 99);
$check(!$failed['success'] && str_contains($failed['message'], 'Das Konto wurde nicht automatisch aktiviert'), 'Versandfehler erhält sichere Fehlermeldung');
$failedAccount = $db->query('SELECT * FROM customer_accounts WHERE id=11')->fetch(PDO::FETCH_ASSOC);
$check($failedAccount['verify_token_hash'] === null, 'Fehlgeschlagener Versand widerruft den neu erzeugten Token');
$check($db->query("SELECT result FROM portal_activation_mail_log WHERE account_id=11")->fetchColumn() === 'failed', 'Versandfehler wird protokolliert');

$deactivated = portal_account_deactivate('customer', 10, 99);
$check($deactivated['success'] && (int)$db->query('SELECT is_active FROM customer_accounts WHERE id=10')->fetchColumn() === 0, 'Konto kann sicher deaktiviert werden');
$check((int)$db->query("SELECT COUNT(*) FROM portal_invitations WHERE recipient_id=10 AND revoked_at IS NULL AND accepted_at IS NULL")->fetchColumn() === 0, 'Deaktivierung widerruft offene Links');

echo 'ACTIVATION_EMAIL_FLOW_ERRORS=' . count($errors) . PHP_EOL;
exit($errors ? 1 : 0);
