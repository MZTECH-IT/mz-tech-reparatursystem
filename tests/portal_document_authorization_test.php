<?php
ob_start();
$root = dirname(__DIR__);
$errors = 0;
$check = static function (bool $condition, string $message) use (&$errors): void {
    echo ($condition ? 'OK: ' : 'FEHLER: ') . $message . PHP_EOL;
    if (!$condition) $errors++;
};

$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mztech_pdf_session_' . bin2hex(random_bytes(6));
$stubDir = $sessionDir . DIRECTORY_SEPARATOR . 'private';
mkdir($stubDir, 0700, true);
ini_set('session.save_path', $sessionDir);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');

const SESSION_NAME = 'mztest_admin';
const PORTAL_SESSION_NAME = 'mztest_portal';
const BUSINESS_SESSION_NAME = 'mztest_business';
define('PRIVATE_PATH', $stubDir);

function get_setting(string $key, mixed $default = null): mixed { return $default; }
function test_start_named_session(string $name): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name($name);
    session_id((string)($_COOKIE[$name] ?? ''));
    session_start();
}

file_put_contents($stubDir . '/auth.php', <<<'PHP'
<?php
function start_secure_session(): void { test_start_named_session(SESSION_NAME); }
PHP);
file_put_contents($stubDir . '/portal_auth.php', <<<'PHP'
<?php
function start_portal_session(): void { test_start_named_session(PORTAL_SESSION_NAME); }
function portal_is_logged_in(): bool { return !empty($_SESSION['portal_customer_id']); }
function portal_current_customer_id(): ?int { return isset($_SESSION['portal_customer_id']) ? (int)$_SESSION['portal_customer_id'] : null; }
function portal_current_account_id(): ?int { return !empty($_SESSION['portal_account_id']) ? (int)$_SESSION['portal_account_id'] : null; }
PHP);
file_put_contents($stubDir . '/business_auth.php', <<<'PHP'
<?php
function start_business_session(): void { test_start_named_session(BUSINESS_SESSION_NAME); }
function business_current_contact_id(): ?int { return isset($_SESSION['business_contact_id']) ? (int)$_SESSION['business_contact_id'] : null; }
function business_current_company_id(): ?int { return isset($_SESSION['business_company_id']) ? (int)$_SESSION['business_company_id'] : null; }
PHP);

final class PortalDocumentFakeStatement {
    private mixed $value = false;
    public function __construct(private PortalDocumentFakeDb $db, private string $sql) {}
    public function execute(array $params = []): bool {
        if (str_contains($this->sql, 'FROM company_contacts')) {
            $this->value = $this->db->businessValid && $params === [51, 9] ? 1 : false;
        } elseif (str_contains($this->sql, 'FROM customer_accounts')) {
            $this->value = $this->db->accountValid && $params === [101, 7] ? 1 : false;
        } elseif (str_contains($this->sql, 'FROM customer_portal_access')) {
            $this->value = $this->db->guestValid && $params === [201, 7] ? 1 : false;
        } elseif (str_contains($this->sql, 'FROM repairs r')) {
            $this->value = $params === [301, 9, 9] ? 1 : false;
        } elseif (str_contains($this->sql, 'FROM repairs WHERE')) {
            $this->value = $params === [301, 7] ? 1 : false;
        } else {
            $this->value = false;
        }
        return true;
    }
    public function fetchColumn(): mixed { return $this->value; }
}

final class PortalDocumentFakeDb {
    public bool $businessValid = true;
    public bool $accountValid = true;
    public bool $guestValid = true;
    public function prepare(string $sql): PortalDocumentFakeStatement {
        return new PortalDocumentFakeStatement($this, $sql);
    }
}

$fakeDb = new PortalDocumentFakeDb();
function get_db(): PortalDocumentFakeDb { global $fakeDb; return $fakeDb; }

function test_write_session(string $name, string $id, array $data): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $_SESSION = [];
    session_id('');
    session_name($name);
    session_id($id);
    session_start();
    $_SESSION = $data;
    session_write_close();
    $_SESSION = [];
    session_id('');
}

test_write_session(SESSION_NAME, 'staleadmin123', []);
test_write_session(PORTAL_SESSION_NAME, 'customerportal123', [
    'portal_customer_id' => 7,
    'portal_account_id' => 101,
    'portal_access_id' => 201,
]);
test_write_session(BUSINESS_SESSION_NAME, 'businessportal123', [
    'business_contact_id' => 51,
    'business_company_id' => 9,
]);

require_once $root . '/private/pdf_common.php';

