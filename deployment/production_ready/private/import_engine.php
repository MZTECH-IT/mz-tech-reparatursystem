<?php
/**
 * MZ Tech – Universeller Import-Assistent, Engine (Phase 6)
 * ----------------------------------------------------------------------
 * Format-Erkennung, Datei-Parsing (CSV/TSV/TXT/XLSX/XML/JSON),
 * Spaltenzuordnung, Vorschau-Diff (neu/aktualisiert/unverändert/
 * Duplikat/Fehler/Warnung) und Anwendung inkl. garantiertem Rollback.
 *
 * Bewusst KEINE externe Bibliothek für XLSX (z. B. PhpSpreadsheet) nötig:
 * eine .xlsx-Datei ist technisch ein ZIP-Archiv aus XML-Dateien – die
 * PHP-Erweiterungen ZipArchive und SimpleXML (beide bereits vorhanden,
 * siehe TCPDF-Nutzung) reichen für einen soliden Lese-Zugriff auf
 * Tabellenblätter mit einfachen Werten/Formaten vollständig aus.
 *
 * Legacy-.xls (binäres Excel-97-2003-Format, OLE2/BIFF) wird bewusst NICHT
 * nativ unterstützt – dafür wäre eine vollständige BIFF-Bibliothek nötig,
 * die in dieser Umgebung nicht ohne Weiteres nachinstallierbar ist (siehe
 * OFFENE_PUNKTE_PHASE6.txt). Eine hochgeladene .xls-Datei erzeugt daher
 * eine klare, verständliche Fehlermeldung mit Empfehlung, als .xlsx oder
 * .csv zu exportieren – statt stillschweigend falsche Daten zu erzeugen.
 */

