<?php

/**
 * @package FoldSnap
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var string $currentPluginBootFile */

define('FOLDSNAP_PATH', dirname($currentPluginBootFile));
define('FOLDSNAP_FILE', $currentPluginBootFile);
define('FOLDSNAP_PLUGIN_URL', plugins_url('', $currentPluginBootFile));
define('FOLDSNAP_VERSION', '0.1.0');
define('FOLDSNAP_BASENAME', plugin_basename($currentPluginBootFile));

require_once FOLDSNAP_PATH . '/src/Utils/Autoloader.php';
\FoldSnap\Utils\Autoloader::register();

\FoldSnap\Core\Bootstrap::init();
