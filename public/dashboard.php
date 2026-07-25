<?php
/**
 * MZ Tech – Dashboard
 */
require_once __DIR__ . '/init.php';

$db = get_db();
$today = date('Y-m-d');

// ── 1. Stats ──────────────────────────────────────────────────────────────────

// Heutige Reparaturen (eingegangen heute)
$stmt = $db->prepare(
    "SELECT COUNT(*) FROM repairs WHERE DATE(created_at) = ?"
);
$stmt->execute([$today]);
$stat_today = (int)$stmt->fetchColumn();

// Offene Reparaturen (nicht abgeholt, nicht storniert)
$stmt = $db->query(
    "SELECT COUNT(*) FROM repairs WHERE status NOT IN ('abgeholt','storniert')"
);
$stat_open = (int)$stmt->fetchColumn();

// Umsatz heute (Status "fertig"/"abholbereit"/"abgeholt", inkl. alter Statuswerte
// vor der Migration, created_at heute)
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(price), 0) FROM repairs
     WHERE status IN ('fertig','abholbereit','abgeholt','repariert') AND DATE(created_at) = ?"
);
$stmt->execute([$today]);
$stat_revenue = (float)$stmt->fetchColumn();

// Kritischer Lagerbestand
$stmt = $db->query(
    "SELECT COUNT(*) FROM parts WHERE stock_quantity <= min_stock AND min_stock > 0"
);
$stat_low_stock = (int)$stmt->fetchColumn();

// ── 2. Letzte 10 Reparaturen ──────────────────────────────────────────────────
$stmt = $db->query(
    "SELECT r.id, r.repair_number, r.device_type, r.manufacturer, r.model,
            r.status, r.price, r.created_at,
            c.first_name, c.last_name
     FROM repairs r
     LEFT JOIN customers c ON c.id = r.customer_id
     ORDER BY r.id DESC
     LIMIT 10"
);
$recent_repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Abholbereit (status=abholbereit, älteste zuerst, max 5) ───────────────
$stmt = $db->query(
    "SELECT r.id, r.repair_number, r.device_type, r.manufacturer, r.model,
            r.created_at,
            c.first_name, c.last_name, c.phone
     FROM repairs r
     LEFT JOIN customers c ON c.id = r.customer_id
     WHERE r.status = 'abholbereit'
     ORDER BY r.created_at ASC
     LIMIT 5"
);
$ready_pickups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 4. Kritische Ersatzteile (max 5) ─────────────────────────────────────────
$stmt = $db->query(
    "SELECT id, name, stock_quantity, min_stock
     FROM parts
     WHERE stock_quantity <= min_stock AND min_stock > 0
     ORDER BY stock_quantity ASC
     LIMIT 5"
);
$low_parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 5. Termine heute (max 6) ──────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.type, a.repair_id,
            c.first_name, c.last_name
     FROM appointments a
     LEFT JOIN customers c ON c.id = a.customer_id
     WHERE DATE(a.start_datetime) = ?
     ORDER BY a.start_datetime ASC
     LIMIT 6"
);
$stmt->execute([$today]);
$appointments_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(start_datetime) = ?");
$stmt->execute([$today]);
$stat_appointments_today = (int)$stmt->fetchColumn();

// ── 6. Offene Terminanfragen (status=angefragt, älteste zuerst, max 5) ───────
$stmt = $db->query(
    "SELECT id, booking_number, first_name, last_name, device_type,
            manufacturer, model, preferred_date, preferred_time, created_at
     FROM booking_requests
     WHERE status = 'angefragt'
     ORDER BY preferred_date ASC, preferred_time ASC
     LIMIT 5"
);
$open_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->query("SELECT COUNT(*) FROM booking_requests WHERE status = 'angefragt'");
$stat_open_bookings = (int)$stmt->fetchColumn();

