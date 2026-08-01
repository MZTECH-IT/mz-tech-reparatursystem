<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
    echo ($condition ? 'OK: ' : 'FEHLER: ') . $message . PHP_EOL;
};

$mailer = (string)file_get_contents($root . '/private/mailer.php');
$verification = (string)file_get_contents($root . '/private/account_verification.php');
$security = (string)file_get_contents($root . '/private/portal_security.php');
$admin = (string)file_get_contents($root . '/public/portal_access.php');
$companies = (string)file_get_contents($root . '/private/companies.php');
$settings = (string)file_get_contents($root . '/public/settings.php');
$customer = (string)file_get_contents($root . '/private/customer_auth.php');
$company = (string)file_get_contents($root . '/private/companies.php');

foreach (['SMTPAuth   = true','ENCRYPTION_STARTTLS','ENCRYPTION_SMTPS','verify_peer_name','allow_self_signed'] as $needle) {
    $check(str_contains($mailer, $needle), "Mailer erzwingt {$needle}");
}
$check(str_contains($mailer, 'send_portal_activation_email'), 'Responsive Aktivierungs-E-Mail besitzt eine eigene Versandfunktion');
$check(!str_contains($companies, "send_generic_template_email('firmenkontakt_konto_erstellt'"), 'Neue Firmenkontakte versenden keine unprotokollierte Alt-Einladung');
$check(str_contains($companies, 'company_contact_created_pending_activation'), 'Neue Firmenkontakte warten auf den kontrollierten Admin-Versand');
$check(str_contains($mailer, 'Ihr Zugang zum MZ-Tech-Kundenportal') && str_contains($mailer, 'Ihr Zugang zum MZ-Tech-Firmenportal'), 'Kunden- und Firmenbetreff sind korrekt');
$check(str_contains($mailer, 'Konto jetzt aktivieren') && str_contains($mailer, 'Falls die Schaltfläche nicht funktioniert'), 'HTML-CTA und Klartext-Link sind vorhanden');
$check(!str_contains($mailer, "error_log('Mailer error: '"), 'SMTP-Rohfehler werden nicht protokolliert');

foreach (['portal_activation_mail_log','portal_activation_resend_wait_seconds','portal_activation_lifetime_hours','portal_account_deactivate'] as $needle) {
    $check(str_contains($verification, $needle), "Aktivierungslogik enthält {$needle}");
}
$check(str_contains($verification, 'Der Aktivierungslink wurde erfolgreich per E-Mail versendet.'), 'Exakte Erfolgsmeldung ist vorhanden');
$check(str_contains($verification, 'Die E-Mail konnte nicht versendet werden. Das Konto wurde nicht automatisch aktiviert.'), 'Exakte sichere Fehlermeldung ist vorhanden');
$check(!str_contains($verification, 'verify_token = ?'), 'Aktivierung akzeptiert keine Klartext-Tokens');
$check(str_contains($security, "smtp_configuration_status()['configured']"), 'Globale Versandsperre prüft vollständige SMTP-Konfiguration');

foreach (['Aktivierungslink per E-Mail senden','Aktivierungslink erneut senden','Konto manuell bestätigen','Aktivierungslink kopieren','Aktivierungslink widerrufen','Konto deaktivieren'] as $label) {
    $check(str_contains($admin, $label), "Adminaktion {$label} ist vorhanden");
}
$check(str_contains($admin, 'portal_mask_email') && str_contains($admin, 'confirm('), 'Versanddialog verwendet maskierte Adresse und Sicherheitsabfrage');
$check(str_contains($admin, 'Letzter Versand') && str_contains($admin, 'Versand erfolgreich') && str_contains($admin, 'Versuche'), 'Versandstatus wird angezeigt');
$check(!str_contains($settings, 'name="smtp_pass"'), 'SMTP-Passwort kann nicht über die Weboberfläche eingegeben werden');
$check(str_contains($settings, 'sicheren lokalen Credential-Workflow'), 'SMTP-Einstellungen verweisen auf den lokalen Credential-Workflow');
$check(str_contains($customer, 'Dieses Konto wurde bereits aktiviert.') && str_contains($company, 'Dieses Konto wurde bereits aktiviert.'), 'Wiederverwendung erhält eine eindeutige Meldung');

foreach (['preflight','postcheck'] as $type) {
    $sql = (string)file_get_contents($root . "/sql/activation_email_{$type}.sql");
    $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
    $check(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b/i', (string)$withoutComments), "activation_email_{$type}.sql ist rein lesend");
}
$migration = (string)file_get_contents($root . '/sql/activation_email_migration.sql');
$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS portal_activation_mail_log'), 'Migration erstellt das Versandprotokoll defensiv');
$check(!preg_match('/\bDROP\s+(TABLE|COLUMN)\b/i', $migration), 'Migration enthält keinen destruktiven DROP');

echo 'ACTIVATION_EMAIL_STATIC_ERRORS=' . count($failures) . PHP_EOL;
exit($failures ? 1 : 0);
