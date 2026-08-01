<?php
require_once __DIR__ . '/billing.php';
/**
 * MZ Tech – Gemeinsame Hilfsfunktionen für die PDF-Erzeugung
 * ----------------------------------------------------------------------
 * Wird von allen Dateien unter public/pdf/*.php eingebunden. Bündelt
 * Logik, die auf mehreren Dokumenttypen gleich sein soll, damit sie
 * nicht mehrfach (und ggf. inkonsistent) implementiert wird:
 *
 *   - pdf_draw_qr()            Zeichnet einen QR-Code als Vektor-
 *                               Rechtecke direkt ins PDF (die im System
 *                               genutzte eigenständige TCPDF-Engine kann
 *                               keine Bilder einbetten).
 *   - pdf_ustg_active()         Prüft anhand der Einstellung "tax_rate",
 *                               ob die Kleinunternehmerregelung nach
 *                               §19 UStG greift (kein MwSt-Ausweis).
 *   - pdf_ustg_notice_text()    Liefert den anzuzeigenden Hinweistext
 *                               (admin-konfigurierbar über die
 *                               Einstellung "ustg_notice_text").
 *   - pdf_draw_portal_footer()  Zeichnet einen Fußbereich mit QR-Code
 *                               und Link zum Kundenportal.
 *
 * Alle Funktionen sind bewusst defensiv (try/catch, Null-Prüfungen),
 * damit ein Problem bei einem einzelnen Kunden (z. B. Portal-Zugang
 * konnte nicht angelegt werden) niemals die PDF-Erzeugung insgesamt
 * zum Absturz bringt – im Zweifel wird der jeweilige Block einfach
 * ausgelassen.
 */

/**
 * Zeichnet einen QR-Code für $data als weiße Kachel mit schwarzen
 * Vektor-Modulen bei ($x, $y) mit Kantenlänge $sizeMm (mm, quadratisch).
 * Bricht bei fehlender QR-Klasse oder zu langem Text (siehe
 * \MZTech\QRCode::getMatrix()) still ab – Aufrufer müssen kein Ergebnis
 * prüfen, es wird im Fehlerfall einfach nichts gezeichnet.
 */
function pdf_draw_qr($pdf, string $data, float $x, float $y, float $sizeMm): void {
    if ($data === '' || !class_exists('\MZTech\QRCode')) return;

    try {
        $result = \MZTech\QRCode::getMatrix($data, 4);
    } catch (Throwable $e) {
        return;
    }
    if ($result === null) return;

    $size   = $result['size'];
    $margin = $result['margin'];
    $total  = $size + 2 * $margin;
    if ($total <= 0) return;
    $module = $sizeMm / $total;

    // Weißer Hintergrund (Ruhezone), damit der Code auch auf farbigem
    // Untergrund zuverlässig scanbar bleibt.
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x, $y, $sizeMm, $sizeMm, 'F');

    $pdf->SetFillColor(0, 0, 0);
    foreach ($result['matrix'] as $r => $row) {
        foreach ($row as $c => $dark) {
            if (!$dark) continue;
            $mx = $x + ($c + $margin) * $module;
            $my = $y + ($r + $margin) * $module;
            $pdf->Rect($mx, $my, $module, $module, 'F');
        }
    }
}

/**
 * Autorisiert den Zugriff auf ein PDF-Dokument (Rechnung/Kostenvoranschlag)
 * zu einer bestimmten Reparatur: entweder als angemeldeter Mitarbeiter
 * (Admin-Session), als angemeldeter Kundenportal-Nutzer oder als aktiver
 * Firmenportal-Nutzer derselben Firma.
 *
 * Bricht bei fehlender Berechtigung mit HTTP 403 ab. Prüft bewusst zuerst
 * anhand des jeweiligen Session-Cookie-Namens, welche Session gestartet
 * werden muss, damit für einen anonymen bzw. nur im Portal angemeldeten
 * Besucher niemals unnötig eine Admin-Session (mit eigenem Cookie)
 * angelegt wird – und umgekehrt.
 */