// ── Zielfelder des Import-Assistenten (Schritt 3: Spaltenzuordnung) ─────
// Deckt sowohl die zentrale Produkt-/Ersatzteildatenbank (Tabelle `parts`)
// als auch das lieferantenspezifische Angebot (`product_supplier_offers`)
// ab. Ein Lieferant muss nicht alle Felder liefern – nicht zugeordnete
// Zielfelder bleiben beim Import einfach leer/unverändert.
function import_target_fields(): array {
    return [
        // Kernidentifikation
        ['key' => 'sku',                   'label' => 'Eigene SKU/Artikelnummer',       'group' => 'Identifikation', 'required' => false],
        ['key' => 'supplier_sku',          'label' => 'Lieferanten-Artikelnummer',       'group' => 'Identifikation', 'required' => true],
        ['key' => 'ean',                   'label' => 'EAN/GTIN',                       'group' => 'Identifikation', 'required' => false],
        ['key' => 'mpn',                   'label' => 'Herstellerteilenummer (MPN)',     'group' => 'Identifikation', 'required' => false],
        // Produktdaten
        ['key' => 'name',                  'label' => 'Produktname',                    'group' => 'Produktdaten', 'required' => true],
        ['key' => 'supplier_product_name', 'label' => 'Produktname (Lieferant, Original)', 'group' => 'Produktdaten', 'required' => false],
        ['key' => 'description',           'label' => 'Beschreibung',                   'group' => 'Produktdaten', 'required' => false],
        ['key' => 'category',              'label' => 'Kategorie',                      'group' => 'Produktdaten', 'required' => false],
        ['key' => 'subcategory',           'label' => 'Unterkategorie',                 'group' => 'Produktdaten', 'required' => false],
        ['key' => 'device_type',           'label' => 'Gerätetyp',                      'group' => 'Produktdaten', 'required' => false],
        ['key' => 'model_compatibility',   'label' => 'Modellkompatibilität',           'group' => 'Produktdaten', 'required' => false],
        ['key' => 'brand',                 'label' => 'Marke',                          'group' => 'Produktdaten', 'required' => false],
        ['key' => 'manufacturer',          'label' => 'Hersteller',                     'group' => 'Produktdaten', 'required' => false],
        ['key' => 'quality_tier',          'label' => 'Qualitätsstufe (original/oem/aftermarket_a/aftermarket_b/generisch)', 'group' => 'Produktdaten', 'required' => false],
        ['key' => 'warranty_note',         'label' => 'Garantiehinweis',                'group' => 'Produktdaten', 'required' => false],
        ['key' => 'image_url',             'label' => 'Bild-URL',                       'group' => 'Produktdaten', 'required' => false],
        ['key' => 'product_url',           'label' => 'Produkt-URL',                    'group' => 'Produktdaten', 'required' => false],
        ['key' => 'datasheet_url',         'label' => 'Datenblatt-URL',                 'group' => 'Produktdaten', 'required' => false],
        ['key' => 'weight_grams',          'label' => 'Gewicht (g)',                    'group' => 'Produktdaten', 'required' => false],
        ['key' => 'packaging_unit',        'label' => 'Verpackungseinheit',             'group' => 'Produktdaten', 'required' => false],
        ['key' => 'is_discontinued',       'label' => 'Auslaufartikel (0/1)',           'group' => 'Produktdaten', 'required' => false],
        ['key' => 'replacement_sku',       'label' => 'Nachfolge-SKU (eigene)',         'group' => 'Produktdaten', 'required' => false],
        ['key' => 'requires_serial_or_batch','label' => 'Seriennummer/Charge erforderlich (0/1)', 'group' => 'Produktdaten', 'required' => false],
        // Preis/Angebot
        ['key' => 'purchase_price',        'label' => 'Einkaufspreis',                  'group' => 'Preis', 'required' => true],
        ['key' => 'currency',              'label' => 'Währung',                        'group' => 'Preis', 'required' => false],
        ['key' => 'tax_rate',              'label' => 'MwSt.-Satz (%)',                 'group' => 'Preis', 'required' => false],
        ['key' => 'is_net_price',          'label' => 'Preis ist Netto (0/1)',          'group' => 'Preis', 'required' => false],
        ['key' => 'selling_price',         'label' => 'Empf. Verkaufspreis (UVP)',      'group' => 'Preis', 'required' => false],
        ['key' => 'shipping_cost_estimate','label' => 'Versandkosten (Schätzung)',      'group' => 'Preis', 'required' => false],
        // Verfügbarkeit/Logistik
        ['key' => 'availability',          'label' => 'Verfügbarkeit (auf_lager/bestellbar/nicht_verfuegbar)', 'group' => 'Verfügbarkeit', 'required' => false],
        ['key' => 'stock_quantity_at_supplier', 'label' => 'Bestand beim Lieferanten',  'group' => 'Verfügbarkeit', 'required' => false],
        ['key' => 'delivery_time_days',    'label' => 'Lieferzeit (Tage)',              'group' => 'Verfügbarkeit', 'required' => false],
        ['key' => 'minimum_order_quantity','label' => 'Mindestbestellmenge',            'group' => 'Verfügbarkeit', 'required' => false],
        ['key' => 'offer_packaging_unit',  'label' => 'Verpackungseinheit (Lieferant, z. B. "10er-Pack")', 'group' => 'Verfügbarkeit', 'required' => false],
        ['key' => 'stock_quantity',        'label' => 'Eigener Lagerbestand',           'group' => 'Verfügbarkeit', 'required' => false],
        ['key' => 'min_stock',             'label' => 'Eigener Mindestbestand',         'group' => 'Verfügbarkeit', 'required' => false],
        ['key' => 'stock_location',        'label' => 'Lagerort',                      'group' => 'Verfügbarkeit', 'required' => false],
    ];
}

