<?php
/**
 * MZ Tech – Eigenständiger Composer-Ersatz-Autoloader
 * ----------------------------------------------------------------------
 * Dieses Projekt verwendet KEINE über Composer aus dem Internet
 * geladenen Pakete. Stattdessen liegen hier vollständige, von Grund auf
 * selbst geschriebene, abhängigkeitsfreie Ersatzimplementierungen der
 * drei benötigten Bibliotheken (PHPMailer, TCPDF, QR-Code-Encoder), die
 * exakt die von diesem Projekt genutzte API abdecken.
 *
 * Diese Datei ersetzt vendor/autoload.php 1:1, sodass alle bestehenden
 * `require_once BASE_PATH . '/vendor/autoload.php';`-Aufrufe im Projekt
 * unverändert funktionieren – ganz ohne `composer install`.
 *
 * Geladene Klassen:
 *   - PHPMailer\PHPMailer\PHPMailer  (+ SMTP, Exception)
 *   - TCPDF
 *   - MZTech\QRCode
 */

if (!defined('MZTECH_VENDOR_LOADED')) {
    define('MZTECH_VENDOR_LOADED', true);

    require_once __DIR__ . '/mztech/PHPMailer.php';
    require_once __DIR__ . '/mztech/TCPDF.php';
    require_once __DIR__ . '/mztech/QRCode.php';
}
