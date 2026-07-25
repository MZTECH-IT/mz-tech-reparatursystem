<?php
/**
 * MZ Tech – Eigenständige PDF-Engine (TCPDF-kompatible Teilmenge)
 *
 * Implementiert die vom Repair-System genutzte TCPDF-API ohne externe
 * Abhängigkeiten. Erzeugt valide PDF-1.4-Dokumente mit den PDF-Core-Fonts
 * (Helvetica), FlateDecode-Komprimierung und Windows-1252-Textkodierung
 * (Umlaute + €-Zeichen).
 *
 * Unterstützte Methoden:
 *   AddPage, Cell, MultiCell, Ln, Line, Rect, Output, Image,
 *   SetFont, SetFillColor, SetTextColor, SetDrawColor, SetLineWidth,
 *   SetMargins, SetAutoPageBreak, SetX, SetY, SetXY, GetX, GetY,
 *   SetCreator, SetAuthor, SetTitle, setPrintHeader, setPrintFooter
 *
 * Bild-Einbettung (Image(), Dokumentenmodul-Erweiterung):
 *   Echte Rasterbild-Einbettung (Firmenlogo) als PDF-XObject. JPEG/PNG/GIF/
 *   WEBP werden über die PHP-GD-Erweiterung eingelesen und einheitlich als
 *   unkomprimiertes RGB (+ optionaler Alpha-/SMask-Kanal für transparente
 *   PNGs) mit FlateDecode eingebettet – dadurch wird Transparenz IMMER
 *   korrekt dargestellt (kein grauer/weißer Kasten um freigestellte Logos),
 *   unabhängig vom Ausgangsformat. Ist die GD-Erweiterung auf dem Server
 *   nicht verfügbar, liefert Image() sauber `false` zurück; aufrufende
 *   Stellen (siehe private/pdf_common.php, pdf_draw_header()) fallen dann
 *   automatisch auf die bisherige Farbkachel mit Firmen-Kürzel zurück –
 *   die PDF-Erzeugung bricht dadurch niemals ab.
 */

if (class_exists('TCPDF', false)) {
    return; // Echte TCPDF-Bibliothek bereits geladen
}

class TCPDF
{
    protected float $pageWidth  = 210.0;   // A4 mm
    protected float $pageHeight = 297.0;
    protected float $k          = 2.834645669; // Punkte pro mm (72/25.4)

    protected float $lMargin = 15.0;
    protected float $tMargin = 15.0;
    protected float $rMargin = 15.0;
    protected float $bMargin = 20.0;

    protected bool  $autoPageBreak    = true;
    protected float $pageBreakTrigger = 277.0;

    protected float $x = 15.0;
    protected float $y = 15.0;
    protected float $lasth = 0.0;

    /** @var string[] Inhalts-Streams je Seite */
    protected array $pages = [];
    protected int   $page  = 0;

    /**
     * Registrierte Bilder (Dokumentenmodul-Erweiterung): realer Dateipfad
     * => Metadaten inkl. bereits GD-normalisierter Roh-RGB-Pixeldaten
     * (+ optionalem Alphakanal für transparente PNGs). Jedes Bild wird nur
     * EINMAL gelesen (dedupliziert über den realen Pfad), auch wenn
     * Image() mehrfach mit demselben Logo aufgerufen wird.
     */
    protected array $images = [];

    protected string $fontFamily = 'helvetica';
    protected string $fontStyle  = '';
    protected float  $fontSize   = 10.0;   // pt
    protected string $fontKey    = 'F1';

    protected string $fillColor = '0 g';
    protected string $textColor = '0 g';
    protected string $drawColor = '0 G';
    protected bool   $colorFlag = false;
    protected float  $lineWidth = 0.2;

    protected array $meta = ['Creator' => '', 'Author' => '', 'Title' => ''];

    /** Genutzte Fonts: key => ['name' => PostScript-Name, 'index' => n] */
    protected array $usedFonts = [];

    /** Helvetica-Zeichenbreiten (1/1000 em), Windows-1252 */
    protected static array $wRegular = [];
    protected static array $wBold    = [];

