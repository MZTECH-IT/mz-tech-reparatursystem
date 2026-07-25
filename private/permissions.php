<?php
/**
 * MZ Tech – Rollen & Rechte (Phase 4)
 * ----------------------------------------------------------------------
 * Zentrale, frei erweiterbare Rechteverwaltung für Mitarbeiter-Rollen
 * (Administrator, Techniker, Empfang, Mitarbeiter, Buchhaltung).
 *
 * Wichtiger Grundsatz: die Rolle "admin" besitzt IMMER, ausnahmslos und
 * unabhängig vom Inhalt der Tabelle role_permissions ALLE Rechte
 * (is_admin()-Sonderfall, siehe user_has_permission() unten). Damit kann
 * ein Administrator sich niemals versehentlich selbst aussperren, z. B.
 * durch eine fehlerhafte Rechtekonfiguration.
 *
 * Neue Berechtigungen hinzufügen (spätere Erweiterung):
 *   1. Neuen Eintrag in PERMISSION_DEFINITIONS unten ergänzen.
 *   2. Einmalig permissions_sync_definitions() aufrufen (geschieht
 *      automatisch beim Aufruf von permissions_all(), siehe unten) –
 *      dadurch landet die neue Berechtigung automatisch in der Tabelle
 *      `permissions` und kann Rollen in den Einstellungen zugewiesen
 *      werden. Kein Code an anderer Stelle muss geändert werden.
 */

// Kanonische Liste aller im System bekannten Berechtigungen. Die Tabelle
// `permissions` wird bei Bedarf automatisch um neue Einträge aus dieser
// Liste ergänzt (additiv, siehe permissions_sync_definitions()) – so bleibt
// die DB immer synchron mit dem Code, ohne dass für jede neue Berechtigung
// eine eigene Migration nötig wäre.
const PERMISSION_DEFINITIONS = [
    'manage_settings'   => ['label' => 'Einstellungen verwalten',       'category' => 'Verwaltung'],
    'manage_users'      => ['label' => 'Benutzer verwalten',            'category' => 'Verwaltung'],
    'manage_numbering'  => ['label' => 'Nummernkreise verwalten',       'category' => 'Verwaltung'],
    'view_activity_log' => ['label' => 'Aktivitätsprotokoll einsehen',  'category' => 'Verwaltung'],
    'manage_repairs'    => ['label' => 'Reparaturen anlegen/bearbeiten','category' => 'Werkstatt'],
    'manage_parts'      => ['label' => 'Ersatzteile verwalten',         'category' => 'Werkstatt'],
    'release_invoices'  => ['label' => 'Rechnungen freigeben',          'category' => 'Finanzen'],
    'view_reports'      => ['label' => 'Statistiken/Berichte einsehen', 'category' => 'Finanzen'],
    'manage_customers'  => ['label' => 'Kunden verwalten',              'category' => 'Kunden'],
    'manage_companies'  => ['label' => 'Firmenkunden verwalten',        'category' => 'Kunden'],
    'manage_tickets'    => ['label' => 'Tickets bearbeiten',            'category' => 'Support'],
    // Phase 6 – Lieferanten-, Produkt-, Preislisten-, Einkaufs- und
    // Beschaffungssystem. Bewusst als eigene, feingranulare Berechtigungen
    // angelegt (nicht unter manage_parts mitgeführt): das Konfigurieren von
    // Lieferanten-Schnittstellen (inkl. Zugangsdaten) und das Freigeben
    // eines Imports sind Aktionen mit deutlich höherem Risiko als das
    // reine Bearbeiten einzelner Ersatzteile und sollen unabhängig davon
    // vergeben werden können.
    'manage_suppliers'       => ['label' => 'Lieferanten verwalten',                'category' => 'Einkauf'],
    'manage_imports'         => ['label' => 'Importe durchführen/konfigurieren',     'category' => 'Einkauf'],
    'approve_imports'        => ['label' => 'Importe freigeben',                    'category' => 'Einkauf'],
    'manage_pricing_rules'   => ['label' => 'Kalkulationsregeln verwalten',         'category' => 'Einkauf'],
    'manage_purchase_orders' => ['label' => 'Bestellungen verwalten',               'category' => 'Einkauf'],
    // Dokumentenmodul (Rechnungsentwürfe, -freigabe, Angebote, Liefer-
    // scheine, Gutschriften/Stornierungen, Nummernkreise). "Rechnungen
    // freigeben" (release_invoices) und "Nummernkreise verwalten"
    // (manage_numbering) decken zwei der im Auftrag genannten acht
    // Berechtigungen bereits exakt ab und werden bewusst NICHT doppelt
    // angelegt (siehe BENUTZER_UND_RECHTE_DOKUMENTENMODUL.txt).
    'create_invoice_drafts'  => ['label' => 'Rechnungsentwürfe erstellen',          'category' => 'Finanzen'],
    'edit_invoices'          => ['label' => 'Rechnungen bearbeiten',                'category' => 'Finanzen'],
    'cancel_invoices'        => ['label' => 'Rechnungen stornieren',                'category' => 'Finanzen'],
    'create_credit_notes'    => ['label' => 'Gutschriften erstellen',               'category' => 'Finanzen'],
    'view_number_ranges'     => ['label' => 'Nummernkreise ansehen',                'category' => 'Verwaltung'],
    'regenerate_documents'   => ['label' => 'Dokumente erneut erzeugen',            'category' => 'Verwaltung'],
];