// ── Format-Erkennung (Schritt 2) ─────────────────────────────────────────
function import_format_detect(string $filename, string $raw): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['csv', 'tsv', 'txt', 'xlsx', 'xls', 'xml', 'json', 'zip'], true)) {
        return $ext;
    }
    // Inhalts-basierte Erkennung als Fallback (z. B. bei API-Antworten
    // ohne Dateiendung, siehe REST-/GraphQL-Adapter).
    $trimmed = ltrim($raw);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) return 'json';
    if ($trimmed !== '' && $trimmed[0] === '<') return 'xml';
    if (str_starts_with($raw, 'PK')) return 'xlsx'; // ZIP-Signatur (xlsx/zip)
    if (str_contains(substr($raw, 0, 2000), "\t")) return 'tsv';
    return 'csv';
}

/**
 * Parst Rohdaten in ein normalisiertes Zeilenformat:
 * ['header' => [...Spaltennamen...], 'rows' => [ [...Werte...], ... ], 'error' => ?string]
 */
function import_parse_rows(string $format, string $raw, array $options = []): array {
    $delimiter = $options['delimiter'] ?? null;
    $hasHeader = $options['has_header'] ?? true;

    switch ($format) {
        case 'csv':
        case 'tsv':
        case 'txt':
            return import_parse_delimited($raw, $delimiter ?: ($format === 'tsv' ? "\t" : ','), $hasHeader);
        case 'xlsx':
            return import_parse_xlsx($raw, $hasHeader);
        case 'xls':
            return ['header' => [], 'rows' => [], 'error' =>
                'Das klassische XLS-Format (Excel 97–2003) wird nicht direkt unterstützt. ' .
                'Bitte die Datei in Excel/LibreOffice als "XLSX" oder "CSV" speichern und erneut hochladen.'];
        case 'xml':
            return import_parse_xml($raw, $options['xml_row_path'] ?? null);
        case 'json':
            return import_parse_json($raw, $options['json_root_path'] ?? null);
        case 'zip':
            return import_parse_zip($raw, $hasHeader, $delimiter);
        default:
            return ['header' => [], 'rows' => [], 'error' => "Unbekanntes/nicht unterstütztes Format: $format"];
    }
}

function import_parse_delimited(string $raw, string $delimiter, bool $hasHeader): array {
    // BOM entfernen, Zeilenenden normalisieren.
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_filter(explode("\n", $raw), fn($l) => trim($l) !== '');
    $lines = array_values($lines);
    if (empty($lines)) return ['header' => [], 'rows' => [], 'error' => 'Datei ist leer.'];

    $parsed = [];
    foreach ($lines as $line) {
        $parsed[] = str_getcsv($line, $delimiter);
    }
    $header = $hasHeader ? array_map('trim', array_shift($parsed)) : array_map(fn($i) => "Spalte $i", array_keys($parsed[0] ?? []));
    return ['header' => $header, 'rows' => $parsed, 'error' => null];
}

/** Native XLSX-Extraktion (ZipArchive + SimpleXML), ohne externe Bibliothek. */
function import_parse_xlsx(string $raw, bool $hasHeader): array {
    if (!class_exists('ZipArchive')) {
        return ['header' => [], 'rows' => [], 'error' => 'Die PHP-Erweiterung ZipArchive ist nicht verfügbar.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return ['header' => [], 'rows' => [], 'error' => 'Die Datei ist kein gültiges XLSX/ZIP-Archiv.'];
    }

    // Shared Strings (gemeinsame Textwerte, von vielen Zellen referenziert)
    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                // <t> direkt oder mehrere <r><t> (rich text) zusammenfassen
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) { $text .= (string)$r->t; }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // Erstes Tabellenblatt (Standardfall für Preislisten-Importe)
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);
    if ($sheetXml === false) {
        return ['header' => [], 'rows' => [], 'error' => 'Kein Tabellenblatt (xl/worksheets/sheet1.xml) gefunden.'];
    }
    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        return ['header' => [], 'rows' => [], 'error' => 'Tabellenblatt konnte nicht gelesen werden (ungültiges XML).'];
    }

    $grid = [];
    $maxCol = 0;
    foreach ($sheet->sheetData->row as $row) {
        $rowIdx = (int)$row['r'];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            $colLetters = $m[1] ?? 'A';
            $colIdx = import_xlsx_col_to_index($colLetters);
            $maxCol = max($maxCol, $colIdx);

            $type = (string)$cell['t'];
            $value = null;
            if (isset($cell->v)) {
                $v = (string)$cell->v;
                $value = $type === 's' ? ($sharedStrings[(int)$v] ?? '') : $v;
            } elseif (isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            }
            $grid[$rowIdx][$colIdx] = $value;
        }
    }
    if (empty($grid)) return ['header' => [], 'rows' => [], 'error' => 'Tabellenblatt enthält keine Daten.'];

    ksort($grid);
    $rows = [];
    foreach ($grid as $rowIdx => $cols) {
        $line = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $line[] = $cols[$c] ?? '';
        }
        $rows[] = $line;
    }

    $header = $hasHeader ? array_map('trim', array_shift($rows)) : array_map(fn($i) => "Spalte $i", range(0, $maxCol));
    return ['header' => $header, 'rows' => $rows, 'error' => null];
}

