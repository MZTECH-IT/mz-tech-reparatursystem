<?php
/**
 * MZ Tech – Lieferant anlegen/bearbeiten inkl. Schnittstellenprofile,
 * Versandregeln, Dokumente, Import-Historie (Phase 6)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_suppliers');

$id = (int)($_GET['id'] ?? 0);
$supplier = $id ? supplier_find($id) : null;
if ($id && !$supplier) {
    flash('error', 'Lieferant nicht gefunden.');
    header('Location: ' . url('suppliers.php'));
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_supplier') {
        try {
            $newId = supplier_save($_POST, $id ?: null, $_SESSION['user_id'] ?? null);
            flash('success', $id ? 'Lieferant aktualisiert.' : 'Lieferant angelegt.');
            header('Location: ' . url('suppliers_form.php') . '?id=' . $newId);
            exit;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'archive' && $id) {
        supplier_set_status($id, 'archiviert');
        flash('success', 'Lieferant archiviert.');
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id);
        exit;
    } elseif ($action === 'reactivate' && $id) {
        supplier_set_status($id, 'aktiv');
        flash('success', 'Lieferant reaktiviert.');
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id);
        exit;
    } elseif ($action === 'save_profile' && $id) {
        require_permission('manage_imports');
        $credentials = [];
        foreach (['username', 'password', 'token', 'api_key', 'api_key_header', 'graphql_query', 'access_token', 'soap_method', 'soap_params'] as $c) {
            if (!empty($_POST['cred_' . $c])) $credentials[$c] = $_POST['cred_' . $c];
        }
        try {
            supplier_interface_profile_save($id, $_POST, $credentials, (int)($_POST['profile_id'] ?? 0) ?: null);
            flash('success', 'Schnittstellenprofil gespeichert.');
        } catch (InvalidArgumentException|JsonException $e) {
            flash('error', 'Schnittstellenprofil nicht gespeichert: ' . $e->getMessage());
        }
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#schnittstellen');
        exit;
    } elseif ($action === 'delete_profile' && $id) {
        require_permission('manage_imports');
        supplier_interface_profile_delete((int)($_POST['profile_id'] ?? 0));
        flash('success', 'Schnittstellenprofil gelöscht.');
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#schnittstellen');
        exit;
    } elseif ($action === 'test_profile' && $id) {
        $result = supplier_interface_test((int)($_POST['profile_id'] ?? 0));
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#schnittstellen');
        exit;
    } elseif ($action === 'upload_document' && $id) {
        if (!empty($_FILES['document']['name'])) {
            $result = supplier_document_save($_FILES['document'], $id, $_POST['description'] ?? null, $_SESSION['user_id'] ?? null);
            flash($result ? 'success' : 'error', $result ? 'Dokument hochgeladen.' : 'Dokument konnte nicht gespeichert werden (Format/Größe prüfen).');
        }
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#dokumente');
        exit;
    } elseif ($action === 'delete_document' && $id) {
        supplier_document_delete((int)($_POST['document_id'] ?? 0));
        flash('success', 'Dokument gelöscht.');
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#dokumente');
        exit;
    } elseif ($action === 'save_shipping_rule' && $id) {
        supplier_shipping_rule_save($id, $_POST, (int)($_POST['rule_id'] ?? 0) ?: null);
        flash('success', 'Versandregel gespeichert.');
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#versand');
        exit;
    } elseif ($action === 'toggle_shipping_rule' && $id) {
        $rule = supplier_shipping_rule_find((int)($_POST['rule_id'] ?? 0));
        if ($rule && (int)$rule['supplier_id'] === $id) {
            supplier_shipping_rule_set_active((int)$rule['id'], !$rule['is_active']);
            flash('success', 'Status der Versandregel aktualisiert.');
        }
    } elseif ($action === 'delete_shipping_rule' && $id) {
        supplier_shipping_rule_delete((int)($_POST['rule_id'] ?? 0));
        flash('success', 'Versandregel gelöscht.');
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#versand');
        exit;
    } elseif ($action === 'apply_import' && $id) {
        require_permission('approve_imports');
        $result = import_job_apply((int)($_POST['job_id'] ?? 0), $_SESSION['user_id'] ?? 0);
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#importe');
        exit;
    } elseif ($action === 'rollback_import' && $id) {
        require_permission('approve_imports');
        $result = import_job_rollback((int)($_POST['job_id'] ?? 0), $_SESSION['user_id'] ?? 0);
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('suppliers_form.php') . '?id=' . $id . '#importe');
        exit;
    }
}

$profiles = $id ? supplier_interface_profiles_list($id) : [];
$documents = $id ? supplier_documents_list($id) : [];
$shippingRules = $id ? supplier_shipping_rules_list_all($id) : [];
$importJobs = $id ? import_jobs_list(['supplier_id' => $id]) : [];
$interfaceLabels = supplier_interface_type_labels();

$page_title = $id ? 'Lieferant: ' . $supplier['name'] : 'Neuer Lieferant';
require_once __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <a href="suppliers.php" class="btn btn-outline"><?= svg_icon('arrow-left', 18) ?> Zurück zur Übersicht</a>
    <?php if ($id): ?>
    <div class="toolbar-actions">
        <a href="supplier_import.php?supplier_id=<?= $id ?>" class="btn btn-outline"><?= svg_icon('upload', 18) ?> Import-Datei hochladen</a>
        <?php if ($supplier['status'] !== 'archiviert'): ?>
        <form method="post" class="inline-form" onsubmit="return confirm('Lieferanten wirklich archivieren? Bereits erfasste Angebote/Historie bleiben erhalten.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="archive">
            <button type="submit" class="btn btn-outline"><?= svg_icon('trash', 18) ?> Archivieren</button>
        </form>
        <?php else: ?>
        <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="reactivate">
            <button type="submit" class="btn btn-outline">Reaktivieren</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . h($e) . '</div>'; ?></div>
<?php endif; ?>

<!-- ── Stammdaten ────────────────────────────────────────────────────── -->
<div class="card" id="stammdaten">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('package', 20) ?> Stammdaten</h2></div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_supplier">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group"><label>Name <span style="color:var(--red)">*</span></label>
                    <input type="text" name="name" value="<?= h($supplier['name'] ?? '') ?>" required></div>
                <div class="form-group"><label>Kürzel (intern)</label>
                    <input type="text" name="short_code" value="<?= h($supplier['short_code'] ?? '') ?>" placeholder="z.B. INGRAM"></div>
                <div class="form-group"><label>Status</label>
                    <select name="status">
                        <?php foreach (['aktiv' => 'Aktiv', 'inaktiv' => 'Inaktiv', 'archiviert' => 'Archiviert'] as $k => $l): ?>
                        <option value="<?= $k ?>" <?= ($supplier['status'] ?? 'aktiv') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Ansprechpartner</label>
                    <input type="text" name="contact_person" value="<?= h($supplier['contact_person'] ?? '') ?>"></div>
                <div class="form-group"><label>E-Mail</label>
                    <input type="email" name="email" value="<?= h($supplier['email'] ?? '') ?>"></div>
                <div class="form-group"><label>Telefon</label>
                    <input type="text" name="phone" value="<?= h($supplier['phone'] ?? '') ?>"></div>
                <div class="form-group"><label>Website</label>
                    <input type="text" name="website" value="<?= h($supplier['website'] ?? '') ?>"></div>
                <div class="form-group"><label>Kundennummer beim Lieferanten</label>
                    <input type="text" name="customer_number_at_supplier" value="<?= h($supplier['customer_number_at_supplier'] ?? '') ?>"></div>
                <div class="form-group"><label>Straße/Nr.</label>
                    <input type="text" name="address_street" value="<?= h($supplier['address_street'] ?? '') ?>"></div>
                <div class="form-group"><label>PLZ</label>
                    <input type="text" name="address_zip" value="<?= h($supplier['address_zip'] ?? '') ?>"></div>
                <div class="form-group"><label>Ort</label>
                    <input type="text" name="address_city" value="<?= h($supplier['address_city'] ?? '') ?>"></div>
                <div class="form-group"><label>Land (ISO-Code)</label>
                    <input type="text" name="address_country" maxlength="2" value="<?= h($supplier['address_country'] ?? 'DE') ?>"></div>
                <div class="form-group"><label>USt-IdNr.</label>
                    <input type="text" name="vat_id" value="<?= h($supplier['vat_id'] ?? '') ?>"></div>
                <div class="form-group"><label>Währung</label>
                    <input type="text" name="currency" maxlength="3" value="<?= h($supplier['currency'] ?? 'EUR') ?>"></div>
                <div class="form-group"><label>Standard-MwSt.-Satz (%)</label>
                    <input type="number" step="0.01" name="default_tax_rate" value="<?= h($supplier['default_tax_rate'] ?? '') ?>"></div>
                <div class="form-group"><label>Zahlungsbedingungen</label>
                    <input type="text" name="payment_terms" value="<?= h($supplier['payment_terms'] ?? '') ?>" placeholder="z.B. 14 Tage netto"></div>
                <div class="form-group"><label>Lieferzeit (Tage, Standard)</label>
                    <input type="number" name="delivery_time_days" value="<?= h($supplier['delivery_time_days'] ?? '') ?>"></div>
                <div class="form-group"><label>Mindestbestellwert (€)</label>
                    <input type="number" step="0.01" name="minimum_order_value" value="<?= h($supplier['minimum_order_value'] ?? '') ?>"></div>
                <div class="form-group"><label>Automatische Synchronisation</label>
                    <select name="auto_sync_mode">
                        <?php foreach (['manuell' => 'Manuell', 'stuendlich' => 'Stündlich', 'taeglich' => 'Täglich', 'woechentlich' => 'Wöchentlich', 'benutzerdefiniert' => 'Benutzerdefiniert (Cron)'] as $k => $l): ?>
                        <option value="<?= $k ?>" <?= ($supplier['auto_sync_mode'] ?? 'manuell') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Cron-Ausdruck (bei "Benutzerdefiniert")</label>
                    <input type="text" name="auto_sync_cron_expression" value="<?= h($supplier['auto_sync_cron_expression'] ?? '') ?>" placeholder="z.B. 0 */6 * * *"></div>
                <div class="form-group full" style="grid-column:1/-1;"><label>Notizen</label>
                    <textarea name="notes" rows="3"><?= h($supplier['notes'] ?? '') ?></textarea></div>
            </div>
            <?php if ($supplier): ?>
            <div class="alert alert-info" style="margin-top:1rem;font-size:.85rem;">
                Automatische Synchronisation erfordert einen echten Server-Cronjob Ihres Hosters, der regelmäßig
                <code>public/api/supplier_cron.php</code> aufruft (siehe SCHNITTSTELLEN_ADAPTER_DOKUMENTATION.txt).
                Ohne einen solchen Cronjob wird nur über den Knopf "Jetzt synchronisieren" abgeglichen –
                nächster geplanter Abgleich: <?= $supplier['next_scheduled_sync_at'] ? h(date('d.m.Y H:i', strtotime($supplier['next_scheduled_sync_at']))) : '–' ?>.
            </div>
            <?php endif; ?>
            <div class="modal-footer" style="padding-left:0;padding-right:0;">
                <button type="submit" class="btn btn-primary"><?= svg_icon('save', 16) ?> Speichern</button>
            </div>
        </form>
    </div>
