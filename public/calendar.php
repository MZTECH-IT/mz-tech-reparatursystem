<?php
require_once __DIR__ . '/init.php';

$db = get_db();

// ── View-Modus (Monat / Woche / Tag) ──────────────────────────────────────
$view = trim($_GET['view'] ?? 'month');
if (!in_array($view, ['month', 'week', 'day'], true)) $view = 'month';

$now       = new DateTimeImmutable();
$today_str = $now->format('Y-m-d');

// Optionaler Anker-Tag (für Wochen-/Tagesansicht)
$date_param   = trim($_GET['date'] ?? '');
$has_date_param = ($date_param !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_param));
if ($has_date_param) {
    try { $anchor = new DateTimeImmutable($date_param); } catch (Exception $e) { $anchor = $now; }
} else {
    $anchor = $now;
}

// ── Monats-Grid-Parameter ──────────────────────────────────────────────────
$year  = (int)($_GET['year']  ?? $anchor->format('Y'));
$month = (int)($_GET['month'] ?? $anchor->format('n'));

if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }
if ($year < 2000) $year = 2000;
if ($year > 2099) $year = 2099;

$first_of_month = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$last_of_month  = $first_of_month->modify('last day of this month');
$prev_month     = $first_of_month->modify('-1 month');
$next_month     = $first_of_month->modify('+1 month');

$month_grid_start = $first_of_month->modify('Monday this week');
if ($month_grid_start > $first_of_month) {
    $month_grid_start = $month_grid_start->modify('-7 days');
}
$month_grid_end = $last_of_month->modify('Sunday this week');
if ($month_grid_end < $last_of_month) {
    $month_grid_end = $month_grid_end->modify('+7 days');
}

// Anker für Ansichtswechsel konsistent halten: wenn kein explizites Datum
// übergeben wurde und wir uns in der Monatsansicht befinden, verwende den
// Monatsersten als Anker (statt "heute"), damit "Woche"/"Tag" beim Wechsel
// im richtigen Monat landen.
if (!$has_date_param && $view === 'month') {
    $anchor = $first_of_month;
}

// ── Wochen-/Tages-Bounds ───────────────────────────────────────────────────
$week_start = $anchor->modify('Monday this week');
$week_end   = $week_start->modify('+6 days');
$day_date   = $anchor;

switch ($view) {
    case 'week':
        $range_start = $week_start;
        $range_end   = $week_end;
        break;
    case 'day':
        $range_start = $day_date;
        $range_end   = $day_date;
        break;
    default:
        $range_start = $month_grid_start;
        $range_end   = $month_grid_end;
        break;
}

// ── Termine im aktuellen Anzeigezeitraum laden ─────────────────────────────
$stmt = $db->prepare(
    'SELECT a.*,
            c.first_name, c.last_name,
            r.repair_number
     FROM appointments a
     LEFT JOIN customers c ON c.id = a.customer_id
     LEFT JOIN repairs   r ON r.id = a.repair_id
     WHERE DATE(a.start_datetime) BETWEEN ? AND ?
     ORDER BY a.start_datetime ASC'
);
$stmt->execute([$range_start->format('Y-m-d'), $range_end->format('Y-m-d')]);
$range_apts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nach Datum indiziert (für Monats-/Wochen-/Tagesraster)
$apts_by_date = [];
foreach ($range_apts as $apt) {
    $d = substr($apt['start_datetime'], 0, 10);
    $apts_by_date[$d][] = $apt;
}

// ── Stundenraster-Grenzen für Wochen-/Tagesansicht (dynamisch, min. 07–20 Uhr) ──
$grid_hour_start = 7;
$grid_hour_end   = 20;
foreach ($range_apts as $apt) {
    $start_ts = strtotime($apt['start_datetime']);
    $sh = (int)date('G', $start_ts);
    if ($sh < $grid_hour_start) $grid_hour_start = max(0, $sh);

    $eh = $sh + 1;
    if (!empty($apt['end_datetime'])) {
        $end_ts = strtotime($apt['end_datetime']);
        $eh2 = (int)date('G', $end_ts) + ((int)date('i', $end_ts) > 0 ? 1 : 0);
        if ($eh2 > $eh) $eh = $eh2;
    }
    if ($eh > $grid_hour_end) $grid_hour_end = min(24, $eh);
}

