<?php
/**
 * MZ Tech – Dokumentenliste (Dokumentenmodul, Auftragsabschnitt 1 + 6)
 * ----------------------------------------------------------------------
 * Konsolidierte, read-only Übersicht ALLER Dokumenttypen des Systems
 * (Rechnungen/Kostenvoranschläge über repairs, Angebote, Lieferscheine,
 * Gutschriften/Stornorechnungen, Bestellungen) an einer zentralen Stelle –
 * inkl. deutlich sichtbarem Entwurfsstatus, wie in Abschnitt 1 gefordert
 * ("... muss ... in der Dokumentenliste sichtbar sein"). Liest bewusst NUR
 * (keine Schreibaktionen hier) und nutzt ausschließlich bereits bestehende
 * Listing-Funktionen der jeweiligen Module – keine neue Datenhaltung.
 */
require_once __DIR__ . '/init.php';
require_once PRIVATE_PATH . '/permissions.php';
require_once PRIVATE_PATH . '/numbering.php';
require_once PRIVATE_PATH . '/invoicing.php';
require_once PRIVATE_PATH . '/quotes.php';
require_once PRIVATE_PATH . '/delivery_notes.php';
require_once PRIVATE_PATH . '/invoice_corrections.php';
if (is_file(PRIVATE_PATH . '/purchase_orders.php')) {
    require_once PRIVATE_PATH . '/purchase_orders.php';
}

require_permission('create_invoice_drafts');

$db = get_db();

/**
 * Baut die einheitliche Zeilenstruktur:
 *   type_label, type_key, number, is_draft, status_label, badge, date, link
 */
$rows = [];

// ── Rechnungen & Kostenvoranschläge (repairs) ──────────────────────────
$stmt = $db->query(
    "SELECT id, repair_number, quote_number, quote_status, invoice_number, invoice_status,
            invoice_released_at, created_at
       FROM repairs
      WHERE quote_number IS NOT NULL OR invoice_number IS NOT NULL
         OR quote_status <> 'entwurf' OR invoice_status = 'freigegeben'"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!empty($r['quote_number']) || ($r['quote_status'] ?? 'entwurf') !== 'entwurf') {
        $released = !empty($r['quote_number']) && ($r['quote_status'] ?? 'entwurf') !== 'entwurf';
        $rows[] = [
            'type_label' => 'Kostenvoranschlag',
            'is_draft'   => !$released,
            'number'     => $released ? $r['quote_number'] : ('Entwurf #' . $r['id']),
            'status'     => $released ? ($r['quote_status'] ?? '') : 'entwurf',
            'date'       => $r['created_at'],
            'link'       => url('pdf/kostenvoranschlag.php') . '?id=' . (int)$r['id'],
        ];
    }
    if (!empty($r['invoice_number']) || invoice_is_released($r)) {
        $released = invoice_is_released($r);
        $rows[] = [
            'type_label' => 'Rechnung',
            'is_draft'   => !$released,
            'number'     => $released ? $r['invoice_number'] : ('Entwurf #' . $r['id']),
            'status'     => $released ? 'freigegeben' : 'entwurf',
            'date'       => $r['invoice_released_at'] ?: $r['created_at'],
            'link'       => url('pdf/rechnung.php') . '?id=' . (int)$r['id'],
        ];
    }
}

// ── Angebote ────────────────────────────────────────────────────────────
foreach (quotes_list() as $q) {
    $released = !empty($q['quote_number']) && ($q['status'] ?? 'entwurf') !== 'entwurf';
    $rows[] = [
        'type_label' => 'Angebot',
        'is_draft'   => !$released,
        'number'     => $released ? $q['quote_number'] : ('Entwurf #' . $q['id']),
        'status'     => $q['status'] ?? 'entwurf',
        'date'       => $q['released_at'] ?: $q['created_at'],
        'link'       => url('quotes_form.php') . '?id=' . (int)$q['id'],
    ];
}

// ── Lieferscheine ───────────────────────────────────────────────────────
foreach (delivery_notes_list() as $d) {
    $released = !empty($d['delivery_note_number']);
    $rows[] = [
        'type_label' => 'Lieferschein',
        'is_draft'   => !$released,
        'number'     => $released ? $d['delivery_note_number'] : ('Entwurf #' . $d['id']),
        'status'     => $d['status'] ?? 'entwurf',
        'date'       => $d['finalized_at'] ?: $d['created_at'],
        'link'       => url('delivery_notes_form.php') . '?id=' . (int)$d['id'],
    ];
}

// ── Gutschriften / Stornorechnungen ─────────────────────────────────────
foreach (invoice_corrections_list() as $c) {
    $released = !empty($c['correction_number']);
    $typeLabel = $c['correction_type'] === 'storno' ? 'Stornorechnung' : 'Gutschrift';
    $rows[] = [
        'type_label' => $typeLabel,
        'is_draft'   => !$released,
        'number'     => $released ? $c['correction_number'] : ('Entwurf #' . $c['id']),
        'status'     => $c['status'] ?? 'entwurf',
        'date'       => $c['released_at'] ?: $c['created_at'],
        'link'       => url('invoice_corrections_form.php') . '?id=' . (int)$c['id'],
    ];
}