    public function __construct(
        string $orientation = 'P',
        string $unit = 'mm',
        string $format = 'A4',
        bool $unicode = true,
        string $encoding = 'UTF-8',
        bool $diskcache = false
    ) {
        if (strtoupper($orientation) === 'L') {
            [$this->pageWidth, $this->pageHeight] = [297.0, 210.0];
        }
        $this->pageBreakTrigger = $this->pageHeight - $this->bMargin;
        self::initWidths();
        $this->selectFont('helvetica', '');
    }

    /* ── Metadaten ──────────────────────────────────── */
    public function SetCreator(string $c): void { $this->meta['Creator'] = $c; }
    public function SetAuthor(string $a): void  { $this->meta['Author']  = $a; }
    public function SetTitle(string $t): void   { $this->meta['Title']   = $t; }
    public function setPrintHeader(bool $v = true): void {}
    public function setPrintFooter(bool $v = true): void {}

    /* ── Layout ─────────────────────────────────────── */
    public function SetMargins(float $left, float $top, float $right = -1): void {
        $this->lMargin = $left;
        $this->tMargin = $top;
        $this->rMargin = ($right < 0) ? $left : $right;
    }

    public function SetAutoPageBreak(bool $auto, float $margin = 0): void {
        $this->autoPageBreak    = $auto;
        $this->bMargin          = $margin;
        $this->pageBreakTrigger = $this->pageHeight - $margin;
    }

    public function AddPage(): void {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        // Zustand auf neuer Seite wiederherstellen
        $this->out(sprintf('%.3F w', $this->lineWidth * $this->k));
        $this->out($this->drawColor);
        $this->out($this->fillColor);
    }

    /* ── Position ───────────────────────────────────── */
    public function GetX(): float { return $this->x; }
    public function GetY(): float { return $this->y; }

    public function SetX(float $x): void {
        $this->x = ($x >= 0) ? $x : $this->pageWidth + $x;
    }

    public function SetY(float $y, bool $resetX = true): void {
        $this->y = ($y >= 0) ? $y : $this->pageHeight + $y;
        if ($resetX) $this->x = $this->lMargin;
    }

    public function SetXY(float $x, float $y): void {
        $this->SetY($y, false);
        $this->SetX($x);
    }

    public function Ln(?float $h = null): void {
        $this->x = $this->lMargin;
        $this->y += ($h === null) ? $this->lasth : $h;
    }

    /* ── Farben / Linien ────────────────────────────── */
    public function SetFillColor(int $r, ?int $g = null, ?int $b = null): void {
        $this->fillColor = $this->colorString($r, $g, $b, 'rg', 'g');
        $this->colorFlag = ($this->fillColor !== $this->textColorAsFill());
        if ($this->page > 0) $this->out($this->fillColor);
    }

    public function SetTextColor(int $r, ?int $g = null, ?int $b = null): void {
        $this->textColor = $this->colorString($r, $g, $b, 'rg', 'g');
        $this->colorFlag = ($this->fillColor !== $this->textColorAsFill());
    }

    public function SetDrawColor(int $r, ?int $g = null, ?int $b = null): void {
        $this->drawColor = $this->colorString($r, $g, $b, 'RG', 'G');
        if ($this->page > 0) $this->out($this->drawColor);
    }

    public function SetLineWidth(float $w): void {
        $this->lineWidth = $w;
        if ($this->page > 0) $this->out(sprintf('%.3F w', $w * $this->k));
    }

    public function Line(float $x1, float $y1, float $x2, float $y2): void {
        $this->out(sprintf(
            '%.2F %.2F m %.2F %.2F l S',
            $x1 * $this->k, ($this->pageHeight - $y1) * $this->k,
            $x2 * $this->k, ($this->pageHeight - $y2) * $this->k
        ));
    }