function import_xlsx_col_to_index(string $letters): int {
    $idx = 0;
    foreach (str_split($letters) as $ch) {
        $idx = $idx * 26 + (ord($ch) - ord('A') + 1);
    }
    return $idx - 1;
}

/**
 * XML-Parsing: erwartet ein sich wiederholendes Element (z. B. <product>
 * unterhalb eines beliebigen Wurzelelements). $rowPath ist optional der
 * XPath-artige Elementname (z. B. "product" oder "items/item"); ohne
 * Angabe wird das am häufigsten wiederholte Kindelement automatisch
 * erkannt.
 */
function import_parse_xml(string $raw, ?string $rowPath): array {
    $prev = libxml_use_internal_errors(true);
    // Haertung: kein Netzwerkzugriff waehrend des Parsens.
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        return ['header' => [], 'rows' => [], 'error' => 'Ungültiges XML-Dokument.'];
    }

    $nodes = null;
    if ($rowPath) {
        $nodes = $xml->xpath('//' . trim($rowPath, '/'));
    }
    if (empty($nodes)) {
        // Automatische Erkennung: das am häufigsten vorkommende Kindelement
        // auf der zweiten Ebene (typisch für "<products><product>...</product></products>").
        $counts = [];
        foreach ($xml->children() as $child) {
            $counts[$child->getName()] = ($counts[$child->getName()] ?? 0) + 1;
        }
        arsort($counts);
        $bestTag = array_key_first($counts);
        $nodes = $bestTag ? $xml->xpath('//' . $bestTag) : [];
    }
    if (empty($nodes)) {
        return ['header' => [], 'rows' => [], 'error' => 'Kein sich wiederholendes Datenelement im XML gefunden.'];
    }

    $header = [];
    $rows = [];
    foreach ($nodes as $node) {
        $rowAssoc = [];
        foreach ($node->children() as $field) {
            $rowAssoc[$field->getName()] = trim((string)$field);
        }
        foreach ($node->attributes() as $aName => $aVal) {
            $rowAssoc['@' . $aName] = (string)$aVal;
        }
        foreach (array_keys($rowAssoc) as $k) {
            if (!in_array($k, $header, true)) $header[] = $k;
        }
        $rows[] = $rowAssoc;
    }
    // In indizierte Zeilen (gemäß gemeinsamem $header) umwandeln, damit
    // die Weiterverarbeitung format-unabhängig bleibt.
    $indexedRows = [];
    foreach ($rows as $rowAssoc) {
        $line = [];
        foreach ($header as $h) { $line[] = $rowAssoc[$h] ?? ''; }
        $indexedRows[] = $line;
    }
    return ['header' => $header, 'rows' => $indexedRows, 'error' => null];
}

/**
 * JSON-Parsing: erwartet ein Array von Objekten, entweder auf der
 * Wurzelebene oder unter einem konfigurierbaren Pfad (z. B.
 * "data.products", punktgetrennt).
 */
