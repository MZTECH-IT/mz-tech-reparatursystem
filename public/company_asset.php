<?php
/**
 * MZ Tech – Streaming-Endpunkt für hochgeladene Firmen-Assets (Phase 3)
 *
 * Liefert ein per Einstellungen > Logo & Design hochgeladenes Firmenlogo/
 * Favicon aus (gespeichert AUSSERHALB des Webroots, analog zu allen
 * anderen Uploads im System, siehe serve_photo() in functions.php).
 * Öffentlich erreichbar (kein Login nötig), da Logo/Favicon auch auf
 * öffentlichen Seiten (Login, Terminbuchung, Kundenportal) angezeigt
 * werden müssen.
 */
$private = dirname(__DIR__) . '/private';
require_once $private . '/config.php';
require_once $private . '/db.php';
require_once $private . '/functions.php';

$type = $_GET['type'] ?? '';
if (!in_array($type, ['logo', 'logo_pdf', 'favicon'], true)) {
    http_response_code(404);
    exit;
}

$asset = company_asset_find($type);
if ($asset === null) {
    http_response_code(404);
    exit;
}

$mime = match ($asset['ext']) {
    'png'        => 'image/png',
    'jpg', 'jpeg'=> 'image/jpeg',
    'webp'       => 'image/webp',
    'ico'        => 'image/x-icon',
    default      => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . filesize($asset['path']));
readfile($asset['path']);
