<?php

/**
 * Plugin Name: FoldSnap
 * Plugin URI: https://github.com/starter-dev/foldsnap
 * Description: Adds folder management capabilities to the WordPress admin media library.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * Author: Starter Dev
 * Author URI: https://github.com/starter-dev
 * Text Domain: foldsnap
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

$currentPluginBootFile = __FILE__;

require_once __DIR__ . '/foldsnap-main.php';