$_COOKIE = [SESSION_NAME => 'staleadmin123', PORTAL_SESSION_NAME => 'customerportal123'];
$check(pdf_repair_access_actor(301) === 'portal', 'Stales Admin-Cookie blockiert gültige Kundensitzung nicht');
$check(pdf_repair_access_actor(302) === null, 'Fremde Reparatur-ID bleibt für Privatkunde gesperrt');
$check(pdf_quote_access_actor(['status'=>'freigegeben','customer_id'=>7,'company_id'=>null]) === 'portal', 'Eigenes freigegebenes Angebot ist für Privatkunde erlaubt');
$check(pdf_quote_access_actor(['status'=>'freigegeben','customer_id'=>8,'company_id'=>null]) === null, 'Fremde Angebots-ID bleibt für Privatkunde gesperrt');
$check(pdf_quote_access_actor(['status'=>'entwurf','customer_id'=>7,'company_id'=>null]) === null, 'Angebotsentwurf bleibt im Kundenportal gesperrt');

$_COOKIE = [SESSION_NAME => '../../invalid', PORTAL_SESSION_NAME => 'customerportal123'];
$check(pdf_repair_access_actor(301) === 'portal', 'Ungültige fremde Session-ID wird verworfen, ohne die Kundensitzung zu blockieren');

$_COOKIE = [SESSION_NAME => 'staleadmin123', BUSINESS_SESSION_NAME => 'businessportal123'];
$check(pdf_repair_access_actor(301) === 'business', 'Stales Admin-Cookie blockiert gültige Firmensitzung nicht');
$check(pdf_repair_access_actor(302) === null, 'Fremde Reparatur-ID bleibt für Firmenkunde gesperrt');
$check(pdf_quote_access_actor(['status'=>'freigegeben','customer_id'=>null,'company_id'=>9]) === 'business', 'Eigenes Firmenangebot ist erlaubt');
$check(pdf_quote_access_actor(['status'=>'freigegeben','customer_id'=>null,'company_id'=>10]) === null, 'Fremdes Firmenangebot bleibt gesperrt');

test_write_session(SESSION_NAME, 'validadmin123', ['user_id' => 1]);
$_COOKIE = [SESSION_NAME => 'validadmin123', PORTAL_SESSION_NAME => 'customerportal123'];
$check(pdf_repair_access_actor(302) === 'staff', 'Angemeldeter Mitarbeiter behält vorgesehenen PDF-Zugriff');

test_write_session(PORTAL_SESSION_NAME, 'guestportal123', [
    'portal_customer_id' => 7,
    'portal_account_id' => null,
    'portal_access_id' => 201,
]);
$_COOKIE = [SESSION_NAME => 'staleadmin123', PORTAL_SESSION_NAME => 'guestportal123'];
$check(pdf_repair_access_actor(301) === 'portal', 'Aktiver Gastzugang darf eigene Reparatur öffnen');
$fakeDb->guestValid = false;
$check(pdf_repair_access_actor(301) === null, 'Abgelaufener oder widerrufener Gastzugang bleibt gesperrt');

$invoice = (string)file_get_contents($root . '/public/pdf/rechnung.php');
$estimate = (string)file_get_contents($root . '/public/pdf/kostenvoranschlag.php');
$portal = (string)file_get_contents($root . '/public/portal.php');
$business = (string)file_get_contents($root . '/public/portal_business.php');
$check(str_contains($invoice, "\$pdf_actor !== 'staff' && !\$released"), 'Unfreigegebene Rechnung bleibt für Portalnutzer gesperrt');
$check(str_contains($estimate, "\$pdf_actor !== 'staff' && !\$quote_released"), 'Unfreigegebener Kostenvoranschlag bleibt für Portalnutzer gesperrt');
$check(!str_contains($invoice, "['internal_notes']"), 'Rechnungs-PDF gibt interne Notizen nicht aus');
$check(!str_contains($estimate, "['internal_notes']"), 'Kostenvoranschlag gibt interne Notizen nicht aus');
$check(str_contains($portal, 'Tabelle seitlich wischen') && str_contains($business, 'Tabelle seitlich wischen'), 'Mobile Tabellen erklären die seitliche Bedienung');
$check(str_contains($portal, 'min-width:680px') && str_contains($business, 'min-width:720px'), 'Portal-Tabellen behalten auf Mobilgeräten lesbare Spaltenbreiten');

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
foreach (glob($stubDir . '/*.php') ?: [] as $file) unlink($file);
rmdir($stubDir);
foreach (glob($sessionDir . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
rmdir($sessionDir);

echo "PORTAL_DOCUMENT_AUTHORIZATION_ERRORS={$errors}" . PHP_EOL;
exit($errors ? 1 : 0);
