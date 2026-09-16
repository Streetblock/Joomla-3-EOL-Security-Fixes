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
    private $createdDirectories = array();

    public function preflight($type, $parent)
    {
        if ($type === 'uninstall') { return true; }
        $this->plan = array();
        $this->written = array();
        $this->complete = false;
        $this->createdDirectories = array();
        try {
            if (!in_array($type, array('install', 'update'), true)) {
                throw new RuntimeException('Only normal installation or update is supported.');
            }
            if (!defined('JVERSION') || !preg_match('/^3\.10\.12(?:[-+].*)?$/D', JVERSION)) {
                throw new RuntimeException('This package requires Joomla 3.10.12.');
            }
            if (version_compare($this->phpVersion(), '7.4.0', '<')) {
                throw new RuntimeException('This package requires PHP 7.4.0 or later.');
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
            $additions = isset($inventory['additions']) ? $inventory['additions'] : array();
            $removals = isset($inventory['removals']) ? $inventory['removals'] : array();
            $accepted = isset($inventory['accepted_targets']) ? $inventory['accepted_targets'] : array();
            if (!is_array($additions) || !is_array($removals) || !is_array($accepted)
                || array_diff($additions, array_keys($inventory['files']))
                || array_intersect(array_keys($removals), array_keys($inventory['files']))) {
                throw new RuntimeException('Invalid dependency file plan.');
            }
            foreach (array_merge($accepted, $removals) as $hashes) {
                if (!is_array($hashes) || !$hashes) { throw new RuntimeException('Invalid accepted dependency hashes.'); }
                foreach ($hashes as $hash) {
                    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                        throw new RuntimeException('Invalid accepted dependency hash.');
                    }
                }
            }
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
                $addition = in_array($relative, $additions, true);
                $dest = $this->checkedPath($root, $relative, $addition);
                $hash = $inventory['files'][$relative];
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || $this->hash($src) !== $hash) {
                    throw new RuntimeException('Package checksum mismatch: ' . $relative);
                }
                $old = is_file($dest) ? $this->hash($dest) : null;
                if ($old !== null && !is_writable($dest)) { throw new RuntimeException('Target is not writable: ' . $relative); }
                if ($old !== null && (($addition && $old !== $hash)
                    || (isset($accepted[$relative]) && $old !== $hash && !in_array($old, $accepted[$relative], true)))) {
                    throw new RuntimeException('Unrecognized dependency/autoloader file; review local changes first: ' . $relative);
                }
                $this->plan[$relative] = array('source' => $src, 'target' => $dest, 'new' => $hash, 'old' => $old);
            }
            foreach ($removals as $relative => $hashes) {
                $dest = $this->checkedPath($root, $relative, true);
                $old = is_file($dest) ? $this->hash($dest) : null;
                if (!is_array($hashes) || !$hashes || ($old !== null && (!in_array($old, $hashes, true) || !is_writable(dirname($dest))))) {
                    throw new RuntimeException('Unrecognized obsolete dependency file: ' . $relative);
                }
                $this->plan[$relative] = array('source' => null, 'target' => $dest, 'new' => null, 'old' => $old);
            }
            uksort($this->plan, array($this, 'markerLast'));
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
                $this->assertOriginalTarget($relative, $item);
                if ($item['new'] === null) {
                    if ($item['old'] !== null) {
                        $this->written[] = $relative;
                        if (!$this->removeFile($item['target'])) { throw new RuntimeException('Cannot remove obsolete file: ' . $relative); }
                        $this->invalidate($item['target']);
                    }
                    continue;
                }
                if ($this->hash($item['source']) !== $item['new']) {
                    throw new RuntimeException('File changed during installation: ' . $relative);
                }
                if ($item['old'] === null) {
                    $this->createParents(dirname($item['target']));
                    $this->checkedPath(realpath(JPATH_ROOT), $relative, true);
                    $handle = @fopen($item['target'], 'x');
                    if (!$handle) { throw new RuntimeException('New file appeared during installation: ' . $relative); }
                    fclose($handle);
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
            $this->assertOriginalTarget($relative, $item);
            if ($item['old'] === null) { continue; }
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
            $this->checkedPath(realpath(JPATH_ROOT), $relative, $item['new'] === null);
            if ($item['new'] === null ? file_exists($item['target']) : $this->hash($item['target']) !== $item['new']) {
                throw new RuntimeException('Installed file state mismatch: ' . $relative);
            }
        }
    }

    private function fail($reason)
    {
        $restored = true;
        foreach (array_reverse($this->written) as $relative) {
            $item = $this->plan[$relative];
            try {
                $this->checkedPath(realpath(JPATH_ROOT), $relative, true);
                if ($item['old'] === null) {
                    if (file_exists($item['target']) && !$this->removeFile($item['target'])) { throw new RuntimeException('Cannot remove added file'); }
                    clearstatcache(true, $item['target']);
                    if (file_exists($item['target'])) { throw new RuntimeException('Added file remains'); }
                    $this->invalidate($item['target']);
                    continue;
                }
                $backup = $this->backupFile($relative);
                if ($this->hash($backup) !== $item['old'] || !$this->copyFile($backup, $item['target'])
                    || $this->hash($item['target']) !== $item['old']) { throw new RuntimeException('Restore failed'); }
                $this->invalidate($item['target']);
            } catch (Exception $e) { $restored = false; }
            catch (Throwable $e) { $restored = false; }
        }
        foreach (array_reverse($this->createdDirectories) as $directory) {
            // Never recursively delete: remove only empty directories created by this run.
            if (is_dir($directory) && !is_link($directory) && count(scandir($directory)) === 2) {
                if (!@rmdir($directory)) { $restored = false; }
            }
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
            . ' managed file states verified (replacements, additions and removals). Backup: ' . $this->backup
            . '. This verifies package installation, not the security of the entire site.', 'message');
        return true;
    }

    private function checkedPath($base, $relative, $allowMissing = false)
    {
        if (!is_string($relative) || !preg_match('#^[a-zA-Z0-9_./-]+$#D', $relative)
            || preg_match('#(^|/)\.\.?(/|$)#', $relative) || substr($relative, 0, 1) === '/') {
            throw new RuntimeException('Unsafe relative path.');
        }
        $path = $base;
        $missing = false;
        foreach (explode('/', $relative) as $segment) {
            if ($allowMissing && !file_exists($path . '/' . $segment) && !$missing) {
                if (!is_dir($path) || !is_writable($path)) { throw new RuntimeException('New file parent is not writable: ' . $relative); }
                $missing = true;
            }
            $path .= '/' . $segment;
            if (is_link($path)) { throw new RuntimeException('Symlinks are not supported: ' . $relative); }
        }
        if ($missing && $allowMissing) { return $path; }
        $resolved = realpath($path);
        if (!$resolved || !$this->inside($resolved, $base) || !is_file($resolved) || !is_readable($resolved)) {
            throw new RuntimeException('Missing, unreadable or unsafe file: ' . $relative);
        }
        return $resolved;
    }

    private function assertOriginalTarget($relative, $item)
    {
        clearstatcache(true, $item['target']);
        $this->checkedPath(realpath(JPATH_ROOT), $relative, $item['old'] === null);
        if ($item['old'] === null ? file_exists($item['target']) : $this->hash($item['target']) !== $item['old']) {
            throw new RuntimeException('File changed during installation: ' . $relative);
        }
    }

    private function createParents($directory)
    {
        $missing = array();
        $root = realpath(JPATH_ROOT);
        while (!is_dir($directory)) {
            if (!$this->inside($directory, $root) || is_link($directory)) { throw new RuntimeException('Unsafe new directory'); }
            $missing[] = $directory;
            $directory = dirname($directory);
        }
        foreach (array_reverse($missing) as $directory) {
            if (!@mkdir($directory, 0755)) { throw new RuntimeException('Cannot create dependency directory'); }
            $this->createdDirectories[] = $directory;
        }
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
    protected function removeFile($path) { return @unlink($path); }
    protected function phpVersion() { return PHP_VERSION; }
    private function backupFile($relative) { return $this->backup . '/' . hash('sha256', $relative) . '.bak'; }

    private function record($status)
    {
        $files = array();
        foreach ($this->plan as $relative => $item) {
            $files[$relative] = array('backup' => $item['old'] === null ? null : basename($this->backupFile($relative)),
                'before' => $item['old'], 'after' => $item['new']);
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
