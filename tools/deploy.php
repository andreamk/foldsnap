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

// --- Exclusions -----------------------------------------------------------

// Top-level entries to skip
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
    'webpack.config.js',
    '.eslintrc.js',
    '.eslintignore',
    'jest.config.js',
    'jest.setup.js',
    'phpunit.xml.dist',
    'phpunit.xml',
    'docs',
    'tests',
    'tools',
    'vendor',
];

// Directory names to skip at any depth
$excludeDeep = ['__tests__'];

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
 * Recursively copy a directory, skipping excluded entries.
 *
 * @param string   $source      Source directory path.
 * @param string   $dest        Destination directory path.
 * @param string[] $exclude     List of top-level names to skip.
 * @param string[] $excludeDeep Directory names to skip at any depth.
 *
 * @return void
 */
function copyDir(string $source, string $dest, array $exclude, array $excludeDeep = []): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative     = substr($item->getPathname(), strlen($source) + 1);
        $segments     = explode(DIRECTORY_SEPARATOR, $relative);
        $firstSegment = $segments[0];

        if (in_array($firstSegment, $exclude, true)) {
            continue;
        }

        $skipDeep = false;
        foreach ($segments as $segment) {
            if (in_array($segment, $excludeDeep, true)) {
                $skipDeep = true;
                break;
            }
        }
        if ($skipDeep) {
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

// Build React assets
echo "Building React assets...\n";
$npmBuildReturn = 0;
$npmBuildOutput = [];
exec('npm run build --prefix ' . escapeshellarg($pluginRoot) . ' 2>&1', $npmBuildOutput, $npmBuildReturn);
echo implode("\n", $npmBuildOutput) . "\n";
if ($npmBuildReturn !== 0) {
    echo "ERROR: npm run build failed with exit code {$npmBuildReturn}\n";
    exit(1);
}
echo "React assets built successfully.\n\n";

// Clean previous build
if (is_dir($destination)) {
    echo "Cleaning previous build...\n";
    removeDir($destination);
}

mkdir($destination, 0755, true);

echo "Source:      {$pluginRoot}\n";
echo "Destination: {$destination}\n";
echo "Excluding:   " . implode(', ', $exclude) . "\n\n";

copyDir($pluginRoot, $destination, $exclude, $excludeDeep);

echo "Done. Plugin files copied to:\n  {$destination}\n";
