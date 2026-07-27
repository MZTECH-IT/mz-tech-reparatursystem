-- Kontrollierter, nicht destruktiver Foneday-Rollback.
-- Keine Geschäfts-, Artikel-, Angebots- oder Warteschlangendaten werden gelöscht.

UPDATE foneday_sync_runs
SET status = 'failed',
    finished_at = COALESCE(finished_at, NOW()),
    error_code = COALESCE(error_code, 'controlled_rollback')
WHERE status = 'running';

UPDATE foneday_sync_lock
SET expires_at = NOW()
WHERE expires_at > NOW();

SELECT 'FONEDAY_ROLLBACK_NON_DESTRUCTIVE_COMPLETE' AS result,
       DATABASE() AS active_schema;
