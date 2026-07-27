<?php
/**
 * MZ Tech – Cronjob-Endpunkt für automatische Lieferanten-Synchronisation
 * (Phase 6, Auftragsabschnitt 3 "Konfigurierbare automatische/manuelle
 * Synchronisationsplanung").
 * ----------------------------------------------------------------------
 * Diese Umgebung selbst kann keinen dauerhaft laufenden Hintergrund-Prozess
 * betreiben. Damit "stündlich/täglich/wöchentlich" tatsächlich automatisch
 * funktioniert, muss dieser Endpunkt von einem ECHTEN, serverseitigen
 * Cronjob Ihres Hosters regelmäßig aufgerufen werden (z. B. bei All-Inkl
 * über KAS > Cronjobs, einmal pro Stunde). Ohne einen solchen Cronjob
 * bleibt die Synchronisation voll funktionsfähig, aber rein manuell (über
 * den Knopf "Jetzt synchronisieren" in der Lieferantenübersicht).
 *
 * Sicherheit: Dieser Endpunkt ist öffentlich erreichbar (Cronjobs können
 * sich nicht an der normalen Mitarbeiter-Session anmelden), daher NICHT
 * über require_auth() geschützt, sondern über ein separates, per
 * Einstellung generiertes Geheimnis (Setting "supplier_cron_secret").
 * Ohne korrektes Geheimnis (?secret=...) passiert nichts.
 */
$private = dirname(dirname(__DIR__)) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/permissions.php';
require_once $private . '/supplier_adapters.php';
require_once $private . '/import_engine.php';
require_once $private . '/price_guard.php';
require_once $private . '/pricing_rules.php';
require_once $private . '/products.php';
require_once $private . '/suppliers.php';

header('Content-Type: application/json; charset=utf-8');

// Geheimnis beim ersten Aufruf automatisch erzeugen, falls noch keins
// hinterlegt ist (verhindert, dass der Endpunkt mit einem leeren/
// erratbaren Geheimnis nutzbar ist).
$secret = get_setting('supplier_cron_secret', '');
if ($secret === '') {
    $secret = bin2hex(random_bytes(24));
    set_setting('supplier_cron_secret', $secret);
}

$providedViaGet = isset($_GET['secret']);
$provided = $_GET['secret'] ?? $_POST['secret'] ?? '';
if (!hash_equals($secret, (string)$provided)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ungültiges oder fehlendes Geheimnis.']);
    exit;
}
if ($providedViaGet) {
    // Secret kam per GET (kann in Server-/Access-Logs landen). Kein
    // Fehler, aber protokolliert (OHNE das Secret selbst) - Empfehlung:
    // private/cli/supplier_sync_worker.php oder POST verwenden.
    log_activity('cron_secret_via_get', 'system', null, 'Cron-Aufruf nutzte GET statt POST fuer das Secret - Empfehlung: CLI-Worker oder POST verwenden.');
}

// Phase 7 - Sperre gegen parallele Laeufe: verhindert, dass zwei sich
// ueberlappende Cronjob-Aufrufe (z. B. ein zu kurz eingestelltes
// Hoster-Cron-Intervall, oder ein manueller Aufruf waehrend ein
// automatischer laeuft) dieselben Lieferanten doppelt synchronisieren.
// Nutzt dieselbe flock()-basierte Technik wie bereits
// private/cli/supplier_sync_worker.php - bewusst eine EIGENE Lock-Datei
// (anderer Dateiname), da CLI-Worker und HTTP-Endpunkt unabhaengig
// voneinander laufen koennen sollen (z. B. CLI-Worker fuer automatische
// Zeitplaene, dieser Endpunkt zusaetzlich fuer einen externen Hoster-Cron)
// und denselben Lieferantenbestand verarbeiten. CLI und HTTP verwenden
// deshalb absichtlich dieselbe Sperrdatei.
$cronLockFile = sys_get_temp_dir() . '/mztech_supplier_sync.lock';
$cronLockHandle = fopen($cronLockFile, 'c');
if ($cronLockHandle === false || !flock($cronLockHandle, LOCK_EX | LOCK_NB)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Ein anderer Synchronisationslauf ist bereits aktiv. Bitte spaeter erneut versuchen.']);
    exit;
}
register_shutdown_function(static function () use ($cronLockHandle) {
    flock($cronLockHandle, LOCK_UN);
    fclose($cronLockHandle);
});

$results = [];
foreach (suppliers_due_for_auto_sync() as $supplier) {
    $result = supplier_sync_now((int)$supplier['id'], null);
    $results[] = ['supplier_id' => (int)$supplier['id'], 'name' => $supplier['name'], 'success' => $result['success'], 'message' => $result['message']];
}

echo json_encode(['success' => true, 'checked_at' => date('c'), 'synced' => $results], JSON_UNESCAPED_UNICODE);
