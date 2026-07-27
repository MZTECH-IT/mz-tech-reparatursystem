<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenDirectories = ['.git', 'backups', 'runtime_secure', 'phase7_upload'];
$findings = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if ($file->isDir()) {
        continue;
    }
    foreach ($forbiddenDirectories as $directory) {
        if (str_contains($path, '/' . $directory . '/')) {
            continue 2;
        }
    }
    if (str_ends_with($path, '/private/config.php') || $file->getSize() > 5 * 1024 * 1024) {
        continue;
    }
    if (!preg_match('/\.(?:php|ps1|psm1|sql|md|txt|html|json)$/i', $path)) {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        continue;
    }
    $patterns = [
        '/Authorization:\s*Bearer\s+[A-Za-z0-9._~+\/=-]{20,}/i',
        '/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\b/',
        '/FONEDAY_API_TOKEN\s*[:=]\s*[\'"][^\'"]{20,}[\'"]/i',
        '/(?:password|passwd|pwd)\s*[:=]\s*[\'"][^\'"]{12,}[\'"]/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            $findings++;
        }
    }
}
if ($findings !== 0) {
    fwrite(STDERR, "Secret-Scan: FEHLER ($findings verdächtige Fundstellen; Inhalte werden nicht ausgegeben)\n");
    exit(1);
}
echo "Secret-Scan: OK (keine verdächtigen Klartextgeheimnisse)\n";