function import_parse_json(string $raw, ?string $rootPath): array {
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['header' => [], 'rows' => [], 'error' => 'Ungültiges JSON: ' . json_last_error_msg()];
    }
    if ($rootPath) {
        foreach (explode('.', $rootPath) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return ['header' => [], 'rows' => [], 'error' => "Pfad '$rootPath' nicht im JSON gefunden."];
            }
            $data = $data[$segment];
        }
    }
    if (!is_array($data)) {
        return ['header' => [], 'rows' => [], 'error' => 'Erwartetes JSON-Array nicht gefunden.'];
    }
    // Falls die Wurzel selbst kein Listen-Array ist, nach dem ersten
    // Listen-wertigen Schlüssel suchen (üblich bei API-Antworten wie
    // {"success":true,"products":[...]}).
    if (!array_is_list($data)) {
        $found = null;
        foreach ($data as $v) {
            if (is_array($v) && array_is_list($v)) { $found = $v; break; }
        }
        if ($found === null) {
            return ['header' => [], 'rows' => [], 'error' => 'Kein Datensatz-Array im JSON gefunden (json_root_path im Import-Profil angeben).'];
        }
        $data = $found;
    }

    $header = [];
    foreach ($data as $item) {
        if (is_array($item)) {
            foreach (array_keys($item) as $k) {
                if (!in_array($k, $header, true)) $header[] = $k;
            }
        }
    }
    $rows = [];
    foreach ($data as $item) {
        $line = [];
        foreach ($header as $h) {
            $v = is_array($item) ? ($item[$h] ?? '') : '';
            $line[] = is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $rows[] = $line;
    }
    return ['header' => $header, 'rows' => $rows, 'error' => null];
}

/** ZIP-Archiv: entpackt die erste unterstützte Datei darin und delegiert. */
function import_parse_zip(string $raw, bool $hasHeader, ?string $delimiter): array {
    if (!class_exists('ZipArchive')) {
        return ['header' => [], 'rows' => [], 'error' => 'Die PHP-Erweiterung ZipArchive ist nicht verfügbar.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'zipimp');
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return ['header' => [], 'rows' => [], 'error' => 'ZIP-Archiv konnte nicht geöffnet werden.'];
    }
    $maxEntries = 500;
    $maxUncompressedBytes = 100 * 1024 * 1024; // 100 MB
    if ($zip->numFiles > $maxEntries) {
        $zip->close();
        @unlink($tmp);
        return ['header' => [], 'rows' => [], 'error' => 'ZIP-Archiv enthaelt zu viele Dateien (' . $zip->numFiles . ', Maximum ' . $maxEntries . ').'];
    }
    $totalUncompressed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat !== false) {
            $totalUncompressed += (int)$stat['size'];
        }
    }
    if ($totalUncompressed > $maxUncompressedBytes) {
        $zip->close();
        @unlink($tmp);
        return ['header' => [], 'rows' => [], 'error' => 'ZIP-Archiv ist unkomprimiert zu groß (Limit ' . round($maxUncompressedBytes / 1024 / 1024) . ' MB).'];
    }
    $innerName = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'tsv', 'txt', 'xlsx', 'xml', 'json'], true)) {
            $innerName = $name;
            break;
        }
    }
    if ($innerName === null) {
        $zip->close();
        @unlink($tmp);
        return ['header' => [], 'rows' => [], 'error' => 'Im ZIP-Archiv wurde keine unterstützte Datei (CSV/TSV/TXT/XLSX/XML/JSON) gefunden.'];
    }
    $innerRaw = $zip->getFromName($innerName);
    $zip->close();
    @unlink($tmp);
    $innerFormat = import_format_detect($innerName, $innerRaw);
    return import_parse_rows($innerFormat, $innerRaw, ['has_header' => $hasHeader, 'delimiter' => $delimiter]);
}

