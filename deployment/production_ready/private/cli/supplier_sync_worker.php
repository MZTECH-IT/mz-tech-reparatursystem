<?php
/**
 * private/cli/supplier_sync_worker.php
 * ---------------------------------------------------------------------
 * NEUE, VOLLSTAENDIG ADDITIVE DATEI.
 *
 * KORRIGIERTE FASSUNG (Integrationsphase) gegenueber dem urspruenglichen
 * Phase-2b-Entwurf. Zwei echte Bugs wurden bei der Integration gefunden
 * und hier behoben, BEVOR diese Datei jemals produktiv eingesetzt wurde:
 *
 *  1) Es fehlten require_once fuer private/config.php und
 *     private/functions.php. private/db.php selbst bindet config.php
 *     NICHT automatisch ein (verifiziert) - get_db() haette ohne
 *     config.php mit einem Fatal Error ("undefined constant") abgebrochen.
 *     functions.php wird ebenfalls von KEINER der hier eingebundenen
 *     Dateien selbst geladen, obwohl suppliers.php intern log_activity()
 *     und decrypt_passcode() aufruft (beide in functions.php definiert)
 *     - ohne diesen Require waere der erste echte Sync-Lauf mit einem
 *     "Call to undefined function" abgestuerzt.
 *
 *  2) Es gab urspruenglich einen Aufruf einer eigenen, neu erfundenen
 *     Protokollfunktion supplier_error_log_write(). Bei der Integration
 *     wurde festgestellt, dass private/suppliers.php in supplier_sync_now()
 *     bereits bei JEDEM Fehlerfall log_activity('sync_error', 'suppliers', ...)
 *     aufruft (echte, bereits vorhandene Funktionalitaet, private/functions.php,
 *     schreibt in die bestehende Tabelle `activity_log`). Eine zusaetzliche,
 *     neue Protokolltabelle waere eine unnoetige Parallelstruktur zu
 *     bereits vorhandenem, funktionierendem Code gewesen und wurde daher
 *     NICHT integriert. Diese Datei nutzt jetzt ausschliesslich die
 *     bestehende log_activity()-Funktion - nur fuer den einen Fall, den
 *     supplier_sync_now() selbst nicht abdeckt (eine Ausnahme, die NICHT
 *     beim adapter-Abruf auftritt, sondern z. B. beim Parsen/Import-Profil/
 *     DB-Zugriff danach - dort faengt supplier_sync_now() aktuell nicht
 *     jede denkbare Exception ab).
 *
 * Zweck: echte Hintergrundjob-Ausfuehrung fuer den automatischen
 * Lieferanten-Sync, aufgerufen direkt per Server-Cronjob (CLI) statt
 * per HTTP-Aufruf von public/api/supplier_cron.php.
 *
 * Warum zusaetzlich zu public/api/supplier_cron.php (das bleibt
 * unveraendert bestehen und wird durch diese Datei NICHT ersetzt,
 * um keine bestehende Funktion zu entfernen):
 *   - Kein Geheimnis, das per URL/GET uebertragen werden und in
 *     Server-Logs landen koennte.
 *   - Kein oeffentlich erreichbarer HTTP-Endpunkt noetig.
 *
 * Einrichtung (Beispiel Hoster-Cronjob-Oberflaeche):
 *   php /pfad/zur/installation/private/cli/supplier_sync_worker.php
 *
 * SICHERHEIT: Diese Datei liegt in private/ (ausserhalb des Webroots)
 * und bricht zusaetzlich hart ab, falls sie doch einmal ueber einen
 * Webserver aufgerufen werden sollte.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

// Harte Absicherung: nur per Kommandozeile ausfuehrbar, niemals per HTTP,
// selbst falls die Datei versehentlich im Webroot landen sollte.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur per Kommandozeile ausfuehrbar.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../suppliers.php';
require_once __DIR__ . '/../products.php';

// Phase 7 - Optionale CLI-Flags, rein additiv: ohne jedes Flag verhaelt sich
// dieser Worker exakt wie zuvor (alle faelligen Lieferanten gemaess
// suppliers_due_for_auto_sync()). --all synchronisiert stattdessen ALLE
// aktiven Lieferanten unabhaengig vom naechsten geplanten Zeitpunkt (z. B.
// fuer einen manuell angestossenen Vollsync). --supplier=ID beschraenkt den
// Lauf auf genau einen Lieferanten (z. B. zum gezielten Testen einer
// einzelnen Anbindung). --prices/--stock sind bewusst rein informativ: die
// bestehende supplier_sync_now()-Pipeline ruft ohnehin immer den vollen
// Angebots-Payload eines Lieferanten ab (Preise UND Bestand gemeinsam,
// siehe private/suppliers.php) - es gibt keine granularere, separate
// Abrufmoeglichkeit nur fuer Preise oder nur fuer Bestand, daher aendern
// diese beiden Flags aktuell NICHTS am tatsaechlichen Abruf. Sie werden
// dennoch entgegengenommen (statt mit einem Fehler abzubrechen), damit
// bestehende/kuenftige Cronjob-Konfigurationen, die sie setzen, nicht
// versehentlich fehlschlagen.
$cliFlagAll = false;
$cliFlagSupplierId = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--all') {
        $cliFlagAll = true;
    } elseif (str_starts_with($arg, '--supplier=')) {
        $cliFlagSupplierId = (int)substr($arg, strlen('--supplier='));
    } elseif ($arg === '--prices' || $arg === '--stock') {
        // Siehe Hinweis oben - bewusst kein Verhaltensunterschied.
        fwrite(STDOUT, "[supplier_sync_worker] Hinweis: Flag $arg wird entgegengenommen, aendert aber nichts am Abruf (Preise und Bestand werden immer gemeinsam abgerufen)." . PHP_EOL);
    } elseif ($arg !== '') {
        fwrite(STDOUT, "[supplier_sync_worker] Hinweis: unbekanntes Flag '$arg' wird ignoriert." . PHP_EOL);
    }
}

// Phase 5 - Absicherung gegen parallele Doppel-Ausfuehrung: falls der
// Cronjob-Intervall kuerzer ist als die Laufzeit eines Sync-Durchlaufs
// (z. B. viele faellige Lieferanten, langsame Lieferanten-API), verhindert
// eine einfache Datei-Sperre (flock), dass zwei Worker-Prozesse gleichzeitig
// dieselben Lieferanten synchronisieren. Kein Fehlerfall: ein bereits
// laufender Worker fuehrt einfach dazu, dass der neue Lauf sofort und ohne
// Aktion mit Exit-Code 0 beendet wird (naechster Cron-Tick versucht es
// erneut) - kein Absturz, keine Fehlermeldung im Cron-Log noetig.
$lockFile = sys_get_temp_dir() . '/mztech_supplier_sync.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, '[supplier_sync_worker] Ein anderer Lauf ist bereits aktiv - beende ohne Aktion.' . PHP_EOL);
    exit(0);
}
// Lock wird beim Skriptende (auch bei Exit/Exception) automatisch
// freigegeben, da register_shutdown_function garantiert ausgefuehrt wird
// und das Betriebssystem den Handle beim Prozessende ohnehin freigibt -
// explizit hier zusaetzlich sauber aufgeraeumt fuer den Normalfall.
register_shutdown_function(static function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

$startedAt = microtime(true);
$processed = 0;
$succeeded = 0;
$failed = 0;

fwrite(STDOUT, '[supplier_sync_worker] Start: ' . date('Y-m-d H:i:s') . PHP_EOL);

try {
    if ($cliFlagSupplierId !== null) {
        $one = supplier_find($cliFlagSupplierId);
        $dueSuppliers = $one ? [$one] : [];
        if (!$one) {
            fwrite(STDERR, "[supplier_sync_worker] Lieferant #$cliFlagSupplierId (--supplier) nicht gefunden." . PHP_EOL);
        }
    } elseif ($cliFlagAll) {
        $dueSuppliers = suppliers_list(['status' => 'aktiv']);
    } else {
        $dueSuppliers = suppliers_due_for_auto_sync();
    }
} catch (Throwable $e) {
    // Kann z. B. auftreten, wenn die Phase-2a-Migration noch nicht
    // eingespielt wurde (Tabelle `suppliers` fehlt) -- klare Meldung statt
    // kryptischem Fatal Error, sauberer Exit-Code fuer die Cron-Ueberwachung.
    fwrite(STDERR, '[supplier_sync_worker] Konnte faellige Lieferanten nicht ermitteln: ' . $e->getMessage() . PHP_EOL);
    try { log_activity('cron_error', 'suppliers', null, $e->getMessage()); } catch (Throwable $ignored) {}
    exit(1);
}

foreach ($dueSuppliers as $supplier) {
    $processed++;
    $supplierId = (int)$supplier['id'];
    try {
        $result = supplier_sync_now($supplierId, null);
        if (!empty($result['success'])) {
            $succeeded++;
            fwrite(STDOUT, sprintf(
                '[supplier_sync_worker] OK  Lieferant #%d (%s)%s',
                $supplierId,
                (string)($supplier['name'] ?? ''),
                PHP_EOL
            ));
        } else {
            // Kein eigener log_activity()-Aufruf hier: supplier_sync_now()
            // hat den Fehlerfall bereits selbst per log_activity('sync_error', ...)
            // protokolliert (siehe private/suppliers.php) - ein zweiter
            // Eintrag waere eine reine Dopplung.
            $failed++;
            $message = (string)($result['message'] ?? 'Unbekannter Fehler beim Sync.');
            fwrite(STDERR, sprintf(
                '[supplier_sync_worker] FEHLER Lieferant #%d: %s%s',
                $supplierId,
                $message,
                PHP_EOL
            ));
        }
    } catch (Throwable $e) {
        // Fehlschlag EINES Lieferanten blockiert nicht die uebrigen --
        // identisches Verhalten wie im bestehenden public/api/supplier_cron.php.
        // Dieser Fall (Exception ausserhalb von supplier_sync_now()'s eigenem
        // internen try/catch um fetchPayload()) wird von supplier_sync_now()
        // NICHT selbst protokolliert - hier daher ein eigener log_activity()-Ruf
        // auf die bestehende Tabelle, keine neue Struktur.
        $failed++;
        try { log_activity('sync_error', 'suppliers', $supplierId, $e->getMessage()); } catch (Throwable $ignored) {}
        fwrite(STDERR, sprintf(
            '[supplier_sync_worker] AUSNAHME Lieferant #%d: %s%s',
            $supplierId,
            $e->getMessage(),
            PHP_EOL
        ));
    }
}

$durationMs = (int)round((microtime(true) - $startedAt) * 1000);
fwrite(STDOUT, sprintf(
    '[supplier_sync_worker] Ende: %d verarbeitet, %d erfolgreich, %d fehlgeschlagen, %d ms%s',
    $processed,
    $succeeded,
    $failed,
    $durationMs,
    PHP_EOL
));

// Exit-Code 0 nur, wenn KEIN einzelner Lieferant fehlgeschlagen ist.
exit($failed > 0 ? 2 : 0);
