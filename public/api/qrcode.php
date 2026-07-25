<?php
$private = dirname(dirname(__DIR__)) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';
require_once $private . '/auth.php';
require_once dirname(__DIR__) . '/includes/icons.php';
start_secure_session();
require_auth();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Ungültige ID');
}

// Ziel-URL aufbauen (dynamisch, unabhängig vom Installationsordner)
$repairUrl = base_app_url() . '/' . 'repairs_view.php?id=' . $id;

// Autoload prüfen für den eigenen, abhängigkeitsfreien QR-Code-Encoder
$autoload = BASE_PATH . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;

    if (class_exists('\MZTech\QRCode')) {
        try {
            $png = \MZTech\QRCode::pngData($repairUrl, 10, 4);
            if ($png !== null) {
                header('Content-Type: image/png');
                header('Cache-Control: private, max-age=3600');
                echo $png;
                exit;
            }
            error_log('QRCode: Inhalt zu lang für unterstützte QR-Version (max. Version 10), Fallback zu SVG.');
        } catch (Throwable $e) {
            error_log('QRCode render failed: ' . $e->getMessage());
            // Fallback zu SVG
        }
    }
}

// ── SVG-Fallback ──────────────────────────────────────
// Wird nur genutzt, falls die Ziel-URL die maximale unterstützte
// QR-Code-Kapazität (Version 10, ca. 271 Byte) übersteigt oder der
// Encoder aus einem anderen Grund nicht verfügbar ist. Im
// Normalbetrieb liefert der Codepfad oben immer einen echten,
// scannbaren PNG-QR-Code aus.
header('Content-Type: image/svg+xml');
render_qr_svg_fallback($repairUrl);