</div>

<?php if ($id): ?>

<!-- ── Schnittstellenprofile ─────────────────────────────────────────── -->
<div class="card" id="schnittstellen">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('link', 20) ?> Schnittstellen</h2></div>
    <div class="card-body">
        <?php if (empty($profiles)): ?>
            <p class="text-muted">Noch kein Schnittstellenprofil hinterlegt.</p>
        <?php else: ?>
        <div class="table-wrap" style="margin-bottom:1.5rem;">
            <table class="table">
                <thead><tr><th>Bezeichnung</th><th>Typ</th><th>Aktiv</th><th>Format</th><th class="col-actions">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($profiles as $p): ?>
                    <tr>
                        <td><?= h($p['label']) ?></td>
                        <td><?= h($interfaceLabels[$p['interface_type']] ?? $p['interface_type']) ?></td>
                        <td><?= $p['is_active'] ? '✓' : '—' ?></td>
                        <td><?= h($p['format'] ?? '—') ?></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <form method="post" class="inline-form"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="test_profile">
                                    <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline" title="Verbindung testen"><?= svg_icon('check', 15) ?></button>
                                </form>
                                <form method="post" class="inline-form" onsubmit="return confirm('Schnittstellenprofil wirklich löschen?');"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_profile">
                                    <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Löschen"><?= svg_icon('trash', 15) ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h3 style="font-size:1rem;margin-bottom:.75rem;">Neues Schnittstellenprofil anlegen</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_profile">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group"><label>Bezeichnung</label><input type="text" name="label" placeholder="z.B. Preisliste per FTP"></div>
                <div class="form-group"><label>Schnittstellentyp</label>
                    <select name="interface_type">
                        <?php foreach ($interfaceLabels as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Endpunkt-/WSDL-/Download-URL</label><input type="text" name="endpoint_url" placeholder="https://…"></div>
                <div class="form-group"><label>Authentifizierung</label>
                    <select name="auth_type">
                        <?php foreach (['none' => 'Keine', 'basic' => 'Basic Auth', 'bearer_token' => 'Bearer-Token', 'api_key' => 'API-Key-Header', 'oauth2' => 'OAuth2 (Access-Token)', 'custom' => 'Individuell'] as $k => $l): ?>
                        <option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Benutzername (falls Basic Auth)</label><input type="text" name="cred_username" autocomplete="off"></div>
                <div class="form-group"><label>Passwort (falls Basic Auth)</label><input type="password" name="cred_password" autocomplete="new-password"></div>
                <div class="form-group"><label>Bearer-/Access-Token</label><input type="password" name="cred_token" autocomplete="new-password"></div>
                <div class="form-group"><label>API-Key</label><input type="password" name="cred_api_key" autocomplete="new-password"></div>
                <div class="form-group"><label>API-Key-Header-Name</label><input type="text" name="cred_api_key_header" placeholder="X-API-Key"></div>
                <div class="form-group"><label>GraphQL-Query (optional)</label><textarea name="cred_graphql_query" rows="3" placeholder="{ products { sku name price } }"></textarea></div>
                <div class="form-group"><label>SOAP-Methode (nur SOAP)</label><input type="text" name="cred_soap_method" placeholder="GetProducts"></div>
                <div class="form-group"><label>SOAP-Parameter als JSON (nur SOAP)</label><textarea name="cred_soap_params" rows="3" placeholder="{}"></textarea></div>
                <div class="form-group"><label>FTP/FTPS-Host</label><input type="text" name="ftp_host"></div>
                <div class="form-group"><label>FTP-Port</label><input type="number" name="ftp_port" placeholder="21"></div>
                <div class="form-group"><label>FTP-Dateipfad</label><input type="text" name="ftp_path" placeholder="/preise/aktuell.csv"></div>
                <div class="form-group"><label>Dateiformat der Nutzdaten</label>
                    <select name="format">
                        <option value="">– automatisch erkennen –</option>
                        <?php foreach (['csv','tsv','txt','xlsx','xml','json'] as $f): ?><option value="<?= $f ?>"><?= strtoupper($f) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Trennzeichen (nur CSV/TSV)</label><input type="text" name="delimiter" maxlength="3" placeholder=","></div>
                <div class="form-group"><label><input type="checkbox" name="is_active" checked style="width:auto;"> Aktiv</label></div>
                <div class="form-group full" style="grid-column:1/-1;"><label>Notizen</label><textarea name="notes" rows="2"></textarea></div>
            </div>
            <p class="text-muted" style="font-size:.82rem;">Zugangsdaten werden ausschließlich verschlüsselt gespeichert (AES-256) und nie im Klartext angezeigt.</p>
            <button type="submit" class="btn btn-primary"><?= svg_icon('save', 16) ?> Schnittstellenprofil speichern</button>
        </form>
    </div>
</div>

<!-- ── Versandkosten-Regeln ──────────────────────────────────────────── -->
<div class="card" id="versand">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('package', 20) ?> Versandkosten-Regeln</h2></div>
    <div class="card-body">
        <?php if (!empty($shippingRules)): ?>
        <div class="table-wrap" style="margin-bottom:1.5rem;">
            <table class="table">
                <thead><tr><th>Typ</th><th>Bedingung</th><th>Betrag</th><th>Priorität</th><th>Status</th><th class="col-actions">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($shippingRules as $r): ?>
                    <tr>
                        <td><?= h($r['rule_type']) ?></td>
                        <td><?= h($r['condition_value'] ?? $r['condition_text'] ?? '—') ?></td>
                        <td><?= h(fmt_money((float)$r['amount'])) ?></td>
                        <td><?= (int)$r['priority'] ?></td>
                        <td><span class="badge <?= $r['is_active'] ? 'badge-green' : 'badge-secondary' ?>"><?= $r['is_active'] ? 'Aktiv' : 'Inaktiv' ?></span></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <form method="post" class="inline-form"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_shipping_rule">
                                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                </form>
                                <form method="post" class="inline-form" onsubmit="return confirm('Regel löschen?');"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_shipping_rule">
                                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><?= svg_icon('trash', 15) ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_shipping_rule">
            <div class="form-grid" style="grid-template-columns:repeat(5,1fr);gap:.75rem;align-items:end;">
                <div class="form-group"><label>Typ</label>
                    <select name="rule_type">
                        <?php foreach (['fest'=>'Fest','kostenlos_ab'=>'Kostenlos ab Betrag','express_zuschlag'=>'Express-Zuschlag','sperrgut_zuschlag'=>'Sperrgut-Zuschlag','gefahrgut_zuschlag'=>'Gefahrgut-Zuschlag','pro_versandklasse'=>'Pro Versandklasse','pro_land'=>'Pro Land'] as $k=>$l): ?>
                        <option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label>Schwellwert (€)</label><input type="number" step="0.01" name="condition_value"></div>
                <div class="form-group"><label>Text (Klasse/Land)</label><input type="text" name="condition_text"></div>
                <div class="form-group"><label>Betrag (€)</label><input type="number" step="0.01" name="amount" value="0"></div>
                <div class="form-group"><label>Priorität</label><input type="number" name="priority" value="100"></div>
                <div class="form-group"><label><input type="checkbox" name="is_active" checked style="width:auto;"> Aktiv</label></div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:.5rem;"><?= svg_icon('save', 16) ?> Regel hinzufügen</button>
        </form>
    </div>
</div>

<!-- ── Dokumente ─────────────────────────────────────────────────────── -->
<div class="card" id="dokumente">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('inbox', 20) ?> Dokumente</h2></div>
    <div class="card-body">
        <?php if (!empty($documents)): ?>
        <ul style="list-style:none;padding:0;margin:0 0 1rem 0;display:flex;flex-direction:column;gap:.4rem;">
            <?php foreach ($documents as $d): ?>
            <li style="display:flex;justify-content:space-between;align-items:center;">
                <a href="supplier_document.php?id=<?= (int)$d['id'] ?>"><?= h($d['original_name']) ?></a>
                <form method="post" class="inline-form" onsubmit="return confirm('Dokument löschen?');"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_document">
                    <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><?= svg_icon('trash', 15) ?></button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload_document">
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <input type="file" name="document" required>
                <input type="text" name="description" placeholder="Beschreibung (optional)">
                <button type="submit" class="btn btn-outline"><?= svg_icon('upload', 16) ?> Hochladen</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Import-Historie / Fehlerprotokoll ─────────────────────────────── -->
<div class="card" id="importe">
    <div class="card-header"><h2 class="card-title"><?= svg_icon('database', 20) ?> Import-Historie</h2></div>
    <div class="card-body">
        <?php if (empty($importJobs)): ?>
            <p class="text-muted">Noch keine Importe für diesen Lieferanten.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Datum</th><th>Quelle</th><th>Status</th><th>Neu/Aktualisiert/Unverändert/Fehler</th><th class="col-actions">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($importJobs as $job): ?>
                    <tr>
                        <td><?= h(date('d.m.Y H:i', strtotime($job['created_at']))) ?></td>
                        <td><?= h($job['source_filename'] ?? $job['source_type']) ?></td>
                        <td>
                            <?php $jobBadge = ['vorschau'=>'badge-secondary','wartet_auf_freigabe'=>'badge-orange','importiert'=>'badge-green','zurueckgerollt'=>'badge-gray','fehlgeschlagen'=>'badge-red'][$job['status']] ?? 'badge-secondary'; ?>
                            <span class="badge <?= $jobBadge ?>"><?= h($job['status']) ?></span>
                        </td>
                        <td><?= (int)$job['rows_new'] ?> / <?= (int)$job['rows_updated'] ?> / <?= (int)$job['rows_unchanged'] ?> / <?= (int)$job['rows_error'] ?></td>
                        <td class="col-actions">
                            <div class="action-group">
                                <?php if ($job['status'] === 'wartet_auf_freigabe' && user_has_permission('approve_imports')): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Diesen Import jetzt verbindlich anwenden?');"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="apply_import">
                                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Freigeben &amp; importieren</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($job['status'] === 'importiert' && user_has_permission('approve_imports')): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Diesen Import wirklich zurückrollen?');"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rollback_import">
                                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline">Rückgängig machen</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