/** Wendet die gespeicherte Spaltenzuordnung auf eine Zeile an. */
function import_apply_mapping(array $header, array $row, array $mapping): array {
    $byName = array_flip($header);
    $out = [];
    foreach ($mapping as $targetKey => $sourceColumn) {
        if ($sourceColumn === '' || $sourceColumn === null) continue;
        $idx = $byName[$sourceColumn] ?? null;
        $out[$targetKey] = $idx !== null ? trim((string)($row[$idx] ?? '')) : '';
    }
    return $out;
}

/**
 * Baut die Vorschau (Schritt 5): vergleicht jede gemappte Zeile gegen den
 * bestehenden Stand in `product_supplier_offers` (Schlüssel gemäß
 * $dedupeKey) und klassifiziert sie als neu/aktualisiert/unverändert/
 * Duplikat/Fehler/Warnung. Führt dabei KEINE Schreiboperation aus.
 */
function import_build_preview(int $supplierId, array $header, array $rows, array $mapping, string $dedupeKey = 'supplier_sku'): array {
    $db = get_db();
    $preview = ['new' => [], 'updated' => [], 'unchanged' => [], 'duplicate' => [], 'errors' => [], 'warnings' => []];
    $seenKeys = [];

    $stmt = $db->prepare(
        'SELECT o.*, p.selling_price AS current_selling_price
           FROM product_supplier_offers o
           JOIN parts p ON p.id = o.part_id
          WHERE o.supplier_id = ? AND o.supplier_sku = ? LIMIT 1'
    );

    foreach ($rows as $rowIdx => $row) {
        $mapped = import_apply_mapping($header, $row, $mapping);
        $rowNum = $rowIdx + 1;

        $dedupeValue = trim((string)($mapped[$dedupeKey] ?? ''));
        if ($dedupeValue === '') {
            $preview['errors'][] = ['row' => $rowNum, 'message' => "Kein Wert für Schlüsselfeld '$dedupeKey' – Zeile kann nicht zugeordnet werden.", 'data' => $mapped];
            continue;
        }
        if (isset($seenKeys[$dedupeValue])) {
            $preview['duplicate'][] = ['row' => $rowNum, 'message' => "Doppelter Schlüssel '$dedupeValue' bereits in Zeile {$seenKeys[$dedupeValue]} dieser Datei.", 'data' => $mapped];
            continue;
        }
        $seenKeys[$dedupeValue] = $rowNum;

        // Preisplausibilität (siehe private/price_guard.php)
        $priceCheck = price_guard_check_row($mapped);
        if (!$priceCheck['ok']) {
            $preview['errors'][] = ['row' => $rowNum, 'message' => implode('; ', $priceCheck['reasons']), 'data' => $mapped];
            continue;
        }
        if (!empty($priceCheck['warnings'])) {
            $preview['warnings'][] = ['row' => $rowNum, 'message' => implode('; ', $priceCheck['warnings']), 'data' => $mapped];
        }

        $stmt->execute([$supplierId, $dedupeValue]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $preview['new'][] = ['row' => $rowNum, 'data' => $mapped];
            continue;
        }

        $priceChanged = isset($mapped['purchase_price']) && $mapped['purchase_price'] !== ''
            && round((float)str_replace(',', '.', $mapped['purchase_price']), 2) !== round((float)$existing['purchase_price'], 2);
        $availChanged = isset($mapped['availability']) && $mapped['availability'] !== '' && $mapped['availability'] !== $existing['availability'];

        if ($priceChanged || $availChanged) {
            $preview['updated'][] = ['row' => $rowNum, 'data' => $mapped, 'existing_offer_id' => (int)$existing['id'], 'old_price' => $existing['purchase_price'], 'old_availability' => $existing['availability']];
        } else {
            $preview['unchanged'][] = ['row' => $rowNum, 'data' => $mapped, 'existing_offer_id' => (int)$existing['id']];
        }
    }

    return $preview;
}
