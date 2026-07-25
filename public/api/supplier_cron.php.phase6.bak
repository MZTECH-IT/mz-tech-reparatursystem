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

$provided = $_GET['secret'] ?? $_POST['secret'] ?? '';
if (!hash_equals($secret, (string)$provided)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ungültiges oder fehlendes Geheimnis.']);
    exit;
}

$results = [];
foreach (suppliers_due_for_auto_sync() as $supplier) {
    $result = supplier_sync_now((int)$supplier['id'], null);
    $results[] = ['supplier_id' => (int)$supplier['id'], 'name' => $supplier['name'], 'success' => $result['success'], 'message' => $result['message']];
}

echo json_encode(['success' => true, 'checked_at' => date('c'), 'synced' => $results], JSON_UNESCAPED_UNICODE);
