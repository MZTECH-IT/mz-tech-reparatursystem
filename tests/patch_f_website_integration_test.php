<?php
// Struktur-/Konsistenztest für Patch F (WEBSITE-INTEGRATION.html).
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$ok = $dom->loadHTMLFile(__DIR__ . '/patch_f_website_integration_test.html');
$errors = libxml_get_errors();
libxml_clear_errors();
$fatal = 0;
foreach ($errors as $e) { if ($e->level >= LIBXML_ERR_ERROR) $fatal++; }

$xpath = new DOMXPath($dom);
$hrefs = [];
foreach ($xpath->query('//a[contains(@href, "mztech-it.de")]') as $a) { $hrefs[] = $a->getAttribute('href'); }
$pattern = '#^https://mztech-it\.de/repair/public/[a-z_]+\.php$#';
$allMatch = true;
foreach ($hrefs as $h) { if (!preg_match($pattern, $h)) $allMatch = false; }
$hasPortalLink = in_array('https://mztech-it.de/repair/public/portal.php', $hrefs, true);

$pass = $ok && $fatal === 0 && $allMatch && $hasPortalLink && count($hrefs) === 6;
echo $pass ? "PASS: HTML wohlgeformt, URL-Muster konsistent, Portal-Link vorhanden (hero+nav)\n" : "FAIL\n";
exit($pass ? 0 : 1);