// ── Upcoming (nächste 30 Tage) ─────────────────────────────────────────────
$upcoming_stmt = $db->prepare(
    'SELECT a.*,
            c.first_name, c.last_name,
            r.repair_number
     FROM appointments a
     LEFT JOIN customers c ON c.id = a.customer_id
     LEFT JOIN repairs   r ON r.id = a.repair_id
     WHERE a.start_datetime >= NOW()
       AND a.start_datetime <= DATE_ADD(NOW(), INTERVAL 30 DAY)
     ORDER BY a.start_datetime ASC
     LIMIT 50'
);
$upcoming_stmt->execute();
$upcoming = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Offene Reparaturen für Dropdown ────────────────────────────────────────
$open_repairs_stmt = $db->query(
    "SELECT id, repair_number, manufacturer, model
     FROM repairs
     WHERE status NOT IN ('abgeholt','storniert')
     ORDER BY repair_number DESC
     LIMIT 200"
);
$open_repairs = $open_repairs_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Hilfsfunktionen ─────────────────────────────────────────────────────────
function apt_type_label(string $type): string {
    return match($type) {
        'eingang'   => 'Eingang',
        'reparatur' => 'Reparatur',
        'abholung'  => 'Abholung',
        default     => 'Sonstiges',
    };
}

function de_month_year(DateTimeImmutable $d): string {
    static $months = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',
                       7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
    return $months[(int)$d->format('n')] . ' ' . $d->format('Y');
}

