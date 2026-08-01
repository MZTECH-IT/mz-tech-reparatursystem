<?php
/**
 * MZ Tech – Firmenkunden: Anlegen/Bearbeiten inkl. Ansprechpartner,
 * Projekten und Dokumenten (Phase 5)
 */
require_once __DIR__ . '/init.php';
require_permission('manage_companies');

$db = get_db();
$id = (int)($_GET['id'] ?? 0);
$company = $id ? company_find($id) : null;
if ($id && !$company) {
    flash('error', 'Firma nicht gefunden.');
    header('Location: ' . url('companies.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_company') {
        if ($id) {
            company_update($id, $_POST);
            log_activity('company_updated', 'companies', $id, 'Firma aktualisiert');
            flash('success', 'Firma erfolgreich aktualisiert.');
            header('Location: ' . url('companies_form.php') . '?id=' . $id);
        } else {
            $newId = company_create($_POST, $_SESSION['user_id'] ?? null);
            log_activity('company_created', 'companies', $newId, 'Firma "' . trim($_POST['company_name'] ?? '') . '" angelegt');
            flash('success', 'Firma erfolgreich angelegt. Sie können nun Ansprechpartner und Projekte hinzufügen.');
            header('Location: ' . url('companies_form.php') . '?id=' . $newId);
        }
        exit;
    }

    if ($action === 'add_contact' && $id) {
        $result = company_contact_create($id, $_POST);
        if ($result['success'] && !empty($result['activation_link'])) {
            $_SESSION['business_activation_once'] = [
                'company_id' => $id,
                'contact_id' => (int)$result['id'],
                'link' => (string)$result['activation_link'],
            ];
        }
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('companies_form.php') . '?id=' . $id . '#kontakte');
        exit;
    }

    if ($action === 'edit_contact' && $id) {
        $cid = (int)($_POST['contact_id'] ?? 0);
        $targetContact = $cid ? company_contact_find($cid) : null;
        if ($targetContact && (int)$targetContact['company_id'] === $id) {
            company_contact_update($cid, $_POST);
            flash('success', 'Ansprechpartner aktualisiert.');
        }
        header('Location: ' . url('companies_form.php') . '?id=' . $id . '#kontakte');
        exit;
    }

    if ($action === 'add_project' && $id) {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('error', 'Bitte einen Projektnamen angeben.');
        } else {
            $pid = project_create($id, $_POST, $_SESSION['user_id'] ?? null);
            log_activity('project_created', 'projects', $pid, 'Projekt angelegt');
            flash('success', 'Projekt erfolgreich angelegt.');
        }
        header('Location: ' . url('companies_form.php') . '?id=' . $id . '#projekte');
        exit;
    }

    if ($action === 'edit_project' && $id) {
        $pid = (int)($_POST['project_id'] ?? 0);
        if ($pid) {
            project_update($pid, $_POST);
            flash('success', 'Projekt aktualisiert.');
        }
        header('Location: ' . url('companies_form.php') . '?id=' . $id . '#projekte');
        exit;
    }

    if ($action === 'upload_document' && $id) {
        if (!empty($_FILES['document']['name'])) {
            $saved = company_document_save($_FILES['document'], $id, trim($_POST['description'] ?? ''), $_SESSION['user_id'] ?? null);
            if ($saved) {
                log_activity('company_document_uploaded', 'companies', $id, 'Dokument hochgeladen');
                flash('success', 'Dokument erfolgreich hochgeladen.');
            } else {
                flash('error', 'Dokument konnte nicht hochgeladen werden (Dateityp/Größe prüfen).');
            }
        }
        header('Location: ' . url('companies_form.php') . '?id=' . $id . '#dokumente');
        exit;
    }

    if ($action === 'delete_document' && $id) {
        $did = (int)($_POST['document_id'] ?? 0);
        if ($did) {
            company_document_delete($did);
            flash('success', 'Dokument gelöscht.');
        }
        header('Location: ' . url('companies_form.php') . '?id=' . $id . '#dokumente');
        exit;
    }
}

