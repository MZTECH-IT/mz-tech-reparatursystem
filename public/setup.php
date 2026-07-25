<?php
/**
 * MZ Tech Repair System – Installations-Assistent
 * Standalone-Seite, kein init.php, kein Auth
 */
$private   = dirname(__DIR__) . '/private';
$lock_file = $private . '/installed.lock';

// ── Heuristik: Wurde bereits (mit einer älteren Version dieses Assistenten
//    ohne Sperrdatei) installiert? Anhand der Platzhalter in config.php. ───
function is_installed(string $private_path): bool {
    $cfg = $private_path . '/config.php';
    if (!file_exists($cfg)) {
        return false;
    }
    $content = file_get_contents($cfg);
    // Platzhalter noch vorhanden → nicht installiert
    if (
        str_contains($content, 'DATENBANK_NAME')      ||
        str_contains($content, 'DATENBANK_BENUTZER')  ||
        str_contains($content, 'DATENBANK_PASSWORT')  ||
        str_contains($content, 'ENCRYPTION_KEY_PLACEHOLDER')
    ) {
        return false;
    }
    return true;
}

// ── Sperrdatei-Mechanismus (automatische Selbstsperre nach Installation) ──
// Sobald diese Datei existiert, führt der Assistent KEINE Datenbank- oder
// Konfigurationsänderungen mehr aus (auch nicht bei erneutem Aufruf von
// setup.php?step=2/3/4) – unabhängig vom Inhalt der URL oder POST-Daten.
// Dadurch kann niemand über eine vergessene setup.php ein zusätzliches
// Administrator-Konto anlegen oder die Datenbankverbindung umbiegen.
function write_install_lock(string $lock_path, string $reason): void {
    $payload = json_encode([
        'installed_at' => date('c'),
        'reason'       => $reason,
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unbekannt',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    // @-Operator: Wenn private/ aus irgendeinem Grund nicht mehr beschreibbar
    // ist (z. B. nach dem empfohlenen chmod 750 durch einen anderen Benutzer),
    // soll das die Sperr-Prüfung selbst nicht zum Absturz bringen – die
    // Platzhalter-Heuristik greift dann weiterhin als Rückfallebene.
    @file_put_contents($lock_path, $payload);
}

// ── Eigene, von der Hauptanwendung unabhängige Session für den Assistenten ─
// (vor der Installation existieren weder Datenbank noch App-Session-Konfig)
session_name('mztech_setup');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function setup_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) . '">';
}
function setup_verify_csrf(): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '');
}

// ── Sperre prüfen ───────────────────────────────────────────────────────────
$is_locked = file_exists($lock_file);

if (!$is_locked && is_installed($private)) {
    // Bestandsinstallation von vor Einführung der Sperrdatei (z. B. ein
    // bereits laufendes System, auf das dieses Update hochgeladen wurde):
    // Sperre jetzt automatisch nachtragen – keine manuelle Aktion nötig.
    write_install_lock($lock_file, 'auto-detected-existing-install');
    $is_locked = true;
}

// Direkt nach Abschluss von Schritt 4 darf im selben Assistenten-Browser
// EINMALIG die Abschluss-Seite (Schritt 5) mit den soeben angelegten
// Zugangsdaten angezeigt werden. Jeder andere Aufruf – auch step=2/3/4 mit
// POST-Daten – wird bei aktiver Sperre sofort und ohne jede Datenbank- oder
// Dateisystemänderung abgewiesen.
$show_post_install_summary = $is_locked
    && !empty($_SESSION['setup_just_completed'])
    && (int)($_GET['step'] ?? 0) === 5
    && $_SERVER['REQUEST_METHOD'] === 'GET';

