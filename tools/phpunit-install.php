<?php

/**
 * PHPUnit WordPress test environment installer
 *
 * Reads configuration from tools/phpunit-install-config.json,
 * cleans any previous installation, and runs install-wp-tests.sh
 * to set up WordPress core and the test suite.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

/**
 * Recursively remove a directory and its contents
 *
 * @param string $path Path to remove
 *
 * @return bool True on success
 */
function rrmdir(string $path): bool
{
    if (is_dir($path)) {
        $dh = opendir($path);
        if ($dh === false) {
            return false;
        }
        while (($object = readdir($dh)) !== false) {
            if ($object === '.' || $object === '..') {
                continue;
            }
            rrmdir($path . '/' . $object);
        }
        closedir($dh);
        return @rmdir($path);
    }

    $result = is_writable($path) && unlink($path);
    if (!$result) {
        echo "Cannot remove {$path}\n";
    }
    return $result;
}

echo "\n";

$configFile = __DIR__ . '/phpunit-install-config.json';
if (!file_exists($configFile)) {
    echo "Install config file {$configFile} does not exist.\n\n";
    echo "Please create it from phpunit-install-config-sample.json\n";
    exit(1);
}

$json = file_get_contents($configFile);
if ($json === false) {
    echo "Cannot read config file {$configFile}\n";
    exit(1);
}

$config = json_decode($json, true);
if (!is_array($config)) {
    echo "Invalid JSON in config file {$configFile}\n";
    exit(1);
}

$config = array_merge([
    'testPath'   => '',
    'dbHost'     => 'localhost',
    'dbName'     => 'foldsnap_phpunit_tests',
    'dbUser'     => '',
    'dbPassword' => '',
    'wpVersion'  => 'latest',
], $config);

if (strlen($config['testPath']) === 0) {
    $config['testPath'] = sys_get_temp_dir();
}
$config['testPath'] = rtrim($config['testPath'], '\\/');

if (strlen($config['dbUser']) === 0) {
    echo "dbUser cannot be empty\n";
    exit(1);
}

if (strlen($config['dbName']) === 0) {
    echo "dbName cannot be empty\n";
    exit(1);
}

if (!is_dir($config['testPath']) && mkdir($config['testPath'], 0777, true) === false) {
    echo "Cannot create folder {$config['testPath']}\n";
    exit(1);
}

// Clean previous installation
$removeItems = [
    $config['testPath'] . '/wp-latest.json',
    $config['testPath'] . '/wordpress.tar.gz',
    $config['testPath'] . '/wordpress',
    $config['testPath'] . '/wordpress-tests-lib',
];

foreach ($removeItems as $item) {
    if (!file_exists($item)) {
        continue;
    }
    echo "REMOVE {$item}\n";
    rrmdir($item);
}

echo "Install test suite on path {$config['testPath']}\n";
echo "Using database {$config['dbName']}\n";

$installArgs = [
    escapeshellarg($config['dbName']),
    escapeshellarg($config['dbUser']),
    escapeshellarg($config['dbPassword']),
    escapeshellarg($config['dbHost']),
    escapeshellarg($config['wpVersion']),
];

$command  = 'export TMPDIR=' . escapeshellarg($config['testPath']) . ';';
$command .= 'bash ' . escapeshellarg(__DIR__ . '/install-wp-tests.sh') . ' ' . implode(' ', $installArgs) . ' 2>&1;';

echo "Exec command: {$command}\n";

$fp = popen($command, 'r');
if ($fp === false) {
    echo "popen failed\n";
    exit(1);
}
while (!feof($fp)) {
    echo fgets($fp, 4096);
}
pclose($fp);

exit(0);
