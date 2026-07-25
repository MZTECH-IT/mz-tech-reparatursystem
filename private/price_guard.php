<?php
/**
 * MZ Tech – Automatischer Schutz vor fehlerhaften/verdächtigen Preisen
 * (Phase 6, Auftragsabschnitt 9 "Automatischer Schutz vor
 * fehlerhaften/verdächtigen Preisen").
 * ----------------------------------------------------------------------
 * Konfigurierbare Schwellenwerte liegen in den generischen Settings
 * (get_setting()/set_setting(), siehe private/functions.php) unter den
 * Schlüsseln price_guard_*, damit sie zentral in den Firmen-/
 * Systemeinstellungen (Phase 3) mitgepflegt werden können, ohne eine
 * weitere Einstellungstabelle einzuführen.
 */

function price_guard_settings(): array {
    return [
        'max_change_percent' => (float)get_setting('price_guard_max_change_percent', '60'),
        'max_reasonable_price' => (float)get_setting('price_guard_max_reasonable_price', '5000'),
        'min_reasonable_tax_rate' => (float)get_setting('price_guard_min_tax_rate', '0'),
        'max_reasonable_tax_rate' => (float)get_setting('price_guard_max_tax_rate', '27'),
        'allowed_currencies' => array_filter(array_map('trim', explode(',', get_setting('price_guard_allowed_currencies', 'EUR,USD,GBP,CHF')))),
    ];
}

/**
 * Prüft eine EINZELNE, bereits auf Zielfelder gemappte Importzeile
 * (siehe import_build_preview()) auf offensichtlich fehlerhafte/
 * verdächtige Preise. Blockiert (ok=false) bei harten Fehlern (Nullpreis,
 * negativer Preis, unplausible Währung); markiert als Warnung
 * (weiterhin importierbar nach expliziter Bestätigung) bei weichen
 * Auffälligkeiten (Ausreißer-Preis, fehlender Steuersatz).
 */
function price_guard_check_row(array $mapped): array {
    $cfg = price_guard_settings();
    $reasons = [];
    $warnings = [];

    $priceRaw = $mapped['purchase_price'] ?? null;
    if ($priceRaw !== null && $priceRaw !== '') {
        $price = (float)str_replace(',', '.', (string)$priceRaw);
        if ($price <= 0) {
            $reasons[] = 'Einkaufspreis ist null oder negativ (' . $priceRaw . ').';
        } elseif ($price > $cfg['max_reasonable_price']) {
            $warnings[] = "Ungewöhnlich hoher Preis ($price) – bitte prüfen.";
        }
    }

    $currency = trim((string)($mapped['currency'] ?? ''));
    if ($currency !== '' && !empty($cfg['allowed_currencies']) && !in_array($currency, $cfg['allowed_currencies'], true)) {
        $reasons[] = "Unbekannte/nicht zugelassene Währung: $currency.";
    }

    $taxRaw = $mapped['tax_rate'] ?? null;
    if ($taxRaw !== null && $taxRaw !== '') {
        $tax = (float)str_replace(',', '.', (string)$taxRaw);
        if ($tax < $cfg['min_reasonable_tax_rate'] || $tax > $cfg['max_reasonable_tax_rate']) {
            $warnings[] = "Unplausibler MwSt.-Satz ($tax %).";
        }
    } else {
        $warnings[] = 'Kein MwSt.-Satz übermittelt – bitte manuell prüfen/ergänzen.';
    }

    // Widersprüchliche Netto-/Bruttoangabe: wenn sowohl Netto- als auch
    // Bruttoflag denkbar wären, aber Verkaufspreis < Einkaufspreis liegt,
    // ist das ein starkes Warnsignal für vertauschte Werte.
    if (isset($mapped['selling_price']) && $mapped['selling_price'] !== '' && isset($priceRaw) && $priceRaw !== '') {
        $sell = (float)str_replace(',', '.', (string)$mapped['selling_price']);
        $buy  = (float)str_replace(',', '.', (string)$priceRaw);
        if ($sell > 0 && $buy > 0 && $sell < $buy) {
            $warnings[] = "Verkaufspreis ($sell) liegt unter dem Einkaufspreis ($buy) – bitte prüfen (evtl. vertauschte Netto-/Bruttoangabe).";
        }
    }

    return ['ok' => empty($reasons), 'reasons' => $reasons, 'warnings' => $warnings];
}

/**
 * Prüft eine Preisänderung gegen den historischen Verlauf
 * (product_price_history) und markiert extreme Sprünge (Abschnitt 9:
 * "Ausreißer-Preise") — wird beim tatsächlichen Anwenden eines Imports
 * (import_job_apply()) zusätzlich zu price_guard_check_row() aufgerufen,
 * da der Vergleich mit dem vorherigen Preis erst zum Schreibzeitpunkt
 * zuverlässig möglich ist (Vorschau vergleicht nur gegen den aktuellen
 * Stand von product_supplier_offers, nicht die volle Historie).
 */
function price_guard_check_against_history(int $partId, ?int $supplierId, float $newPrice): array {
    $cfg = price_guard_settings();
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT price FROM product_price_history
          WHERE part_id = ? AND price_type = "einkauf" ' . ($supplierId ? 'AND supplier_id = ?' : '') . '
          ORDER BY recorded_at DESC LIMIT 1'
    );
    $params = [$partId];
    if ($supplierId) $params[] = $supplierId;
    $stmt->execute($params);
    $prior = $stmt->fetchColumn();

    if ($prior === false || (float)$prior <= 0) {
        return ['flagged' => false, 'reason' => null];
    }
    $prior = (float)$prior;
    $changePercent = abs($newPrice - $prior) / $prior * 100;
    if ($changePercent > $cfg['max_change_percent']) {
        return ['flagged' => true, 'reason' => sprintf(
            'Preissprung von %s auf %s (%.1f %%, Schwelle %.0f %%).',
            fmt_money($prior), fmt_money($newPrice), $changePercent, $cfg['max_change_percent']
        )];
    }
    return ['flagged' => false, 'reason' => null];
}
