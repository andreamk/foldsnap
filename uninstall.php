<?php

/**
 * Uninstall script
 *
 * Executed when the plugin is deleted from WordPress.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Utils/Autoloader.php';
\FoldSnap\Utils\Autoloader::register();

\FoldSnap\Core\Uninstall::run();
