<?php
/**
 * MZ Tech – Statistiken
 */
require_once __DIR__ . '/init.php';

$db   = get_db();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Plausibilitätsprüfung Jahr
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

// ── 1. Stat-Tiles (ganzheitlich, kein Jahresfilter) ──────────────────────────

$stmt = $db->query("SELECT COUNT(*) FROM repairs");
$total_repairs = (int)$stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM customers");
$total_customers = (int)$stmt->fetchColumn();

$stmt = $db->query(
    "SELECT COALESCE(SUM(price), 0) FROM repairs WHERE status IN ('fertig','abholbereit','abgeholt','repariert')"
);
$total_revenue = (float)$stmt->fetchColumn();

$stmt = $db->query(
    "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at))
     FROM repairs WHERE completed_at IS NOT NULL"
);
$avg_hours = $stmt->fetchColumn();
$avg_hours = $avg_hours !== null ? round((float)$avg_hours, 1) : null;

// ── 2. Reparaturen pro Monat (letzten 12 Monate ab Jahresanfang des gewählten Jahres) ──
$stmt = $db->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS mon, COUNT(*) AS cnt
     FROM repairs
     WHERE YEAR(created_at) = ?
     GROUP BY mon
     ORDER BY mon ASC"
);
$stmt->execute([$year]);
$repairs_by_month_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$repairs_month_map = [];
foreach ($repairs_by_month_raw as $row) {
    $repairs_month_map[$row['mon']] = (int)$row['cnt'];
}

$month_labels   = [];
$repairs_values = [];
for ($m = 1; $m <= 12; $m++) {
    $key              = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    $month_labels[]   = date('M', mktime(0, 0, 0, $m, 1));
    $repairs_values[] = $repairs_month_map[$key] ?? 0;
}

// ── 3. Umsatz pro Monat ───────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS mon, COALESCE(SUM(price), 0) AS rev
     FROM repairs
     WHERE status IN ('fertig','abholbereit','abgeholt','repariert') AND YEAR(created_at) = ?
     GROUP BY mon
     ORDER BY mon ASC"
);
$stmt->execute([$year]);
$revenue_by_month_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$revenue_month_map = [];
foreach ($revenue_by_month_raw as $row) {
    $revenue_month_map[$row['mon']] = (float)$row['rev'];
}

$revenue_values = [];
for ($m = 1; $m <= 12; $m++) {
    $key              = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    $revenue_values[] = round($revenue_month_map[$key] ?? 0, 2);
}

// ── 4. Status-Verteilung ──────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT status, COUNT(*) AS cnt FROM repairs WHERE YEAR(created_at) = ? GROUP BY status ORDER BY cnt DESC"
);
$stmt->execute([$year]);
$status_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_labels = [];
$status_values = [];
foreach ($status_rows as $row) {
    $status_labels[] = repair_status_label($row['status']);
    $status_values[] = (int)$row['cnt'];
}

// ── 5. Gerät-Typen ────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT device_type, COUNT(*) AS cnt FROM repairs
     WHERE YEAR(created_at) = ? AND device_type IS NOT NULL AND device_type != ''
     GROUP BY device_type ORDER BY cnt DESC LIMIT 10"
);
$stmt->execute([$year]);
$device_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$device_labels = [];
$device_values = [];
foreach ($device_rows as $row) {
    $device_labels[] = $row['device_type'];
    $device_values[] = (int)$row['cnt'];
}