    /**
     * Bettet eine Rasterbilddatei (PNG/JPEG/GIF/WEBP) proportional in das
     * PDF ein. $x/$y = linke obere Ecke in mm; $w/$h = Zielgröße in mm
     * (wird bei 0 aus dem jeweils anderen Wert UND dem Seitenverhältnis
     * des Bildes automatisch proportional berechnet – NIEMALS verzerrt).
     * Gibt true bei Erfolg zurück, false wenn die Datei nicht lesbar ist
     * oder die GD-Erweiterung fehlt (Aufrufer sollten in diesem Fall auf
     * einen Fallback ausweichen, siehe pdf_common.php).
     */
    public function Image(string $file, float $x, float $y, float $w = 0, float $h = 0): bool {
        $key = $this->registerImage($file);
        if ($key === null) return false;
        $img = $this->images[$key];

        if ($w <= 0 && $h <= 0) {
            // Ohne Zielgröße: 96 dpi als plausible Standardannahme.
            $w = $img['width_px']  / 96 * 25.4;
            $h = $img['height_px'] / 96 * 25.4;
        } elseif ($w <= 0) {
            $w = $h * $img['width_px'] / $img['height_px'];
        } elseif ($h <= 0) {
            $h = $w * $img['height_px'] / $img['width_px'];
        }

        $k = $this->k;
        $this->out(sprintf(
            'q %.3F 0 0 %.3F %.2F %.2F cm /%s Do Q',
            $w * $k, $h * $k,
            $x * $k, ($this->pageHeight - $y - $h) * $k,
            $key
        ));
        return true;
    }

    /** Liefert [width_px, height_px] eines bereits eingebetteten/einbettbaren Bildes, oder null. */
    public function GetImageSize(string $file): ?array {
        $key = $this->registerImage($file);
        if ($key === null) return null;
        return [$this->images[$key]['width_px'], $this->images[$key]['height_px']];
    }

    /**
     * Liest eine Bilddatei einmalig über GD ein und normalisiert sie zu
     * rohen RGB-Pixeldaten plus optionalem Alphakanal (Graustufen-SMask).
     * Die Normalisierung über GD (statt Passthrough der Originalbytes)
     * stellt sicher, dass transparente PNGs IMMER korrekt (mit echtem
     * Alphakanal, ohne grauen/weißen Rand) dargestellt werden, unabhängig
     * vom Quellformat.
     */
    protected function registerImage(string $file): ?string {
        if ($file === '' || !is_file($file) || !is_readable($file)) return null;
        $realPath = realpath($file) ?: $file;

        foreach ($this->images as $key => $img) {
            if ($img['path'] === $realPath) return $key;
        }

        if (!function_exists('imagecreatefromstring')) return null; // GD fehlt

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;

        $im = @imagecreatefromstring($raw);
        if ($im === false) return null;

        $widthPx  = imagesx($im);
        $heightPx = imagesy($im);
        if ($widthPx <= 0 || $heightPx <= 0) { imagedestroy($im); return null; }

        imagepalettetotruecolor($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $rgb = '';
        $alpha = '';
        $hasAlpha = false;

        for ($yy = 0; $yy < $heightPx; $yy++) {
            for ($xx = 0; $xx < $widthPx; $xx++) {
                $rgba = imagecolorat($im, $xx, $yy);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $a = ($rgba >> 24) & 0x7F; // GD: 0 = deckend, 127 = vollständig transparent
                $alphaByte = 255 - (int)round($a * 255 / 127);
                if ($alphaByte < 255) $hasAlpha = true;
                $rgb   .= chr($r) . chr($g) . chr($b);
                $alpha .= chr($alphaByte);
            }
        }
        imagedestroy($im);

        $key = 'Im' . (count($this->images) + 1);
        $this->images[$key] = [
            'path'       => $realPath,
            'width_px'   => $widthPx,
            'height_px'  => $heightPx,
            'rgb'        => $rgb,
            'alpha'      => $hasAlpha ? $alpha : null,
        ];
        return $key;
    }

    public function Rect(float $x, float $y, float $w, float $h, string $style = ''): void {
        $op = match (strtoupper($style)) {
            'F'        => 'f',
            'FD', 'DF' => 'B',
            default    => 'S',
        };
        $this->out(sprintf(
            '%.2F %.2F %.2F %.2F re %s',
            $x * $this->k, ($this->pageHeight - $y) * $this->k,
            $w * $this->k, -$h * $this->k, $op
        ));
    }

    /* ── Schrift ────────────────────────────────────── */
    public function SetFont(string $family, string $style = '', ?float $size = null): void {
        $this->selectFont($family, $style, $size);
        if ($this->page > 0) {
            $this->out(sprintf('BT /%s %.2F Tf ET', $this->fontKey, $this->fontSize));
        }
    }

    protected function selectFont(string $family, string $style, ?float $size = null): void {
        $family = strtolower($family) ?: 'helvetica';
        $style  = strtoupper($style);
        $this->fontFamily = $family;
        $this->fontStyle  = $style;
        if ($size !== null) $this->fontSize = $size;

        $bold   = str_contains($style, 'B');
        $italic = str_contains($style, 'I');

        $psName = 'Helvetica';
        if ($bold && $italic)      $psName = 'Helvetica-BoldOblique';
        elseif ($bold)             $psName = 'Helvetica-Bold';
        elseif ($italic)           $psName = 'Helvetica-Oblique';

        if (!isset($this->usedFonts[$psName])) {
            $this->usedFonts[$psName] = ['name' => $psName, 'index' => count($this->usedFonts) + 1];
        }
        $this->fontKey = 'F' . $this->usedFonts[$psName]['index'];
    }

    /* ── Textbreite ─────────────────────────────────── */
    public function GetStringWidth(string $txt): float {
        $s = $this->toCp1252($txt);
        $widths = str_contains($this->fontStyle, 'B') ? self::$wBold : self::$wRegular;
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $w += $widths[ord($s[$i])] ?? 556;
        }
        return $w * $this->fontSize / 1000 / $this->k;
    }

