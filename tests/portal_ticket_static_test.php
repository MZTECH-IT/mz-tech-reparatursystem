<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) $errors[] = $message;
};
$read = static function (string $relative) use ($root, &$errors): string {
    $path = $root . '/' . $relative;
    $data = is_file($path) ? file_get_contents($path) : false;
    if ($data === false) {
        $errors[] = "Datei fehlt oder ist nicht lesbar: $relative";
        return '';
    }
    return $data;
};

$required = [
    'private/portal_security.php',
    'private/portal_auth.php',
    'private/customer_auth.php',
    'private/business_auth.php',
    'private/companies.php',
    'private/tickets.php',
    'public/portal.php',
    'public/portal_business.php',
    'public/portal_tickets.php',
    'public/portal_guest.php',
    'public/portal_ticket_attachment.php',
    'public/portal_access.php',
    'sql/portal_ticket_preflight.sql',
    'sql/portal_ticket_migration.sql',
    'sql/portal_ticket_postcheck.sql',
    'sql/portal_ticket_rollback.sql',
];
foreach ($required as $file) $read($file);

$security = $read('private/portal_security.php');
$customerAuth = $read('private/customer_auth.php');
$businessAuth = $read('private/business_auth.php');
$companies = $read('private/companies.php');
$companiesForm = $read('public/companies_form.php');
$tickets = $read('private/tickets.php');
$businessPortal = $read('public/portal_business.php');
$customerTickets = $read('public/portal_tickets.php');
$attachment = $read('public/portal_ticket_attachment.php');
$migration = $read('sql/portal_ticket_migration.sql');
$preflight = preg_replace('/--[^\r\n]*/', '', $read('sql/portal_ticket_preflight.sql'));
$postcheck = preg_replace('/--[^\r\n]*/', '', $read('sql/portal_ticket_postcheck.sql'));
$website = $read('WEBSITE-INTEGRATION.html');

$assert(str_contains($security, "hash('sha256'"), 'Token-Hashfunktion fehlt.');
$assert(str_contains($security, 'finfo(FILEINFO_MIME_TYPE)'), 'Upload-MIME-Prüfung fehlt.');
$assert(str_contains($customerAuth, 'verify_token_hash') && str_contains($customerAuth, 'reset_token_hash'), 'Kundentokens werden nicht gehasht verwendet.');
$assert(str_contains($companies, 'verify_token_hash') && str_contains($companies, 'reset_token_hash'), 'Firmentokens werden nicht gehasht verwendet.');
$assert(str_contains($businessAuth, 'business_current_company_id') && str_contains($businessAuth, 'portal_role'), 'Firmenmandant/Rolle fehlt.');
$assert(str_contains($tickets, "require_once __DIR__ . '/numbering.php';"), 'Ticketlogik lädt die Nummerierung nicht selbst.');
$assert(str_contains($companies, "require_once __DIR__ . '/numbering.php';"), 'Projektlogik lädt die Nummerierung nicht selbst.');
$assert(str_contains($companies, "'activation_link'") && str_contains($companiesForm, 'business_activation_link_once'), 'Einmaliger Firmen-Aktivierungslink fehlt bei deaktiviertem E-Mail-Versand.');
$assert(str_contains($tickets, "company_id = ? OR cc.company_id = ?"), 'Firmen-Ticketzugriff filtert nicht serverseitig nach Firma.');
$assert(str_contains($tickets, "status = 'aktiv' LIMIT 1"), 'Projektwahl wird nicht serverseitig auf aktive Firmenprojekte geprüft.');
$assert(str_contains($tickets, 'is_internal = 0'), 'Interne Ticketnotizen werden im Portal nicht ausgefiltert.');
$assert(str_contains($attachment, '!empty($attachment[\'is_internal\'])'), 'Interne Anhänge werden nicht gesperrt.');
$assert(str_contains($attachment, 'ticket_access_for_customer') && str_contains($attachment, 'ticket_access_for_company'), 'Anhang-Download ohne Mandantenprüfung.');
$assert(str_contains($businessPortal, "business_can('manage_users')"), 'Firmenbenutzerverwaltung ist nicht rollenbasiert.');
$assert(str_contains($businessPortal, "\$open_ticket['project_number']") && str_contains($businessPortal, "\$open_ticket['project_name']"), 'Projektzuordnung fehlt im Firmen-Ticketdetail.');
$assert(str_contains($businessPortal, 'project_id') && str_contains($customerTickets, 'customer_reference'), 'Erweiterte Ticketfelder fehlen in den Portalen.');

foreach (['portal_guest_access','portal_activity_log','portal_invitations','ticket_history','ticket_links','company_contact_project_access'] as $table) {
    $assert(str_contains($migration, "`$table`"), "Migration enthält Tabelle $table nicht.");
}
$assert(
    !preg_match('/^\s*(?:DROP|TRUNCATE|DELETE)\b/im', preg_replace('/--[^\r\n]*/', '', $migration)),
    'Migration enthält eine destruktive Anweisung.'
);
foreach (['INSERT','UPDATE','DELETE','ALTER','CREATE','DROP','TRUNCATE','REPLACE'] as $keyword) {
    $assert(!preg_match('/\b' . $keyword . '\b/i', $preflight), "Preflight enthält $keyword.");
    $assert(!preg_match('/\b' . $keyword . '\b/i', $postcheck), "Postcheck enthält $keyword.");
}
$assert(!str_contains($website, 'https://mztech-it.de/repair/public/'), 'Website-Integration enthält alte Produktiv-URL.');
$assert(str_contains($website, '/repair_neu/public/portal_business.php'), 'Firmenportal-Link fehlt.');

echo 'PORTAL_TICKET_STATIC_ASSERTIONS=' . (31 + count($required)) . PHP_EOL;
echo 'PORTAL_TICKET_STATIC_ERRORS=' . count($errors) . PHP_EOL;
foreach ($errors as $error) echo "ERROR: $error" . PHP_EOL;
exit($errors ? 1 : 0);