// ── 6. Top 10 Kunden ─────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT c.id, c.first_name, c.last_name, c.email, c.phone,
            COUNT(r.id) AS repair_count,
            COALESCE(SUM(CASE WHEN r.status IN ('fertig','abholbereit','abgeholt','repariert') THEN r.price ELSE 0 END), 0) AS total_spent
     FROM customers c
     JOIN repairs r ON r.customer_id = c.id
     WHERE YEAR(r.created_at) = ?
     GROUP BY c.id, c.first_name, c.last_name, c.email, c.phone
     ORDER BY repair_count DESC
     LIMIT 10"
);
$stmt->execute([$year]);
$top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Jahres-Optionen für Filter ────────────────────────────────────────────────
$stmt = $db->query("SELECT MIN(YEAR(created_at)), MAX(YEAR(created_at)) FROM repairs");
$minmax = $stmt->fetch(PDO::FETCH_NUM);
$year_min = $minmax[0] ? (int)$minmax[0] : (int)date('Y');
$year_max = max($year_min, (int)date('Y'));

// ── Chart.js Farben ───────────────────────────────────────────────────────────
$pie_colors = [
    '#0057B8','#F59E0B','#10B981','#6366F1','#EF4444','#8B5CF6','#EC4899','#14B8A6'
];
$pie_colors_json = json_encode(array_slice($pie_colors, 0, count($status_labels)));

