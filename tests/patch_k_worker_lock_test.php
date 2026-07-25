<?php
// Isolierter Test der Locking-Logik aus dem CLI-Worker (Fix E, Phase 5).
// Simuliert zwei "parallele" Worker-Starts im selben Prozess durch zwei
// unabhaengige Dateihandles auf dieselbe Lock-Datei.

$lockFile = sys_get_temp_dir() . '/mztech_supplier_sync_worker_TEST.lock';
@unlink($lockFile);

$handleA = fopen($lockFile, 'c');
$gotA = flock($handleA, LOCK_EX | LOCK_NB);

$handleB = fopen($lockFile, 'c');
$gotB = flock($handleB, LOCK_EX | LOCK_NB);

$fails = 0;
if ($gotA === true && $gotB === false) {
    echo "PASS Test1: Zweiter 'Worker' erhaelt die Sperre NICHT, waehrend der erste sie haelt (keine parallele Doppel-Ausfuehrung)\n";
} else {
    echo "FAIL Test1: gotA=" . var_export($gotA, true) . " gotB=" . var_export($gotB, true) . "\n"; $fails++;
}

// Sperre freigeben (wie im register_shutdown_function-Handler) und erneut versuchen
flock($handleA, LOCK_UN);
fclose($handleA);

$handleC = fopen($lockFile, 'c');
$gotC = flock($handleC, LOCK_EX | LOCK_NB);
if ($gotC === true) {
    echo "PASS Test2: Nach Freigabe kann ein neuer Worker-Lauf die Sperre wieder erhalten\n";
} else {
    echo "FAIL Test2: gotC=" . var_export($gotC, true) . "\n"; $fails++;
}
flock($handleC, LOCK_UN);
fclose($handleC);
fclose($handleB);
@unlink($lockFile);

exit($fails > 0 ? 1 : 0);
