<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$phpFiles = [];
foreach (['private', 'public'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php' &&
            str_replace('\\', '/', $file->getPathname()) !== str_replace('\\', '/', $root . '/private/config.php')) {
            $phpFiles[] = $file->getPathname();
        }
    }
}

$definitions = [];
foreach ($phpFiles as $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        $failures[] = 'Nicht lesbar: ' . $path;
        continue;
    }
    $tokens = token_get_all($source);
    $braceDepth = 0;
    $classDepths = [];
    $pendingClass = false;
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $pendingClass = true;
            continue;
        }
        if ($token === '{') {
            $braceDepth++;
            if ($pendingClass) {
                $classDepths[] = $braceDepth;
                $pendingClass = false;
            }
            continue;
        }
        if ($token === '}') {
            if ($classDepths !== [] && end($classDepths) === $braceDepth) {
                array_pop($classDepths);
            }
            $braceDepth--;
            continue;
        }
        if (is_array($token) && $token[0] === T_FUNCTION && $classDepths === []) {
            for ($look = $index + 1; $look < $count; $look++) {
                if (is_array($tokens[$look]) && $tokens[$look][0] === T_STRING) {
                    $name = strtolower($tokens[$look][1]);
                    $definitions[$name][] = str_replace('\\', '/', substr($path, strlen($root) + 1));
                    break;
                }
                if ($tokens[$look] === '(') {
                    break; // anonyme Funktion
                }
            }
        }
    }

    if (preg_match_all(
        '/\b(?:require|include)(?:_once)?\s*(?:\(\s*)?__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/',
        $source,
        $matches
    )) {
        foreach ($matches[1] as $relative) {
            $target = dirname($path) . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($target) && basename(str_replace('\\', '/', $relative)) !== 'config.php') {
                $failures[] = 'Fehlender Include-Pfad: ' .
                    str_replace('\\', '/', substr($path, strlen($root) + 1)) . ' -> ' . $relative;
            }
        }
    }
}

foreach ($definitions as $name => $paths) {
    $unique = array_values(array_unique($paths));
    $privateDefinitions = array_values(array_filter(
        $unique,
        static fn(string $path): bool => str_starts_with($path, 'private/')
    ));
    if (count($paths) !== count($unique) || count($privateDefinitions) > 1) {
        $failures[] = 'Doppelte globale Funktion ' . $name . ': ' . implode(', ', $unique);
    }
}

$header = file_get_contents($root . '/public/includes/header.php');
if ($header === false) {
    $failures[] = 'Header nicht lesbar.';
} elseif (preg_match_all('/href=["\']([A-Za-z0-9_\/-]+\.php)(?:\?[^"\']*)?["\']/', $header, $links)) {
    foreach (array_unique($links[1]) as $link) {
        $target = $root . '/public/' . ltrim($link, '/');
        if (!is_file($target)) {
            $failures[] = 'Menülink ohne Datei: ' . $link;
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Projektintegrität fehlgeschlagen:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Projektintegrität: OK (' . count($phpFiles) . " PHP-Dateien geprüft)\n";
