<?php
// Real installer + original vendor tree in an isolated temporary fixture.
// Joomla installer services are doubles; no database or HTTP requests are used.
namespace Joomla\CMS {
    class Factory {
        public static $backup;
        public static function getApplication() { return new self; }
        public static function getConfig() { return new self; }
        public function get($key, $default) { return self::$backup; }
        public function enqueueMessage($text, $type) { if ($type === 'error') { echo $text . "\n"; } }
    }
}
namespace {
    if (PHP_SAPI !== 'cli' || !isset($argv[1])) { exit(2); }
    $original = realpath($argv[1]);
    if (!$original || !is_file($original . '/libraries/vendor/autoload.php')) { exit(2); }
    $package = dirname(__DIR__);
    $inventory = json_decode(file_get_contents($package . '/checksums.json'), true);
    $base = sys_get_temp_dir() . '/joomla-full-package-' . bin2hex(random_bytes(12));
    mkdir($base . '/site', 0700, true);
    mkdir($base . '/backups', 0700);
    define('_JEXEC', 1);
    define('JVERSION', '3.10.12');
    define('JPATH_ROOT', $base . '/site');
    \Joomla\CMS\Factory::$backup = $base . '/backups';
    require $package . '/script.php';
    class FullPackageParent {
        public function getParent() { return $this; }
        public function getPath($key) { return dirname(__DIR__); }
    }
    function copyFixture($from, $to) {
        if (!is_dir(dirname($to))) { mkdir(dirname($to), 0700, true); }
        if (!copy($from, $to)) { throw new \RuntimeException('Fixture copy failed'); }
    }
    function cleanupPackage($path, $base) {
        if ($path !== $base && strpos($path, $base . '/') !== 0) { throw new \RuntimeException('Unsafe cleanup'); }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { cleanupPackage($path . '/' . $name, $base); } }
            rmdir($path);
        } else { unlink($path); }
    }
    try {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($original . '/libraries/vendor', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) { copyFixture($entry->getPathname(), JPATH_ROOT . substr($entry->getPathname(), strlen($original))); }
        }
        foreach ($inventory['files'] as $relative => $hash) {
            if (is_file($original . '/' . $relative)) { copyFixture($original . '/' . $relative, JPATH_ROOT . '/' . $relative); }
        }
        // Optional prior package overlay verifies upgrades from a published fork version.
        if (isset($argv[2])) {
            $previous = realpath($argv[2]);
            if (!$previous || !is_dir($previous . '/files')) { throw new \RuntimeException('Invalid prior package'); }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($previous . '/files', \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isFile()) { copyFixture($entry->getPathname(), JPATH_ROOT . substr($entry->getPathname(), strlen($previous . '/files'))); }
            }
        }
        foreach (array('install', 'update') as $route) {
            $installer = new \joomla3eolsecurityfixesInstallerScript;
            $parent = new FullPackageParent;
            if (!$installer->preflight($route, $parent) || !$installer->$route($parent) || !$installer->postflight($route, $parent)) {
                throw new \RuntimeException('Package ' . $route . ' failed');
            }
            unset($installer);
            foreach ($inventory['files'] as $relative => $hash) {
                if (hash_file('sha256', JPATH_ROOT . '/' . $relative) !== $hash) { throw new \RuntimeException('Wrong installed bytes: ' . $relative); }
            }
            foreach ($inventory['removals'] as $relative => $hashes) {
                if (file_exists(JPATH_ROOT . '/' . $relative)) { throw new \RuntimeException('Obsolete file remains'); }
            }
            echo 'PASS complete package ' . $route . ': ' . count($inventory['files']) . " hashes + removals\n";
        }
        foreach (array('dependency-runtime.php', 'yaml-limits.php') as $test) {
            $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__DIR__ . '/' . $test) . ' ' . escapeshellarg(JPATH_ROOT);
            if ($test === 'yaml-limits.php') { $command .= ' ' . escapeshellarg(JPATH_ROOT); }
            passthru($command, $status);
            if ($status !== 0) { throw new \RuntimeException('Installed runtime failed ' . $test); }
        }
        $extension = getenv('JOOMLA_TEST_SODIUM_EXTENSION');
        if ($extension) {
            $command = escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg('extension=' . $extension)
                . ' ' . escapeshellarg(__DIR__ . '/dependency-runtime.php') . ' ' . escapeshellarg(JPATH_ROOT) . ' require-native';
            passthru($command, $status);
            if ($status !== 0) { throw new \RuntimeException('Native sodium runtime failed'); }
        }
        echo "PASS full package fixture; production deployment not tested\n";
    } catch (\Throwable $e) { fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n"); $failed = true; }
    finally { unset($installer); cleanupPackage($base, $base); }
    exit(!empty($failed) ? 1 : 0);
}
