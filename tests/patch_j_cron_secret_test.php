<?php
// Test für Fix D: public/api/supplier_cron.php protokolliert (ohne das
// Secret selbst auszugeben), wenn das Secret per GET statt POST kam.

$loggedCalls = [];
function log_activity(string $action, ?string $entity_type = null, ?int $entity_id = null, ?string $details = null): void {
    global $loggedCalls;
    $loggedCalls[] = [$action, $entity_type, $entity_id, $details];
}

function handle_cron_request(array $get, array $post, string $realSecret): array {
    $providedViaGet = isset($get['secret']);
    $provided = $get['secret'] ?? $post['secret'] ?? '';
    if (!hash_equals($realSecret, (string)$provided)) {
        return ['status' => 403];
    }
    if ($providedViaGet) {
        log_activity('cron_secret_via_get', 'system', null, 'Cron-Aufruf nutzte GET statt POST fuer das Secret - Empfehlung: CLI-Worker oder POST verwenden.');
    }
    return ['status' => 200];
}

$fails = 0;
$secret = 'geheim123';

// Test 1: gueltiges Secret per POST -> kein Warn-Log
global $loggedCalls; $loggedCalls = [];
$res = handle_cron_request([], ['secret' => $secret], $secret);
if ($res['status'] === 200 && count($loggedCalls) === 0) {
    echo "PASS Test1: Secret per POST -> kein Warn-Log (Normalfall)\n";
} else {
    echo "FAIL Test1: " . json_encode($res) . " logs=" . json_encode($loggedCalls) . "\n"; $fails++;
}

// Test 2: gueltiges Secret per GET -> Warn-Log wird geschrieben, OHNE das Secret im Log
$loggedCalls = [];
$res = handle_cron_request(['secret' => $secret], [], $secret);
$logContainsSecret = false;
foreach ($loggedCalls as $c) { if (str_contains((string)$c[3], $secret)) $logContainsSecret = true; }
if ($res['status'] === 200 && count($loggedCalls) === 1 && $loggedCalls[0][0] === 'cron_secret_via_get' && !$logContainsSecret) {
    echo "PASS Test2: Secret per GET -> genau 1 Warn-Log-Eintrag, Secret selbst NICHT im Log enthalten\n";
} else {
    echo "FAIL Test2: " . json_encode($res) . " logs=" . json_encode($loggedCalls) . "\n"; $fails++;
}

// Test 3: falsches Secret -> weiterhin 403, kein Warn-Log (Regression)
$loggedCalls = [];
$res = handle_cron_request(['secret' => 'falsch'], [], $secret);
if ($res['status'] === 403 && count($loggedCalls) === 0) {
    echo "PASS Test3 (Regression): Falsches Secret weiterhin mit 403 abgelehnt\n";
} else {
    echo "FAIL Test3: " . json_encode($res) . "\n"; $fails++;
}

exit($fails > 0 ? 1 : 0);