// ── 7. Chart-Daten: Statusverteilung (offene Reparaturen) ────────────────────
$stmt = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM repairs
     WHERE status NOT IN ('abgeholt','storniert')
     GROUP BY status"
);
$status_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$status_labels = [];
$status_values = [];
$status_colors_map = [
    'eingegangen'         => '#60A5FA',
    'angenommen'          => '#60A5FA',
    'in_arbeit'           => '#F59E0B',
    'in_reparatur'        => '#F59E0B',
    'warte_auf_teile'     => '#A855F7',
    'ersatzteil_bestellt' => '#A855F7',
    'repariert'           => '#34D399',
    'fertig'              => '#34D399',
    'abholbereit'         => '#0057B8',
];
$status_colors = [];
foreach ($status_rows as $row) {
    $status_labels[]  = repair_status_label($row['status']);
    $status_values[]  = (int)$row['cnt'];
    $status_colors[]  = $status_colors_map[$row['status']] ?? '#9CA3AF';
}
$status_labels_json = json_encode($status_labels);
$status_values_json = json_encode($status_values);
$status_colors_json = json_encode($status_colors);

// ── 8. Chart-Daten: Reparaturen pro Tag (letzte 30 Tage) ─────────────────────
$stmt = $db->prepare(
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
     FROM repairs
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(created_at)
     ORDER BY day ASC"
);
$stmt->execute();
$chart_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build full 30-day array (fill missing days with 0)
$chart_labels = [];
$chart_values = [];
$chart_map    = [];
foreach ($chart_rows as $row) {
    $chart_map[$row['day']] = (int)$row['cnt'];
}
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d.m.', strtotime($day));
    $chart_values[] = $chart_map[$day] ?? 0;
}

$chart_labels_json = json_encode($chart_labels);
$chart_values_json = json_encode($chart_values);

// ── Chart.js inline script (injected via $extra_js before footer) ─────────────
$extra_js = <<<JS
<script>
(function () {
  var ctx = document.getElementById('repairsChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: {$chart_labels_json},
      datasets: [{
        label: 'Reparaturen',
        data: {$chart_values_json},
        fill: true,
        tension: 0.35,
        borderColor: '#0057B8',
        backgroundColor: 'rgba(0,87,184,0.10)',
        pointBackgroundColor: '#0057B8',
        pointRadius: 3,
        pointHoverRadius: 5,
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: function(items) { return items[0].label; },
            label: function(item) { return item.parsed.y + ' Reparatur(en)'; }
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { maxRotation: 45, font: { size: 11 } }
        },
        y: {
          beginAtZero: true,
          ticks: {
            stepSize: 1,
            precision: 0,
            font: { size: 11 }
          }
        }
      }
    }
  });

  var ctx2 = document.getElementById('statusChart');
  if (ctx2 && ({$status_values_json}).length) {
    new Chart(ctx2, {
      type: 'doughnut',
      data: {
        labels: {$status_labels_json},
        datasets: [{
          data: {$status_values_json},
          backgroundColor: {$status_colors_json},
          borderWidth: 2,
          borderColor: '#fff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
          legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
        }
      }
    });
  }
})();
</script>
JS;

$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Willkommen zurück, <?= h($_SESSION['user_name'] ?? 'Benutzer') ?>. Hier ist die aktuelle Übersicht.</p>
  </div>
  <div class="page-actions">
    <a href="<?= url('repairs_form.php') ?>" class="btn btn-primary">
      <?= svg_icon('plus') ?> Neue Reparatur
    </a>
  </div>
</div>

<!-- ── Stats ── -->
<div class="stats-grid">

  <div class="stat-card">
    <div class="stat-icon blue">
      <?= svg_icon('wrench') ?>
    </div>
    <div>
      <div class="stat-label">Heutige Reparaturen</div>
      <div class="stat-value"><?= $stat_today ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon yellow">
      <?= svg_icon('clock') ?>
    </div>
    <div>
      <div class="stat-label">Offene Reparaturen</div>
      <div class="stat-value"><?= $stat_open ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon green">
      <?= svg_icon('euro') ?>
    </div>
    <div>
      <div class="stat-label">Umsatz heute</div>
      <div class="stat-value"><?= fmt_money($stat_revenue) ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon orange">
      <?= svg_icon('package') ?>
    </div>
    <div>
      <div class="stat-label">Kritischer Lagerbestand</div>
      <div class="stat-value"><?= $stat_low_stock ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon blue">
      <?= svg_icon('calendar') ?>
    </div>
    <div>
      <div class="stat-label">Termine heute</div>
      <div class="stat-value"><?= $stat_appointments_today ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon yellow">
      <?= svg_icon('inbox') ?>
    </div>
    <div>
      <div class="stat-label">Offene Terminanfragen</div>
      <div class="stat-value"><?= $stat_open_bookings ?></div>
    </div>
  </div>

