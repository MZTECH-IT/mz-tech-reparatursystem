<?php
/**
 * MZ Tech – QR-Code für den Kundenportal-Zugang eines Kunden (Admin-Bereich)
 *
 * Erzeugt (falls noch nicht vorhanden) den Portal-Zugang des Kunden und
 * liefert einen scannbaren QR-Code auf den persönlichen Zugangslink
 * (portal.php?t=TOKEN) zurück – z. B. zum Ausdrucken/Aushändigen.
 */
$private = dirname(dirname(__DIR__)) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
require_once dirname(__DIR__) . '/includes/icons.php';
start_secure_session();
require_auth();

$customer_id = intval($_GET['customer_id'] ?? 0);
if (!$customer_id) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Ungültige Kunden-ID');
}

// Portal-Zugang sicherstellen (idempotent, legt bei Bedarf Token+PIN an)
$access    = ensure_customer_portal_access($customer_id);
$portalUrl = $access['link'];

// Autoload prüfen für den eigenen, abhängigkeitsfreien QR-Code-Encoder
$autoload = BASE_PATH . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;

    if (class_exists('\MZTech\QRCode')) {
        try {
            $png = \MZTech\QRCode::pngData($portalUrl, 10, 4);
            if ($png !== null) {
                header('Content-Type: image/png');
                header('Cache-Control: private, max-age=60');
                echo $png;
                exit;
            }
            error_log('portal_qrcode: Inhalt zu lang für unterstützte QR-Version (max. Version 10), Fallback zu SVG.');
        } catch (Throwable $e) {
            error_log('portal_qrcode render failed: ' . $e->getMessage());
            // Fallback zu SVG
        }
    }
}

// ── SVG-Fallback ──────────────────────────────────────
header('Content-Type: image/svg+xml');
render_qr_svg_fallback($portalUrl);