// Alle bekannten Mitarbeiter-Rollen (fest im System verankert – die Menge
// der ROLLEN selbst ist bewusst nicht per UI erweiterbar, da jede Rolle
// ggf. mit eigener Fachlogik verknüpft ist; die RECHTE je Rolle hingegen
// sind frei konfigurierbar, siehe role_permissions).
function known_roles(): array {
    return ['admin', 'techniker', 'empfang', 'mitarbeiter', 'buchhaltung'];
}

function role_label(string $role): string {
    return match ($role) {
        'admin'       => 'Administrator',
        'techniker'   => 'Techniker',
        'empfang'     => 'Empfang',
        'mitarbeiter' => 'Mitarbeiter',
        'buchhaltung' => 'Buchhaltung',
        default       => ucfirst($role),
    };
}

/**
 * Stellt sicher, dass jede in PERMISSION_DEFINITIONS gepflegte Berechtigung
 * auch als Zeile in der Tabelle `permissions` existiert (additiv, idempotent).
 * Entfernt NIEMALS Zeilen (auch nicht, wenn eine Berechtigung aus dem Code
 * entfernt würde) – bereits vergebene role_permissions-Zuordnungen bleiben
 * dadurch immer erhalten und werden nie stillschweigend gelöscht.
 */
function permissions_sync_definitions(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        $stmt = $db->prepare(
            'INSERT INTO permissions (perm_key, label, category) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category)'
        );
        foreach (PERMISSION_DEFINITIONS as $key => $def) {
            $stmt->execute([$key, $def['label'], $def['category']]);
        }
    } catch (Throwable) {
        // Tabelle evtl. noch nicht migriert (DB-Update ausstehend) – dann
        // greift permissions_all() unten auf die reine Code-Definition
        // zurück, das System bleibt trotzdem benutzbar.
    }
}

/** Alle Berechtigungen, gruppiert nach Kategorie, für die Rechte-Matrix. */
function permissions_all(): array {
    permissions_sync_definitions();
    try {
        $rows = get_db()->query('SELECT perm_key, label, category FROM permissions ORDER BY category, label')
            ->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    } catch (Throwable) {
        // Fallback unten
    }
    $out = [];
    foreach (PERMISSION_DEFINITIONS as $key => $def) {
        $out[] = ['perm_key' => $key, 'label' => $def['label'], 'category' => $def['category']];
    }
    return $out;
}

/** Liefert perm_key => [role => bool] für die Rechte-Matrix-Anzeige. */
function role_permissions_matrix(): array {
    $matrix = [];
    foreach (permissions_all() as $p) {
        foreach (known_roles() as $r) {
            $matrix[$p['perm_key']][$r] = false;
        }
    }
    try {
        $rows = get_db()->query('SELECT role, perm_key FROM role_permissions')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (isset($matrix[$r['perm_key']][$r['role']])) {
                $matrix[$r['perm_key']][$r['role']] = true;
            }
        }
    } catch (Throwable) {
        // role_permissions evtl. noch nicht migriert – Matrix bleibt dann
        // komplett "false", die Einstellungsseite zeigt das transparent an.
    }
    return $matrix;
}

/**
 * Speichert die komplette Rechte-Matrix (aus der Einstellungsseite,
 * $_POST['perm'][role][perm_key] = "1"). Admin wird dabei stets implizit
 * als "alle Rechte" behandelt (siehe user_has_permission()) und muss daher
 * gar nicht in role_permissions gepflegt werden – wird aber der
 * Vollständigkeit halber trotzdem mit allen bekannten Rechten befüllt,
 * damit die Übersicht konsistent aussieht.
 */
function role_permissions_save(array $postPerm): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM role_permissions');
        $stmt = $db->prepare('INSERT INTO role_permissions (role, perm_key) VALUES (?, ?)');
        $allKeys = array_column(permissions_all(), 'perm_key');

        foreach (known_roles() as $role) {
            if ($role === 'admin') {
                // Admin bekommt zur Anzeige immer alle Rechte gespeichert.
                foreach ($allKeys as $key) {
                    $stmt->execute(['admin', $key]);
                }
                continue;
            }
            $selected = $postPerm[$role] ?? [];
            foreach ($allKeys as $key) {
                if (!empty($selected[$key])) {
                    $stmt->execute([$role, $key]);
                }
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Prüft, ob der aktuell angemeldete Mitarbeiter (Admin-Session, siehe
 * private/auth.php) die angegebene Berechtigung besitzt.
 *
 * WICHTIG: is_admin() ist IMMER true → Administrator hat ausnahmslos
 * jede Berechtigung, unabhängig vom Inhalt von role_permissions.
 */
function user_has_permission(string $perm_key): bool {
    if (is_admin()) return true;

    $role = $_SESSION['user_role'] ?? '';
    if ($role === '') return false;

    static $cache = [];
    if (isset($cache[$role])) {
        return in_array($perm_key, $cache[$role], true);
    }

    try {
        $stmt = get_db()->prepare('SELECT perm_key FROM role_permissions WHERE role = ?');
        $stmt->execute([$role]);
        $cache[$role] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'perm_key');
    } catch (Throwable) {
        $cache[$role] = [];
    }

    return in_array($perm_key, $cache[$role], true);
}

/**
 * Erzwingt eine Berechtigung für die aktuelle Seite. Ohne Berechtigung:
 * Umleitung auf das Dashboard mit Fehlermeldung (analog zu require_admin()).
 */
function require_permission(string $perm_key): void {
    require_auth();
    if (!user_has_permission($perm_key)) {
        flash('error', 'Keine Berechtigung für diese Aktion.');
        header('Location: ' . url('dashboard.php'));
        exit;
    }
}
