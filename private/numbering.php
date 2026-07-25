<?php
/**
 * MZ Tech – Nummernkreis-Engine (Phase 2)
 * ----------------------------------------------------------------------
 * Zentrale, generische Vergabe fortlaufender Dokumentnummern (Angebote,
 * Kostenvoranschläge, Reparaturaufträge, Rechnungen, Gutschriften,
 * Lieferscheine, Bestellungen, Tickets) über die Tabelle `number_ranges`
 * (siehe sql/schema.sql bzw. sql/update.sql, Abschnitt "Phase 2").
 *
 * Wichtig – Nummernvergabe ist absichtlich sparsam:
 *   generate_document_number() darf NUR dann aufgerufen werden, wenn ein
 *   Dokument tatsächlich verbindlich ausgestellt wird (z. B. eine Rechnung
 *   freigegeben wird). Ein bloßes Anzeigen/Vorschauen eines Dokuments darf
 *   NIEMALS eine neue Nummer verbrauchen – sonst entstehen Lücken im
 *   Nummernkreis, was einer GoBD-konformen, lückenlosen Nummerierung
 *   widerspricht. Aufrufende Stellen (siehe public/pdf/kostenvoranschlag.php,
 *   public/repairs_view.php) speichern die einmal vergebene Nummer daher
 *   persistent (repairs.quote_number / repairs.invoice_number) und rufen
 *   generate_document_number() nur auf, solange noch keine Nummer vorliegt.
 *
 * Nebenläufigkeit:
 *   Die Vergabe erfolgt innerhalb einer Datenbanktransaktion mit
 *   Zeilensperre (SELECT ... FOR UPDATE) auf die jeweilige Nummernkreis-
 *   Zeile. Dadurch ist sichergestellt, dass auch bei mehreren gleichzeitig
 *   arbeitenden Mitarbeitern (mehrere PHP-Prozesse/Datenbankverbindungen)
 *   niemals dieselbe Nummer zweimal vergeben wird – die zweite Transaktion
 *   wartet einfach, bis die erste committet hat, und erhält danach
 *   garantiert die nächste freie Nummer.
 */

