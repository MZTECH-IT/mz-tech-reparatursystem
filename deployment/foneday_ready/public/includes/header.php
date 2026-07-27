<?php
/**
 * MZ Tech – Seitenheader
 * Wird von jeder Seite eingebunden NACH require_auth()
 */
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user_name    = $_SESSION['user_name'] ?? 'Benutzer';
$user_initials = strtoupper(substr($user_name, 0, 1)) . (strpos($user_name, ' ') !== false ? strtoupper(substr(strrchr($user_name, ' '), 1, 1)) : '');
$company_name = get_setting('company_name', 'MZ Tech');
$page_title   = $page_title ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($page_title) ?> – <?= h($company_name) ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
  <?php if (!empty($extra_css)) echo $extra_css; ?>
  <script>window.APP_URL_BASE = <?= json_encode(APP_URL_BASE, JSON_UNESCAPED_SLASHES) ?>;</script>
</head>
<body>
<div class="app-shell">

  <!-- ── Sidebar ── -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none">
        <rect width="32" height="32" rx="8" fill="#0057B8"/>
        <text x="16" y="22" font-family="Arial" font-weight="bold" font-size="14" fill="white" text-anchor="middle">MZ</text>
      </svg>
      <span><?= h($company_name) ?></span>
    </div>

    <nav class="sidebar-nav">
      <span class="nav-group-label">Übersicht</span>
      <a href="<?= url('dashboard.php') ?>" class="nav-item <?= $current_page==='dashboard'?'active':'' ?>">
        <?= svg_icon('dashboard') ?> Dashboard
      </a>

      <span class="nav-group-label">Werkstatt</span>
      <a href="<?= url('repairs.php') ?>" class="nav-item <?= $current_page==='repairs'||$current_page==='repairs_form'||$current_page==='repairs_view'?'active':'' ?>">
        <?= svg_icon('wrench') ?> Reparaturen
      </a>
      <a href="<?= url('customers.php') ?>" class="nav-item <?= $current_page==='customers'||$current_page==='customers_form'?'active':'' ?>">
        <?= svg_icon('users') ?> Kunden
      </a>
      <a href="<?= url('parts.php') ?>" class="nav-item <?= $current_page==='parts'?'active':'' ?>">
        <?= svg_icon('package') ?> Ersatzteile
      </a>
      <a href="<?= url('calendar.php') ?>" class="nav-item <?= $current_page==='calendar'?'active':'' ?>">
        <?= svg_icon('calendar') ?> Kalender
      </a>
      <a href="<?= url('booking_requests.php') ?>" class="nav-item <?= $current_page==='booking_requests'||$current_page==='booking_view'?'active':'' ?>">
        <?= svg_icon('clock') ?> Terminanfragen
      </a>
      <a href="<?= url('repair_requests.php') ?>" class="nav-item <?= $current_page==='repair_requests'||$current_page==='repair_request_view'?'active':'' ?>">
        <?= svg_icon('inbox') ?> Online-Anfragen
      </a>
      <a href="<?= url('customer_messages.php') ?>" class="nav-item <?= $current_page==='customer_messages'||$current_page==='customer_message_view'?'active':'' ?>">
        <?= svg_icon('message-circle') ?> Kundennachrichten
        <?php
            // Defensiv: falls die Tabelle (z. B. vor einem DB-Update) noch nicht
            // existiert, darf das die Navigation auf keiner Seite blockieren.
            $unread_msg_count = 0;
            try {
                $unread_msg_count = (int)get_db()
                    ->query("SELECT COUNT(*) FROM customer_messages WHERE sender='customer' AND is_read=0")
                    ->fetchColumn();
            } catch (Throwable $e) {
                $unread_msg_count = 0;
            }
        ?>
        <?php if ($unread_msg_count > 0): ?>
          <span class="badge-red" style="margin-left:auto;"><?= $unread_msg_count ?></span>
        <?php endif; ?>
      </a>

      <span class="nav-group-label">Dokumente</span>
      <a href="<?= url('documents.php') ?>" class="nav-item <?= $current_page==='documents'?'active':'' ?>">
        <?= svg_icon('list') ?> Dokumentenliste
      </a>
      <a href="<?= url('quotes.php') ?>" class="nav-item <?= $current_page==='quotes'||$current_page==='quotes_form'?'active':'' ?>">
        <?= svg_icon('clipboard') ?> Angebote
      </a>
      <a href="<?= url('delivery_notes.php') ?>" class="nav-item <?= $current_page==='delivery_notes'||$current_page==='delivery_notes_form'?'active':'' ?>">
        <?= svg_icon('package') ?> Lieferscheine
      </a>
      <a href="<?= url('invoice_corrections.php') ?>" class="nav-item <?= $current_page==='invoice_corrections'||$current_page==='invoice_corrections_form'?'active':'' ?>">
        <?= svg_icon('euro') ?> Gutschriften/Storno
      </a>
      <a href="<?= url('companies.php') ?>" class="nav-item <?= $current_page==='companies'||$current_page==='companies_form'?'active':'' ?>">
        <?= svg_icon('users') ?> Firmenkunden
      </a>

      <?php if (user_has_permission('manage_suppliers') || user_has_permission('manage_purchase_orders')): ?>
      <span class="nav-group-label">Beschaffung</span>
      <?php if (user_has_permission('manage_suppliers')): ?>
      <a href="<?= url('suppliers.php') ?>" class="nav-item <?= $current_page==='suppliers'||$current_page==='suppliers_form'?'active':'' ?>">
        <?= svg_icon('package') ?> Lieferanten &amp; Großhändler
      </a>

      <?php if (user_has_permission('manage_tickets') || user_has_permission('manage_companies')): ?>
      <span class="nav-group-label">Support &amp; Portale</span>
      <?php if (user_has_permission('manage_tickets')): ?>
      <a href="<?= url('tickets.php') ?>" class="nav-item <?= $current_page==='tickets'||$current_page==='ticket_view'?'active':'' ?>">
        <?= svg_icon('message-circle') ?> Tickets
      </a>
      <?php endif; ?>
      <?php if (user_has_permission('manage_companies')): ?>
      <a href="<?= url('portal_access.php') ?>" class="nav-item <?= $current_page==='portal_access'?'active':'' ?>">
        <?= svg_icon('key') ?> Portalzugänge
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <a href="<?= url('supplier_interfaces.php') ?>" class="nav-item <?= $current_page==='supplier_interfaces'?'active':'' ?>">
        <?= svg_icon('settings') ?> API &amp; Adapter
      </a>
      <a href="<?= url('supplier_offers.php') ?>" class="nav-item <?= $current_page==='supplier_offers'?'active':'' ?>">
        <?= svg_icon('list') ?> Lieferantenangebote
      </a>
      <?php endif; ?>
      <?php if (user_has_permission('manage_purchase_orders')): ?>
      <a href="<?= url('procurement_suggestions.php') ?>" class="nav-item <?= $current_page==='procurement_suggestions'?'active':'' ?>">
        <?= svg_icon('inbox') ?> Beschaffungsvorschläge
      </a>
      <a href="<?= url('purchase_orders.php') ?>" class="nav-item <?= $current_page==='purchase_orders'||$current_page==='purchase_order_form'?'active':'' ?>">
        <?= svg_icon('database') ?> Bestellungen
      </a>
      <a href="<?= url('purchase_order_receive.php') ?>" class="nav-item <?= $current_page==='purchase_order_receive'?'active':'' ?>">
        <?= svg_icon('upload') ?> Wareneingang
      </a>
      <?php endif; ?>
      <?php if (user_has_permission('manage_suppliers')): ?>
      <a href="<?= url('supplier_shipping_rules.php') ?>" class="nav-item <?= $current_page==='supplier_shipping_rules'?'active':'' ?>">
        <?= svg_icon('package') ?> Versandkostenregeln
      </a>
      <a href="<?= url('supplier_sync_logs.php') ?>" class="nav-item <?= $current_page==='supplier_sync_logs'?'active':'' ?>">
        <?= svg_icon('refresh-cw') ?> Synchronisationsprotokoll
      </a>
      <a href="<?= url('pricing_rules.php') ?>" class="nav-item <?= $current_page==='pricing_rules'?'active':'' ?>">
        <?= svg_icon('euro') ?> Kalkulationsregeln
      </a>
      <a href="<?= url('foneday.php') ?>" class="nav-item <?= in_array($current_page, ['foneday','foneday_queue'], true)?'active':'' ?>">
        <?= svg_icon('refresh-cw') ?> Foneday API
      </a>
      <?php endif; ?>
      <?php endif; ?>

      <?php if (user_has_permission('manage_accounting')): ?>
      <span class="nav-group-label">Buchhaltung</span>
      <a href="<?= url('accounting_settings.php') ?>" class="nav-item <?= $current_page==='accounting_settings'?'active':'' ?>">
        <?= svg_icon('settings') ?> Einstellungen
      </a>
      <a href="<?= url('accounting_sync.php') ?>" class="nav-item <?= $current_page==='accounting_sync'?'active':'' ?>">
        <?= svg_icon('refresh-cw') ?> Synchronisation
      </a>
      <a href="<?= url('accounting_exports.php') ?>" class="nav-item <?= $current_page==='accounting_exports'?'active':'' ?>">
        <?= svg_icon('download') ?> Exporte
      </a>
      <a href="<?= url('accounting_logs.php') ?>" class="nav-item <?= $current_page==='accounting_logs'?'active':'' ?>">
        <?= svg_icon('list') ?> Protokoll
      </a>
      <?php endif; ?>

      <span class="nav-group-label">Auswertung</span>
      <a href="<?= url('statistics.php') ?>" class="nav-item <?= $current_page==='statistics'?'active':'' ?>">
        <?= svg_icon('chart') ?> Statistiken
      </a>
      <a href="<?= url('activity.php') ?>" class="nav-item <?= $current_page==='activity'?'active':'' ?>">
        <?= svg_icon('list') ?> Aktivitätslog
      </a>

      <?php if (is_admin()): ?>
      <span class="nav-group-label">System</span>
      <a href="<?= url('settings.php') ?>" class="nav-item <?= $current_page==='settings'?'active':'' ?>">
        <?= svg_icon('settings') ?> Einstellungen
      </a>
      <a href="<?= url('backup.php') ?>" class="nav-item <?= $current_page==='backup'?'active':'' ?>">
        <?= svg_icon('database') ?> Backup
      </a>
      <a href="<?= url('email_templates.php') ?>" class="nav-item <?= $current_page==='email_templates'?'active':'' ?>">
        <?= svg_icon('mail') ?> E-Mail-Vorlagen
      </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      MZ Tech Repair v1.0
    </div>
  </aside>

  <!-- ── Haupt-Bereich ── -->
  <div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
      <div class="topbar-left">
        <button class="hamburger" id="hamburger" aria-label="Menü">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <span class="page-title"><?= h($page_title) ?></span>
      </div>

      <div class="topbar-right">
        <div class="dropdown">
          <div class="user-badge" onclick="document.getElementById('user-menu').classList.toggle('open')" style="cursor:pointer;">
            <div class="user-avatar"><?= h($user_initials) ?></div>
            <div style="line-height:1.2;">
              <div style="font-weight:600;font-size:.85rem;"><?= h($user_name) ?></div>
              <div style="font-size:.75rem;color:var(--text-muted);"><?= is_admin()?'Administrator':'Techniker' ?></div>
            </div>
          </div>
          <div class="dropdown-menu" id="user-menu">
            <a href="<?= url('profile.php') ?>" class="dropdown-item">
              <?= svg_icon('user','14') ?> Mein Profil
            </a>
            <?php if (is_admin()): ?>
            <a href="<?= url('settings.php') ?>" class="dropdown-item">
              <?= svg_icon('settings','14') ?> Einstellungen
            </a>
            <?php endif; ?>
            <hr class="dropdown-divider">
            <a href="<?= url('logout.php') ?>" class="dropdown-item danger">
              <?= svg_icon('logout','14') ?> Abmelden
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Flash-Nachricht -->
    <div style="padding:0 28px;">
      <?php show_flash(); ?>
    </div>

    <!-- Page Content -->
    <main class="main-content">