function de_date_short(DateTimeImmutable $d): string {
    static $days = [1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'];
    return $days[(int)$d->format('N')] . ' ' . $d->format('d.m.');
}

function de_date_long(DateTimeImmutable $d): string {
    static $days   = [1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag',6=>'Samstag',7=>'Sonntag'];
    static $months = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',
                       7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
    return $days[(int)$d->format('N')] . ', ' . (int)$d->format('j') . '. ' . $months[(int)$d->format('n')] . ' ' . $d->format('Y');
}

/** Liefert [top_px, height_px] für die absolute Positionierung eines Termins im Stundenraster. */
function apt_position(array $apt, int $grid_hour_start, int $row_h): array {
    $start_ts      = strtotime($apt['start_datetime']);
    $start_minutes = ((int)date('G', $start_ts) - $grid_hour_start) * 60 + (int)date('i', $start_ts);
    $duration_min  = 30;
    if (!empty($apt['end_datetime'])) {
        $end_ts = strtotime($apt['end_datetime']);
        $d = ($end_ts - $start_ts) / 60;
        if ($d > 0) $duration_min = $d;
    }
    $top    = max(0, ($start_minutes / 60) * $row_h);
    $height = max(22, ($duration_min / 60) * $row_h);
    return [$top, $height];
}

function cal_link(string $view, array $extra = []): string {
    $params = array_merge(['view' => $view], $extra);
    return 'calendar.php?' . http_build_query($params);
}

// ── Navigations-URLs & Label je nach Ansicht ───────────────────────────────
switch ($view) {
    case 'week':
        $nav_prev_url  = cal_link('week', ['date' => $week_start->modify('-7 days')->format('Y-m-d')]);
        $nav_next_url  = cal_link('week', ['date' => $week_start->modify('+7 days')->format('Y-m-d')]);
        $nav_today_url = cal_link('week', ['date' => $today_str]);
        $nav_label     = 'KW ' . $week_start->format('W') . ' · ' . $week_start->format('d.m.') . '–' . $week_end->format('d.m.Y');
        break;
    case 'day':
        $nav_prev_url  = cal_link('day', ['date' => $day_date->modify('-1 day')->format('Y-m-d')]);
        $nav_next_url  = cal_link('day', ['date' => $day_date->modify('+1 day')->format('Y-m-d')]);
        $nav_today_url = cal_link('day', ['date' => $today_str]);
        $nav_label     = de_date_long($day_date);
        break;
    default:
        $nav_prev_url  = cal_link('month', ['year' => $prev_month->format('Y'), 'month' => $prev_month->format('n')]);
        $nav_next_url  = cal_link('month', ['year' => $next_month->format('Y'), 'month' => $next_month->format('n')]);
        $nav_today_url = cal_link('month');
        $nav_label     = de_month_year($first_of_month);
        break;
}

$view_month_url = cal_link('month', ['year' => $anchor->format('Y'), 'month' => $anchor->format('n')]);
$view_week_url  = cal_link('week',  ['date' => $anchor->format('Y-m-d')]);
$view_day_url   = cal_link('day',   ['date' => $anchor->format('Y-m-d')]);

$page_title = 'Kalender';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    border-left: 1px solid var(--border);
    border-top: 1px solid var(--border);
}
.cal-day-name {
    background: var(--gray-bg);
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-muted);
    text-align: center;
    padding: 8px 4px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.cal-day {
    min-height: 90px;
    padding: 6px 6px 4px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background .15s;
    position: relative;
    vertical-align: top;
}
.cal-day:hover { background: var(--blue-light); }
.cal-day.today  { background: #EFF6FF; }
.cal-day.other-month .cal-day-num { color: #C9CDD4; }
.cal-day.drop-hover { background: var(--blue-light) !important; outline: 2px dashed var(--blue); outline-offset: -2px; }
.cal-day-num {
    font-size: .8rem;
    font-weight: 600;
    margin-bottom: 4px;
    line-height: 1;
}
.cal-today-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--blue);
    color: #fff;
}
.cal-event {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    font-size: .72rem;
    padding: 2px 5px;
    border-radius: 4px;
    margin-bottom: 2px;
    line-height: 1.3;
    cursor: grab;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.cal-event:active { cursor: grabbing; }
.cal-event.dragging { opacity: .4; }
.cal-event .apt-del {
    background: none;
    border: none;
    cursor: pointer;
    font-size: .7rem;
    line-height: 1;
    padding: 0 1px;
    opacity: .6;
    flex-shrink: 0;
    color: inherit;
}
.cal-event .apt-del:hover { opacity: 1; }
.cal-event.type-eingang   { background: var(--yellow-bg); color: var(--yellow); }
.cal-event.type-reparatur { background: var(--blue-light); color: var(--blue); }
.cal-event.type-abholung  { background: var(--green-bg);  color: var(--green); }
.cal-event.type-sonstiges { background: var(--gray-bg);   color: var(--gray); }
.apt-list-table th { cursor: pointer; user-select: none; }
.apt-list-table th:hover { color: var(--blue); }
.apt-list-table .sort-arrow { font-size:.7rem; margin-left:3px; }

/* ── View-Switch ── */
.view-switch { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.view-switch-btn {
    padding: 7px 14px;
    font-size: .82rem;
    font-weight: 600;
    color: var(--text-muted);
    text-decoration: none;
    background: #fff;
    border-right: 1px solid var(--border);
}
.view-switch-btn:last-child { border-right: none; }
.view-switch-btn.active { background: var(--blue); color: #fff; }
.view-switch-btn:hover:not(.active) { background: var(--gray-bg); }

/* ── Wochen-/Tagesraster ── */
.wk-wrap { display: flex; border-left: 1px solid var(--border); border-top: 1px solid var(--border); }
.wk-time-col { flex: 0 0 56px; }
.wk-col-head {
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--text-muted);
    background: var(--gray-bg);
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    box-sizing: border-box;
}
.wk-col-head.today { color: var(--blue); }
.wk-time-label {
    font-size: .7rem;
    color: var(--text-muted);
    text-align: right;
    padding: 2px 6px 0 0;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    box-sizing: border-box;
}
.wk-day-col { flex: 1; min-width: 100px; border-right: 1px solid var(--border); }
.wk-day-body { position: relative; }
.wk-hour-cell {
    border-bottom: 1px solid var(--border);
    box-sizing: border-box;
    cursor: pointer;
    transition: background .1s;
}
.wk-hour-cell:hover { background: var(--blue-light); }
.wk-hour-cell.drop-hover { background: var(--blue-light); outline: 2px dashed var(--blue); outline-offset: -2px; }
.wk-day-col.today .wk-hour-cell { background: #FAFCFF; }
.wk-event {
    position: absolute;
    left: 3px;
    right: 3px;
    border-radius: 5px;
    padding: 3px 6px;
    font-size: .72rem;
    line-height: 1.25;
    overflow: hidden;
    cursor: grab;
    box-shadow: 0 1px 2px rgba(0,0,0,.12);
    z-index: 2;
}
.wk-event:active { cursor: grabbing; }
.wk-event.dragging { opacity: .4; }
.wk-event.type-eingang   { background: var(--yellow-bg); color: var(--yellow); border-left: 3px solid var(--yellow); }
.wk-event.type-reparatur { background: var(--blue-light); color: var(--blue); border-left: 3px solid var(--blue); }
.wk-event.type-abholung  { background: var(--green-bg);  color: var(--green); border-left: 3px solid var(--green); }
.wk-event.type-sonstiges { background: var(--gray-bg);   color: var(--gray); border-left: 3px solid var(--gray); }
.wk-event .apt-del {
    position: absolute;
    top: 1px;
    right: 2px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: .68rem;
    opacity: .55;
    line-height: 1;
    padding: 1px;
    color: inherit;
}
.wk-event .apt-del:hover { opacity: 1; }
</style>

<!-- ── Toolbar ───────────────────────────────────────────────────────────── -->
<div class="toolbar">
    <div class="toolbar-search" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <a href="<?= h($nav_prev_url) ?>" class="btn btn-outline">
            <?= svg_icon('chevron-left', 16) ?>
        </a>
        <h2 style="margin:0;font-size:1.1rem;font-weight:700;min-width:200px;text-align:center;">
            <?= h($nav_label) ?>
        </h2>
        <a href="<?= h($nav_next_url) ?>" class="btn btn-outline">
            <?= svg_icon('chevron-right', 16) ?>
        </a>
        <a href="<?= h($nav_today_url) ?>" class="btn btn-outline" style="font-size:.82rem;">Heute</a>
    </div>
    <div class="toolbar-actions" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">
        <div class="view-switch">
            <a href="<?= h($view_month_url) ?>" class="view-switch-btn <?= $view === 'month' ? 'active' : '' ?>">Monat</a>
            <a href="<?= h($view_week_url) ?>"  class="view-switch-btn <?= $view === 'week'  ? 'active' : '' ?>">Woche</a>
            <a href="<?= h($view_day_url) ?>"   class="view-switch-btn <?= $view === 'day'   ? 'active' : '' ?>">Tag</a>
        </div>
        <?php if (ics_feed_enabled()): ?>
            <a href="<?= url('settings.php') ?>#kalender" class="btn btn-outline" title="Kalender-Abo-Link und Google-Kalender-Synchronisation verwalten">
                <?= svg_icon('calendar', 16) ?> Kalender-Sync
            </a>
        <?php endif; ?>
        <button onclick="openAptModal(null)" class="btn btn-primary">
            <?= svg_icon('plus', 18) ?> Neuer Termin
        </button>
    </div>
</div>

<?php if ($view === 'month'): ?>
<!-- ── Monatsraster ──────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body" style="padding:0;overflow:hidden;">
        <div class="cal-grid">
            <!-- Day names -->
            <?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $dn): ?>
                <div class="cal-day-name"><?= $dn ?></div>
            <?php endforeach; ?>

            <?php
            $cur = $month_grid_start;
            while ($cur <= $month_grid_end):
                $cur_str    = $cur->format('Y-m-d');
                $is_today   = ($cur_str === $today_str);
                $is_cur_mo  = ((int)$cur->format('n') === $month);
                $day_apts   = $apts_by_date[$cur_str] ?? [];
            ?>
                <div class="cal-day <?= !$is_cur_mo ? 'other-month' : '' ?> <?= $is_today ? 'today' : '' ?>"
                     data-date="<?= $cur_str ?>"
                     onclick="openAptModal('<?= $cur_str ?>')"
                     ondragover="allowDrop(event)"
                     ondragleave="dragLeave(event)"
                     ondrop="handleMonthDrop(event, '<?= $cur_str ?>')"
                     title="<?= $cur->format('d.m.Y') ?>">
                    <div class="cal-day-num">
                        <?php if ($is_today): ?>
                            <span class="cal-today-num"><?= $cur->format('j') ?></span>
                        <?php else: ?>
                            <?= $cur->format('j') ?>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($day_apts as $apt):
                        $apt_time = substr($apt['start_datetime'], 11, 5);
                    ?>
                        <div class="cal-event type-<?= h($apt['type'] ?? 'sonstiges') ?>"
                             draggable="true"
                             data-id="<?= (int)$apt['id'] ?>"
                             data-time="<?= h($apt_time) ?>"
                             ondragstart="handleDragStart(event, <?= (int)$apt['id'] ?>, '<?= h($apt_time) ?>')"
                             ondragend="handleDragEnd(event)"
                             onclick="event.stopPropagation();"
                             title="<?= h($apt['title']) . ($apt['start_datetime'] ? ' – ' . $apt_time : '') ?>">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php if ($apt['start_datetime']): ?>
                                    <span style="opacity:.7;"><?= $apt_time ?></span>
                                <?php endif; ?>
                                <?= h($apt['title']) ?>
                            </span>
                            <button class="apt-del"
                                    data-id="<?= (int)$apt['id'] ?>"
                                    onclick="event.stopPropagation(); deleteApt(<?= (int)$apt['id'] ?>, <?= json_encode($apt['title']) ?>, this)"
                                    title="Löschen">✕</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php $cur = $cur->modify('+1 day'); endwhile; ?>
        </div>
    </div>
</div>

<?php else: /* week / day */
    $grid_days = [];
    if ($view === 'week') {
        $gd = $week_start;
        for ($i = 0; $i < 7; $i++) { $grid_days[] = $gd; $gd = $gd->modify('+1 day'); }
    } else {
        $grid_days = [$day_date];
    }
    $ROW_H = 48;
?>
<!-- ── Wochen-/Tagesraster ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <div class="wk-wrap" style="min-width:<?= $view === 'week' ? '760px' : '320px' ?>;">
            <div class="wk-time-col">
                <div class="wk-col-head">&nbsp;</div>
                <?php for ($h = $grid_hour_start; $h < $grid_hour_end; $h++): ?>
                    <div class="wk-time-label" style="height:<?= $ROW_H ?>px;"><?= sprintf('%02d:00', $h) ?></div>
                <?php endfor; ?>
            </div>
            <?php foreach ($grid_days as $gday):
                $gday_str = $gday->format('Y-m-d');
                $is_today = ($gday_str === $today_str);
                $day_apts = $apts_by_date[$gday_str] ?? [];
            ?>
                <div class="wk-day-col <?= $is_today ? 'today' : '' ?>">
                    <div class="wk-col-head <?= $is_today ? 'today' : '' ?>"><?= h(de_date_short($gday)) ?></div>
                    <div class="wk-day-body" style="height:<?= ($grid_hour_end - $grid_hour_start) * $ROW_H ?>px;">
                        <?php for ($h = $grid_hour_start; $h < $grid_hour_end; $h++): ?>
                            <div class="wk-hour-cell"
                                 style="height:<?= $ROW_H ?>px;"
                                 onclick="openAptModal('<?= $gday_str ?>', '<?= sprintf('%02d:00', $h) ?>')"
                                 ondragover="allowDrop(event)"
                                 ondragleave="dragLeave(event)"
                                 ondrop="handleGridDrop(event, '<?= $gday_str ?>', <?= $h ?>)"></div>
                        <?php endfor; ?>
                        <?php foreach ($day_apts as $apt):
                            [$top, $height] = apt_position($apt, $grid_hour_start, $ROW_H);
                            $apt_time = substr($apt['start_datetime'], 11, 5);
                        ?>
                            <div class="wk-event type-<?= h($apt['type'] ?? 'sonstiges') ?>"
                                 draggable="true"
                                 data-id="<?= (int)$apt['id'] ?>"
                                 data-time="<?= h($apt_time) ?>"
                                 ondragstart="handleDragStart(event, <?= (int)$apt['id'] ?>, '<?= h($apt_time) ?>')"
                                 ondragend="handleDragEnd(event)"
                                 onclick="event.stopPropagation();"
                                 style="top:<?= $top ?>px;height:<?= $height ?>px;"
                                 title="<?= h($apt['title']) . ' – ' . $apt_time ?>">
                                <strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= $apt_time ?> <?= h($apt['title']) ?>
                                </strong>
                                <?php if ($apt['first_name'] || $apt['last_name']): ?>
                                    <span style="opacity:.8;font-size:.68rem;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        <?= h(trim($apt['first_name'] . ' ' . $apt['last_name'])) ?>
                                    </span>
                                <?php endif; ?>
                                <button class="apt-del"
                                        data-id="<?= (int)$apt['id'] ?>"
                                        onclick="event.stopPropagation(); deleteApt(<?= (int)$apt['id'] ?>, <?= json_encode($apt['title']) ?>, this)"
                                        title="Löschen">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Upcoming Appointments ─────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <?= svg_icon('calendar', 20) ?> Bevorstehende Termine (nächste 30 Tage)
            <span class="badge-secondary"><?= count($upcoming) ?></span>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($upcoming)): ?>
            <div class="empty-state">
                <?= svg_icon('calendar', 48) ?>
                <p>Keine bevorstehenden Termine in den nächsten 30 Tagen.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table apt-list-table" id="upcoming-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)" data-col="0">Datum <?= svg_icon('chevrons-up-down', 13) ?></th>
                            <th onclick="sortTable(1)" data-col="1">Uhrzeit <?= svg_icon('chevrons-up-down', 13) ?></th>
                            <th onclick="sortTable(2)" data-col="2">Titel <?= svg_icon('chevrons-up-down', 13) ?></th>
                            <th onclick="sortTable(3)" data-col="3">Typ <?= svg_icon('chevrons-up-down', 13) ?></th>
                            <th onclick="sortTable(4)" data-col="4">Kunde <?= svg_icon('chevrons-up-down', 13) ?></th>
                            <th onclick="sortTable(5)" data-col="5">Reparatur <?= svg_icon('chevrons-up-down', 13) ?></th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming as $apt): ?>
                            <tr data-id="<?= (int)$apt['id'] ?>">
                                <td data-sort="<?= h($apt['start_datetime']) ?>"><?= h(fmt_date($apt['start_datetime'])) ?></td>
                                <td><?= $apt['start_datetime'] ? substr($apt['start_datetime'], 11, 5) . ' Uhr' : '<span class="text-muted">—</span>' ?></td>
                                <td><strong><?= h($apt['title']) ?></strong>
                                    <?php if ($apt['notes']): ?>
                                        <br><small class="text-muted"><?= h(mb_strimwidth($apt['notes'], 0, 60, '…')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cal-event type-<?= h($apt['type'] ?? 'sonstiges') ?>" style="display:inline-block;cursor:default;">
                                        <?= h(apt_type_label($apt['type'] ?? 'sonstiges')) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($apt['first_name'] || $apt['last_name']): ?>
                                        <?= h(trim($apt['first_name'] . ' ' . $apt['last_name'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($apt['repair_number']): ?>
                                        <a href="repairs_view.php?id=<?= (int)$apt['repair_id'] ?>" class="font-mono text-primary">
                                            <?= h($apt['repair_number']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-actions">
                                    <button class="btn btn-sm btn-danger"
                                            onclick="deleteApt(<?= (int)$apt['id'] ?>, <?= json_encode($apt['title']) ?>, this)"
                                            title="Löschen">
                                        <?= svg_icon('trash', 14) ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Add Appointment Modal ─────────────────────────────────────────────── -->
<div class="modal-overlay" id="apt-modal-overlay">
    <div class="modal" style="max-width:600px;width:100%;" role="dialog" aria-modal="true" aria-labelledby="apt-modal-title">
        <div class="modal-header">
            <span class="modal-title" id="apt-modal-title">Neuer Termin</span>
            <button class="modal-close" onclick="closeAptModal()" aria-label="Schließen">✕</button>
        </div>
        <form id="apt-form">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div id="apt-form-error" class="alert alert-danger" style="display:none;margin-bottom:1rem;"></div>
                <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group full" style="grid-column:1/-1;">
                        <label for="apt-title">Titel <span style="color:var(--red)">*</span></label>
                        <input type="text" id="apt-title" name="title" required placeholder="z.B. Abholung Müller – iPhone 13">
                    </div>
                    <div class="form-group">
                        <label for="apt-date">Datum <span style="color:var(--red)">*</span></label>
                        <input type="date" id="apt-date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="apt-time">Uhrzeit</label>
                        <input type="time" id="apt-time" name="time">
                    </div>
                    <div class="form-group full" style="grid-column:1/-1;">
                        <label for="apt-type">Typ</label>
                        <select id="apt-type" name="type">
                            <option value="sonstiges">Sonstiges</option>
                            <option value="eingang">Eingang</option>
                            <option value="reparatur">Reparatur</option>
                            <option value="abholung">Abholung</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="apt-repair">Optionale Reparatur</label>
                        <select id="apt-repair" name="repair_id">
                            <option value="">– Keine –</option>
                            <?php foreach ($open_repairs as $rep): ?>
                                <option value="<?= (int)$rep['id'] ?>">
                                    <?= h($rep['repair_number']) ?>
                                    <?php if ($rep['manufacturer'] || $rep['model']): ?>
                                        – <?= h(trim($rep['manufacturer'] . ' ' . $rep['model'])) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="apt-customer">Optionaler Kunde (Name)</label>
                        <input type="text" id="apt-customer-name" name="customer_name" placeholder="Vor- und Nachname suchen …" autocomplete="off">
                        <input type="hidden" id="apt-customer-id" name="customer_id" value="">
                        <div id="apt-customer-results" style="display:none;position:absolute;z-index:200;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-md);max-width:260px;"></div>
                    </div>
                    <div class="form-group full" style="grid-column:1/-1;">
                        <label for="apt-notes">Notizen</label>
                        <textarea id="apt-notes" name="notes" rows="3" placeholder="Optionale Notizen …"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeAptModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary" id="apt-submit-btn">
                    <?= svg_icon('calendar', 16) ?> Speichern
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var CSRF = <?= json_encode(csrf_token()) ?>;

// ── Appointment Modal ─────────────────────────────────────────────────────
function openAptModal(dateStr, timeStr) {
    var overlay = document.getElementById('apt-modal-overlay');
    document.getElementById('apt-title').value   = '';
    document.getElementById('apt-date').value    = dateStr || '';
    document.getElementById('apt-time').value    = timeStr || '';
    document.getElementById('apt-type').value    = 'sonstiges';
    document.getElementById('apt-repair').value  = '';
    document.getElementById('apt-customer-name').value = '';
    document.getElementById('apt-customer-id').value   = '';
    document.getElementById('apt-notes').value   = '';
    document.getElementById('apt-form-error').style.display = 'none';
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('apt-title').focus();
}
function closeAptModal() {
    document.getElementById('apt-modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('apt-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeAptModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAptModal();
});

// ── Customer live search ──────────────────────────────────────────────────
var csTimeout;
document.getElementById('apt-customer-name').addEventListener('input', function() {
    var val = this.value.trim();
    clearTimeout(csTimeout);
    document.getElementById('apt-customer-id').value = '';
    if (val.length < 2) {
        document.getElementById('apt-customer-results').style.display = 'none';
        return;
    }
    csTimeout = setTimeout(function() {
        fetch((window.APP_URL_BASE || '') + '/api/calendar.php?action=search_customers&q=' + encodeURIComponent(val) + '&csrf_token=' + encodeURIComponent(CSRF))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var box = document.getElementById('apt-customer-results');
            box.innerHTML = '';
            if (!data.customers || data.customers.length === 0) {
                box.style.display = 'none';
                return;
            }
            data.customers.forEach(function(c) {
                var item = document.createElement('div');
                item.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:.88rem;';
                item.textContent = c.first_name + ' ' + c.last_name;
                item.addEventListener('mousedown', function() {
                    document.getElementById('apt-customer-name').value = c.first_name + ' ' + c.last_name;
                    document.getElementById('apt-customer-id').value   = c.id;
                    box.style.display = 'none';
                });
                item.addEventListener('mouseover', function() { item.style.background = 'var(--blue-light)'; });
                item.addEventListener('mouseout',  function() { item.style.background = ''; });
                box.appendChild(item);
            });
            // Position relative to input
            var input = document.getElementById('apt-customer-name');
            var rect  = input.getBoundingClientRect();
            box.style.top   = (input.offsetTop + input.offsetHeight + 2) + 'px';
            box.style.left  = input.offsetLeft + 'px';
            box.style.width = input.offsetWidth + 'px';
            box.style.display = 'block';
        });
    }, 280);
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('#apt-customer-name') && !e.target.closest('#apt-customer-results')) {
        document.getElementById('apt-customer-results').style.display = 'none';
    }
});

// ── Form submit ───────────────────────────────────────────────────────────
document.getElementById('apt-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn  = document.getElementById('apt-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Speichern …';

    var data = new FormData(this);
    data.append('action', 'save');

    fetch((window.APP_URL_BASE || '') + '/api/calendar.php', {
        method: 'POST',
        body: data
    })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
        btn.disabled = false;
        btn.innerHTML = '<svg>…</svg> Speichern';
        if (resp.success) {
            closeAptModal();
            window.location.reload();
        } else {
            var errBox = document.getElementById('apt-form-error');
            errBox.textContent = resp.message || 'Unbekannter Fehler.';
            errBox.style.display = 'block';
        }
    })
    .catch(function() {
        btn.disabled = false;
        var errBox = document.getElementById('apt-form-error');
        errBox.textContent = 'Netzwerkfehler. Bitte versuche es erneut.';
        errBox.style.display = 'block';
    });
});

