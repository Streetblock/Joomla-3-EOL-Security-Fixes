<?php
/**
 * Joomla 3 EOL Security Fixes: verified replacement and recovery.
 * @license GNU General Public License version 2 or later; see LICENSE
 */
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class joomla3eolsecurityfixesInstallerScript
{
    private $version;
    private $plan = array();
    private $written = array();
    private $backup;
    private $lock;
    private $complete = false;

    public function preflight($type, $parent)
    {
        if ($type === 'uninstall') { return true; }
        $this->plan = array();
        $this->written = array();
        $this->complete = false;
        try {
            if (!in_array($type, array('install', 'update'), true)) {
                throw new RuntimeException('Only normal installation or update is supported.');
            }
            if (!defined('JVERSION') || !preg_match('/^3\.10\.12(?:[-+].*)?$/D', JVERSION)) {
                throw new RuntimeException('This package requires Joomla 3.10.12.');
            }
            $source = realpath($parent->getParent()->getPath('source'));
            $root = realpath(JPATH_ROOT);
            if (!$source || !$root) { throw new RuntimeException('Invalid installation paths.'); }
            $inventory = json_decode(@file_get_contents($source . '/checksums.json'), true);
            $manifest = @simplexml_load_file($source . '/joomla3eolsecurityfixes.xml');
            if (!$manifest || !is_array($inventory) || !isset($inventory['version'], $inventory['files'])
                || $inventory['version'] !== (string) $manifest->version || !is_array($inventory['files']) || !$inventory['files']) {
                throw new RuntimeException('Missing or invalid package checksum inventory / manifest version.');
            }
            $this->version = (string) $manifest->version;
            $actual = array();
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/files', FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) { throw new RuntimeException('Unexpected package entry.'); }
                $actual[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($source . '/files/')));
            }
            $expected = array_keys($inventory['files']);
            sort($actual); sort($expected);
            if ($actual !== $expected) { throw new RuntimeException('Package files do not match the inventory.'); }
            // The visible version marker is replaced last, but is never treated as proof.
            usort($expected, array($this, 'markerLast'));
            foreach ($expected as $relative) {
                $src = $this->checkedPath($source . '/files', $relative);
                $dest = $this->checkedPath($root, $relative);
                $hash = $inventory['files'][$relative];
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || $this->hash($src) !== $hash) {
                    throw new RuntimeException('Package checksum mismatch: ' . $relative);
                }
                if (!is_writable($dest)) { throw new RuntimeException('Target is not writable: ' . $relative); }
                $this->plan[$relative] = array('source' => $src, 'target' => $dest, 'new' => $hash, 'old' => $this->hash($dest));
            }
            return true;
        } catch (Exception $e) {
            $this->plan = array();
            $this->message($e->getMessage(), 'error');
            return false;
        }
    }

    public function markerLast($a, $b)
    {
        if ($a === $b) { return 0; }
        if ($a === 'libraries/src/Version.php') { return 1; }
        if ($b === 'libraries/src/Version.php') { return -1; }
        return strcmp($a, $b);
    }

    public function install($parent) { return $this->apply(); }
    public function update($parent) { return $this->apply(); }

    private function apply()
    {
        try {
            if (!$this->plan || $this->complete) { throw new RuntimeException('No validated installation plan.'); }
            $this->prepareBackup();
            foreach ($this->plan as $relative => $item) {
                $this->checkedPath(realpath(JPATH_ROOT), $relative);
                if ($this->hash($item['source']) !== $item['new'] || $this->hash($item['target']) !== $item['old']) {
                    throw new RuntimeException('File changed during installation: ' . $relative);
                }
                $this->written[] = $relative; // A failed copy may already have truncated the destination.
                if (!$this->copyFile($item['source'], $item['target']) || $this->hash($item['target']) !== $item['new']) {
                    throw new RuntimeException('Replacement failed verification: ' . $relative);
                }
                $this->invalidate($item['target']);
            }
            $this->verifyInstalled();
            $this->record('verified');
            $this->complete = true;
            return true;
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
        catch (Throwable $e) { return $this->fail($e->getMessage()); }
    }

    private function prepareBackup()
    {
        $root = realpath(JPATH_ROOT);
        $base = Factory::getConfig()->get('eol_security_backup_path', dirname($root) . '/joomla3-eol-backups');
        $base = realpath($base);
        if (!$base || !is_dir($base) || !is_writable($base) || $this->inside($base, $root)) {
            throw new RuntimeException('Create a writable backup directory outside Joomla and configure eol_security_backup_path.');
        }
        $lockPath = $base . '/installation.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Unsafe backup lock path.'); }
        $this->lock = @fopen($lockPath, 'c');
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Cannot acquire the installation lock.'); }
        $this->backup = $base . '/' . gmdate('Ymd-His') . '-' . uniqid('', true);
        if (!mkdir($this->backup, 0700)) { throw new RuntimeException('Cannot create backup directory.'); }
        foreach ($this->plan as $relative => $item) {
            $this->checkedPath($root, $relative);
            $target = $this->backupFile($relative);
            if (!$this->copyFile($item['target'], $target) || $this->hash($target) !== $item['old']) {
                throw new RuntimeException('Backup failed verification: ' . $relative);
            }
            if (DIRECTORY_SEPARATOR !== '\\' && !chmod($target, 0600)) { throw new RuntimeException('Cannot restrict backup permissions.'); }
        }
        $this->record('prepared');
        $this->message('Verified backup: ' . $this->backup, 'message');
    }

    private function verifyInstalled()
    {
        foreach ($this->plan as $relative => $item) {
            $this->checkedPath(realpath(JPATH_ROOT), $relative);
            if ($this->hash($item['target']) !== $item['new']) { throw new RuntimeException('Installed checksum mismatch: ' . $relative); }
        }
    }

    private function fail($reason)
    {
        $restored = true;
        foreach (array_reverse($this->written) as $relative) {
            $item = $this->plan[$relative];
            try {
                $this->checkedPath(realpath(JPATH_ROOT), $relative);
                $backup = $this->backupFile($relative);
                if ($this->hash($backup) !== $item['old'] || !$this->copyFile($backup, $item['target'])
                    || $this->hash($item['target']) !== $item['old']) { throw new RuntimeException('Restore failed'); }
                $this->invalidate($item['target']);
            } catch (Exception $e) { $restored = false; }
            catch (Throwable $e) { $restored = false; }
        }
        $this->complete = false;
        if ($this->backup && is_dir($this->backup)) {
            try { $this->record($restored ? 'aborted-restored' : 'RESTORE-FAILED'); }
            catch (Exception $e) { $this->message('Could not update the backup report.', 'warning'); }
        }
        $this->unlock();
        $this->message('Installation failed: ' . $reason, 'error');
        if ($this->written) {
            $this->message($restored ? 'All attempted replacements were restored and verified.'
                : 'RESTORE FAILED: keep the site offline and restore manually from ' . $this->backup, 'error');
        }
        return false;
    }

    public function postflight($type, $parent)
    {
        if ($type === 'uninstall') { return true; }
        if (!$this->complete) { throw new RuntimeException('No verified patch installation; no success can be reported.'); }
        try { $this->verifyInstalled(); }
        catch (Exception $e) {
            $this->fail($e->getMessage());
            // Joomla 3 ignores a false postflight return; throw to prevent a false success.
            throw new RuntimeException('Final patch verification failed.');
        }
        $this->unlock();
        $this->message('Security Fixes ' . $this->version . ': ' . count($this->plan)
            . ' replacement files verified. Backup: ' . $this->backup
            . '. This verifies package installation, not the security of the entire site.', 'message');
        return true;
    }

    private function checkedPath($base, $relative)
    {
        if (!is_string($relative) || !preg_match('#^[a-zA-Z0-9_./-]+$#D', $relative)
            || preg_match('#(^|/)\.\.?(/|$)#', $relative) || substr($relative, 0, 1) === '/') {
            throw new RuntimeException('Unsafe relative path.');
        }
        $path = $base;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) { throw new RuntimeException('Symlinks are not supported: ' . $relative); }
        }
        $resolved = realpath($path);
        if (!$resolved || !$this->inside($resolved, $base) || !is_file($resolved) || !is_readable($resolved)) {
            throw new RuntimeException('Missing, unreadable or unsafe file: ' . $relative);
        }
        return $resolved;
    }

    private function inside($path, $base)
    {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $base), '/');
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $base = strtolower($base); }
        return $path === $base || strpos($path, $base . '/') === 0;
    }

    private function hash($path)
    {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) { throw new RuntimeException('Cannot read a file checksum.'); }
        return $hash;
    }

    protected function copyFile($source, $target) { return @copy($source, $target); }
    private function backupFile($relative) { return $this->backup . '/' . hash('sha256', $relative) . '.bak'; }

    private function record($status)
    {
        $files = array();
        foreach ($this->plan as $relative => $item) {
            $files[$relative] = array('backup' => basename($this->backupFile($relative)), 'before' => $item['old'], 'after' => $item['new']);
        }
        $json = json_encode(array('version' => $this->version, 'root' => realpath(JPATH_ROOT),
            'status' => $status, 'utc' => gmdate('c'), 'files' => $files));
        if ($json === false || file_put_contents($this->backup . '/restore.json', $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Cannot write backup report.');
        }
    }

    private function invalidate($path)
    {
        if (function_exists('opcache_invalidate')) { opcache_invalidate($path, true); }
    }

    private function unlock()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }

    public function __destruct() { $this->unlock(); }

    private function message($text, $type)
    {
        Factory::getApplication()->enqueueMessage(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), $type);
    }
}