</div><!-- /.stats-grid -->

<!-- ── Main row ── -->
<div style="display:grid;grid-template-columns:1fr;gap:24px;margin-top:24px;">

  <!-- ── Charts ── -->
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Reparaturen – letzte 30 Tage</h2>
      </div>
      <div class="card-body">
        <div class="chart-wrap" style="position:relative;height:260px;">
          <canvas id="repairsChart"></canvas>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Statusverteilung</h2>
      </div>
      <div class="card-body">
        <?php if (empty($status_values)): ?>
          <div class="empty-state">
            <?= svg_icon('chart') ?>
            <p>Keine offenen Reparaturen.</p>
          </div>
        <?php else: ?>
          <div class="chart-wrap" style="position:relative;height:260px;">
            <canvas id="statusChart"></canvas>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Letzte 10 Reparaturen ── -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Letzte Reparaturen</h2>
      <a href="<?= url('repairs.php') ?>" class="btn btn-sm btn-outline">Alle anzeigen</a>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if (empty($recent_repairs)): ?>
        <div class="empty-state">
          <?= svg_icon('wrench') ?>
          <p>Noch keine Reparaturen vorhanden.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Auftragsnr.</th>
                <th>Gerät</th>
                <th>Kunde</th>
                <th>Status</th>
                <th>Preis</th>
                <th>Datum</th>
                <th>Aktion</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_repairs as $r): ?>
              <tr>
                <td>
                  <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$r['id'] ?>" style="font-weight:600;color:#0057B8;text-decoration:none;">
                    <?= h($r['repair_number']) ?>
                  </a>
                </td>
                <td>
                  <?php
                    $device = trim(h($r['manufacturer']) . ' ' . h($r['model']));
                    echo $device ?: h($r['device_type']);
                  ?>
                </td>
                <td>
                  <?php if ($r['first_name'] || $r['last_name']): ?>
                    <?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?>
                  <?php else: ?>
                    <span style="color:#9CA3AF;">–</span>
                  <?php endif; ?>
                </td>
                <td><?= repair_status_badge($r['status']) ?></td>
                <td><?= $r['price'] !== null ? fmt_money((float)$r['price']) : '<span style="color:#9CA3AF;">–</span>' ?></td>
                <td><?= fmt_date($r['created_at']) ?></td>
                <td>
                  <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline" title="Anzeigen">
                    <?= svg_icon('eye') ?>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Two-column sub-row ── -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

    <!-- ── Abholbereit ── -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Abholbereit</h2>
        <?php if (count($ready_pickups) === 5): ?>
          <a href="<?= url('repairs.php') ?>?status=abholbereit" class="btn btn-sm btn-outline">Alle</a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($ready_pickups)): ?>
          <div class="empty-state">
            <?= svg_icon('check') ?>
            <p>Keine Reparaturen auf Abholung wartend.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Auftragsnr.</th>
                  <th>Gerät</th>
                  <th>Kunde</th>
                  <th>Seit</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ready_pickups as $r): ?>
                <tr>
                  <td>
                    <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$r['id'] ?>" style="font-weight:600;color:#0057B8;text-decoration:none;">
                      <?= h($r['repair_number']) ?>
                    </a>
                  </td>
                  <td>
                    <?php
                      $device = trim(h($r['manufacturer']) . ' ' . h($r['model']));
                      echo $device ?: h($r['device_type']);
                    ?>
                  </td>
                  <td><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                  <td style="white-space:nowrap;"><?= fmt_date($r['created_at']) ?></td>
                  <td>
                    <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline" title="Anzeigen">
                      <?= svg_icon('eye') ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Kritischer Lagerbestand ── -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Kritischer Lagerbestand</h2>
        <?php if ($stat_low_stock > 0): ?>
          <a href="<?= url('parts.php') ?>?filter=low_stock" class="btn btn-sm btn-outline">Alle (<?= $stat_low_stock ?>)</a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($low_parts)): ?>
          <div class="empty-state">
            <?= svg_icon('package') ?>
            <p>Kein kritischer Lagerbestand.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Ersatzteil</th>
                  <th>Bestand</th>
                  <th>Mindest</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($low_parts as $p): ?>
                <?php
                  $qty = (int)$p['stock_quantity'];
                  $min = (int)$p['min_stock'];
                  $out = $qty === 0;
                ?>
                <tr>
                  <td><?= h($p['name']) ?></td>
                  <td>
                    <span class="badge <?= $out ? 'badge-red' : 'badge-orange' ?>">
                      <?= $qty ?>
                    </span>
                  </td>
                  <td style="color:#6B7280;"><?= $min ?></td>
                  <td>
                    <a href="<?= url('parts.php') ?>?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline" title="Bearbeiten">
                      <?= svg_icon('edit') ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.two-column -->

  <!-- ── Two-column sub-row 2: Termine & Terminanfragen ── -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

    <!-- ── Termine heute ── -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Termine heute</h2>
        <a href="<?= url('calendar.php') ?>" class="btn btn-sm btn-outline">Kalender</a>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($appointments_today)): ?>
          <div class="empty-state">
            <?= svg_icon('calendar') ?>
            <p>Keine Termine für heute.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Uhrzeit</th>
                  <th>Titel</th>
                  <th>Kunde</th>
                  <th>Typ</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($appointments_today as $a): ?>
                <tr>
                  <td style="white-space:nowrap;"><?= h(date('H:i', strtotime($a['start_datetime']))) ?></td>
                  <td>
                    <?php if ($a['repair_id']): ?>
                      <a href="<?= url('repairs_view.php') ?>?id=<?= (int)$a['repair_id'] ?>" style="font-weight:600;color:#0057B8;text-decoration:none;">
                        <?= h($a['title']) ?>
                      </a>
                    <?php else: ?>
                      <?= h($a['title']) ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($a['first_name'] || $a['last_name']): ?>
                      <?= h(trim($a['first_name'] . ' ' . $a['last_name'])) ?>
                    <?php else: ?>
                      <span style="color:#9CA3AF;">–</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h(ucfirst($a['type'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Offene Terminanfragen ── -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Offene Terminanfragen</h2>
        <?php if ($stat_open_bookings > 0): ?>
          <a href="<?= url('booking_requests.php') ?>?status=angefragt" class="btn btn-sm btn-outline">Alle (<?= $stat_open_bookings ?>)</a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if (empty($open_bookings)): ?>
          <div class="empty-state">
            <?= svg_icon('inbox') ?>
            <p>Keine offenen Terminanfragen.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Nr.</th>
                  <th>Kunde</th>
                  <th>Gerät</th>
                  <th>Wunschtermin</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($open_bookings as $b): ?>
                <tr>
                  <td>
                    <a href="<?= url('booking_view.php') ?>?id=<?= (int)$b['id'] ?>" style="font-weight:600;color:#0057B8;text-decoration:none;">
                      <?= h($b['booking_number']) ?>
                    </a>
                  </td>
                  <td><?= h(trim($b['first_name'] . ' ' . $b['last_name'])) ?></td>
                  <td>
                    <?php
                      $device = trim(h($b['manufacturer']) . ' ' . h($b['model']));
                      echo $device ?: h($b['device_type']);
                    ?>
                  </td>
                  <td style="white-space:nowrap;"><?= h(fmt_date($b['preferred_date'])) ?> <?= h(substr($b['preferred_time'], 0, 5)) ?></td>
                  <td>
                    <a href="<?= url('booking_view.php') ?>?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline" title="Ansehen">
                      <?= svg_icon('eye') ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.two-column-2 -->

</div><!-- /.main row -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
