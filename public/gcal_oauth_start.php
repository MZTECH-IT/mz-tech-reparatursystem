<?php
/**
 * MZ Tech – Google-Kalender: OAuth2-Flow starten (Admin only)
 *
 * Leitet den Admin zur Google-Consent-Seite weiter, damit MZ Tech die
 * Erlaubnis erhält, Termine im gewählten Google-Kalender anzulegen/zu
 * aktualisieren/zu löschen. Nutzt ausschließlich die kostenlose Google
 * Calendar API (kein kostenpflichtiger Dienst).
 */
require_once __DIR__ . '/init.php';
require_admin();

if (trim(get_setting('gcal_client_id', '')) === '' || gcal_client_secret() === '') {
    flash('error', 'Bitte zuerst Client-ID und Client-Secret speichern, bevor Sie sich mit Google verbinden.');
    header('Location: ' . url('settings.php') . '#kalender');
    exit;
}

header('Location: ' . gcal_get_authorize_url());
exit;