// Bekannte Dokumenttypen mit sprechendem Label und Default-Konfiguration.
// Wird nur verwendet, falls für einen Dokumenttyp noch KEINE Zeile in
// number_ranges existiert (z. B. sehr alte Installation, bei der die
// Migration aus irgendeinem Grund übersprungen wurde) – im Regelfall legt
// bereits die SQL-Migration (schema.sql/update.sql) alle 8 Zeilen an.
function number_range_defaults(string $doc_type): array {
    $defaults = [
        'REP' => ['label' => 'Reparaturaufträge',   'prefix' => get_setting('repair_prefix', 'MZ'), 'separator' => '',  'digits' => 4, 'yearly_reset' => 1, 'start_number' => 1],
        'KV'  => ['label' => 'Kostenvoranschläge',  'prefix' => 'KV',  'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
        'RE'  => ['label' => 'Rechnungen',          'prefix' => 'RE',  'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
        'ANG' => ['label' => 'Angebote',            'prefix' => 'ANG', 'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
        'GS'  => ['label' => 'Gutschriften',        'prefix' => 'GS',  'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
        'LS'  => ['label' => 'Lieferscheine',       'prefix' => 'LS',  'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
        'BE'  => ['label' => 'Bestellungen',        'prefix' => 'BE',  'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
        'TIC' => ['label' => 'Tickets',             'prefix' => 'TIC', 'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
        // Phase 5: Projektnummern (Firmenkundenportal). Kein Jahreswechsel,
        // da Projekte i. d. R. über Jahresgrenzen hinweg laufen.
        'PRJ' => ['label' => 'Projekte',            'prefix' => 'PRJ', 'separator' => '-', 'digits' => 4, 'yearly_reset' => 0, 'start_number' => 1],
        // Dokumentenmodul: Stornorechnungen (Gutschriften "GS" existieren
        // bereits seit Phase 2 und werden ab jetzt erstmals aktiv genutzt).
        'STO' => ['label' => 'Stornorechnungen',    'prefix' => 'STO', 'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1],
    ];
    return $defaults[$doc_type] ?? ['label' => $doc_type, 'prefix' => $doc_type, 'separator' => '-', 'digits' => 6, 'yearly_reset' => 1, 'start_number' => 1];
}

// Alle bekannten Dokumenttypen in fester, sinnvoller Anzeigereihenfolge
// (aktiv genutzte zuerst, dann für künftige Module reservierte).
function number_range_known_types(): array {
    return ['REP', 'KV', 'RE', 'ANG', 'GS', 'STO', 'LS', 'BE', 'TIC', 'PRJ'];
}

// Dokumenttypen, die in dieser Version bereits von einem echten Modul
// verwendet werden (steuert nur die Anzeige/Kennzeichnung in den
// Einstellungen, nicht die Funktion der Engine selbst).
function number_range_active_types(): array {
    // Phase 6: "BE" (Bestellungen) wird vom Beschaffungs-/Bestellmodul
    // verwendet. Dokumentenmodul: "RE" wird nun tatsächlich über die
    // Freigabe vergeben (statt der bisherigen Ad-hoc-Nummer), "ANG"
    // (eigenständige Angebote), "GS"/"STO" (Gutschriften/Stornorechnungen)
    // und "LS" (Lieferscheine) werden ab dieser Version erstmals aktiv
    // genutzt.
    return ['REP', 'KV', 'RE', 'ANG', 'GS', 'STO', 'LS', 'TIC', 'PRJ', 'BE'];
}

/**
 * Liefert [Tabelle, Spalte], in der bereits vergebene Nummern eines
 * Dokumenttyps stehen — Grundlage für die Kollisionswarnung in den
 * Einstellungen (number_range_check_collision()). Liefert null für
 * Dokumenttypen ohne (bzw. mit gemischter) Speicherung.
 */
function number_range_document_table(string $doc_type): ?array {
    return match ($doc_type) {
        'REP'         => ['repairs', 'repair_number'],
        'KV'          => ['repairs', 'quote_number'],
        'RE'          => ['repairs', 'invoice_number'],
        'ANG'         => ['quotes', 'quote_number'],
        'GS', 'STO'   => ['invoice_corrections', 'correction_number'],
        'LS'          => ['delivery_notes', 'delivery_note_number'],
        'BE'          => ['purchase_orders', 'order_number'],
        'TIC'         => ['tickets', 'ticket_number'],
        'PRJ'         => ['projects', 'project_number'],
        default       => null,
    };
}

/**
 * Heuristische Kollisionswarnung für die Einstellungsseite (Vorgabe:
 * "Warnung anzeigen, wenn eine Änderung zu Kollisionen führen könnte" /
 * "vor Speichern auf bereits vorhandene Nummern prüfen"). Vergleicht die
 * reine Zählnummer (letzte Ziffernfolge) bereits vergebener Nummern mit
 * der geplanten neuen Startnummer. Liefert bis zu 20 bereits vergebene
 * Nummern, die bei der neuen Startnummer NICHT mehr eindeutig unterhalb
 * der neuen Zählung lägen. Bewusst als vorsichtige Warnung ausgelegt
 * (kann bei einem Präfixwechsel im Einzelfall auch "falsch positiv"
 * warnen) – ersetzt NICHT die harte Absicherung durch den UNIQUE-Index
 * auf repairs.invoice_number, sondern ergänzt sie um einen frühzeitigen,
 * verständlichen Hinweis direkt in der Oberfläche.
 */
function number_range_check_collision(string $doc_type, int $startNumber): array {
    $map = number_range_document_table($doc_type);
    if (!$map) return [];
    [$table, $col] = $map;

    try {
        $sql = "SELECT `$col` FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> ''";
        if ($doc_type === 'GS' || $doc_type === 'STO') {
            $type = $doc_type === 'GS' ? 'gutschrift' : 'storno';
            $sql .= ' AND correction_type = ' . get_db()->quote($type);
        }
        $stmt = get_db()->query($sql);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return [];
    }

    $collisions = [];
    foreach ($existing as $num) {
        if (preg_match('/(\d+)$/', (string)$num, $m)) {
            $n = (int)ltrim($m[1], '0');
            if ($n >= $startNumber) {
                $collisions[] = $num;
            }
        }
    }
    $collisions = array_values(array_unique($collisions));
    sort($collisions);
    return array_slice($collisions, 0, 20);
}

/**
 * Liest die Konfigurationszeile eines Dokumenttyps; legt sie mit sicheren
 * Default-Werten an, falls sie (entgegen dem Regelfall) noch nicht existiert.
 */
function number_range_ensure_exists(string $doc_type): array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM number_ranges WHERE doc_type = ? LIMIT 1');
    $stmt->execute([$doc_type]);
    $row = $stmt->fetch();
    if ($row) return $row;

    $d = number_range_defaults($doc_type);
    $db->prepare(
        'INSERT INTO number_ranges
            (doc_type, label, prefix, separator, digits, yearly_reset, start_number, current_year, current_number, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0, 1)
         ON DUPLICATE KEY UPDATE doc_type = doc_type'
    )->execute([$doc_type, $d['label'], $d['prefix'], $d['separator'], $d['digits'], $d['yearly_reset'], $d['start_number']]);

    $stmt->execute([$doc_type]);
    return $stmt->fetch();
}

// Stellt sicher, dass alle bekannten Nummernkreise existieren (für die
// Einstellungsseite, damit dort immer alle 8 Zeilen angezeigt werden,
// auch auf einer Installation, die die SQL-Migration noch nicht
// ausgeführt hat).
function number_range_ensure_all_exist(): void {
    foreach (number_range_known_types() as $t) {
        number_range_ensure_exists($t);
    }
}

/**
 * Liefert alle Nummernkreise (für die Einstellungsseite), inkl. einer
 * Beispielnummer zur Vorschau, in fester Anzeigereihenfolge.
 */
function number_range_list(): array {
    number_range_ensure_all_exist();
    $db = get_db();
    $rows = $db->query('SELECT * FROM number_ranges')->fetchAll(PDO::FETCH_ASSOC);
    $byType = [];
    foreach ($rows as $r) { $byType[$r['doc_type']] = $r; }

    $active = number_range_active_types();
    $out = [];
    foreach (number_range_known_types() as $t) {
        if (!isset($byType[$t])) continue;
        $r = $byType[$t];
        $r['is_active_module'] = in_array($t, $active, true);
        $r['preview'] = number_range_format((int)$r['start_number'], $r);
        $out[] = $r;
    }
    return $out;
}

/**
 * Speichert die admin-editierbaren Felder eines Nummernkreises (Präfix,
 * Trennzeichen, Ziffernanzahl, Jahreswechsel, Startnummer). Der laufende
 * Zähler (current_number/current_year) wird hier bewusst NICHT verändert,
 * damit ein Speichern in den Einstellungen niemals versehentlich bereits
 * vergebene Nummern erneut vergibt oder Lücken erzeugt.
 */
function number_range_save(string $doc_type, string $prefix, string $separator, int $digits, bool $yearlyReset, int $startNumber): void {
    number_range_ensure_exists($doc_type);
    $digits = max(1, min(10, $digits));
    $startNumber = max(1, $startNumber);
    $separator = mb_substr($separator, 0, 1);
    get_db()->prepare(
        'UPDATE number_ranges
            SET prefix = ?, separator = ?, digits = ?, yearly_reset = ?, start_number = ?
          WHERE doc_type = ?'
    )->execute([$prefix, $separator, $digits, $yearlyReset ? 1 : 0, $startNumber, $doc_type]);
}

// Formatiert eine Zählernummer gemäß der Konfiguration eines Nummernkreises
// (z. B. "KV-2026-000001"). $range muss mind. prefix/separator/digits/
// yearly_reset enthalten.
function number_range_format(int $number, array $range): string {
    $digits = (int)($range['digits'] ?? 6);
    $sep    = (string)($range['separator'] ?? '-');
    $parts  = [(string)$range['prefix']];
    if (!empty($range['yearly_reset'])) {
        $parts[] = (string)date('Y');
    }
    $parts[] = str_pad((string)$number, $digits, '0', STR_PAD_LEFT);
    return implode($sep, $parts);
}

/**
 * Vergibt und liefert die NÄCHSTE Nummer für einen Dokumenttyp.
 *
 * ACHTUNG: Jeder Aufruf verbraucht endgültig eine Nummer, siehe Hinweis am
 * Dateianfang. Nur aufrufen, wenn ein Dokument tatsächlich verbindlich
 * ausgestellt wird, und das Ergebnis sofort persistent speichern.
 */
function generate_document_number(string $doc_type): string {
    $db = get_db();
    $currentYear = (int)date('Y');

    // Falls diese Funktion aus einem bereits laufenden Transaktionskontext
    // heraus aufgerufen wird (aktuell nirgends im System der Fall, aber
    // defensiv für künftige Erweiterungen), keine verschachtelte
    // Transaktion starten, sondern die bestehende weiterverwenden.
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        // Zeile ggf. mit sicheren Defaults anlegen (in der Praxis so gut wie
        // nie nötig, da die SQL-Migration bereits alle 8 Zeilen anlegt) und
        // direkt danach per SELECT...FOR UPDATE sperren. Beides läuft in
        // derselben Transaktion/Verbindung, kollidiert also nicht mit sich
        // selbst; die eigentliche Nebenläufigkeitssicherheit gegenüber
        // ANDEREN, parallelen Aufrufen entsteht durch die Zeilensperre unten.
        if ($ownTransaction) {
            number_range_ensure_exists($doc_type);
        }

        $stmt = $db->prepare('SELECT * FROM number_ranges WHERE doc_type = ? FOR UPDATE');
        $stmt->execute([$doc_type]);
        $range = $stmt->fetch();

        if (!$range) {
            // Äußerst defensiver Fallback (sollte praktisch nie eintreten,
            // da number_range_ensure_exists() oben bereits eine Zeile
            // angelegt hat): ohne Konfigurationszeile keine Nummer vergeben.
            throw new RuntimeException("Unbekannter Nummernkreis-Typ: {$doc_type}");
        }

        $yearlyReset = (bool)$range['yearly_reset'];
        $year        = $range['current_year'] !== null ? (int)$range['current_year'] : null;
        $startNumber = (int)$range['start_number'];

        if ($yearlyReset && $year !== $currentYear) {
            $nextNumber = $startNumber;
        } else {
            $nextNumber = max((int)$range['current_number'] + 1, $startNumber);
        }
        $newYear = $yearlyReset ? $currentYear : $year;

        $db->prepare('UPDATE number_ranges SET current_number = ?, current_year = ? WHERE doc_type = ?')
           ->execute([$nextNumber, $newYear, $doc_type]);

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $range['current_number'] = $nextNumber;
    return number_range_format($nextNumber, [
        'prefix'       => $range['prefix'],
        'separator'    => $range['separator'],
        'digits'       => $range['digits'],
        'yearly_reset' => $yearlyReset,
    ]);
}
