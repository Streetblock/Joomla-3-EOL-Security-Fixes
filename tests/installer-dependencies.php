<?php
// Isolated filesystem scenarios for dependency additions, removals and recovery.
namespace Joomla\CMS {
    class Factory {
        public static $backup;
        public static function getApplication() { return new self; }
        public static function getConfig() { return new self; }
        public function get($key, $default) { return self::$backup; }
        public function enqueueMessage($text, $type) {}
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { exit(2); }
    $cases = array('success', 'reinstall', 'rollback', 'remove-failure', 'postflight-tamper',
        'addition-collision', 'guard-mismatch', 'obsolete-mismatch', 'late-addition',
        'parent-is-file', 'php73', 'invalid-hashes');
    if (!isset($argv[1])) {
        $failed = 0;
        foreach ($cases as $case) {
            passthru(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' ' . $case, $status);
            $failed += $status !== 0 ? 1 : 0;
        }
        echo count($cases) . " dependency installer scenarios, $failed failures\n";
        exit($failed ? 1 : 0);
    }
    $case = $argv[1];
    if (!in_array($case, $cases, true)) { exit(2); }
    $base = sys_get_temp_dir() . '/joomla-dependency-test-' . bin2hex(random_bytes(12));
    mkdir($base . '/site', 0700, true);
    mkdir($base . '/package/files', 0700, true);
    mkdir($base . '/backups', 0700);
    define('_JEXEC', 1);
    define('JVERSION', '3.10.12');
    define('JPATH_ROOT', $base . '/site');
    \Joomla\CMS\Factory::$backup = $base . '/backups';
    require dirname(__DIR__) . '/script.php';
    class DependencyParent {
        public $source;
        public function getParent() { return $this; }
        public function getPath($key) { return $this->source; }
    }
    class DependencyInstaller extends \joomla3eolsecurityfixesInstallerScript {
        public $case;
        protected function phpVersion() { return $this->case === 'php73' ? '7.3.33' : PHP_VERSION; }
        protected function removeFile($path) {
            return $this->case === 'remove-failure' && basename($path) === 'b-old.txt' ? false : parent::removeFile($path);
        }
        protected function copyFile($source, $target) {
            if ($this->case === 'rollback' && basename($target) === 'z-final.txt' && substr($source, -4) !== '.bak') {
                file_put_contents($target, 'partial');
                return false;
            }
            return parent::copyFile($source, $target);
        }
    }
    function expect($ok, $label) { if (!$ok) { throw new \RuntimeException($label); } }
    function cleanupDependency($path, $base) {
        if ($path !== $base && strpos($path, $base . '/') !== 0) { throw new \RuntimeException('Unsafe cleanup'); }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { cleanupDependency($path . '/' . $name, $base); } }
            rmdir($path);
        } else { unlink($path); }
    }
    $added = 'a-new/sub/file.txt';
    $names = array($added, 'z-final.txt');
    mkdir($base . '/package/files/a-new/sub', 0700, true);
    $inventory = array('version' => '1.3.0', 'files' => array(), 'additions' => array($added),
        'removals' => array('b-old.txt' => array(hash('sha256', 'obsolete'))),
        'accepted_targets' => array('z-final.txt' => array(hash('sha256', 'original'))));
    foreach ($names as $name) {
        file_put_contents($base . '/package/files/' . $name, 'new ' . $name);
        $inventory['files'][$name] = hash('sha256', 'new ' . $name);
    }
    file_put_contents(JPATH_ROOT . '/z-final.txt', 'original');
    file_put_contents(JPATH_ROOT . '/b-old.txt', 'obsolete');
    if ($case === 'addition-collision') {
        mkdir(JPATH_ROOT . '/a-new/sub', 0700, true);
        file_put_contents(JPATH_ROOT . '/' . $added, 'local');
    }
    if ($case === 'guard-mismatch') { file_put_contents(JPATH_ROOT . '/z-final.txt', 'local'); }
    if ($case === 'obsolete-mismatch') { file_put_contents(JPATH_ROOT . '/b-old.txt', 'local'); }
    if ($case === 'parent-is-file') { file_put_contents(JPATH_ROOT . '/a-new', 'local'); }
    if ($case === 'invalid-hashes') { $inventory['accepted_targets']['z-final.txt'] = 'not-an-array'; }
    file_put_contents($base . '/package/checksums.json', json_encode($inventory));
    file_put_contents($base . '/package/joomla3eolsecurityfixes.xml', '<extension><version>1.3.0</version></extension>');
    $parent = new DependencyParent;
    $parent->source = $base . '/package';
    $installer = new DependencyInstaller;
    $installer->case = $case;
    try {
        $invalid = in_array($case, array('addition-collision', 'guard-mismatch', 'obsolete-mismatch', 'parent-is-file', 'php73', 'invalid-hashes'), true);
        expect($installer->preflight('install', $parent) === !$invalid, 'preflight result');
        if ($invalid) {
            expect(file_get_contents(JPATH_ROOT . '/z-final.txt') === ($case === 'guard-mismatch' ? 'local' : 'original'), 'preflight wrote a target');
            expect(!glob($base . '/backups/*/restore.json'), 'preflight created backup');
        } else {
            if ($case === 'late-addition') {
                mkdir(JPATH_ROOT . '/a-new/sub', 0700, true);
                file_put_contents(JPATH_ROOT . '/' . $added, 'concurrent');
            }
            $succeeds = in_array($case, array('success', 'reinstall', 'postflight-tamper'), true);
            expect($installer->install($parent) === $succeeds, 'apply result');
            if ($succeeds) {
                if ($case === 'postflight-tamper') { file_put_contents(JPATH_ROOT . '/' . $added, 'tampered'); }
                $thrown = false;
                try { $installer->postflight('install', $parent); } catch (\RuntimeException $e) { $thrown = true; }
                expect($thrown === ($case === 'postflight-tamper'), 'postflight result');
            }
            if ($case === 'reinstall') {
                unset($installer);
                $installer = new DependencyInstaller;
                expect($installer->preflight('update', $parent), 'reinstall preflight');
                expect($installer->update($parent), 'reinstall apply');
                expect($installer->postflight('update', $parent), 'reinstall postflight');
            }
            $finalSuccess = in_array($case, array('success', 'reinstall'), true);
            expect(file_get_contents(JPATH_ROOT . '/z-final.txt') === ($finalSuccess ? 'new z-final.txt' : 'original'), 'replacement bytes');
            expect(file_exists(JPATH_ROOT . '/b-old.txt') === !$finalSuccess, 'obsolete existence');
            if (!$finalSuccess) { expect(file_get_contents(JPATH_ROOT . '/b-old.txt') === 'obsolete', 'obsolete restored bytes'); }
            if ($case === 'late-addition') { expect(file_get_contents(JPATH_ROOT . '/' . $added) === 'concurrent', 'concurrent file preserved'); }
            elseif ($finalSuccess) { expect(file_get_contents(JPATH_ROOT . '/' . $added) === 'new ' . $added, 'addition bytes'); }
            else { expect(!file_exists(JPATH_ROOT . '/a-new'), 'added file/directories rolled back'); }
            foreach (glob($base . '/backups/*/restore.json') as $path) {
                $report = json_decode(file_get_contents($path), true);
                expect($report['status'] === ($finalSuccess ? 'verified' : 'aborted-restored'), 'journal status');
                if ($case !== 'reinstall') {
                    expect($report['files'][$added]['before'] === null && $report['files'][$added]['backup'] === null, 'new file recovery record');
                    expect($report['files']['b-old.txt']['after'] === null, 'removal recovery record');
                }
            }
        }
        echo "PASS $case\n";
    } catch (\Throwable $e) { fwrite(STDERR, "FAIL $case: " . $e->getMessage() . "\n"); $failed = true; }
    finally { unset($installer); cleanupDependency($base, $base); }
    exit(!empty($failed) ? 1 : 0);
}
