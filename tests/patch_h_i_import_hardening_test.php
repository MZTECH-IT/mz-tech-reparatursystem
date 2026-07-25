<?php
// Test für Fix A (LIBXML_NONET) und Fix B (Zip-Bomben-Schutz) in
// private/import_engine.php.

function import_format_detect(string $filename, string $raw): string { return 'csv'; }
function import_parse_rows(string $format, string $raw, array $options = []): array {
    return ['header' => ['a', 'b'], 'rows' => [['1', '2']], 'error' => null];
}

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
    $indexedRows = [];
    foreach ($rows as $rowAssoc) {
        $line = [];
        foreach ($header as $h) { $line[] = $rowAssoc[$h] ?? ''; }
        $indexedRows[] = $line;
    }
    return ['header' => $header, 'rows' => $indexedRows, 'error' => null];
}

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
    $maxUncompressedBytes = 100 * 1024 * 1024;
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

$fails = 0;

// Test 1 (Regression): normales, kleines XML wird weiterhin korrekt geparst
$xml = '<products><product><sku>A1</sku><price>9.99</price></product><product><sku>A2</sku><price>5.50</price></product></products>';
$result = import_parse_xml($xml, null);
if ($result['error'] === null && count($result['rows']) === 2) {
    echo "PASS Test1 (Regression XML): normales XML weiterhin korrekt geparst trotz LIBXML_NONET\n";
} else {
    echo "FAIL Test1: " . json_encode($result) . "\n"; $fails++;
}

// Test 2 (Regression): normales kleines ZIP mit einer CSV-Datei wird weiterhin akzeptiert
$zipPath = tempnam(sys_get_temp_dir(), 'testzip');
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('preisliste.csv', "sku;price\nA1;9.99\n");
$zip->close();
$raw = file_get_contents($zipPath);
unlink($zipPath);
$result = import_parse_zip($raw, true, ';');
if ($result['error'] === null) {
    echo "PASS Test2 (Regression ZIP): normales kleines ZIP weiterhin akzeptiert\n";
} else {
    echo "FAIL Test2: " . json_encode($result) . "\n"; $fails++;
}

// Test 3: ZIP mit zu vielen Einträgen wird abgelehnt (Zip-Bomben-Schutz, Eintragsanzahl)
$zipPath = tempnam(sys_get_temp_dir(), 'testzip2');
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
for ($i = 0; $i < 501; $i++) {
    $zip->addFromString("file_$i.txt", "x");
}
$zip->close();
$raw = file_get_contents($zipPath);
unlink($zipPath);
$result = import_parse_zip($raw, true, ';');
if ($result['error'] !== null && str_contains($result['error'], 'zu viele Dateien')) {
    echo "PASS Test3 (Haertung): ZIP mit 501 Eintraegen (Limit 500) wird abgelehnt\n";
} else {
    echo "FAIL Test3: " . json_encode($result) . "\n"; $fails++;
}

// Test 4: ZIP mit zu großer unkomprimierter Gesamtgröße wird abgelehnt (Zip-Bomben-Schutz, Größe)
$zipPath = tempnam(sys_get_temp_dir(), 'testzip3');
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
// Stark komprimierbarer Inhalt (viele Nullen) -> kleine Datei, aber riesige
// unkomprimierte Groesse simuliert durch mehrere grosse addFromString-Aufrufe.
$bigChunk = str_repeat('0', 5 * 1024 * 1024); // 5 MB pro Eintrag
for ($i = 0; $i < 21; $i++) { // 21 * 5MB = 105 MB > 100 MB Limit
    $zip->addFromString("big_$i.dat.csv", $bigChunk);
}
$zip->close();
$raw = file_get_contents($zipPath);
unlink($zipPath);
$result = import_parse_zip($raw, true, ';');
if ($result['error'] !== null && str_contains($result['error'], 'zu groß')) {
    echo "PASS Test4 (Haertung): ZIP mit 105 MB unkomprimiert (Limit 100 MB) wird abgelehnt\n";
} else {
    echo "FAIL Test4: " . json_encode($result) . "\n"; $fails++;
}

exit($fails > 0 ? 1 : 0);
