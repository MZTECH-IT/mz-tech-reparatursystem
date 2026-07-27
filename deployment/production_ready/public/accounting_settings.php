<?php
/**
 * MZ Tech – Buchhaltung: Einstellungen (Phase 7, Abschnitt 8)
 * ----------------------------------------------------------------------
 * Zugangsdaten (verschlüsselt), aktiver Anbieter, automatische Übertragung
 * (Standard: AUS) und DATEV-Kontenrahmen-Einstellungen. Nutzt ausschließlich
 * die in private/accounting.php gekapselte Logik – keine Zugangsdaten
 * werden hier direkt verarbeitet/gespeichert, nur über
 * accounting_credentials_set().
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/accounting.php';
require_permission('manage_accounting');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_credentials') {
        $provider = trim($_POST['provider'] ?? '');
        if (isset(ACCOUNTING_PROVIDERS[$provider])) {
            if ($provider === 'lexoffice') {
                $apiKey = trim($_POST['api_key'] ?? '');
                if ($apiKey !== '') {
                    accounting_credentials_set('lexoffice', ['api_key' => $apiKey]);
                    flash('success', 'lexoffice-Zugangsdaten gespeichert.');
                }
            } elseif ($provider === 'sevdesk') {
                $apiToken = trim($_POST['api_token'] ?? '');
                if ($apiToken !== '') {
                    accounting_credentials_set('sevdesk', ['api_token' => $apiToken]);
                    flash('success', 'sevDesk-Zugangsdaten gespeichert.');
                }
            }
        }
    } elseif ($action === 'set_active_provider') {
        accounting_active_provider_set(trim($_POST['active_provider'] ?? ''));
        flash('success', 'Aktiver Anbieter aktualisiert.');
    } elseif ($action === 'set_auto_sync') {
        accounting_auto_sync_enabled_set(!empty($_POST['auto_sync_enabled']));
        flash('success', 'Einstellung für automatische Übertragung gespeichert.');
    } elseif ($action === 'save_datev_settings') {
        accounting_datev_settings_set($_POST);
        flash('success', 'DATEV-Einstellungen gespeichert.');
    } elseif ($action === 'test_connection') {
        $provider = trim($_POST['provider'] ?? '');
        if (isset(ACCOUNTING_PROVIDERS[$provider])) {
            $result = accounting_test_connection($provider);
            flash($result['success'] ? 'success' : 'error', $result['message']);
        }
    }
    header('Location: ' . url('accounting_settings.php'));
    exit;
}

$activeProvider = accounting_active_provider();
$autoSync = accounting_auto_sync_enabled();
$datevSettings = accounting_datev_settings();

$page_title = 'Buchhaltung – Einstellungen';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('settings', 20) ?> Aktiver Anbieter</h2></div>
    <div class="card-body">
        <form method="post" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_active_provider">
            <select name="active_provider">
                <option value="">– Kein Anbieter aktiv –</option>
                <?php foreach (ACCOUNTING_PROVIDERS as $k => $l): ?>
                    <option value="<?= h($k) ?>" <?= $activeProvider === $k ? 'selected' : '' ?>><?= h($l) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><?= svg_icon('save', 16) ?> Speichern</button>
        </form>
        <form method="post" style="margin-top:.75rem;display:flex;gap:.5rem;align-items:center;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_auto_sync">
            <label style="display:flex;gap:.4rem;align-items:center;">
                <input type="checkbox" name="auto_sync_enabled" value="1" style="width:auto;" <?= $autoSync ? 'checked' : '' ?>>
                Automatische Übertragung aktiviert
            </label>
            <button type="submit" class="btn btn-outline btn-sm">Übernehmen</button>
        </form>
        <p class="text-muted" style="margin-top:.5rem;">
            Die Automatik wird ausschließlich durch <code>private/cli/accounting_sync_worker.php</code> ausgeführt.
            Ohne eingerichteten Server-Cronjob bleibt die Einstellung wirkungslos.
            <br>
            Wichtig: Automatische Übertragung erst aktivieren, nachdem "Verbindung testen" für den gewählten
            Anbieter erfolgreich war UND mindestens ein Testbeleg manuell mit dem Ergebnis in
            <?= h($activeProvider ? ACCOUNTING_PROVIDERS[$activeProvider] : 'lexoffice/sevDesk') ?> abgeglichen wurde.
        </p>
    </div>
</div>

<?php foreach (ACCOUNTING_PROVIDERS as $providerKey => $providerLabel):
    $configured = accounting_credentials_configured($providerKey);
    $liveStatus = accounting_adapter_live_write_status($providerKey);
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= svg_icon('key', 20) ?> <?= h($providerLabel) ?>
            <span class="badge <?= $configured ? 'badge-green' : 'badge-secondary' ?>"><?= $configured ? 'Zugangsdaten hinterlegt' : 'Nicht konfiguriert' ?></span>
        </h2>
    </div>
    <div class="card-body">
        <p class="<?= $liveStatus['enabled'] ? 'text-muted' : 'alert alert-warning' ?>"><?= h($liveStatus['message']) ?></p>
        <form method="post" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_credentials">
            <input type="hidden" name="provider" value="<?= h($providerKey) ?>">
            <?php if ($providerKey === 'lexoffice'): ?>
                <div class="form-group"><label>API-Schlüssel (Bearer-Token)</label>
                    <input type="password" name="api_key" placeholder="<?= $configured ? '•••••••• (zum Ändern neu eingeben)' : 'API-Schlüssel eingeben' ?>" style="min-width:320px;">
                </div>
            <?php else: ?>
                <div class="form-group"><label>API-Token</label>
                    <input type="password" name="api_token" placeholder="<?= $configured ? '•••••••• (zum Ändern neu eingeben)' : 'API-Token eingeben' ?>" style="min-width:320px;">
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= svg_icon('save', 16) ?> Speichern</button>
        </form>
        <form method="post" style="margin-top:.5rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_connection">
            <input type="hidden" name="provider" value="<?= h($providerKey) ?>">
            <button type="submit" class="btn btn-outline" <?= $configured ? '' : 'disabled' ?>><?= svg_icon('refresh-cw', 16) ?> Verbindung testen</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<div class="card">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('database', 20) ?> DATEV-Export-Einstellungen</h2></div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_datev_settings">
            <div class="form-grid" style="grid-template-columns:repeat(3,1fr);gap:.75rem;">
                <div class="form-group"><label>Berater-Nummer</label><input type="text" name="advisor_number" value="<?= h($datevSettings['advisor_number']) ?>"></div>
                <div class="form-group"><label>Mandanten-Nummer</label><input type="text" name="client_number" value="<?= h($datevSettings['client_number']) ?>"></div>
                <div class="form-group"><label>Kontenlänge</label><input type="number" name="account_length" value="<?= (int)$datevSettings['account_length'] ?>"></div>
                <div class="form-group"><label>Erlöskonto (Reparaturen)</label><input type="text" name="revenue_account" value="<?= h($datevSettings['revenue_account']) ?>"></div>
                <div class="form-group"><label>Aufwandskonto (Bestellungen)</label><input type="text" name="expense_account" value="<?= h($datevSettings['expense_account']) ?>"></div>
                <div class="form-group"><label>Wirtschaftsjahresbeginn</label><input type="date" name="fiscal_year_start" value="<?= h($datevSettings['fiscal_year_start']) ?>"></div>
            </div>
            <p class="text-muted" style="margin-top:.5rem;">Hinweis: Konto-Platzhalter (8400/3300) vor produktivem Export mit dem tatsächlichen Kontenrahmen des Steuerberaters abgleichen.</p>
            <button type="submit" class="btn btn-primary" style="margin-top:.5rem;"><?= svg_icon('save', 16) ?> Speichern</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