function pdf_authorize_repair_access(int $repairId): void {
    // 1) Mitarbeiter (Admin/Techniker) – bestehende Admin-Session?
    if (!empty($_COOKIE[SESSION_NAME] ?? null)) {
        require_once PRIVATE_PATH . '/auth.php';
        start_secure_session();
        if (!empty($_SESSION['user_id'])) {
            return;
        }
    }

    // 2) Firmenportal: aktiver Kontakt und Reparatur der eigenen Firma.
    if (!empty($_COOKIE[BUSINESS_SESSION_NAME] ?? null)) {
        require_once PRIVATE_PATH . '/business_auth.php';
        start_business_session();
        $contactId = business_current_contact_id();
        $companyId = business_current_company_id();
        if ($contactId && $companyId) {
            $contactStmt = get_db()->prepare(
                'SELECT 1 FROM company_contacts
                 WHERE id = ? AND company_id = ? AND is_active = 1 AND is_verified = 1
                 LIMIT 1'
            );
            $contactStmt->execute([$contactId, $companyId]);
            if ($contactStmt->fetchColumn()) {
                $repairStmt = get_db()->prepare(
                    'SELECT 1 FROM repairs r
                     JOIN customers c ON c.id = r.customer_id
                     LEFT JOIN projects p ON p.id = r.project_id
                     WHERE r.id = ? AND (c.company_id = ? OR p.company_id = ?)
                     LIMIT 1'
                );
                $repairStmt->execute([$repairId, $companyId, $companyId]);
                if ($repairStmt->fetchColumn()) return;
            }
        }
    }

    // 3) Kundenportal – Gast-Zugang ODER registriertes Konto, aber nur
    //    für die EIGENE Reparatur (IDOR-Schutz: niemals ungeprüft nach
    //    einer client-seitig übergebenen ID filtern).
    require_once PRIVATE_PATH . '/portal_auth.php';
    start_portal_session();
    if (portal_is_logged_in()) {
        $stmt = get_db()->prepare('SELECT customer_id FROM repairs WHERE id = ? LIMIT 1');
        $stmt->execute([$repairId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId !== false && (int)$ownerId === portal_current_customer_id()) {
            return;
        }
    }

    http_response_code(403);
    die('Kein Zugriff auf dieses Dokument.');
}

/**
 * true, wenn Rechnungsdokumente ohne Umsatzsteuerausweis erstellt werden
 * sollen (Kleinunternehmerregelung §19 UStG). Steuerung ausschließlich
 * über die Einstellung "tax_rate" (Verwaltung > Einstellungen): ein
 * Steuersatz von 0 (bzw. leer) bedeutet Kleinunternehmer.
 */
function pdf_ustg_active(): bool {
    return billing_is_small_business();
}

/**
 * Hinweistext für Dokumente ohne MwSt-Ausweis. Über die Einstellung
 * "ustg_notice_text" admin-konfigurierbar, damit der genaue Wortlaut
 * bei Bedarf ohne Code-Änderung angepasst werden kann.
 */
function pdf_ustg_notice_text(): string {
    return billing_legal_notice();
}

/** Autorisiert ein eigenständiges Angebot ohne eine vom Client ableitbare Freigabe. */
function pdf_authorize_quote_access(array $quote): void {
    if (!empty($_COOKIE[SESSION_NAME] ?? null)) {
        require_once PRIVATE_PATH . '/auth.php';
        start_secure_session();
        if (!empty($_SESSION['user_id'])) return;
    }
    if (in_array((string)($quote['status'] ?? 'entwurf'), ['entwurf','zur_pruefung'], true)) {
        http_response_code(403);
        die('Dieses Angebot ist noch nicht für Kunden freigegeben.');
    }
    if (!empty($_COOKIE[BUSINESS_SESSION_NAME] ?? null) && !empty($quote['company_id'])) {
        require_once PRIVATE_PATH . '/business_auth.php';
        start_business_session();
        if (business_current_contact_id() && business_current_company_id() === (int)$quote['company_id']) return;
    }
    if (!empty($quote['customer_id'])) {
        require_once PRIVATE_PATH . '/portal_auth.php';
        start_portal_session();
        if (portal_is_logged_in() && portal_current_customer_id() === (int)$quote['customer_id']) return;
    }
    http_response_code(403);
    die('Kein Zugriff auf dieses Angebot.');
}

/**
 * Zeichnet einen Trennstrich gefolgt von einem Kundenportal-Hinweis mit
 * QR-Code (falls für $customerId ein Portal-Zugang ermittelt werden
 * kann) und aktualisiert die Y-Position des PDF entsprechend. Bei
 * fehlender Kunden-ID oder deaktiviertem Portal wird nur der Hinweis
 * ohne QR-Code (bzw. gar nichts) gezeichnet.
 */
function pdf_draw_portal_footer($pdf, ?int $customerId, float $pageWidth = 210, float $margin = 15): void {
    if (!function_exists('portal_enabled') || !portal_enabled()) return;

    $link = '';
    if ($customerId) {
        try {
            $access = ensure_customer_portal_access($customerId);
            $link   = $access['link'] ?? '';
        } catch (Throwable $e) {
            $link = '';
        }
    }
    if ($link === '') return;

    $y = $pdf->GetY() + 6;
    // Ggf. Seitenumbruch, damit der Portal-Block nicht abgeschnitten wird.
    if ($y > 260) {
        $pdf->AddPage();
        $y = 20;
    }

    $pdf->SetDrawColor(200, 200, 200);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($margin, $y, $pageWidth - $margin, $y);
    $y += 4;

    $qrSize = 22;
    $textX  = $margin;
    $textW  = $pageWidth - 2 * $margin;

    pdf_draw_qr($pdf, $link, $margin, $y, $qrSize);
    $textX = $margin + $qrSize + 5;
    $textW = $pageWidth - $margin - $textX;

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(0, 87, 184);
    $pdf->SetXY($textX, $y);
    $pdf->Cell($textW, 5, 'Ihr Kundenportal', 0, 1, 'L');

    $pdf->SetX($textX);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell(
        $textW, 4,
        'Scannen Sie den QR-Code oder besuchen Sie Ihr persönliches Kundenportal, ' .
        'um jederzeit den aktuellen Status Ihres Auftrags einzusehen.',
        0, 'L'
    );

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetY(max($pdf->GetY(), $y + $qrSize) + 4);
}

/**
 * Liefert die zentralen Firmenstammdaten einheitlich für alle Dokument-
 * PDFs (Rechnung, Kostenvoranschlag, Angebot, Lieferschein, Auftrag,
 * Abholschein, Reparaturbericht, Terminbestätigung, Gutschrift/Storno) –
 * damit jede PDF-Vorlage exakt dieselben Werte aus denselben Einstellungen
 * liest (keine Doppelpflege, keine abweichenden Feldnamen je Vorlage).
 */
function pdf_company_info(): array {
    return [
        'name'    => get_setting('company_name', 'MZ Tech'),
        'address' => get_setting('company_address', ''),
        'phone'   => get_setting('company_phone', ''),
        'email'   => get_setting('company_email', ''),
        'website' => get_setting('company_website', ''),
        'iban'    => get_setting('company_iban', ''),
        'bic'     => get_setting('company_bic', ''),
        'tax_id'  => get_setting('company_tax_id', ''),
        'steuernummer' => get_setting('company_steuernummer', ''),
    ];
}

/**
 * Ermittelt den Dateipfad des Firmenlogos, falls eines hinterlegt ist.
 * Bevorzugt den in den Einstellungen konfigurierten Pfad (Einstellung
 * "company_logo_path", relativ zum Installationsverzeichnis); fällt sonst
 * auf den konventionellen Pfad "uploads/company/logo.png" zurück, falls
 * dort bereits eine Datei liegt (z. B. manuell hochgeladen). Liefert null,
 * wenn keine Logodatei gefunden werden kann – Aufrufer (pdf_draw_header())
 * verwenden dann automatisch die farbige Kachel mit Firmenkürzel als
 * Fallback, die PDF-Erzeugung bricht dadurch niemals ab.
 */
function pdf_company_logo_path(): ?string {
    $candidates = [];
    $configured = trim((string)get_setting('company_logo_path', ''));
    if ($configured !== '') $candidates[] = $configured;
    $candidates[] = 'uploads/company/logo.png';

    foreach ($candidates as $rel) {
        $rel = ltrim($rel, '/');
        $abs = defined('BASE_PATH') ? (BASE_PATH . '/' . $rel) : null;
        if ($abs !== null && is_file($abs) && is_readable($abs)) {
            return $abs;
        }
    }
    return null;
}

/** 1-2 Buchstaben Firmenkürzel als Fallback, falls kein Logo eingebettet werden kann. */
function pdf_company_initials(string $name): string {
    $words = preg_split('/\s+/', trim($name));
    $words = array_filter($words, fn($w) => $w !== '');
    if (!$words) return 'MZ';
    if (count($words) === 1) {
        return mb_strtoupper(mb_substr($words[array_key_first($words)], 0, 2));
    }
    $first = array_shift($words);
    $last  = array_pop($words);
    return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}

/**
 * Einheitlicher Dokumentkopf für ALLE Dokument-PDFs (Auftragsabschnitt 10
 * der Phase "Dokumente, Nummernkreise und Freigaben"): Firmenlogo LINKS,
 * proportional skaliert (nie verzerrt), nutzt die volle verfügbare Breite
 * des linken Kopfbereichs (Standard bis 78 mm breit / 26 mm hoch), mit nur
 * kleinem Abstand zu den Firmendaten rechts daneben. Zeichnet darunter eine
 * klare blaue Trennlinie. Optional wird links neben/unter dem Logo ein gut
 * sichtbares Entwurfs-Band gezeichnet (siehe $draftLabel), wenn ein
 * Dokument noch nicht finalisiert ist.
 *
 * Gibt die Y-Position (mm) zurück, ab der der eigentliche Dokumentinhalt
 * (Titel/Metadaten) beginnen soll.
 */
function pdf_draw_header($pdf, array $company, ?string $draftLabel = null, float $logoMaxW = 78.0, float $logoMaxH = 26.0): float {
    $x0 = 15.0;
    $y0 = 15.0;
    $logoRightEdge = $x0 + 30.0; // Fallback-Breite, falls kein Logo gezeichnet wird
    $logoDrawn = false;

    $logoPath = pdf_company_logo_path();
    if ($logoPath !== null && method_exists($pdf, 'GetImageSize')) {
        $size = $pdf->GetImageSize($logoPath);
        if ($size !== null && $size[0] > 0 && $size[1] > 0) {
            $ratio = $size[0] / $size[1];
            $w = $logoMaxW;
            $h = $w / $ratio;
            if ($h > $logoMaxH) {
                $h = $logoMaxH;
                $w = $h * $ratio;
            }
            if ($pdf->Image($logoPath, $x0, $y0, $w, $h)) {
                $logoDrawn = true;
                $logoRightEdge = $x0 + $w;
            }
        }
    }

    if (!$logoDrawn) {
        // Fallback: farbige Kachel mit Firmenkürzel (kein Logo hinterlegt
        // oder GD auf dem Server nicht verfügbar) – bricht niemals ab.
        $pdf->SetFillColor(0, 87, 184);
        $pdf->Rect($x0, $y0, 30, 26, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY($x0, $y0 + 8);
        $pdf->Cell(30, 10, pdf_company_initials($company['name'] ?? 'MZ'), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $logoRightEdge = $x0 + 30;
    }

    // Firmendaten rechts vom Logo, mit kleinem Abstand.
    $infoX = $logoRightEdge + 6;
    $infoW = (210 - 15) - $infoX;
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY($infoX, $y0);
    $pdf->Cell($infoW, 6.5, $company['name'] ?? '', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 9);
    $lineY = $pdf->GetY();
    $lines = array_filter([
        $company['address'] ?? '',
        !empty($company['phone']) ? 'Tel: ' . $company['phone'] : '',
        $company['email'] ?? '',
        $company['website'] ?? '',
    ]);
    foreach ($lines as $line) {
        $pdf->SetXY($infoX, $lineY);
        $pdf->Cell($infoW, 4.5, $line, 0, 1, 'L');
        $lineY = $pdf->GetY();
    }

    $bottom = max($y0 + 26, $lineY) + 3;

    // Entwurfs-Kennzeichnung: deutlich sichtbares farbiges Band mit
    // Warnhinweis, direkt unter dem Kopfbereich (siehe Abschnitt 1 der
    // Phase "Dokumente, Nummernkreise und Freigaben" – muss klar
    // erkennbar sein, bevor der eigentliche Dokumentinhalt beginnt).
    if ($draftLabel !== null && $draftLabel !== '') {
        $pdf->SetFillColor(255, 244, 214);
        $pdf->Rect(15, $bottom, 180, 10, 'F');
        $pdf->SetDrawColor(224, 168, 0);
        $pdf->SetLineWidth(0.4);
        $pdf->Rect(15, $bottom, 180, 10, 'D');
        $pdf->SetTextColor(146, 90, 0);
        $pdf->SetFont('helvetica', 'B', 9.5);
        $pdf->SetXY(19, $bottom + 2.8);
        $pdf->Cell(172, 5, $draftLabel, 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $bottom += 13;
    }

    $pdf->SetDrawColor(0, 87, 184);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $bottom, 195, $bottom);
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(0, 0, 0);

    return $bottom + 5;
}
