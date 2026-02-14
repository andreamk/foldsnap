<?php

/**
 * PHPUnit bootstrap file
 *
 * @package FoldSnap\Tests
 */

declare(strict_types=1);

ini_set('error_reporting', (string) E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Load test autoloader for FoldSnap\Tests\ namespace
require_once __DIR__ . '/TestsUtils/TestsAutoloader.php';
\FoldSnap\Tests\TestsUtils\TestsAutoloader::register();

// Read install config to find WordPress test environment path
$configFile = __DIR__ . '/../tools/phpunit-install-config.json';
if (!file_exists($configFile)) {
    echo "Could not find {$configFile}" . PHP_EOL .
        "Create it from tools/phpunit-install-config-sample.json and run 'composer phpunit-install'" . PHP_EOL;
    exit(1);
}

$configContent = file_get_contents($configFile);
if ($configContent === false) {
    echo "Could not read {$configFile}" . PHP_EOL;
    exit(1);
}

$testConfig = json_decode($configContent, true);
if (!is_array($testConfig)) {
    echo "Could not parse {$configFile}" . PHP_EOL .
        "Create it from tools/phpunit-install-config-sample.json and run 'composer phpunit-install'" . PHP_EOL;
    exit(1);
}

if (empty($testConfig['testPath'])) {
    $testBasePath = sys_get_temp_dir();
} else {
    $testBasePath = $testConfig['testPath'];
}
$testBasePath = rtrim($testBasePath, '/\\');

$wpTestsDir = $testBasePath . '/wordpress-tests-lib';

// Silence PSR-0 deprecation notices from Requests library
if (!defined('REQUESTS_SILENCE_PSR0_DEPRECATIONS')) {
    define('REQUESTS_SILENCE_PSR0_DEPRECATIONS', true);
}

// Forward PHPUnit Polyfills configuration
$phpunitPolyfillsPath = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH');
if (false !== $phpunitPolyfillsPath) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $phpunitPolyfillsPath);
}

// Verify WordPress test environment exists
if (!file_exists("{$wpTestsDir}/includes/functions.php")) {
    echo "Could not find {$wpTestsDir}/includes/functions.php" . PHP_EOL .
        "Have you run 'composer phpunit-install'?" . PHP_EOL;
    exit(1);
}

// Set WP_PLUGIN_DIR to the parent of this plugin's directory
// so WordPress can find the plugin at the expected path.
define('WP_PLUGIN_DIR', dirname(__DIR__, 2));

$pluginSlug = basename(dirname(__DIR__)) . '/foldsnap.php';
define('FOLDSNAP_TEST_PLUGIN_SLUG', $pluginSlug);

// Give access to tests_add_filter() function.
require_once "{$wpTestsDir}/includes/functions.php";

/**
 * Activate plugin during test bootstrap.
 *
 * @return void
 */
function foldsnap_test_activate_plugin(): void
{
    echo "CURRENT PROJECT PATH \"" . dirname(__DIR__) . "\"\n";
    echo "PLUGIN DIR IS \"" . WP_PLUGIN_DIR . "\"\n";

    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $result = activate_plugin(FOLDSNAP_TEST_PLUGIN_SLUG);
    if ($result !== null) {
        throw new \Exception('Cannot activate plugin. Result: ' . print_r($result, true));
    }

    echo "PLUGIN " . FOLDSNAP_TEST_PLUGIN_SLUG . " ACTIVATED\n";
}

tests_add_filter('init', 'foldsnap_test_activate_plugin', 0);

// Start up the WP testing environment.
require "{$wpTestsDir}/includes/bootstrap.php";