$contacts  = $id ? company_contacts_list($id) : [];
$projects  = $id ? projects_list_for_company($id) : [];
$documents = $id ? company_documents_list($id) : [];
$customers = $id ? company_customers($id) : [];
$all_customers = $db->query("SELECT id, first_name, last_name FROM customers ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
$businessActivationOnce = $_SESSION['business_activation_once'] ?? null;
unset($_SESSION['business_activation_once']);
if (!$businessActivationOnce || (int)($businessActivationOnce['company_id'] ?? 0) !== $id) {
    $businessActivationOnce = null;
}

$page_title = $id ? 'Firma: ' . $company['company_name'] : 'Neue Firma';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $id ? h($company['company_name']) : 'Neue Firma anlegen' ?></h1>
    <p class="page-subtitle"><a href="<?= url('companies.php') ?>"><?= svg_icon('arrow-left', 14) ?> Zurück zur Übersicht</a></p>
  </div>
</div>

<?php show_flash(); ?>

<?php if ($businessActivationOnce): ?>
  <div class="alert alert-warning" id="business-activation-once">
    <strong>Aktivierungslink – nur jetzt sichtbar:</strong>
    <input type="text" id="business_activation_link_once" readonly
           value="<?= h($businessActivationOnce['link']) ?>"
           onclick="this.select()" style="width:100%;margin-top:8px;">
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:24px;">
  <div class="card-header"><h2 class="card-title">Firmendaten</h2></div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_company">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1;">
          <label for="company_name">Firmenname *</label>
          <input type="text" id="company_name" name="company_name" class="form-control" required value="<?= h($company['company_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="email">E-Mail</label>
          <input type="email" id="email" name="email" class="form-control" value="<?= h($company['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="phone">Telefon</label>
          <input type="text" id="phone" name="phone" class="form-control" value="<?= h($company['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="website">Website</label>
          <input type="url" id="website" name="website" class="form-control" value="<?= h($company['website'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="zip">PLZ</label>
          <input type="text" id="zip" name="zip" class="form-control" value="<?= h($company['zip'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="city">Ort</label>
          <input type="text" id="city" name="city" class="form-control" value="<?= h($company['city'] ?? '') ?>">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label for="address">Adresse (Straße, Hausnummer)</label>
          <input type="text" id="address" name="address" class="form-control" value="<?= h($company['address'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="tax_id">USt-IdNr.</label>
          <input type="text" id="tax_id" name="tax_id" class="form-control" value="<?= h($company['tax_id'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="vat_id">Steuernummer</label>
          <input type="text" id="vat_id" name="vat_id" class="form-control" value="<?= h($company['vat_id'] ?? '') ?>">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label for="notes">Notizen</label>
          <textarea id="notes" name="notes" class="form-control" rows="3"><?= h($company['notes'] ?? '') ?></textarea>
        </div>
        <?php if ($id): ?>
        <div class="form-group" style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" id="is_active" name="is_active" value="1" <?= !empty($company['is_active']) ? 'checked' : '' ?>>
          <label for="is_active" style="margin:0;">Firma aktiv</label>
        </div>
        <?php endif; ?>
      </div>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> <?= $id ? 'Speichern' : 'Firma anlegen' ?></button>
      </div>
    </form>
  </div>
</div>

<?php if ($id): ?>

<!-- ── Ansprechpartner ── -->
<div class="card" id="kontakte" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('users') ?> Ansprechpartner</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-new-contact').style.display='flex'"><?= svg_icon('plus') ?> Ansprechpartner hinzufügen</button>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($contacts)): ?>
      <div class="empty-state"><p>Noch keine Ansprechpartner.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Name</th><th>E-Mail</th><th>Funktion</th><th>Status</th><th>Letzter Login</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($contacts as $c): ?>
            <tr>
              <td style="font-weight:600;"><?= h($c['first_name'] . ' ' . $c['last_name']) ?> <?= $c['is_primary'] ? '<span class="badge badge-blue">Primär</span>' : '' ?></td>
              <td><?= h($c['email']) ?></td>
              <td><?= h($c['role_title'] ?? '') ?></td>
              <td>
                <?php if (!$c['is_active']): ?><span class="badge badge-red">Deaktiviert</span>
                <?php elseif (!$c['is_verified']): ?><span class="badge badge-yellow">Einladung ausstehend</span>
                <?php else: ?><span class="badge badge-green">Aktiv</span><?php endif; ?>
              </td>
              <td><?= $c['last_login_at'] ? fmt_date($c['last_login_at'], true) : '–' ?></td>
              <td>
                <button class="btn btn-sm btn-outline" onclick='openEditContact(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'><?= svg_icon('edit') ?></button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Projekte ── -->
<div class="card" id="projekte" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('clipboard') ?> Projekte</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-new-project').style.display='flex'"><?= svg_icon('plus') ?> Neues Projekt</button>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($projects)): ?>
      <div class="empty-state"><p>Noch keine Projekte.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Nr.</th><th>Name</th><th>Status</th><th>Aufträge</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($projects as $p): $prepairs = project_repairs((int)$p['id']); ?>
            <tr>
              <td><code><?= h($p['project_number']) ?></code></td>
              <td style="font-weight:600;"><?= h($p['name']) ?></td>
              <td>
                <?php $st = ['aktiv'=>'badge-blue','abgeschlossen'=>'badge-green','pausiert'=>'badge-gray'][$p['status']] ?? 'badge-gray'; ?>
                <span class="badge <?= $st ?>"><?= h(ucfirst($p['status'])) ?></span>
              </td>
              <td><?= count($prepairs) ?></td>
              <td><button class="btn btn-sm btn-outline" onclick='openEditProject(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'><?= svg_icon('edit') ?></button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Zugeordnete Kunden & Aufträge ── -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-header"><h2 class="card-title"><?= svg_icon('user') ?> Zugeordnete Kunden</h2></div>
  <div class="card-body">
    <?php if (empty($customers)): ?>
      <p class="text-muted">Noch keine Kunden dieser Firma zugeordnet. Die Zuordnung erfolgt über die Kundenbearbeitung ("Firma" auswählen).</p>
    <?php else: ?>
      <ul style="margin:0;padding-left:18px;">
        <?php foreach ($customers as $cu): ?>
          <li><a href="<?= url('customers_form.php') ?>?id=<?= (int)$cu['id'] ?>"><?= h($cu['first_name'] . ' ' . $cu['last_name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<!-- ── Dokumente ── -->
<div class="card" id="dokumente" style="margin-bottom:24px;">
  <div class="card-header"><h2 class="card-title"><?= svg_icon('download') ?> Dokumente</h2></div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_document">
      <div class="form-group" style="margin:0;">
        <label>Datei</label>
        <input type="file" name="document" required>
      </div>
      <div class="form-group" style="margin:0;flex:1;">
        <label>Beschreibung</label>
        <input type="text" name="description" class="form-control" placeholder="z. B. Wartungsvertrag 2026">
      </div>
      <button type="submit" class="btn btn-primary"><?= svg_icon('upload') ?> Hochladen</button>
    </form>

    <?php if (empty($documents)): ?>
      <p class="text-muted">Noch keine Dokumente hochgeladen.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Datei</th><th>Beschreibung</th><th>Hochgeladen von</th><th>Datum</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($documents as $d): ?>
            <tr>
              <td><a href="<?= url('company_document.php') ?>?id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['original_name']) ?></a></td>
              <td><?= h($d['description'] ?? '') ?></td>
              <td><?= h($d['uploaded_by_name'] ?? '–') ?></td>
              <td><?= fmt_date($d['created_at']) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Dokument wirklich löschen?');" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_document">
                  <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger"><?= svg_icon('trash') ?></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Modal: Ansprechpartner hinzufügen ── -->
<div id="modal-new-contact" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;">
  <div class="card" style="width:480px;max-width:95vw;margin:0;">
    <div class="card-header">
      <h2 class="card-title">Ansprechpartner hinzufügen</h2>
      <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-new-contact').style.display='none'">✕</button>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_contact">
        <div class="form-grid">
          <div class="form-group"><label>Vorname *</label><input type="text" name="first_name" class="form-control" required></div>
          <div class="form-group"><label>Nachname *</label><input type="text" name="last_name" class="form-control" required></div>
          <div class="form-group" style="grid-column:1/-1;"><label>E-Mail *</label><input type="email" name="email" class="form-control" required></div>
          <div class="form-group"><label>Telefon</label><input type="text" name="phone" class="form-control"></div>
          <div class="form-group"><label>Funktion</label><input type="text" name="role_title" class="form-control" placeholder="z. B. IT-Leitung"></div>
          <div class="form-group"><label>Portalrolle</label>
            <select name="portal_role" class="form-control">
              <option value="employee">Firmenmitarbeiter</option>
              <option value="read_only">Nur Lesen</option>
              <option value="admin">Firmenadministrator</option>
            </select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px;">
            <input type="checkbox" id="np_primary" name="is_primary" value="1">
            <label for="np_primary" style="margin:0;">Primärer Ansprechpartner</label>
          </div>
        </div>
        <p class="form-hint">Nach dem Anlegen senden Sie den Aktivierungslink kontrolliert über die Portalverwaltung. Der Ansprechpartner legt damit selbst sein Passwort fest.</p>
        <div style="margin-top:16px;display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-new-contact').style.display='none'">Abbrechen</button>
          <button type="submit" class="btn btn-primary"><?= svg_icon('plus') ?> Hinzufügen</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Ansprechpartner bearbeiten ── -->
<div id="modal-edit-contact" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;">
  <div class="card" style="width:480px;max-width:95vw;margin:0;">
    <div class="card-header">
      <h2 class="card-title">Ansprechpartner bearbeiten</h2>
      <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-contact').style.display='none'">✕</button>
    </div>
    <div class="card-body">
      <form method="post" id="form-edit-contact">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_contact">
        <input type="hidden" name="contact_id" id="ec_id">
        <div class="form-grid">
          <div class="form-group"><label>Vorname</label><input type="text" name="first_name" id="ec_first_name" class="form-control" required></div>
          <div class="form-group"><label>Nachname</label><input type="text" name="last_name" id="ec_last_name" class="form-control" required></div>
          <div class="form-group"><label>Telefon</label><input type="text" name="phone" id="ec_phone" class="form-control"></div>
          <div class="form-group"><label>Funktion</label><input type="text" name="role_title" id="ec_role_title" class="form-control"></div>
          <div class="form-group"><label>Portalrolle</label>
            <select name="portal_role" id="ec_portal_role" class="form-control">
              <option value="employee">Firmenmitarbeiter</option>
              <option value="read_only">Nur Lesen</option>
              <option value="admin">Firmenadministrator</option>
            </select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="ec_primary" name="is_primary" value="1">
            <label for="ec_primary" style="margin:0;">Primärer Ansprechpartner</label>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="ec_active" name="is_active" value="1">
            <label for="ec_active" style="margin:0;">Zugang aktiv</label>
          </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-contact').style.display='none'">Abbrechen</button>
          <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Neues Projekt ── -->
<div id="modal-new-project" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;">
  <div class="card" style="width:480px;max-width:95vw;margin:0;">
    <div class="card-header">
      <h2 class="card-title">Neues Projekt</h2>
      <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-new-project').style.display='none'">✕</button>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_project">
        <div class="form-group"><label>Projektname *</label><input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label>Beschreibung</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div style="margin-top:16px;display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-new-project').style.display='none'">Abbrechen</button>
          <button type="submit" class="btn btn-primary"><?= svg_icon('plus') ?> Anlegen</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Projekt bearbeiten ── -->
<div id="modal-edit-project" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;">
  <div class="card" style="width:480px;max-width:95vw;margin:0;">
    <div class="card-header">
      <h2 class="card-title">Projekt bearbeiten</h2>
      <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-project').style.display='none'">✕</button>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_project">
        <input type="hidden" name="project_id" id="ep_id">
        <div class="form-group"><label>Projektname</label><input type="text" name="name" id="ep_name" class="form-control" required></div>
        <div class="form-group"><label>Beschreibung</label><textarea name="description" id="ep_description" class="form-control" rows="3"></textarea></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status" id="ep_status" class="form-control">
            <option value="aktiv">Aktiv</option>
            <option value="pausiert">Pausiert</option>
            <option value="abgeschlossen">Abgeschlossen</option>
          </select>
        </div>
        <div style="margin-top:16px;display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-project').style.display='none'">Abbrechen</button>
          <button type="submit" class="btn btn-primary"><?= svg_icon('check') ?> Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditContact(c) {
  document.getElementById('ec_id').value = c.id;
  document.getElementById('ec_first_name').value = c.first_name;
  document.getElementById('ec_last_name').value = c.last_name;
  document.getElementById('ec_phone').value = c.phone || '';
  document.getElementById('ec_role_title').value = c.role_title || '';
  document.getElementById('ec_portal_role').value = c.portal_role || 'employee';
  document.getElementById('ec_primary').checked = c.is_primary == 1;
  document.getElementById('ec_active').checked = c.is_active == 1;
  document.getElementById('modal-edit-contact').style.display = 'flex';
}
function openEditProject(p) {
  document.getElementById('ep_id').value = p.id;
  document.getElementById('ep_name').value = p.name;
  document.getElementById('ep_description').value = p.description || '';
  document.getElementById('ep_status').value = p.status;
  document.getElementById('modal-edit-project').style.display = 'flex';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    ['modal-new-contact','modal-edit-contact','modal-new-project','modal-edit-project'].forEach(function(id){
      document.getElementById(id).style.display = 'none';
    });
  }
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
