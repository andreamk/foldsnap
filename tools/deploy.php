<?php

/**
 * Deploy script — builds a clean plugin distribution folder.
 *
 * Usage:  php tools/deploy.php [destination]
 *         composer deploy [-- destination]
 *
 * If no destination is given, defaults to tools/tmp/foldsnap/
 *
 * @package FoldSnap
 */

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$pluginSlug = 'foldsnap';

// --- Destination ----------------------------------------------------------

$destination = rtrim($argv[1] ?? $pluginRoot . '/tools/tmp', '/\\') . '/' . $pluginSlug;

// --- Exclusions (relative to plugin root) ---------------------------------

$exclude = [
    '.git',
    '.github',
    '.gitignore',
    '.claude',
    '.vscode',
    '.idea',
    '.phpunit.result.cache',
    'CLAUDE.md',
    'composer.json',
    'composer.lock',
    'node_modules',
    'package.json',
    'package-lock.json',
    'phpunit.xml.dist',
    'phpunit.xml',
    'docs',
    'tests',
    'tools',
    'vendor',
];

// --- Helpers --------------------------------------------------------------

/**
 * Recursively remove a directory and its contents.
 *
 * @param string $path Directory path to remove.
 *
 * @return void
 */
function removeDir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

/**
 * Recursively copy a directory, skipping excluded top-level entries.
 *
 * @param string   $source  Source directory path.
 * @param string   $dest    Destination directory path.
 * @param string[] $exclude List of top-level names to skip.
 *
 * @return void
 */
function copyDir(string $source, string $dest, array $exclude): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative     = substr($item->getPathname(), strlen($source) + 1);
        $firstSegment = explode(DIRECTORY_SEPARATOR, $relative)[0];

        if (in_array($firstSegment, $exclude, true)) {
            continue;
        }

        $target = $dest . '/' . $relative;

        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

// --- Main -----------------------------------------------------------------

echo "FoldSnap — Deploy\n";
echo str_repeat('-', 40) . "\n";

// Clean previous build
if (is_dir($destination)) {
    echo "Cleaning previous build...\n";
    removeDir($destination);
}

mkdir($destination, 0755, true);

echo "Source:      {$pluginRoot}\n";
echo "Destination: {$destination}\n";
echo "Excluding:   " . implode(', ', $exclude) . "\n\n";

copyDir($pluginRoot, $destination, $exclude);

echo "Done. Plugin files copied to:\n  {$destination}\n";
