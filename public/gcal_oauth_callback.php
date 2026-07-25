<?php
/**
 * MZ Tech – Google-Kalender: OAuth2-Callback (Admin only)
 *
 * Ziel der "Autorisierte Weiterleitungs-URI" in der Google Cloud Console.
 * Tauscht den von Google übergebenen Autorisierungscode gegen ein
 * Refresh-Token, das verschlüsselt gespeichert wird (siehe
 * private/google_calendar.php).
 */
require_once __DIR__ . '/init.php';
require_admin();

$error = trim($_GET['error'] ?? '');
if ($error !== '') {
    flash('error', 'Google-Verbindung abgebrochen: ' . $error);
    header('Location: ' . url('settings.php') . '#kalender');
    exit;
}

$state = trim($_GET['state'] ?? '');
$code  = trim($_GET['code']  ?? '');

if ($code === '' || !gcal_verify_state($state)) {
    flash('error', 'Ungültige oder abgelaufene Google-Autorisierungsanfrage. Bitte erneut versuchen.');
    header('Location: ' . url('settings.php') . '#kalender');
    exit;
}

if (gcal_exchange_code($code)) {
    log_activity('gcal_connected', 'settings', null, 'Google-Kalender verbunden');
    flash('success', 'Google-Kalender wurde erfolgreich verbunden.');
} else {
    flash('error', 'Verbindung zu Google Kalender fehlgeschlagen. Bitte Client-ID/Secret prüfen und erneut versuchen.');
}

header('Location: ' . url('settings.php') . '#kalender');
exit;
