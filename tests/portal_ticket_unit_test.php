<?php
declare(strict_types=1);

function get_setting(string $key, string $default = ''): string { return $default; }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

require_once dirname(__DIR__) . '/private/portal_security.php';
require_once dirname(__DIR__) . '/private/business_auth.php';
require_once dirname(__DIR__) . '/private/tickets.php';

$errors = [];
$check = static function (bool $ok, string $message) use (&$errors): void {
    if (!$ok) $errors[] = $message;
};

$tokenA = portal_new_token();
$tokenB = portal_new_token();
$check(strlen($tokenA['plain']) === 64, 'Token hat nicht 256 Bit in Hexdarstellung.');
$check(strlen($tokenA['hash']) === 64, 'Token-Hash ist kein SHA-256-Hexwert.');
$check($tokenA['plain'] !== $tokenA['hash'], 'Token wird nicht gehasht.');
$check($tokenA['plain'] !== $tokenB['plain'], 'Zwei Tokens sind identisch.');
$check(hash_equals(portal_token_hash($tokenA['plain']), $tokenA['hash']), 'Token-Hash ist nicht reproduzierbar.');
$check(portal_email_delivery_enabled() === false, 'Portal-Mailversand ist ohne Konfiguration nicht sicher deaktiviert.');

$_SESSION['business_portal_role'] = 'admin';
$check(business_can('manage_users'), 'Firmenadmin darf Benutzer nicht verwalten.');
$check(business_can('create_ticket'), 'Firmenadmin darf kein Ticket erstellen.');
$_SESSION['business_portal_role'] = 'employee';
$check(!business_can('manage_users'), 'Firmenmitarbeiter darf Benutzer verwalten.');
$check(business_can('create_ticket') && business_can('reply_ticket'), 'Firmenmitarbeiter darf Tickets nicht bearbeiten.');
$_SESSION['business_portal_role'] = 'read_only';
$check(business_can('view'), 'Nur-Lesen-Rolle darf Inhalte nicht sehen.');
$check(!business_can('create_ticket') && !business_can('reply_ticket'), 'Nur-Lesen-Rolle besitzt Schreibrechte.');

$statuses = ticket_valid_statuses();
foreach (['offen','in_bearbeitung','wartet_auf_kunde','wartet_intern','geloest','geschlossen','storniert'] as $status) {
    $check(in_array($status, $statuses, true), "Ticketstatus fehlt: $status");
}
$check(str_contains(ticket_status_badge('storniert'), 'badge-red'), 'Stornierter Status hat keine Warnfarbe.');

echo 'PORTAL_TICKET_UNIT_ASSERTIONS=20' . PHP_EOL;
echo 'PORTAL_TICKET_UNIT_ERRORS=' . count($errors) . PHP_EOL;
foreach ($errors as $error) echo "ERROR: $error" . PHP_EOL;
exit($errors ? 1 : 0);