// ── Bestellungen (falls Modul vorhanden) ────────────────────────────────
if (function_exists('purchase_orders_list')) {
    try {
        foreach (purchase_orders_list() as $p) {
            $released = !empty($p['order_number']);
            $rows[] = [
                'type_label' => 'Bestellung',
                'is_draft'   => $p['status'] === 'entwurf',
                'number'     => $released ? $p['order_number'] : ('Entwurf #' . $p['id']),
                'status'     => $p['status'] ?? 'entwurf',
                'date'       => $p['ordered_at'] ?? $p['created_at'],
                'link'       => url('purchase_order_form.php') . '?id=' . (int)$p['id'],
            ];
        }
    } catch (Throwable $e) {
        // Bestellmodul evtl. noch nicht vollständig migriert – Dokumentenliste
        // bleibt trotzdem benutzbar (die übrigen Dokumenttypen werden weiter
        // angezeigt).
    }
}

// ── Filter (Typ / nur Entwürfe) ─────────────────────────────────────────
$typeFilter  = trim($_GET['type'] ?? '');
$draftsOnly  = !empty($_GET['drafts_only']);
if ($typeFilter !== '') {
    $rows = array_filter($rows, fn($r) => $r['type_label'] === $typeFilter);
}
if ($draftsOnly) {
    $rows = array_filter($rows, fn($r) => $r['is_draft']);
}

// ── Sortierung: neueste zuerst ──────────────────────────────────────────
usort($rows, function ($a, $b) {
    return strtotime($b['date'] ?? '1970-01-01') <=> strtotime($a['date'] ?? '1970-01-01');
});

$allTypes = ['Rechnung', 'Kostenvoranschlag', 'Angebot', 'Lieferschein', 'Gutschrift', 'Stornorechnung', 'Bestellung'];

$statusBadge = [
    'entwurf'             => 'badge-gray',
    'freigegeben'         => 'badge-blue',
    'gesendet'            => 'badge-blue',
    'angenommen'          => 'badge-green',
    'erstellt'            => 'badge-blue',
    'versendet'           => 'badge-blue',
    'zugestellt'          => 'badge-green',
    'bestellt'            => 'badge-blue',
    'geliefert'           => 'badge-green',
    'teilweise_geliefert' => 'badge-orange',
    'abgelehnt'           => 'badge-red',
    'abgelaufen'          => 'badge-orange',
    'storniert'           => 'badge-red',
];

$page_title = 'Dokumente';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Dokumente</h1>
    <p class="page-subtitle">Alle Rechnungen, Kostenvoranschläge, Angebote, Lieferscheine, Gutschriften/Stornorechnungen und Bestellungen an einer Stelle – Entwürfe sind deutlich als solche gekennzeichnet.</p>
  </div>
</div>

<?php show_flash(); ?>

<div class="toolbar">
  <form method="get" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <select name="type" class="search-input" style="width:auto;">
      <option value="">– Alle Dokumenttypen –</option>
      <?php foreach ($allTypes as $t): ?>
        <option value="<?= h($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= h($t) ?></option>
      <?php endforeach; ?>
    </select>
    <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;">
      <input type="checkbox" name="drafts_only" value="1" <?= $draftsOnly ? 'checked' : '' ?>> nur Entwürfe
    </label>
    <button type="submit" class="btn btn-outline"><?= svg_icon('search', 18) ?> Filtern</button>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <h2 class="card-title"><?= svg_icon('list', 20) ?> Dokumente <span class="badge badge-gray"><?= count($rows) ?></span></h2>
  </div>
  <div class="card-body">
    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <?= svg_icon('list', 48) ?>
        <p>Keine Dokumente gefunden.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Typ</th>
              <th>Nummer</th>
              <th>Status</th>
              <th>Datum</th>
              <th class="col-actions">Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['type_label']) ?></td>
              <td class="font-mono">
                <?= h($r['number']) ?>
                <?php if ($r['is_draft']): ?>
                  <span class="badge badge-orange" title="Vorläufige Vorschau, keine gültige Nummer">ENTWURF</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $b = $statusBadge[$r['status']] ?? 'badge-gray'; ?>
                <span class="badge <?= $b ?>"><?= h($r['status']) ?></span>
              </td>
              <td><?= $r['date'] ? h(date('d.m.Y', strtotime($r['date']))) : '<span class="text-muted">–</span>' ?></td>
              <td class="col-actions">
                <a href="<?= h($r['link']) ?>" target="_blank" class="btn btn-sm btn-outline"><?= svg_icon('eye', 15) ?> Ansehen</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