    /* ── Zellen ─────────────────────────────────────── */
    public function Cell(
        float $w,
        float $h = 0,
        string $txt = '',
        mixed $border = 0,
        int $ln = 0,
        string $align = '',
        bool $fill = false,
        string $link = ''
    ): void {
        // Automatischer Seitenumbruch
        if ($this->autoPageBreak && $this->y + $h > $this->pageBreakTrigger && $this->page > 0) {
            $x = $this->x;
            $this->AddPage();
            $this->x = $x;
        }

        if ($w == 0) $w = $this->pageWidth - $this->rMargin - $this->x;

        $k = $this->k;
        $s = '';

        // Füllung / Rahmen (Rechteck)
        if ($fill || $border === 1 || $border === true) {
            $op = $fill ? (($border === 1 || $border === true) ? 'B' : 'f') : 'S';
            $s .= sprintf(
                '%.2F %.2F %.2F %.2F re %s ',
                $this->x * $k, ($this->pageHeight - $this->y) * $k,
                $w * $k, -$h * $k, $op
            );
        }

        // Rahmen als Buchstabenkombination (L,T,R,B)
        if (is_string($border) && $border !== '' && $border !== '0') {
            $bx = $this->x; $by = $this->y;
            $ph = $this->pageHeight;
            if (str_contains($border, 'L'))
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $bx*$k, ($ph-$by)*$k, $bx*$k, ($ph-($by+$h))*$k);
            if (str_contains($border, 'T'))
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $bx*$k, ($ph-$by)*$k, ($bx+$w)*$k, ($ph-$by)*$k);
            if (str_contains($border, 'R'))
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($bx+$w)*$k, ($ph-$by)*$k, ($bx+$w)*$k, ($ph-($by+$h))*$k);
            if (str_contains($border, 'B'))
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $bx*$k, ($ph-($by+$h))*$k, ($bx+$w)*$k, ($ph-($by+$h))*$k);
        }

        // Text
        if ($txt !== '') {
            $enc  = $this->toCp1252($txt);
            $strW = $this->GetStringWidth($txt);

            $dx = match (strtoupper($align)) {
                'R'      => $w - 1.0 - $strW,
                'C'      => ($w - $strW) / 2,
                default  => 1.0, // L / J
            };

            $tx = ($this->x + $dx) * $k;
            $ty = ($this->pageHeight - ($this->y + 0.5 * $h + 0.35 * $this->fontSize / $k)) * $k;

            $s .= 'q ' . $this->textColor . ' BT ';
            $s .= sprintf('/%s %.2F Tf ', $this->fontKey, $this->fontSize);
            $s .= sprintf('%.2F %.2F Td (%s) Tj ', $tx, $ty, $this->escape($enc));
            $s .= 'ET Q';

            // Unterstreichen
            if (str_contains($this->fontStyle, 'U')) {
                $uy = $this->y + 0.5 * $h + 0.35 * $this->fontSize / $k + 0.6;
                $s .= sprintf(
                    ' %.2F %.2F m %.2F %.2F l S',
                    ($this->x + $dx) * $k, ($this->pageHeight - $uy) * $k,
                    ($this->x + $dx + $strW) * $k, ($this->pageHeight - $uy) * $k
                );
            }
        }

        if ($s !== '') $this->out(trim($s));

        $this->lasth = $h;

        if ($ln > 0) {
            $this->y += $h;
            if ($ln === 1) $this->x = $this->lMargin;
        } else {
            $this->x += $w;
        }
    }

    public function MultiCell(
        float $w,
        float $h,
        string $txt,
        mixed $border = 0,
        string $align = 'J',
        bool $fill = false
    ): int {
        if ($w == 0) $w = $this->pageWidth - $this->rMargin - $this->x;

        $maxWidth = $w - 2.0; // 1 mm Innenabstand je Seite
        $lines    = $this->wrapText($txt, $maxWidth);
        $xStart   = $this->x;

        $borderStr = '';
        if ($border === 1 || $border === true) $borderStr = 'LTRB';
        elseif (is_string($border)) $borderStr = strtoupper($border);

        $n = count($lines);
        foreach ($lines as $i => $line) {
            $cellBorder = '';
            if ($borderStr !== '') {
                if (str_contains($borderStr, 'L')) $cellBorder .= 'L';
                if (str_contains($borderStr, 'R')) $cellBorder .= 'R';
                if ($i === 0 && str_contains($borderStr, 'T'))       $cellBorder .= 'T';
                if ($i === $n - 1 && str_contains($borderStr, 'B'))  $cellBorder .= 'B';
            }
            $cellAlign = (strtoupper($align) === 'J') ? 'L' : $align;
            $this->x = $xStart;
            $this->Cell($w, $h, $line, $cellBorder === '' ? 0 : $cellBorder, 2, $cellAlign, $fill);
        }
        $this->x = $this->lMargin;
        return $n;
    }

    /** Text an Wortgrenzen umbrechen */
    protected function wrapText(string $txt, float $maxWidth): array {
        $txt = str_replace(["\r\n", "\r"], "\n", $txt);
        $paragraphs = explode("\n", $txt);
        $lines = [];

        foreach ($paragraphs as $para) {
            if ($para === '') { $lines[] = ''; continue; }
            $words = explode(' ', $para);
            $line  = '';
            foreach ($words as $word) {
                $try = ($line === '') ? $word : $line . ' ' . $word;
                if ($this->GetStringWidth($try) <= $maxWidth) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') { $lines[] = $line; $line = ''; }
                // Einzelnes überlanges Wort hart umbrechen
                while ($this->GetStringWidth($word) > $maxWidth && mb_strlen($word, 'UTF-8') > 1) {
                    $cut = mb_strlen($word, 'UTF-8');
                    while ($cut > 1 && $this->GetStringWidth(mb_substr($word, 0, $cut, 'UTF-8')) > $maxWidth) {
                        $cut--;
                    }
                    $lines[] = mb_substr($word, 0, $cut, 'UTF-8');
                    $word    = mb_substr($word, $cut, null, 'UTF-8');
                }
                $line = $word;
            }
            $lines[] = $line;
        }
        return $lines ?: [''];
    }

    /* ── Ausgabe ────────────────────────────────────── */
    public function Output(string $name = 'doc.pdf', string $dest = 'I'): string {
        $pdf = $this->buildPdf();
        $dest = strtoupper($dest);

        switch ($dest) {
            case 'S':
                return $pdf;
            case 'D':
                if (!headers_sent()) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
                    header('Content-Length: ' . strlen($pdf));
                    header('Cache-Control: private, max-age=0, must-revalidate');
                }
                echo $pdf;
                return '';
            case 'F':
                file_put_contents($name, $pdf);
                return '';
            case 'I':
            default:
                if (!headers_sent()) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
                    header('Content-Length: ' . strlen($pdf));
                    header('Cache-Control: private, max-age=0, must-revalidate');
                }
                echo $pdf;
                return '';
        }
    }

    protected function buildPdf(): string {
        $nPages = max(1, $this->page);
        if ($this->page === 0) $this->AddPage();

        $objects = [];   // objNum => Inhalt
        $nFonts  = count($this->usedFonts);

        // Objekt-Nummern:
        // 1 = Catalog, 2 = Pages
        // 3 .. 2+2*nPages           = Seiten + Content-Streams
        // danach Fonts
        // danach Bilder (je transparentem PNG/GIF/WEBP zusätzlich ein
        // eigenes SMask-Objekt für den Alphakanal)
        // danach Info
        $firstFontObj = 3 + 2 * $nPages;

        // Bild-Objektnummern vorab zuteilen (Bilder VOR SMasks je Bild,
        // damit das Bild-Objekt per /SMask N 0 R direkt darauf verweisen kann).
        $firstImageObj = $firstFontObj + $nFonts;
        $imageObjNum = [];   // Im-Key => Objektnummer des Bildes
        $smaskObjNum = [];   // Im-Key => Objektnummer des SMask (falls vorhanden)
        $cursor = $firstImageObj;
        foreach ($this->images as $key => $img) {
            $imageObjNum[$key] = $cursor++;
            if ($img['alpha'] !== null) {
                $smaskObjNum[$key] = $cursor++;
            }
        }
        $infoObj = $cursor;

        // Font-Ressourcen-Dictionary
        $fontRes = '';
        foreach ($this->usedFonts as $f) {
            $fontRes .= '/F' . $f['index'] . ' ' . ($firstFontObj + $f['index'] - 1) . ' 0 R ';
        }

        // Bild-Ressourcen-Dictionary (allen Seiten gemeinsam zur Verfügung
        // gestellt, analog zu den Fonts oben — ungenutzte Verweise sind in
        // einem PDF-Resources-Dictionary unschädlich).
        $xobjRes = '';
        foreach ($imageObjNum as $key => $num) {
            $xobjRes .= '/' . $key . ' ' . $num . ' 0 R ';
        }
        $procSet = $this->images ? '[/PDF /Text /ImageB /ImageC /ImageI]' : '[/PDF /Text]';

        // Seiten
        $kids = [];
        for ($i = 1; $i <= $nPages; $i++) {
            $pageObj    = 3 + 2 * ($i - 1);
            $contentObj = $pageObj + 1;
            $kids[]     = $pageObj . ' 0 R';

            $resources = '<< /ProcSet ' . $procSet . ' /Font << ' . trim($fontRes) . ' >>';
            if ($xobjRes !== '') {
                $resources .= ' /XObject << ' . trim($xobjRes) . ' >>';
            }
            $resources .= ' >>';

            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R'
                . sprintf(' /MediaBox [0 0 %.2F %.2F]', $this->pageWidth * $this->k, $this->pageHeight * $this->k)
                . ' /Resources ' . $resources
                . ' /Contents ' . $contentObj . ' 0 R >>';

            $content = $this->pages[$i] ?? '';
            $compressed = gzcompress($content);
            $objects[$contentObj] = '<< /Filter /FlateDecode /Length ' . strlen($compressed) . " >>\nstream\n"
                . $compressed . "\nendstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $nPages . ' >>';

        // Fonts
        foreach ($this->usedFonts as $f) {
            $objects[$firstFontObj + $f['index'] - 1] =
                '<< /Type /Font /Subtype /Type1 /BaseFont /' . $f['name'] . ' /Encoding /WinAnsiEncoding >>';
        }

        // Bilder + ggf. SMask (Alphakanal, DeviceGray). Beide Streams werden
        // als rohe Pixeldaten mit FlateDecode komprimiert eingebettet – das
        // ist unabhängig vom Ausgangsformat (JPEG/PNG/GIF/WEBP) immer
        // korrekt und behandelt Transparenz einheitlich über den SMask.
        foreach ($this->images as $key => $img) {
            $num = $imageObjNum[$key];
            $rgbCompressed = gzcompress($img['rgb']);
            $smaskRef = '';
            if ($img['alpha'] !== null && isset($smaskObjNum[$key])) {
                $smaskRef = ' /SMask ' . $smaskObjNum[$key] . ' 0 R';
                $alphaCompressed = gzcompress($img['alpha']);
                $objects[$smaskObjNum[$key]] =
                    '<< /Type /XObject /Subtype /Image'
                    . ' /Width ' . $img['width_px'] . ' /Height ' . $img['height_px']
                    . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode'
                    . ' /Length ' . strlen($alphaCompressed) . " >>\nstream\n"
                    . $alphaCompressed . "\nendstream";
            }
            $objects[$num] =
                '<< /Type /XObject /Subtype /Image'
                . ' /Width ' . $img['width_px'] . ' /Height ' . $img['height_px']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode'
                . $smaskRef
                . ' /Length ' . strlen($rgbCompressed) . " >>\nstream\n"
                . $rgbCompressed . "\nendstream";
        }

        // Info
        $info = '<<';
        foreach ($this->meta as $key => $val) {
            if ($val !== '') $info .= ' /' . $key . ' (' . $this->escape($this->toCp1252($val)) . ')';
        }
        $info .= ' /Producer (MZ Tech PDF Engine) >>';
        $objects[$infoObj] = $info;

        // Zusammensetzen
        $out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $maxObj  = $infoObj;
        for ($n = 1; $n <= $maxObj; $n++) {
            $offsets[$n] = strlen($out);
            $out .= $n . " 0 obj\n" . $objects[$n] . "\nendobj\n";
        }

        $xrefPos = strlen($out);
        $out .= "xref\n0 " . ($maxObj + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObj; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $out .= "trailer\n<< /Size " . ($maxObj + 1) . ' /Root 1 0 R /Info ' . $infoObj . " 0 R >>\n";
        $out .= "startxref\n" . $xrefPos . "\n%%EOF";

        return $out;
    }

    /* ── Intern ─────────────────────────────────────── */
    protected function out(string $s): void {
        if ($this->page > 0) {
            $this->pages[$this->page] .= $s . "\n";
        }
    }

    protected function toCp1252(string $utf8): string {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $utf8);
        return ($s === false) ? preg_replace('/[^\x20-\x7E]/', '?', $utf8) : $s;
    }

    protected function escape(string $s): string {
        return str_replace(
            ['\\', '(', ')', "\r"],
            ['\\\\', '\\(', '\\)', '\\r'],
            $s
        );
    }

    protected function colorString(int $r, ?int $g, ?int $b, string $opRgb, string $opGray): string {
        if ($g === null || $b === null) {
            return sprintf('%.3F %s', $r / 255, $opGray);
        }
        if ($r === $g && $g === $b) {
            return sprintf('%.3F %s', $r / 255, $opGray);
        }
        return sprintf('%.3F %.3F %.3F %s', $r / 255, $g / 255, $b / 255, $opRgb);
    }

    protected function textColorAsFill(): string {
        return str_replace(['RG', ' G'], ['rg', ' g'], $this->textColor);
    }

    /* ── Helvetica-Breitentabellen (Adobe AFM, 1/1000 em) ── */
    protected static function initWidths(): void {
        if (self::$wRegular) return;

        $reg = [
            ' '=>278, '!'=>278, '"'=>355, '#'=>556, '$'=>556, '%'=>889, '&'=>667, "'"=>191,
            '('=>333, ')'=>333, '*'=>389, '+'=>584, ','=>278, '-'=>333, '.'=>278, '/'=>278,
            '0'=>556, '1'=>556, '2'=>556, '3'=>556, '4'=>556, '5'=>556, '6'=>556, '7'=>556,
            '8'=>556, '9'=>556, ':'=>278, ';'=>278, '<'=>584, '='=>584, '>'=>584, '?'=>556,
            '@'=>1015, 'A'=>667, 'B'=>667, 'C'=>722, 'D'=>722, 'E'=>667, 'F'=>611, 'G'=>778,
            'H'=>722, 'I'=>278, 'J'=>500, 'K'=>667, 'L'=>556, 'M'=>833, 'N'=>722, 'O'=>778,
            'P'=>667, 'Q'=>778, 'R'=>722, 'S'=>667, 'T'=>611, 'U'=>722, 'V'=>667, 'W'=>944,
            'X'=>667, 'Y'=>667, 'Z'=>611, '['=>278, '\\'=>278, ']'=>278, '^'=>469, '_'=>556,
            '`'=>333, 'a'=>556, 'b'=>556, 'c'=>500, 'd'=>556, 'e'=>556, 'f'=>278, 'g'=>556,
            'h'=>556, 'i'=>222, 'j'=>222, 'k'=>500, 'l'=>222, 'm'=>833, 'n'=>556, 'o'=>556,
            'p'=>556, 'q'=>556, 'r'=>333, 's'=>500, 't'=>278, 'u'=>556, 'v'=>500, 'w'=>722,
            'x'=>500, 'y'=>500, 'z'=>500, '{'=>334, '|'=>260, '}'=>334, '~'=>584,
        ];
        $bold = [
            ' '=>278, '!'=>333, '"'=>474, '#'=>556, '$'=>556, '%'=>889, '&'=>722, "'"=>238,
            '('=>333, ')'=>333, '*'=>389, '+'=>584, ','=>278, '-'=>333, '.'=>278, '/'=>278,
            '0'=>556, '1'=>556, '2'=>556, '3'=>556, '4'=>556, '5'=>556, '6'=>556, '7'=>556,
            '8'=>556, '9'=>556, ':'=>333, ';'=>333, '<'=>584, '='=>584, '>'=>584, '?'=>611,
            '@'=>975, 'A'=>722, 'B'=>722, 'C'=>722, 'D'=>722, 'E'=>667, 'F'=>611, 'G'=>778,
            'H'=>722, 'I'=>278, 'J'=>556, 'K'=>722, 'L'=>611, 'M'=>833, 'N'=>722, 'O'=>778,
            'P'=>667, 'Q'=>778, 'R'=>722, 'S'=>667, 'T'=>611, 'U'=>722, 'V'=>667, 'W'=>944,
            'X'=>667, 'Y'=>667, 'Z'=>611, '['=>333, '\\'=>278, ']'=>333, '^'=>584, '_'=>556,
            '`'=>333, 'a'=>556, 'b'=>611, 'c'=>556, 'd'=>611, 'e'=>556, 'f'=>333, 'g'=>611,
            'h'=>611, 'i'=>278, 'j'=>278, 'k'=>556, 'l'=>278, 'm'=>889, 'n'=>611, 'o'=>611,
            'p'=>611, 'q'=>611, 'r'=>389, 's'=>556, 't'=>333, 'u'=>611, 'v'=>556, 'w'=>778,
            'x'=>556, 'y'=>556, 'z'=>500, '{'=>389, '|'=>280, '}'=>389, '~'=>584,
        ];

        // Nach Byte-Index konvertieren
        foreach ($reg as $ch => $w)  self::$wRegular[ord($ch)] = $w;
        foreach ($bold as $ch => $w) self::$wBold[ord($ch)]    = $w;

        // Wichtige Windows-1252-Zeichen (Umlaute, ß, €, Sonderzeichen)
        $high = [
            0x80 => [556, 556],   // €
            0x96 => [556, 556],   // –
            0x97 => [1000, 1000], // —
            0xA7 => [556, 556],   // §
            0xB0 => [400, 400],   // °
            0xB7 => [278, 278],   // ·
            0xC4 => [667, 722],   // Ä
            0xD6 => [778, 778],   // Ö
            0xDC => [722, 722],   // Ü
            0xDF => [611, 611],   // ß
            0xE4 => [556, 556],   // ä
            0xF6 => [556, 611],   // ö
            0xFC => [556, 611],   // ü
            0xE9 => [556, 556],   // é
            0xE8 => [556, 556],   // è
        ];
        foreach ($high as $byte => [$w1, $w2]) {
            self::$wRegular[$byte] = $w1;
            self::$wBold[$byte]    = $w2;
        }
        // Default für alle übrigen High-Bytes
        for ($b = 0x80; $b <= 0xFF; $b++) {
            self::$wRegular[$b] ??= 556;
            self::$wBold[$b]    ??= 556;
        }
    }
}
