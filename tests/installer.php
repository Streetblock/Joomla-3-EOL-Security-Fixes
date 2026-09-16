<?php
// Isolated real-filesystem tests. No Joomla site, database or network is used.
namespace Joomla\CMS {
    class Factory {
        public static $messages = array();
        public static $backup;
        public static function getApplication() { return new self; }
        public static function getConfig() { return new self; }
        public function get($key, $default) { return self::$backup; }
        public function enqueueMessage($text, $type) { self::$messages[] = array($type, $text); }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { exit; }
    error_reporting(E_ALL & ~E_DEPRECATED);
    $cases = array('success', 'update', 'missing-target', 'missing-source', 'extra-source', 'altered-source',
        'wrong-version', 'manifest-mismatch', 'empty-inventory', 'missing-inventory', 'traversal',
        'backup-failure', 'partial-copy', 'corrupt-copy', 'restore-failure', 'source-changed', 'target-changed',
        'postflight-tamper', 'lock-held', 'backup-inside-root', 'missing-backup-directory', 'unprepared', 'uninstall');
    if (!isset($argv[1])) {
        $failed = 0;
        foreach ($cases as $case) {
            $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case);
            passthru($command, $status);
            if ($status !== 0) { $failed++; }
        }
        echo count($cases) . ' scenarios, ' . $failed . " failures\n";
        exit($failed ? 1 : 0);
    }
    $case = $argv[1];
    if (!in_array($case, $cases, true)) { exit(2); }
    $base = sys_get_temp_dir() . '/joomla-installer-test-' . bin2hex(random_bytes(12));
    mkdir($base, 0700);
    mkdir($base . '/site', 0700);
    mkdir($base . '/package/files', 0700, true);
    mkdir($base . '/backups', 0700);
    define('_JEXEC', 1);
    define('JPATH_ROOT', $base . '/site');
    define('JVERSION', $case === 'wrong-version' ? '4.4.0' : '3.10.12-previous-patch');
    \Joomla\CMS\Factory::$backup = $base . '/backups';
    require dirname(__DIR__) . '/script.php';

