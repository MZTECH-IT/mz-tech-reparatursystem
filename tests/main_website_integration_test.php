<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$htmlPath = $root . '/deployment/main_website_ready/index.html';
$cssPath = $root . '/deployment/main_website_ready/style.css';
$jsPath = $root . '/deployment/main_website_ready/script.js';
$errors = [];

$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) $errors[] = $message;
};

$html = is_file($htmlPath) ? (string)file_get_contents($htmlPath) : '';
$css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
$js = is_file($jsPath) ? (string)file_get_contents($jsPath) : '';

$assert($html !== '' && $css !== '' && $js !== '', 'Website-Dateien fehlen.');
$assert(substr_count($html, 'id="kunden-firmenzugang"') === 1, 'Startseitenbereich fehlt oder ist doppelt.');
$assert(str_contains($html, 'href="#kunden-firmenzugang"'), 'Navigationslink zum Portalbereich fehlt.');

$requiredLinks = [
    '/repair_neu/public/portal_start.php',
    '/repair_neu/public/portal.php',
    '/repair_neu/public/portal_business.php',
    '/repair_neu/public/portal_tickets.php',
    '/repair_neu/public/portal_guest.php',
    '/repair_neu/public/portal.php?view=forgot',
    '#kontakt',
];
foreach ($requiredLinks as $link) {
    $assert(str_contains($html, 'href="' . $link . '"'), 'Erforderlicher Link fehlt: ' . $link);
}

$footerPosition = strpos($html, '<footer>');
$assert($footerPosition !== false, 'Footer fehlt.');
$footer = $footerPosition === false ? '' : substr($html, $footerPosition);
foreach (['Kundenportal', 'Firmenportal', 'Support-Ticket erstellen', 'Reparaturstatus'] as $label) {
    $assert(str_contains($footer, $label), 'Footer-Link fehlt: ' . $label);
}

$assert(!preg_match('/href="[^"]*(?:admin|deployment|phpmyadmin|sql)[^"]*"/i', $html), 'Interner Link wurde veröffentlicht.');
$assert(!preg_match('/portal_business\.php\?[^"]*register/i', $html), 'Offene Firmenregistrierung wurde verlinkt.');
$assert(str_contains($html, 'persönlichen Aktivierungslink'), 'Einladungsweg ist nicht erklärt.');
$assert(str_contains($html, 'id="portal-password-forgot"'), 'Passwort-vergessen-Weg fehlt.');
$assert(str_contains($html, 'id="portal-access-request"'), 'Zugang-anfordern-Weg fehlt.');

foreach (['.portal-grid', '.portal-card', '.portal-help', '.portal-invitation-note'] as $selector) {
    $assert(str_contains($css, $selector), 'Portal-CSS fehlt: ' . $selector);
}
$assert(str_contains($css, '@media (max-width: 600px)'), 'Mobile Portal-Darstellung fehlt.');
$assert(substr_count($css, '{') === substr_count($css, '}'), 'CSS-Klammern sind unausgeglichen.');
$assert(str_contains($js, "navLinks.classList.toggle('open'"), 'Bestehende mobile Menüsteuerung fehlt.');
$assert(str_contains($js, "navLinks.querySelectorAll('a')"), 'Mobiles Menü schließt nicht nach Linkwahl.');

if (class_exists(DOMDocument::class)) {
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $assert($loaded, 'HTML konnte nicht strukturell geparst werden.');
}

echo 'MAIN_WEBSITE_INTEGRATION_ASSERTIONS=31' . PHP_EOL;
echo 'MAIN_WEBSITE_INTEGRATION_ERRORS=' . count($errors) . PHP_EOL;
foreach ($errors as $error) echo 'ERROR: ' . $error . PHP_EOL;
exit($errors ? 1 : 0);
