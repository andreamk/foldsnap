<?php

/**
 * Autoloader for test classes
 *
 * @package FoldSnap\Tests
 */

declare(strict_types=1);

namespace FoldSnap\Tests\TestsUtils;

final class TestsAutoloader
{
    private const ROOT_TESTS_NAMESPACE = 'FoldSnap\\Tests\\';

    /**
     * Register autoloader function
     *
     * @return void
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    /**
     * Load class by name
     *
     * @param string $className Fully qualified class name
     *
     * @return void
     */
    public static function load(string $className): void
    {
        if (strpos($className, self::ROOT_TESTS_NAMESPACE) !== 0) {
            return;
        }

        $relativeClass = substr($className, strlen(self::ROOT_TESTS_NAMESPACE));
        $filePath      = dirname(__DIR__) . '/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($filePath)) {
            require_once $filePath;
        }
    }
}
