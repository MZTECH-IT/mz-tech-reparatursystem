<?php
/**
 * MZ Tech – Eigenständiger, abhängigkeitsfreier QR-Code-Encoder
 * ----------------------------------------------------------------------
 * Erzeugt ECHTE, scanbare QR-Codes rein lokal in PHP (GD), ohne jede
 * externe Bibliothek und ohne jede externe API (insbesondere KEINE
 * Google-Chart-API). Unterstützt Byte-Modus, Fehlerkorrekturstufe L,
 * QR-Versionen 1–10 (Datenkapazität bis 271 Byte) – ausreichend für die
 * im System verwendeten internen Reparatur-URLs.
 *
 * Implementiert den vollständigen ISO/IEC-18004-Algorithmus:
 * Galois-Feld-Arithmetik, Reed-Solomon-Fehlerkorrektur, Modulplatzierung
 * (Suchmuster, Trennlinien, Zeitgebermuster, Ausrichtungsmuster, Format-
 * /Versionsinformationen), alle 8 Maskierungsmuster mit Penalty-Bewertung
 * nach Spezifikation, sowie PNG-Rendering via GD.
 *
 * Wird nur genutzt, wenn keine per Composer installierte QR-Bibliothek
 * (chillerlan/php-qrcode) vorhanden ist.
 */

namespace MZTech;

class QRCode {

    /** @var array<int,int> */
    private static $expTable = [];
    /** @var array<int,int> */
    private static $logTable = [];

    // Datencodewörter je Version (1..10), Level L
    private static $DATA_CODEWORDS = [
        1 => 19, 2 => 34, 3 => 55, 4 => 80, 5 => 108,
        6 => 136, 7 => 156, 8 => 194, 9 => 232, 10 => 274,
    ];

    // EC-Codewörter pro Block, Level L
    private static $ECC_PER_BLOCK = [
        1 => 7, 2 => 10, 3 => 15, 4 => 20, 5 => 26,
        6 => 18, 7 => 20, 8 => 24, 9 => 30, 10 => 18,
    ];

    // Blockstruktur [ [anzahlBloecke, datenCodewoerterProBlock], ... ], Level L
    private static $BLOCK_STRUCTURE = [
        1  => [[1, 19]],
        2  => [[1, 34]],
        3  => [[1, 55]],
        4  => [[1, 80]],
        5  => [[1, 108]],
        6  => [[2, 68]],
        7  => [[2, 78]],
        8  => [[2, 97]],
        9  => [[2, 116]],
        10 => [[2, 68], [2, 69]],
    ];

    // Restbits nach den Codewörtern, je Version
    private static $REMAINDER_BITS = [
        1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7,
        6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0,
    ];

    // Ausrichtungsmuster-Zentren je Version (Position 6 ist Teil des Timing-Patterns)
    private static $ALIGNMENT_COORDS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    private static function initTables(): void {
        if (!empty(self::$expTable)) return;
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
        self::$expTable = $exp;
        self::$logTable = $log;
    }

    private static function gfMul(int $a, int $b): int {
        if ($a === 0 || $b === 0) return 0;
        return self::$expTable[self::$logTable[$a] + self::$logTable[$b]];
    }