$extra_js = '<script>
(function () {
  // ── Chart 1: Reparaturen pro Monat (Bar) ──
  var ctx1 = document.getElementById("chartRepairs");
  if (ctx1) {
    new Chart(ctx1, {
      type: "bar",
      data: {
        labels: ' . json_encode($month_labels) . ',
        datasets: [{
          label: "Reparaturen",
          data: ' . json_encode($repairs_values) . ',
          backgroundColor: "rgba(0,87,184,0.75)",
          borderColor: "#0057B8",
          borderWidth: 1,
          borderRadius: 4
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
          x: { grid: { display: false } }
        }
      }
    });
  }

  // ── Chart 2: Umsatz pro Monat (Line) ──
  var ctx2 = document.getElementById("chartRevenue");
  if (ctx2) {
    new Chart(ctx2, {
      type: "line",
      data: {
        labels: ' . json_encode($month_labels) . ',
        datasets: [{
          label: "Umsatz (€)",
          data: ' . json_encode($revenue_values) . ',
          fill: true,
          tension: 0.35,
          borderColor: "#10B981",
          backgroundColor: "rgba(16,185,129,0.10)",
          pointBackgroundColor: "#10B981",
          pointRadius: 3,
          pointHoverRadius: 5,
          borderWidth: 2
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function(v) { return v.toLocaleString("de-DE", {minimumFractionDigits:2}) + " €"; }
            }
          },
          x: { grid: { display: false } }
        }
      }
    });
  }

  // ── Chart 3: Status-Verteilung (Pie) ──
  var ctx3 = document.getElementById("chartStatus");
  if (ctx3) {
    new Chart(ctx3, {
      type: "pie",
      data: {
        labels: ' . json_encode($status_labels) . ',
        datasets: [{
          data: ' . json_encode($status_values) . ',
          backgroundColor: ' . $pie_colors_json . ',
          borderWidth: 2,
          borderColor: "#fff"
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: "bottom", labels: { padding: 12, font: { size: 12 } } }
        }
      }
    });
  }

  // ── Chart 4: Gerät-Typen (Horizontal Bar) ──
  var ctx4 = document.getElementById("chartDevices");
  if (ctx4) {
    new Chart(ctx4, {
      type: "bar",
      data: {
        labels: ' . json_encode($device_labels) . ',
        datasets: [{
          label: "Anzahl",
          data: ' . json_encode($device_values) . ',
          backgroundColor: "rgba(99,102,241,0.75)",
          borderColor: "#6366F1",
          borderWidth: 1,
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: "y",
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
          y: { grid: { display: false } }
        }
      }
    });
  }
})();
</script>';

$page_title = 'Statistiken';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Statistiken</h1>
    <p class="page-subtitle">Auswertungen und Kennzahlen für <?= h($year) ?></p>
  </div>
  <div class="page-actions">
    <!-- Jahresfilter -->
    <form method="get" style="display:flex;gap:8px;align-items:center;">
      <label for="year" style="font-size:.875rem;font-weight:500;color:#374151;">Jahr:</label>
      <select name="year" id="year" onchange="this.form.submit()" class="form-control" style="width:100px;">
        <?php for ($y = $year_max; $y >= $year_min; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>
</div>

<!-- ── Stat-Tiles ── -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon blue"><?= svg_icon('wrench') ?></div>
    <div>
      <div class="stat-label">Total Reparaturen</div>
      <div class="stat-value"><?= number_format($total_repairs, 0, ',', '.') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><?= svg_icon('users') ?></div>
    <div>
      <div class="stat-label">Total Kunden</div>
      <div class="stat-value"><?= number_format($total_customers, 0, ',', '.') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><?= svg_icon('euro') ?></div>
    <div>
      <div class="stat-label">Umsatz gesamt</div>
      <div class="stat-value"><?= fmt_money($total_revenue) ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow"><?= svg_icon('clock') ?></div>
    <div>
      <div class="stat-label">Ø Reparaturdauer</div>
      <div class="stat-value"><?= $avg_hours !== null ? h($avg_hours) . ' h' : '–' ?></div>
    </div>
  </div>
</div>

<!-- ── Charts Reihe 1 ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px;">
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Reparaturen pro Monat <?= h($year) ?></h2>
    </div>
    <div class="card-body">
      <div class="chart-wrap" style="position:relative;height:280px;">
        <canvas id="chartRepairs"></canvas>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Umsatz pro Monat <?= h($year) ?></h2>
    </div>
    <div class="card-body">
      <div class="chart-wrap" style="position:relative;height:280px;">
        <canvas id="chartRevenue"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- ── Charts Reihe 2 ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px;">
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Status-Verteilung <?= h($year) ?></h2>
    </div>
    <div class="card-body">
      <?php if (empty($status_labels)): ?>
        <div class="empty-state"><?= svg_icon('chart') ?><p>Keine Daten für <?= h($year) ?>.</p></div>
      <?php else: ?>
        <div class="chart-wrap" style="position:relative;height:280px;">
          <canvas id="chartStatus"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Gerät-Typen <?= h($year) ?></h2>
    </div>
    <div class="card-body">
      <?php if (empty($device_labels)): ?>
        <div class="empty-state"><?= svg_icon('chart') ?><p>Keine Daten für <?= h($year) ?>.</p></div>
      <?php else: ?>
        <div class="chart-wrap" style="position:relative;height:280px;">
          <canvas id="chartDevices"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Top 10 Kunden ── -->
<div class="card" style="margin-top:24px;">
  <div class="card-header">
    <h2 class="card-title">Top 10 Kunden (<?= h($year) ?>)</h2>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($top_customers)): ?>
      <div class="empty-state"><?= svg_icon('users') ?><p>Keine Kundendaten für <?= h($year) ?> vorhanden.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Kunde</th>
              <th>E-Mail</th>
              <th>Telefon</th>
              <th style="text-align:right;">Reparaturen</th>
              <th style="text-align:right;">Umsatz</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($top_customers as $i => $c): ?>
            <tr>
              <td style="color:#9CA3AF;font-weight:600;"><?= $i + 1 ?></td>
              <td style="font-weight:600;"><?= h(trim($c['first_name'] . ' ' . $c['last_name'])) ?></td>
              <td><?= $c['email'] ? h($c['email']) : '<span style="color:#9CA3AF;">–</span>' ?></td>
              <td><?= $c['phone'] ? h($c['phone']) : '<span style="color:#9CA3AF;">–</span>' ?></td>
              <td style="text-align:right;font-weight:600;"><?= (int)$c['repair_count'] ?></td>
              <td style="text-align:right;"><?= fmt_money((float)$c['total_spent']) ?></td>
              <td>
                <a href="<?= url('customers_form.php') ?>?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline"><?= svg_icon('eye') ?></a>
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
