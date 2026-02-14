<?php

/**
 * PSR-4 Autoloader
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Utils;

final class Autoloader
{
    private const ROOT_NAMESPACE = 'FoldSnap\\';

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
        if (strpos($className, self::ROOT_NAMESPACE) !== 0) {
            return;
        }

        $relativeClass = substr($className, strlen(self::ROOT_NAMESPACE));
        $filePath      = FOLDSNAP_PATH . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($filePath)) {
            require_once $filePath;
        }
    }
}
