<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$phpFiles = [];
foreach ([$root . '/private', $root . '/public'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}

$definitions = [];
$includeCount = 0;
foreach ($phpFiles as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        $errors[] = "Nicht lesbar: $file";
        continue;
    }
    if (preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches)) {
        foreach ($matches[1] as $name) {
            $definitions[$name][] = str_replace('\\', '/', substr($file, strlen($root) + 1));
        }
    }
    if (preg_match_all('/\b(?:require|include)(?:_once)?\s+(__DIR__|PRIVATE_PATH)\s*\.\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $base = $match[1] === 'PRIVATE_PATH' ? $root . '/private' : dirname($file);
            $target = $base . '/' . ltrim($match[2], '/\\');
            $includeCount++;
            $normalizedTarget = str_replace('\\', '/', $target);
            $isProtectedRuntimeConfig = str_ends_with($normalizedTarget, '/private/config.php')
                || str_ends_with($normalizedTarget, '/private/cli/../config.php');
            if (!is_file($target) && !$isProtectedRuntimeConfig) {
                $errors[] = "Include-Ziel fehlt: $target (aus $file)";
            }
        }
    }
}

$allowedDuplicates = [
    'action_label' => ['public/activity.php', 'public/profile.php'],
    'json_out' => ['public/api/calendar.php', 'public/api/parts.php'],
];
foreach ($definitions as $name => $files) {
    if (count($files) < 2) {
        continue;
    }
    sort($files);
    $allowed = $allowedDuplicates[$name] ?? [];
    sort($allowed);
    if ($files !== $allowed) {
        $errors[] = "Doppelte Funktionsdefinition $name: " . implode(', ', $files);
    }
}
foreach (['procurement_suggestions_low_stock', 'procurement_suggestion_for_repair'] as $name) {
    if (count($definitions[$name] ?? []) !== 1) {
        $errors[] = "$name muss exakt einmal definiert sein.";
    }
}

$header = file_get_contents($root . '/public/includes/header.php') ?: '';
preg_match_all('/url\([\'"]([^?\'"]+\.php)/', $header, $menuMatches);
$menuLinks = array_values(array_unique($menuMatches[1]));
foreach ($menuLinks as $link) {
    if (!is_file($root . '/public/' . $link)) {
        $errors[] = "Menülink ohne Zieldatei: $link";
    }
}

$sqlRequired = [
    'production_preflight.sql',
    'production_complete_migration.sql',
    'production_postcheck.sql',
    'phase2b_03_append_to_update.sql',
    'phase7_complete_integrations.sql',
    'portal_ticket_preflight.sql',
    'portal_ticket_migration.sql',
    'portal_ticket_postcheck.sql',
    'portal_ticket_rollback.sql',
];
foreach ($sqlRequired as $name) {
    if (!is_file($root . '/sql/' . $name)) {
        $errors[] = "SQL-Datei fehlt: $name";
    }
}
$migration = file_get_contents($root . '/sql/production_complete_migration.sql') ?: '';
foreach (['suppliers', 'purchase_orders', 'purchase_order_receipts', 'accounting_contact_mapping', 'accounting_document_sync'] as $table) {
    if (!str_contains($migration, "`$table`")) {
        $errors[] = "Produktionsmigration referenziert $table nicht.";
    }
}
foreach (['production_preflight.sql', 'production_postcheck.sql'] as $name) {
    $sql = file_get_contents($root . '/sql/' . $name) ?: '';
    $withoutComments = preg_replace('/--[^\r\n]*/', '', $sql);
    if (preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b/i', $withoutComments)) {
        $errors[] = "$name enthält eine verändernde SQL-Anweisung.";
    }
}

$deployment = $root . '/deployment/production_ready';
$forbidden = ['config.php', '.git', '.bak', '.diff'];
if (!is_dir($deployment)) {
    $errors[] = 'Deployment-Paket fehlt.';
} else {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($deployment, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $name = strtolower($file->getFilename());
        foreach ($forbidden as $needle) {
            if ($name === $needle || str_ends_with($name, $needle)) {
                $errors[] = 'Unerlaubte Deployment-Datei: ' . $file->getPathname();
            }
        }
    }
}

echo 'PHP_SOURCE_FILES=' . count($phpFiles) . PHP_EOL;
echo 'FUNCTION_NAMES=' . count($definitions) . PHP_EOL;
echo 'STATIC_INCLUDE_PATHS=' . $includeCount . PHP_EOL;
echo 'MENU_LINKS=' . count($menuLinks) . PHP_EOL;
echo 'STATIC_ERRORS=' . count($errors) . PHP_EOL;
foreach ($errors as $error) {
    echo "ERROR: $error" . PHP_EOL;
}
exit($errors ? 1 : 0);
