<?php

/**
 * @package FoldSnap
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var string $foldsnapBootFile */

define('FOLDSNAP_PATH', dirname($foldsnapBootFile));
define('FOLDSNAP_FILE', $foldsnapBootFile);
define('FOLDSNAP_PLUGIN_URL', plugins_url('', $foldsnapBootFile));
define('FOLDSNAP_VERSION', '0.1.0');
define('FOLDSNAP_BASENAME', plugin_basename($foldsnapBootFile));

require_once FOLDSNAP_PATH . '/src/Utils/Autoloader.php';
\FoldSnap\Utils\Autoloader::register();

\FoldSnap\Core\Bootstrap::init();