if ($is_locked && !$show_post_install_summary) {
    http_response_code(200);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MZ Tech – Installation bereits abgeschlossen</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f0f4f8; color:#1a202c; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:1.5rem; }
            .box { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.06); padding:2.5rem; max-width:480px; text-align:center; }
            .box .icon { font-size:2.5rem; margin-bottom:1rem; }
            .box h1 { font-size:1.25rem; margin:0 0 .75rem; }
            .box p { color:#64748b; font-size:.9rem; line-height:1.6; margin:0 0 1.5rem; }
            .box a { display:inline-block; background:#1a56db; color:#fff; text-decoration:none; padding:.7rem 1.6rem; border-radius:8px; font-weight:600; font-size:.9rem; }
            .box a:hover { background:#1e40af; }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="icon">&#128274;</div>
            <h1>Installation bereits abgeschlossen</h1>
            <p>
                Der Installations-Assistent wurde automatisch gesperrt, um Ihr System zu
                schützen. Es sind keine weiteren Schritte nötig. Falls Sie das System
                wirklich neu installieren möchten, löschen Sie die Datei
                <code>private/installed.lock</code> über den KAS-Datei-Manager oder FTP.
            </p>
            <a href="index.php">Zum Login &#8594;</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── SQL-Statement-Splitter (schema-import-sicher) ──────────────────────────
// WICHTIG: Ein naives explode(';', $sql) reicht NICHT aus, um sql/schema.sql
// in einzelne Anweisungen zu zerlegen. Die HTML-E-Mail-Vorlagen in der
// Tabelle email_templates enthalten CSS-Attribute wie
// style="color:#0057B8;font-weight:bold;" – die Semikolons darin liegen
// zwar sicher innerhalb eines einfach gequoteten SQL-Strings, ein reines
// explode(';', ...) kennt aber keine String-Grenzen und zerschneidet genau
// dort mitten in einem gültigen INSERT-Statement. Das erzeugt exakt den
// gemeldeten Fehler "1064 ... near '&#039;&#039;&lt;p&gt;Hallo ...'": ein
// Fragment endet mitten in einer Zeichenkette, das nächste beginnt mit dem
// verbleibenden Rest, den MySQL nicht mehr sinnvoll parsen kann.
//
// split_sql_statements() zerlegt das Skript stattdessen zeichenweise unter
// Berücksichtigung von '...'-Strings (inkl. ''- und \'-Escaping),
// "..."-Strings (z. B. HTML-Attribute), `...`-Bezeichnern sowie
// -- / # Zeilenkommentaren und /* ... */ Blockkommentaren. Nur Semikolons,
// die tatsächlich außerhalb all dieser Kontexte stehen, gelten als
// Anweisungsende.
function split_sql_statements(string $sql): array {
    $statements = [];
    $buffer = '';
    $state  = 'none'; // none|single|double|backtick|line_comment|block_comment
    $len    = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c  = $sql[$i];
        $c2 = ($i + 1 < $len) ? $sql[$i + 1] : '';

        switch ($state) {
            case 'none':
                if ($c === '-' && $c2 === '-') { $state = 'line_comment'; $buffer .= $c; break; }
                if ($c === '#')                { $state = 'line_comment'; $buffer .= $c; break; }
                if ($c === '/' && $c2 === '*') { $state = 'block_comment'; $buffer .= $c; break; }
                if ($c === "'")  { $state = 'single';   $buffer .= $c; break; }
                if ($c === '"')  { $state = 'double';   $buffer .= $c; break; }
                if ($c === '`')  { $state = 'backtick'; $buffer .= $c; break; }
                if ($c === ';') {
                    $trimmed = trim($buffer);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $buffer = '';
                    break;
                }
                $buffer .= $c;
                break;

            case 'single':
                $buffer .= $c;
                if ($c === '\\') {
                    if ($c2 !== '') { $buffer .= $c2; $i++; }
                    break;
                }
                if ($c === "'") {
                    if ($c2 === "'") { $buffer .= $c2; $i++; break; }
                    $state = 'none';
                }
                break;

            case 'double':
                $buffer .= $c;
                if ($c === '\\') {
                    if ($c2 !== '') { $buffer .= $c2; $i++; }
                    break;
                }
                if ($c === '"') {
                    if ($c2 === '"') { $buffer .= $c2; $i++; break; }
                    $state = 'none';
                }
                break;

            case 'backtick':
                $buffer .= $c;
                if ($c === '`') { $state = 'none'; }
                break;

            case 'line_comment':
                $buffer .= $c;
                if ($c === "\n") { $state = 'none'; }
                break;

            case 'block_comment':
                $buffer .= $c;
                if ($c === '*' && $c2 === '/') { $buffer .= $c2; $i++; $state = 'none'; }
                break;
        }
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

// ── Config updater ────────────────────────────────────────────────────────
function update_config(string $key, string $value): void {
    $path    = dirname(__DIR__) . '/private/config.php';
    $content = file_get_contents($path);
    $content = preg_replace(
        "/define\('" . preg_quote($key, '/') . "',\s*'[^']*'\)/",
        "define('" . $key . "', '" . addslashes($value) . "')",
        $content
    );
    file_put_contents($path, $content);
}

// ── Step handling ─────────────────────────────────────────────────────────
$step     = max(1, min(5, (int)($_GET['step'] ?? 1)));
$errors   = [];
$success  = '';

// ──────────────────────────────────────────────────────────────────────────
// STEP 2 – Database
// ──────────────────────────────────────────────────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!setup_verify_csrf()) {
        $errors[] = 'Sicherheitsprüfung fehlgeschlagen (ungültiges CSRF-Token). Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
    } else {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';

    if (!$db_name || !$db_user) {
        $errors[] = 'Datenbankname und Benutzer sind Pflichtfelder.';
    } else {
        try {
            // Test connection (without db name first, to allow CREATE DATABASE)
            $dsn_root = "mysql:host={$db_host};charset=utf8mb4";
            $pdo_test = new PDO($dsn_root, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            // Create DB if not exists
            $pdo_test->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $db_name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // Now connect to the specific DB and run schema
            $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $schema_path = dirname(__DIR__) . '/sql/schema.sql';
            if (file_exists($schema_path)) {
                $sql = file_get_contents($schema_path);
                // String-/kommentarbewusster Split (siehe split_sql_statements()
                // oben) – ein naives explode(';', $sql) würde an den Semikolons
                // innerhalb der CSS-style-Attribute der E-Mail-Vorlagen
                // (z. B. style="color:#0057B8;font-weight:bold;") mitten in
                // gültigen INSERT-Statements zerbrechen.
                $statements = split_sql_statements($sql);

                // Da CREATE TABLE stets mit IF NOT EXISTS und alle Seed-INSERTs
                // stets mit ON DUPLICATE KEY UPDATE arbeiten, ist ein erneuter
                // Import (z. B. nach einem zuvor teilweise fehlgeschlagenen
                // Lauf) sicher wiederholbar: bereits vorhandene Tabellen/Zeilen
                // werden übersprungen bzw. unverändert aktualisiert, es kommt
                // zu keinen doppelten oder inkonsistenten Daten.
                $stmt_no = 0;
                foreach ($statements as $stmt) {
                    $stmt_no++;
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $stmt_error) {
                        // Präzisere Fehlermeldung inkl. Statement-Nummer und
                        // Kontext-Ausschnitt, damit ein Fehlschlag im Schema
                        // künftig sofort lokalisierbar ist – statt einer
                        // generischen PDO-Meldung ohne jeden Bezug zur Datei.
                        $preview = substr(preg_replace('/\s+/', ' ', $stmt), 0, 120);
                        throw new PDOException(
                            "Fehler in sql/schema.sql, Anweisung #{$stmt_no}: "
                            . $stmt_error->getMessage()
                            . " (Beginn der Anweisung: \"{$preview}...\")",
                            (int)$stmt_error->getCode()
                        );
                    }
                }
            }

            // Persist credentials to config.php
            update_config('DB_HOST', $db_host);
            update_config('DB_NAME', $db_name);
            update_config('DB_USER', $db_user);
            update_config('DB_PASS', $db_pass);

            $success = 'Datenbankverbindung erfolgreich. Schema wurde eingespielt.';
            header('Location: setup.php?step=3&db_ok=1');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Datenbankfehler: ' . htmlspecialchars($e->getMessage());
        }
    }
    }
}

// ──────────────────────────────────────────────────────────────────────────
// STEP 3 – Admin account
// ──────────────────────────────────────────────────────────────────────────
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!setup_verify_csrf()) {
        $errors[] = 'Sicherheitsprüfung fehlgeschlagen (ungültiges CSRF-Token). Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
    } else {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = $_POST['password']       ?? '';
    $confirm   = $_POST['password_confirm'] ?? '';

    if (!$full_name || !$username || !$email || !$password) {
        $errors[] = 'Alle Felder sind Pflichtfelder.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ungültige E-Mail-Adresse.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Das Passwort muss mindestens einen Großbuchstaben enthalten.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Das Passwort muss mindestens eine Zahl enthalten.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Die Passwörter stimmen nicht überein.';
    } else {
        // Load current DB config from updated config.php
        $cfg_vars = [];
        $cfg_content = file_get_contents($private . '/config.php');
        preg_match_all("/define\('(DB_HOST|DB_NAME|DB_USER|DB_PASS)',\s*'([^']*)'\)/", $cfg_content, $m);
        foreach ($m[1] as $i => $k) {
            $cfg_vars[$k] = $m[2][$i];
        }

        try {
            $dsn = "mysql:host={$cfg_vars['DB_HOST']};dbname={$cfg_vars['DB_NAME']};charset=utf8mb4";
            $pdo = new PDO($dsn, $cfg_vars['DB_USER'], $cfg_vars['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, full_name, role, is_active)
                 VALUES (?, ?, ?, ?, 'admin', 1)"
            );
            $stmt->execute([$username, $email, $hash, $full_name]);

            // Generate encryption key
            $enc_key = bin2hex(random_bytes(32)); // 64 hex chars
            update_config('ENCRYPTION_KEY', $enc_key);

            // Store username in session for step 5 display
            // (Session ist bereits aktiv, siehe CSRF-Bootstrap oben)
            $_SESSION['setup_admin_username'] = $username;
            $_SESSION['setup_admin_email']    = $email;
            $_SESSION['setup_admin_name']     = $full_name;

            header('Location: setup.php?step=4&admin_ok=1');
            exit;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $errors[] = 'Benutzername oder E-Mail bereits vergeben.';
            } else {
                $errors[] = 'Datenbankfehler: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
    }
}

// ──────────────────────────────────────────────────────────────────────────
// STEP 4 – Company data
// ──────────────────────────────────────────────────────────────────────────
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!setup_verify_csrf()) {
        $errors[] = 'Sicherheitsprüfung fehlgeschlagen (ungültiges CSRF-Token). Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
    } else {
    $company_name    = trim($_POST['company_name']    ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $company_phone   = trim($_POST['company_phone']   ?? '');
    $company_email   = trim($_POST['company_email']   ?? '');
    $company_website = trim($_POST['company_website'] ?? '');

    if (!$company_name) {
        $errors[] = 'Der Firmenname ist ein Pflichtfeld.';
    } else {
        $cfg_vars = [];
        $cfg_content = file_get_contents($private . '/config.php');
        preg_match_all("/define\('(DB_HOST|DB_NAME|DB_USER|DB_PASS)',\s*'([^']*)'\)/", $cfg_content, $m);
        foreach ($m[1] as $i => $k) {
            $cfg_vars[$k] = $m[2][$i];
        }

        try {
            $dsn = "mysql:host={$cfg_vars['DB_HOST']};dbname={$cfg_vars['DB_NAME']};charset=utf8mb4";
            $pdo = new PDO($dsn, $cfg_vars['DB_USER'], $cfg_vars['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $settings = [
                'company_name'    => $company_name,
                'company_address' => $company_address,
                'company_phone'   => $company_phone,
                'company_email'   => $company_email,
                'company_website' => $company_website,
            ];

            $stmt = $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            foreach ($settings as $key => $value) {
                $stmt->execute([$key, $value]);
            }

            // Installation vollständig abgeschlossen: Assistent ab sofort
            // automatisch sperren. Die Erfolgsseite (Schritt 5) darf im
            // selben Browser noch einmalig angezeigt werden.
            write_install_lock($lock_file, 'setup-completed');
            $_SESSION['setup_just_completed'] = true;

            header('Location: setup.php?step=5&company_ok=1');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Datenbankfehler: ' . htmlspecialchars($e->getMessage());
        }
    }
    }
}

// ──────────────────────────────────────────────────────────────────────────
// STEP 1 – System checks
// ──────────────────────────────────────────────────────────────────────────
$checks = [];
if ($step === 1) {
    $base = dirname(__DIR__);
    $checks = [
        [
            'label'    => 'PHP Version ≥ 8.0',
            'ok'       => version_compare(PHP_VERSION, '8.0.0', '>='),
            'info'     => 'PHP ' . PHP_VERSION,
            'critical' => true,
        ],
        [
            'label'    => 'PDO MySQL Erweiterung',
            'ok'       => extension_loaded('pdo_mysql'),
            'info'     => '',
            'critical' => true,
        ],
        [
            'label'    => 'OpenSSL Erweiterung',
            'ok'       => extension_loaded('openssl'),
            'info'     => '',
            'critical' => true,
        ],
        [
            'label'    => 'GD Erweiterung',
            'ok'       => extension_loaded('gd'),
            'info'     => '',
            'critical' => false,
        ],
        [
            'label'    => 'private/ beschreibbar',
            'ok'       => is_writable($base . '/private'),
            'info'     => $base . '/private',
            'critical' => true,
        ],
        [
            'label'    => 'uploads/ beschreibbar',
            'ok'       => is_writable($base . '/uploads'),
            'info'     => $base . '/uploads',
            'critical' => false,
        ],
        [
            'label'    => 'logs/ beschreibbar',
            'ok'       => is_writable($base . '/logs'),
            'info'     => $base . '/logs',
            'critical' => false,
        ],
    ];

    $all_critical_pass = array_reduce($checks, fn($carry, $c) => $carry && (!$c['critical'] || $c['ok']), true);
}

// ──────────────────────────────────────────────────────────────────────────
// STEP 5 – Done
// ──────────────────────────────────────────────────────────────────────────
$setup_admin = [];
if ($step === 5) {
    // Session ist bereits aktiv, siehe CSRF-Bootstrap oben
    $setup_admin = [
        'username' => $_SESSION['setup_admin_username'] ?? '(Benutzer)',
        'email'    => $_SESSION['setup_admin_email']    ?? '',
        'name'     => $_SESSION['setup_admin_name']     ?? '',
    ];
}

// ──────────────────────────────────────────────────────────────────────────
// HTML OUTPUT
// ──────────────────────────────────────────────────────────────────────────
$step_titles = [
    1 => 'Systemprüfung',
    2 => 'Datenbank',
    3 => 'Administrator',
    4 => 'Firmendaten',
    5 => 'Fertig',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MZ Tech – Installation</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f4f8;
            color: #1a202c;
            min-height: 100vh;
        }

        /* ── Header ── */
        .setup-header {
            background: linear-gradient(135deg, #1a56db 0%, #1e40af 100%);
            color: #fff;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }
        .setup-header .logo {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -.5px;
        }
        .setup-header .logo span { color: #93c5fd; }
        .setup-header .subtitle {
            font-size: .85rem;
            opacity: .8;
        }

        /* ── Stepper ── */
        .stepper {
            display: flex;
            justify-content: center;
            gap: 0;
            padding: 2rem 1rem 0;
            max-width: 700px;
            margin: 0 auto;
        }
        .step-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            font-size: .78rem;
            color: #94a3b8;
        }
        .step-item::before {
            content: '';
            position: absolute;
            top: 16px;
            left: calc(-50% + 16px);
            right: calc(50% + 16px);
            height: 2px;
            background: #cbd5e1;
        }
        .step-item:first-child::before { display: none; }
        .step-item.active { color: #1a56db; }
        .step-item.done   { color: #16a34a; }
        .step-item.done::before,
        .step-item.active::before { background: #1a56db; }

        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .8rem;
            background: #fff;
            margin-bottom: .4rem;
            position: relative;
            z-index: 1;
        }
        .step-item.active .step-circle { border-color: #1a56db; color: #1a56db; }
        .step-item.done   .step-circle {
            border-color: #16a34a;
            background: #16a34a;
            color: #fff;
        }

        /* ── Card ── */
        .setup-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
            padding: 2rem 2.5rem;
            max-width: 700px;
            margin: 1.5rem auto 3rem;
        }
        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 1.5rem;
            padding-bottom: .75rem;
            border-bottom: 2px solid #e2e8f0;
        }

        /* ── Alerts ── */
        .alert {
            border-radius: 8px;
            padding: .9rem 1.1rem;
            margin-bottom: 1.2rem;
            font-size: .9rem;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
        }
        .alert-danger  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .alert-info    { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }

        /* ── Checks table ── */
        .checks-table { width: 100%; border-collapse: collapse; }
        .checks-table tr { border-bottom: 1px solid #f1f5f9; }
        .checks-table tr:last-child { border-bottom: none; }
        .checks-table td { padding: .65rem .25rem; font-size: .9rem; }
        .checks-table .label { width: 55%; font-weight: 500; }
        .checks-table .info  { color: #64748b; font-size: .8rem; }
        .status-ok   { color: #16a34a; font-weight: 700; font-size: 1rem; }
        .status-fail { color: #dc2626; font-weight: 700; font-size: 1rem; }
        .badge-critical {
            font-size: .7rem;
            background: #fef3c7;
            color: #92400e;
            border-radius: 4px;
            padding: .15rem .4rem;
            margin-left: .4rem;
            vertical-align: middle;
        }
        .badge-optional {
            font-size: .7rem;
            background: #f1f5f9;
            color: #64748b;
            border-radius: 4px;
            padding: .15rem .4rem;
            margin-left: .4rem;
            vertical-align: middle;
        }

        /* ── Form ── */
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; font-size: .85rem; font-weight: 600; color: #374151; margin-bottom: .35rem; }
        input[type=text],
        input[type=email],
        input[type=password],
        input[type=url],
        input[type=tel] {
            width: 100%;
            padding: .6rem .85rem;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-size: .95rem;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
            background: #fafafa;
        }
        input:focus {
            border-color: #1a56db;
            box-shadow: 0 0 0 3px rgba(26,86,219,.12);
            background: #fff;
        }
        .form-hint { font-size: .78rem; color: #94a3b8; margin-top: .3rem; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .65rem 1.4rem;
            border-radius: 8px;
            font-size: .9rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, transform .1s;
        }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background: #1a56db; color: #fff; }
        .btn-primary:hover { background: #1e40af; }
        .btn-primary:disabled { background: #93c5fd; cursor: not-allowed; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-success:hover { background: #15803d; }
        .btn-secondary { background: #e2e8f0; color: #374151; }
        .btn-secondary:hover { background: #cbd5e1; }

        .btn-group { display: flex; gap: .75rem; margin-top: 1.5rem; flex-wrap: wrap; }

        /* ── Step 5 – Done ── */
        .done-icon {
            font-size: 3.5rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        .done-title {
            font-size: 1.6rem;
            font-weight: 800;
            text-align: center;
            color: #16a34a;
            margin-bottom: .5rem;
        }
        .done-subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 1.5rem;
        }
        .credentials-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.2rem 1.5rem;
            margin: 1.2rem 0;
        }
        .credentials-box h4 { font-size: .85rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin-bottom: .8rem; }
        .credential-row { display: flex; justify-content: space-between; padding: .35rem 0; border-bottom: 1px solid #f1f5f9; font-size: .9rem; }
        .credential-row:last-child { border-bottom: none; }
        .credential-row .key { color: #64748b; }
        .credential-row .val { font-weight: 600; color: #1a202c; }

        .next-steps { list-style: none; margin-top: 1rem; }
        .next-steps li {
            padding: .5rem 0;
            padding-left: 1.5rem;
            position: relative;
            color: #374151;
            font-size: .9rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .next-steps li:last-child { border-bottom: none; }
        .next-steps li::before { content: '→'; position: absolute; left: 0; color: #1a56db; font-weight: 700; }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .setup-card { padding: 1.25rem 1rem; margin: 1rem .5rem 2rem; }
            .form-row { grid-template-columns: 1fr; }
            .stepper { gap: 0; }
            .step-item .step-label { display: none; }
        }
    </style>
</head>
<body>

<!-- ── Header ─────────────────────────────────────────────────────────── -->
<header class="setup-header">
    <div>
        <div class="logo">MZ<span>Tech</span></div>
        <div class="subtitle">Repair Management System</div>
    </div>
    <div style="margin-left: auto; font-size: .85rem; opacity: .75;">Installations-Assistent</div>
</header>

<!-- ── Stepper ────────────────────────────────────────────────────────── -->
<nav class="stepper" aria-label="Setup-Schritte">
    <?php foreach ($step_titles as $n => $title): ?>
        <div class="step-item <?= $n < $step ? 'done' : ($n === $step ? 'active' : '') ?>">
            <div class="step-circle"><?= $n < $step ? '✓' : $n ?></div>
            <span class="step-label"><?= htmlspecialchars($title) ?></span>
        </div>
    <?php endforeach; ?>
</nav>

<!-- ── Card ───────────────────────────────────────────────────────────── -->
<main class="setup-card">

<?php if ($errors): ?>
    <div class="alert alert-danger" role="alert">
        <span>&#9888;</span>
        <div>
            <?php if (count($errors) === 1): ?>
                <?= htmlspecialchars($errors[0]) ?>
            <?php else: ?>
                <ul style="padding-left:1rem;margin:0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" role="alert">
        <span>&#10003;</span>
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- STEP 1 – Systemprüfung                                              -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<?php if ($step === 1): ?>
    <h2 class="card-title">Schritt 1: Systemprüfung</h2>

    <p style="color:#64748b;font-size:.9rem;margin-bottom:1.2rem;">
        Stellen Sie sicher, dass alle kritischen Anforderungen erfüllt sind, bevor Sie mit der Installation fortfahren.
    </p>

    <table class="checks-table" role="table">
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td class="label">
                    <?= htmlspecialchars($check['label']) ?>
                    <?php if ($check['critical']): ?>
                        <span class="badge-critical">erforderlich</span>
                    <?php else: ?>
                        <span class="badge-optional">optional</span>
                    <?php endif; ?>
                </td>
                <td class="info"><?= htmlspecialchars($check['info']) ?></td>
                <td style="text-align:right">
                    <?php if ($check['ok']): ?>
                        <span class="status-ok" aria-label="OK">&#10003;</span>
                    <?php else: ?>
                        <span class="status-fail" aria-label="Fehlgeschlagen">&#10007;</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!$all_critical_pass): ?>
        <div class="alert alert-danger" style="margin-top:1.2rem">
            <span>&#9888;</span>
            Bitte beheben Sie alle kritischen Fehler, bevor Sie fortfahren. Laden Sie diese Seite anschließend neu.
        </div>
    <?php endif; ?>

    <div class="btn-group">
        <?php if ($all_critical_pass): ?>
            <a href="setup.php?step=2" class="btn btn-primary">Weiter &#8594;</a>
        <?php else: ?>
            <button class="btn btn-primary" disabled>Weiter &#8594;</button>
            <a href="setup.php?step=1" class="btn btn-secondary">Erneut prüfen</a>
        <?php endif; ?>
    </div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- STEP 2 – Datenbank                                                  -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 2): ?>
    <h2 class="card-title">Schritt 2: Datenbank</h2>
    <p style="color:#64748b;font-size:.9rem;margin-bottom:1.2rem;">
        Geben Sie die Verbindungsdaten für Ihre MySQL/MariaDB-Datenbank ein.
        Der Benutzer benötigt die Rechte <code>CREATE DATABASE</code>, <code>CREATE TABLE</code> und <code>INSERT</code>.
    </p>

    <form method="POST" action="setup.php?step=2" novalidate>
        <?= setup_csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="db_host">DB Host</label>
                <input type="text" id="db_host" name="db_host"
                       value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"
                       placeholder="localhost" required>
            </div>
            <div class="form-group">
                <label for="db_name">DB Name <span style="color:#dc2626">*</span></label>
                <input type="text" id="db_name" name="db_name"
                       value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>"
                       placeholder="mztech_repairs" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="db_user">DB Benutzer <span style="color:#dc2626">*</span></label>
                <input type="text" id="db_user" name="db_user"
                       value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>"
                       placeholder="mztech_user" required>
            </div>
            <div class="form-group">
                <label for="db_pass">DB Passwort</label>
                <input type="password" id="db_pass" name="db_pass"
                       value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>"
                       placeholder="Datenbankpasswort">
            </div>
        </div>

        <div class="alert alert-info" style="margin-top:.5rem">
            <span>&#8505;</span>
            Wenn die Datenbank noch nicht existiert, wird sie automatisch erstellt.
            Das Datenbankschema wird ebenfalls automatisch eingespielt.
        </div>

        <div class="btn-group">
            <a href="setup.php?step=1" class="btn btn-secondary">&#8592; Zurück</a>
            <button type="submit" class="btn btn-primary">Verbindung testen &amp; Weiter &#8594;</button>
        </div>
    </form>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- STEP 3 – Administrator                                              -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 3): ?>
    <h2 class="card-title">Schritt 3: Administrator anlegen</h2>
    <p style="color:#64748b;font-size:.9rem;margin-bottom:1.2rem;">
        Erstellen Sie das erste Administrator-Konto. Mit diesem Konto melden Sie sich nach der Installation an.
    </p>

    <form method="POST" action="setup.php?step=3" novalidate>
        <?= setup_csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="full_name">Vollständiger Name <span style="color:#dc2626">*</span></label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                       placeholder="Max Mustermann" required>
            </div>
            <div class="form-group">
                <label for="username">Benutzername <span style="color:#dc2626">*</span></label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       placeholder="admin" required autocomplete="username">
            </div>
        </div>
        <div class="form-group">
            <label for="email">E-Mail <span style="color:#dc2626">*</span></label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   placeholder="admin@example.com" required autocomplete="email">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="password">Passwort <span style="color:#dc2626">*</span></label>
                <input type="password" id="password" name="password"
                       placeholder="Mindestens 8 Zeichen" required autocomplete="new-password">
                <div class="form-hint">Min. 8 Zeichen, 1 Großbuchstabe, 1 Zahl</div>
            </div>
            <div class="form-group">
                <label for="password_confirm">Passwort bestätigen <span style="color:#dc2626">*</span></label>
                <input type="password" id="password_confirm" name="password_confirm"
                       placeholder="Passwort wiederholen" required autocomplete="new-password">
            </div>
        </div>

        <div class="btn-group">
            <a href="setup.php?step=2" class="btn btn-secondary">&#8592; Zurück</a>
            <button type="submit" class="btn btn-primary">Administrator erstellen &amp; Weiter &#8594;</button>
        </div>
    </form>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- STEP 4 – Firmendaten                                                -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 4): ?>
    <h2 class="card-title">Schritt 4: Firmendaten</h2>
    <p style="color:#64748b;font-size:.9rem;margin-bottom:1.2rem;">
        Diese Daten erscheinen auf Rechnungen, Kostenvoranschlägen und in Ihrem Kundenkommunikation.
    </p>

    <form method="POST" action="setup.php?step=4" novalidate>
        <?= setup_csrf_field() ?>
        <div class="form-group">
            <label for="company_name">Firmenname <span style="color:#dc2626">*</span></label>
            <input type="text" id="company_name" name="company_name"
                   value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>"
                   placeholder="MZ Tech Repair" required>
        </div>
        <div class="form-group">
            <label for="company_address">Adresse</label>
            <input type="text" id="company_address" name="company_address"
                   value="<?= htmlspecialchars($_POST['company_address'] ?? '') ?>"
                   placeholder="Musterstraße 1, 12345 Musterstadt">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="company_phone">Telefon</label>
                <input type="tel" id="company_phone" name="company_phone"
                       value="<?= htmlspecialchars($_POST['company_phone'] ?? '') ?>"
                       placeholder="+49 123 456789">
            </div>
            <div class="form-group">
                <label for="company_email">E-Mail</label>
                <input type="email" id="company_email" name="company_email"
                       value="<?= htmlspecialchars($_POST['company_email'] ?? '') ?>"
                       placeholder="info@mztech.de">
            </div>
        </div>
        <div class="form-group">
            <label for="company_website">Website</label>
            <input type="url" id="company_website" name="company_website"
                   value="<?= htmlspecialchars($_POST['company_website'] ?? '') ?>"
                   placeholder="https://www.mztech.de">
        </div>

        <div class="btn-group">
            <a href="setup.php?step=3" class="btn btn-secondary">&#8592; Zurück</a>
            <button type="submit" class="btn btn-primary">Speichern &amp; Weiter &#8594;</button>
        </div>
    </form>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- STEP 5 – Fertig                                                     -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<?php elseif ($step === 5): ?>
    <div class="done-icon">&#127881;</div>
    <div class="done-title">Installation abgeschlossen!</div>
    <div class="done-subtitle">MZ Tech Repair System wurde erfolgreich eingerichtet.</div>

    <div class="credentials-box">
        <h4>Ihre Administrator-Zugangsdaten</h4>
        <div class="credential-row">
            <span class="key">Name</span>
            <span class="val"><?= htmlspecialchars($setup_admin['name']) ?></span>
        </div>
        <div class="credential-row">
            <span class="key">Benutzername</span>
            <span class="val"><?= htmlspecialchars($setup_admin['username']) ?></span>
        </div>
        <div class="credential-row">
            <span class="key">E-Mail</span>
            <span class="val"><?= htmlspecialchars($setup_admin['email']) ?></span>
        </div>
    </div>

    <div class="alert alert-success">
        <span>&#128274;</span>
        <div>
            <strong>Assistent automatisch gesperrt:</strong> <code>setup.php</code> hat sich
            soeben selbst gesperrt (Datei <code>private/installed.lock</code> wurde angelegt).
            Ein erneuter Aufruf dieser Seite legt keinen zusätzlichen Administrator mehr an und
            ändert keine Datenbankverbindung mehr. Sie müssen nichts weiter tun.
        </div>
    </div>

    <h3 style="font-size:.95rem;font-weight:700;margin:1.2rem 0 .5rem;color:#374151;">Nächste Schritte</h3>
    <ul class="next-steps">
        <li>Melden Sie sich mit Ihren Administrator-Zugangsdaten an</li>
        <li>Überprüfen Sie die E-Mail-Einstellungen unter Einstellungen &rarr; System</li>
        <li>Legen Sie Techniker-Konten für Ihre Mitarbeiter an</li>
        <li>Konfigurieren Sie Reparaturkategorien und Preislisten</li>
        <li>Richten Sie regelmäßige Datenbank-Backups ein</li>
    </ul>

    <div class="btn-group" style="margin-top:2rem;justify-content:center">
        <a href="index.php" class="btn btn-primary" style="padding:.8rem 2rem;font-size:1rem;">
            Zum Login &#8594;
        </a>
    </div>

<?php endif; ?>

</main>

<footer style="text-align:center;color:#94a3b8;font-size:.78rem;padding-bottom:2rem;">
    MZ Tech Repair System &mdash; Installations-Assistent &mdash; PHP <?= PHP_VERSION ?>
</footer>

</body>
</html>
