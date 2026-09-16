<?php
// Read-only compatibility check against an extracted Joomla source tree.
namespace Joomla\CMS {
    class Factory {
        public static function getApplication() { return new self; }
        public function enqueueMessage($text, $type) { echo $type . ': ' . $text . "\n"; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli' || !isset($argv[1])) { exit(2); }
    define('_JEXEC', 1);
    define('JPATH_PLATFORM', __DIR__);
    define('JPATH_ROOT', realpath($argv[1]));
    require JPATH_ROOT . '/libraries/src/Version.php';
    $version = new \Joomla\CMS\Version;
    define('JVERSION', $version->getShortVersion());
    require dirname(__DIR__) . '/script.php';
    class PackageParent {
        public function getParent() { return $this; }
        public function getPath($key) { return dirname(__DIR__); }
    }
    $installer = new \joomla3eolsecurityfixesInstallerScript;
    $ok = $installer->preflight('install', new PackageParent);
    echo $ok ? "PASS complete package preflight; no replacement performed\n" : "FAIL complete package preflight\n";
    exit($ok ? 0 : 1);
}