    class ReviewParent {
        public $source;
        public function getParent() { return $this; }
        public function getPath($name) { return $this->source; }
    }
    class ReviewInstaller extends \joomla3eolsecurityfixesInstallerScript {
        public $failure;
        public $writes = array();
        protected function copyFile($source, $target) {
            $replacement = strpos(str_replace('\\', '/', $source), '/package/files/') !== false;
            $restore = substr($source, -4) === '.bak';
            if ($this->failure === 'backup-failure' && substr($target, -4) === '.bak') { return false; }
            if ($replacement) {
                $this->writes[] = basename($target);
                if (basename($target) === 'b.txt' && in_array($this->failure, array('partial-copy', 'corrupt-copy', 'restore-failure'), true)) {
                    file_put_contents($target, 'partial bytes');
                    return $this->failure === 'corrupt-copy';
                }
            }
            if ($restore && $this->failure === 'restore-failure') { return false; }
            return parent::copyFile($source, $target);
        }
    }
    $checks = 0;
    function check($condition, $label) {
        global $checks;
        $checks++;
        if (!$condition) { throw new \RuntimeException($label); }
    }
    function cleanup($path, $base) {
        // Only remove this test's freshly allocated directory, never follow links.
        if (strpos(str_replace('\\', '/', $path), str_replace('\\', '/', $base) . '/') !== 0 && $path !== $base) {
            throw new \RuntimeException('Cleanup escaped fixture root');
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { cleanup($path . '/' . $name, $base); } }
            rmdir($path);
        } else { unlink($path); }
    }
    $lock = null;
    $installer = new ReviewInstaller;
    $installer->failure = $case;
    $parent = new ReviewParent;
    $parent->source = $base . '/package';
    $names = array('a.txt', 'b.txt', 'libraries/src/Version.php');
    $inventory = array('version' => '1.2.0', 'files' => array());
    foreach ($names as $name) {
        foreach (array('/site/', '/package/files/') as $folder) {
            if (!is_dir(dirname($base . $folder . $name))) { mkdir(dirname($base . $folder . $name), 0700, true); }
            file_put_contents($base . $folder . $name, ($folder === '/site/' ? 'original ' : 'patched ') . $name);
        }
        $inventory['files'][$name] = hash_file('sha256', $base . '/package/files/' . $name);
    }
    file_put_contents($base . '/package/joomla3eolsecurityfixes.xml', '<extension><version>1.2.0</version></extension>');
    if ($case === 'missing-target') { unlink($base . '/site/b.txt'); }
    if ($case === 'missing-source') { unlink($base . '/package/files/b.txt'); }
    if ($case === 'extra-source') { file_put_contents($base . '/package/files/extra.txt', 'extra'); }
    if ($case === 'altered-source') { file_put_contents($base . '/package/files/b.txt', 'tampered'); }
    if ($case === 'manifest-mismatch') { $inventory['version'] = '9.0.0'; }
    if ($case === 'empty-inventory') { $inventory['files'] = array(); }
    if ($case === 'traversal') { $inventory['files']['../escape.txt'] = str_repeat('0', 64); }
    file_put_contents($base . '/package/checksums.json', json_encode($inventory));
    if ($case === 'missing-inventory') { unlink($base . '/package/checksums.json'); }
    if ($case === 'backup-inside-root') { \Joomla\CMS\Factory::$backup = $base . '/site'; }
    if ($case === 'missing-backup-directory') { \Joomla\CMS\Factory::$backup = $base . '/absent'; }
    if ($case === 'lock-held') { $lock = fopen($base . '/backups/installation.lock', 'c'); flock($lock, LOCK_EX); }
    try {
        if ($case === 'uninstall') {
            check($installer->preflight('uninstall', $parent) === true, 'uninstall preflight');
            check($installer->postflight('uninstall', $parent) === true, 'uninstall postflight');
            check(!$installer->writes, 'uninstall changed files');
        } elseif ($case === 'unprepared') {
            check($installer->install($parent) === false, 'unprepared install accepted');
            $thrown = false;
            try { $installer->postflight('install', $parent); } catch (\RuntimeException $e) { $thrown = true; }
            check($thrown, 'unprepared postflight reported success');
            check(!$installer->writes, 'unprepared writes');
        } else {
            $route = $case === 'update' ? 'update' : 'install';
            $preflight = $installer->preflight($route, $parent);
            check(!$installer->writes, 'preflight modified core');
            $invalid = in_array($case, array('missing-target', 'missing-source', 'extra-source', 'altered-source',
                'wrong-version', 'manifest-mismatch', 'empty-inventory', 'missing-inventory', 'traversal'), true);
            check($preflight === !$invalid, 'unexpected preflight result');
            if (!$invalid) {
                if ($case === 'source-changed') { file_put_contents($base . '/package/files/b.txt', 'changed after validation'); }
                if ($case === 'target-changed') { file_put_contents($base . '/site/b.txt', 'changed after validation'); }
                $applied = $installer->$route($parent);
                $success = in_array($case, array('success', 'update', 'postflight-tamper'), true);
                check($applied === $success, 'unexpected apply result');
                if ($success) {
                    check(end($installer->writes) === 'Version.php', 'marker was not last');
                    if ($case === 'postflight-tamper') { file_put_contents($base . '/site/b.txt', 'changed before postflight'); }
                    $thrown = false;
                    try { $installer->postflight($route, $parent); } catch (\RuntimeException $e) { $thrown = true; }
                    check($thrown === ($case === 'postflight-tamper'), 'unexpected postflight result');
                } else {
                    $thrown = false;
                    try { $installer->postflight($route, $parent); } catch (\RuntimeException $e) { $thrown = true; }
                    check($thrown, 'failed installation reported success');
                }
                foreach ($names as $name) {
                    $expected = in_array($case, array('success', 'update'), true) ? 'patched ' . $name : 'original ' . $name;
                    if ($case === 'target-changed' && $name === 'b.txt') { $expected = 'changed after validation'; }
                    if ($case !== 'restore-failure') { check(file_get_contents($base . '/site/' . $name) === $expected, 'wrong final bytes: ' . $name); }
                }
                $reports = glob($base . '/backups/*/restore.json');
                if (in_array($case, array('success', 'update', 'partial-copy', 'corrupt-copy', 'restore-failure', 'postflight-tamper'), true)) {
                    check(count($reports) === 1, 'missing recovery report');
                    $report = json_decode(file_get_contents($reports[0]), true);
                    $status = in_array($case, array('success', 'update'), true) ? 'verified' : ($case === 'restore-failure' ? 'RESTORE-FAILED' : 'aborted-restored');
                    check($report['status'] === $status, 'incorrect report status');
                    foreach ($names as $name) {
                        check(hash_file('sha256', dirname($reports[0]) . '/' . $report['files'][$name]['backup']) === hash('sha256', 'original ' . $name), 'backup bytes wrong');
                    }
                }
            }
        }
        $messages = json_encode(\Joomla\CMS\Factory::$messages);
        check(strpos($messages, 'The system is now secure') === false, 'blanket security claim');
        if ($case === 'restore-failure') { check(strpos($messages, 'RESTORE FAILED') !== false, 'restore failure hidden'); }
        echo 'PASS ' . $case . ' (' . $checks . ")\n";
    } catch (\Throwable $e) { fwrite(STDERR, 'FAIL ' . $case . ': ' . $e->getMessage() . "\n"); $failed = true; }
    finally {
        unset($installer);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        cleanup($base, $base);
    }
    exit(!empty($failed) ? 1 : 0);
}