    /** Erzeugt das Reed-Solomon-Generatorpolynom vom Grad $degree. */
    private static function generatorPoly(int $degree): array {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $ci => $coef) {
                $next[$ci] ^= self::gfMul($coef, self::$expTable[$i]);
                $next[$ci + 1] ^= $coef;
            }
            $poly = $next;
        }
        // $poly ist bisher niedrigster Grad zuerst geordnet; rsEncode() erwartet
        // höchsten Grad zuerst (gen[0] == 1 als führender Koeffizient).
        return array_reverse($poly); // Grad $degree, count($poly) == $degree+1, poly[0]==1
    }

    /** Berechnet die EC-Codewörter für einen Datenblock. */
    private static function rsEncode(array $data, int $eccCount): array {
        $gen = self::generatorPoly($eccCount);
        $result = array_fill(0, $eccCount, 0);
        foreach ($data as $d) {
            $factor = $d ^ $result[0];
            array_shift($result);
            $result[] = 0;
            if ($factor !== 0) {
                for ($i = 0; $i < $eccCount; $i++) {
                    $result[$i] ^= self::gfMul($gen[$i + 1], $factor);
                }
            }
        }
        return $result;
    }

    /** Ermittelt die kleinste passende Version (1-10) für $byteLen Bytes im Byte-Modus, Level L. */
    private static function selectVersion(int $byteLen): ?int {
        for ($v = 1; $v <= 10; $v++) {
            $ccBits = ($v <= 9) ? 8 : 16;
            $capacityBits = self::$DATA_CODEWORDS[$v] * 8 - 4 - $ccBits;
            $capacityBytes = intdiv($capacityBits, 8);
            if ($byteLen <= $capacityBytes) return $v;
        }
        return null; // zu lang für unterstützte Versionen
    }

    /** Baut den vollständigen Bitstring (Modus+Länge+Daten+Padding) für eine Version. */
    private static function buildDataBits(string $data, int $version): string {
        $ccBits = ($version <= 9) ? 8 : 16;
        $bits = '0100'; // Byte-Modus
        $bits .= str_pad(decbin(strlen($data)), $ccBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = self::$DATA_CODEWORDS[$version] * 8;

        // Terminator (max. 4 Nullbits)
        $termLen = min(4, $capacityBits - strlen($bits));
        if ($termLen > 0) $bits .= str_repeat('0', $termLen);

        // Auf Byte-Grenze auffüllen
        while (strlen($bits) % 8 !== 0) $bits .= '0';

        // Mit Padding-Bytes 11101100 / 00010001 auffüllen
        $padBytes = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $padBytes[$i % 2];
            $i++;
        }

        return substr($bits, 0, $capacityBits);
    }

    private static function bitsToBytes(string $bits): array {
        $bytes = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $bytes[] = bindec(substr($bits, $i, 8));
        }
        return $bytes;
    }

    /** Teilt Datencodewörter in Blöcke, berechnet ECC, interleaved alles zu einem finalen Bitstrom. */
    private static function interleave(array $dataBytes, int $version): string {
        $structure = self::$BLOCK_STRUCTURE[$version];
        $eccCount  = self::$ECC_PER_BLOCK[$version];

        $blocks = [];
        $eccBlocks = [];
        $offset = 0;
        foreach ($structure as [$count, $blockLen]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($dataBytes, $offset, $blockLen);
                $offset += $blockLen;
                $blocks[] = $block;
                $eccBlocks[] = self::rsEncode($block, $eccCount);
            }
        }

        $maxDataLen = max(array_map('count', $blocks));
        $result = [];
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) $result[] = $block[$i];
            }
        }
        for ($i = 0; $i < $eccCount; $i++) {
            foreach ($eccBlocks as $eccBlock) {
                $result[] = $eccBlock[$i];
            }
        }

        $bits = '';
        foreach ($result as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', self::$REMAINDER_BITS[$version]);
        return $bits;
    }

    private static function formatInfoBits(int $maskPattern): string {
        // Fehlerkorrekturstufe L = Bitmuster '01'
        $data = (0b01 << 3) | $maskPattern; // 5 Bit
        $g = 0b10100110111; // Generatorpolynom Grad 10
        $val = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($val >> $i) & 1) $val ^= $g << ($i - 10);
        }
        $formatBits = ($data << 10) | ($val & 0x3FF);
        $formatBits ^= 0x5412;
        return str_pad(decbin($formatBits), 15, '0', STR_PAD_LEFT);
    }

    private static function versionInfoBits(int $version): string {
        $g = 0b1111100100101; // Generatorpolynom Grad 12
        $val = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($val >> $i) & 1) $val ^= $g << ($i - 12);
        }
        $bits = ($version << 12) | ($val & 0xFFF);
        return str_pad(decbin($bits), 18, '0', STR_PAD_LEFT);
    }

    private static function applyMask(int $pattern, int $r, int $c): bool {
        switch ($pattern) {
            case 0: return (($r + $c) % 2) === 0;
            case 1: return ($r % 2) === 0;
            case 2: return ($c % 3) === 0;
            case 3: return (($r + $c) % 3) === 0;
            case 4: return ((intdiv($r, 2) + intdiv($c, 3)) % 2) === 0;
            case 5: return ((($r * $c) % 2) + (($r * $c) % 3)) === 0;
            case 6: return (((($r * $c) % 2) + (($r * $c) % 3)) % 2) === 0;
            case 7: return (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0;
        }
        return false;
    }

    /**
     * Baut die vollständige Modulmatrix (inkl. Maskierung/Format-/Versionsinfo)
     * für einen gegebenen Text. Gibt [size, matrix] zurück, matrix[r][c] = bool (true=dunkel).
     */
    private static function buildMatrix(string $text): ?array {
        self::initTables();

        $version = self::selectVersion(strlen($text));
        if ($version === null) return null;

        $size = 4 * $version + 17;
        $dataBytes = self::bitsToBytes(self::buildDataBits($text, $version));
        $finalBits = self::interleave($dataBytes, $version);

        $module   = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $setFn = function (int $r, int $c, bool $dark) use (&$module, &$reserved) {
            $module[$r][$c] = $dark;
            $reserved[$r][$c] = true;
        };

        // Suchmuster (Finder Patterns) + Trennlinien in den 3 Ecken
        $placeFinder = function (int $topR, int $topC) use (&$setFn, $size) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $topR + $r; $cc = $topC + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) continue;
                    $dark = false;
                    if ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) {
                        $isBorder = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                        $isCore   = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                        $dark = $isBorder || $isCore;
                    }
                    $setFn($rr, $cc, $dark);
                }
            }
        };
        $placeFinder(0, 0);
        $placeFinder(0, $size - 7);
        $placeFinder($size - 7, 0);

        // Zeitgebermuster (Timing Patterns)
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = ($i % 2) === 0;
            if (!$reserved[6][$i]) $setFn(6, $i, $dark);
            if (!$reserved[$i][6]) $setFn($i, 6, $dark);
        }

        // Ausrichtungsmuster (Alignment Patterns)
        $coords = self::$ALIGNMENT_COORDS[$version];
        foreach ($coords as $cr) {
            foreach ($coords as $cc) {
                // Überschneidung mit den drei Suchmustern vermeiden
                if (($cr === 6 && $cc === 6) ||
                    ($cr === 6 && $cc === $size - 7) ||
                    ($cr === $size - 7 && $cc === 6)) continue;
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $dark = (max(abs($r), abs($c)) !== 1);
                        $setFn($cr + $r, $cc + $c, $dark);
                    }
                }
            }
        }

        // Dunkles Modul (immer gesetzt)
        $setFn(4 * $version + 9, 8, true);

        // Format-Info-Bereiche reservieren (Werte werden nach Maskenwahl geschrieben)
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) { $reserved[8][$i] = true; }
            if ($i !== 6) { $reserved[$i][8] = true; }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
        }
        for ($i = 0; $i < 7; $i++) {
            $reserved[$size - 1 - $i][8] = true;
        }
        $reserved[$size - 8][8] = true;

        // Versionsinfo reservieren (nur Version >= 7)
        if ($version >= 7) {
            for ($r = 0; $r < 6; $r++) {
                for ($c = 0; $c < 3; $c++) {
                    $reserved[$r][$size - 11 + $c] = true;
                    $reserved[$size - 11 + $c][$r] = true;
                }
            }
        }

        // Datenbits im Zickzack von unten rechts nach oben links platzieren
        $bitIndex = 0;
        $bitLen = strlen($finalBits);
        $upward = true;
        $col = $size - 1;
        while ($col > 0) {
            if ($col === 6) $col--; // Timing-Spalte überspringen
            for ($k = 0; $k < $size; $k++) {
                $r = $upward ? ($size - 1 - $k) : $k;
                for ($cOffset = 0; $cOffset < 2; $cOffset++) {
                    $c = $col - $cOffset;
                    if ($reserved[$r][$c]) continue;
                    $bit = ($bitIndex < $bitLen) ? ($finalBits[$bitIndex] === '1') : false;
                    $module[$r][$c] = $bit;
                    $bitIndex++;
                }
            }
            $upward = !$upward;
            $col -= 2;
        }

        // Alle 8 Maskierungsmuster bewerten, bestes wählen
        $bestPattern = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMasked = null;
        for ($p = 0; $p < 8; $p++) {
            $masked = [];
            for ($r = 0; $r < $size; $r++) {
                $row = [];
                for ($c = 0; $c < $size; $c++) {
                    $val = $module[$r][$c];
                    if (!$reserved[$r][$c] && self::applyMask($p, $r, $c)) $val = !$val;
                    $row[] = $val;
                }
                $masked[] = $row;
            }
            $penalty = self::evaluatePenalty($masked, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestPattern = $p;
                $bestMasked = $masked;
            }
        }

        // Format-Info schreiben
        $fmtBits = self::formatInfoBits($bestPattern);
        // Rund um obere linke Ecke
        $fmtPositions1 = [[8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],[7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]];
        for ($i = 0; $i < 15; $i++) {
            [$r, $c] = $fmtPositions1[$i];
            $bestMasked[$r][$c] = $fmtBits[$i] === '1';
        }
        // Zweite Kopie (oben rechts / unten links)
        for ($i = 0; $i < 8; $i++) {
            $bestMasked[8][$size - 1 - $i] = $fmtBits[14 - $i] === '1';
        }
        for ($i = 0; $i < 7; $i++) {
            $bestMasked[$size - 1 - $i][8] = $fmtBits[$i] === '1';
        }
        $bestMasked[$size - 8][8] = true; // dunkles Modul erneut sichern

        // Versionsinfo schreiben (Version >= 7)
        if ($version >= 7) {
            $vBits = self::versionInfoBits($version);
            $idx = 0;
            for ($c = 0; $c < 3; $c++) {
                for ($r = 0; $r < 6; $r++) {
                    $bit = $vBits[17 - $idx] === '1';
                    $bestMasked[$r][$size - 11 + $c] = $bit;
                    $bestMasked[$size - 11 + $c][$r] = $bit;
                    $idx++;
                }
            }
        }

        return [$size, $bestMasked];
    }

    private static function evaluatePenalty(array $m, int $size): int {
        $penalty = 0;

        // Regel 1: gleichfarbige Läufe in Zeilen und Spalten
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) $penalty += 3 + ($run - 5);
                    $run = 1;
                }
            }
            if ($run >= 5) $penalty += 3 + ($run - 5);
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r - 1][$c]) {
                    $run++;
                } else {
                    if ($run >= 5) $penalty += 3 + ($run - 5);
                    $run = 1;
                }
            }
            if ($run >= 5) $penalty += 3 + ($run - 5);
        }

        // Regel 2: 2x2-Blöcke gleicher Farbe
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Regel 3: Finder-ähnliche Muster (1:1:3:1:1) mit 4 hellen Modulen davor/danach
        $patternDark  = [true, false, true, true, true, false, true];
        $checkSeq = function (array $seq) use ($patternDark): bool {
            $n = count($patternDark);
            for ($i = 0; $i <= count($seq) - $n; $i++) {
                $slice = array_slice($seq, $i, $n);
                if ($slice === $patternDark) {
                    $before = array_slice($seq, max(0, $i - 4), min(4, $i));
                    $after  = array_slice($seq, $i + $n, min(4, count($seq) - $i - $n));
                    $beforeOk = count($before) < 4 || !in_array(true, $before, true);
                    $afterOk  = count($after) < 4 || !in_array(true, $after, true);
                    if ($beforeOk && $afterOk) return true;
                }
            }
            return false;
        };
        for ($r = 0; $r < $size; $r++) {
            if ($checkSeq($m[$r])) $penalty += 40;
        }
        for ($c = 0; $c < $size; $c++) {
            $col = [];
            for ($r = 0; $r < $size; $r++) $col[] = $m[$r][$c];
            if ($checkSeq($col)) $penalty += 40;
        }

        // Regel 4: Verhältnis dunkler zu heller Module
        $dark = 0;
        foreach ($m as $row) foreach ($row as $v) if ($v) $dark++;
        $total = $size * $size;
        $percent = ($dark * 100) / $total;
        $penalty += (int)(floor(abs($percent - 50) / 5)) * 10;

        return $penalty;
    }

    /**
     * Gibt die rohe Modulmatrix für $text zurück: ['size' => int, 'margin' => int,
     * 'matrix' => bool[][]] (matrix[r][c] = true bedeutet "dunkles Modul").
     * Wird u. a. von der PDF-Erzeugung genutzt, um den QR-Code als Vektor-
     * Rechtecke direkt ins PDF zu zeichnen (ohne Bild-Einbettung nötig zu
     * haben). Gibt null zurück, wenn der Text zu lang für die unterstützten
     * QR-Versionen (1–10) ist.
     */
    public static function getMatrix(string $text, int $margin = 4): ?array {
        $result = self::buildMatrix($text);
        if ($result === null) return null;
        [$size, $matrix] = $result;
        return ['size' => $size, 'margin' => $margin, 'matrix' => $matrix];
    }

    /**
     * Rendert den QR-Code für $text als PNG-Binärdaten und gibt sie zurück.
     * $scale = Pixel pro Modul, $margin = Ruhezone in Modulen (Standard 4 lt. Spezifikation).
     * Gibt null zurück, wenn der Text zu lang für die unterstützten Versionen ist.
     */
    public static function pngData(string $text, int $scale = 8, int $margin = 4): ?string {
        $result = self::buildMatrix($text);
        if ($result === null) return null;
        [$size, $matrix] = $result;

        $imgSize = ($size + 2 * $margin) * $scale;
        $img = imagecreate($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $imgSize - 1, $imgSize - 1, $white);

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c]) {
                    $x = ($c + $margin) * $scale;
                    $y = ($r + $margin) * $scale;
                    imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return $data;
    }
}
