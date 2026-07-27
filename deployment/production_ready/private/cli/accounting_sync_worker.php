<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../accounting.php';

$lock = fopen(sys_get_temp_dir() . '/mztech_accounting_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Buchhaltungssynchronisation läuft bereits.\n");
    exit(2);
}

try {
    $provider = accounting_active_provider();
    $status = $provider ? accounting_adapter_live_write_status($provider) : ['enabled' => false, 'message' => 'Kein Anbieter aktiv.'];
    if (!accounting_auto_sync_enabled()) {
        fwrite(STDOUT, "Automatische Buchhaltungssynchronisation ist deaktiviert.\n");
        exit(0);
    }
    if (!$provider || !accounting_credentials_configured($provider) || !$status['enabled']) {
        fwrite(STDERR, $status['message'] . "\n");
        exit(3);
    }

    $db = get_db();
    $processed = 0;
    $failed = 0;

    $repairIds = $db->query(
        "SELECT id FROM repairs
          WHERE invoice_number IS NOT NULL AND invoice_status='freigegeben'
          ORDER BY invoice_released_at ASC LIMIT 50"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($repairIds as $id) {
        if (accounting_document_already_synced($provider, 'invoice_repair', (int)$id)) continue;
        $result = accounting_sync_repair_invoice((int)$id, $provider);
        $processed++;
        if (!$result['success']) $failed++;
    }

    $correctionIds = $db->query(
        "SELECT id FROM invoice_corrections WHERE status='freigegeben' ORDER BY released_at ASC LIMIT 50"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($correctionIds as $id) {
        if (accounting_document_already_synced($provider, 'invoice_correction', (int)$id)) continue;
        $result = accounting_sync_invoice_correction((int)$id, $provider);
        $processed++;
        if (!$result['success']) $failed++;
    }

    fwrite(STDOUT, "Verarbeitet: $processed; Fehler: $failed\n");
    exit($failed ? 1 : 0);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