// ── Delete appointment ────────────────────────────────────────────────────
function deleteApt(id, title, btn) {
    if (!confirm('Termin «' + title + '» wirklich löschen?')) return;
    fetch((window.APP_URL_BASE || '') + '/api/calendar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&id=' + id + '&csrf_token=' + encodeURIComponent(CSRF)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            // Remove from cal grid
            document.querySelectorAll('[data-id="' + id + '"]').forEach(function(el) { el.remove(); });
            // Remove chip
            var chip = btn.closest('.cal-event') || btn.closest('.wk-event');
            if (chip) chip.remove();
            // Remove list row
            var row = btn.closest('tr');
            if (row) row.remove();
        } else {
            alert(data.message || 'Fehler beim Löschen.');
        }
    })
    .catch(function() { alert('Netzwerkfehler.'); });
}

// ── Drag & Drop: Terminverschiebung ────────────────────────────────────────
var dragData = null;

function handleDragStart(e, id, time) {
    dragData = { id: id, time: time };
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', String(id)); } catch (err) { /* Safari-Fallback ok */ }
    e.target.classList.add('dragging');
}
function handleDragEnd(e) {
    e.target.classList.remove('dragging');
}
function allowDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drop-hover');
}
function dragLeave(e) {
    e.currentTarget.classList.remove('drop-hover');
}
function handleMonthDrop(e, dateStr) {
    e.preventDefault();
    e.currentTarget.classList.remove('drop-hover');
    if (!dragData) return;
    rescheduleAppointment(dragData.id, dateStr, dragData.time);
    dragData = null;
}
function handleGridDrop(e, dateStr, hour) {
    e.preventDefault();
    e.currentTarget.classList.remove('drop-hover');
    if (!dragData) return;
    var time = String(hour).padStart(2, '0') + ':00';
    rescheduleAppointment(dragData.id, dateStr, time);
    dragData = null;
}
function rescheduleAppointment(id, dateStr, time) {
    fetch((window.APP_URL_BASE || '') + '/api/calendar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=reschedule&id=' + encodeURIComponent(id) + '&date=' + encodeURIComponent(dateStr) +
              '&time=' + encodeURIComponent(time || '') + '&csrf_token=' + encodeURIComponent(CSRF)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Termin konnte nicht verschoben werden.');
        }
    })
    .catch(function() { alert('Netzwerkfehler beim Verschieben.'); });
}

// ── Table sorting ─────────────────────────────────────────────────────────
var sortDir = {};
function sortTable(col) {
    var table = document.getElementById('upcoming-table');
    var tbody = table.querySelector('tbody');
    var rows  = Array.from(tbody.querySelectorAll('tr'));
    var asc   = sortDir[col] !== true;
    sortDir = {};
    sortDir[col] = asc;

    rows.sort(function(a, b) {
        var aCell = a.querySelectorAll('td')[col];
        var bCell = b.querySelectorAll('td')[col];
        var aVal  = (aCell.dataset.sort || aCell.textContent).trim().toLowerCase();
        var bVal  = (bCell.dataset.sort || bCell.textContent).trim().toLowerCase();
        if (aVal < bVal) return asc ? -1 : 1;
        if (aVal > bVal) return asc ? 1 : -1;
        return 0;
    });
    rows.forEach(function(r) { tbody.appendChild(r); });

    // Update arrows
    table.querySelectorAll('th').forEach(function(th, i) {
        th.style.color = i === col ? 'var(--blue)' : '';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
