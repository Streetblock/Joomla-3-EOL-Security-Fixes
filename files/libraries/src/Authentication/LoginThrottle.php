<?php
/**
 * Optional Joomla 3 password-login throttling experiment.
 * @license GNU General Public License version 2 or later; see LICENSE
 */
namespace Joomla\CMS\Authentication;

defined('JPATH_PLATFORM') or die;

/** Single-host store: short file locks, bounded state, no password hashes or sleeps. */
class LoginThrottle
{
    private $directory;
    private $secret;
    private $clock;
    private $policy;
    private $events = array();
    const MAX_BYTES = 8388608;
    const AUDIT_BYTES = 1048576;

    public function __construct($directory, $secret, array $policy = array(), $clock = null)
    {
        $root = str_replace('\\', '/', realpath(JPATH_ROOT));
        $resolved = realpath($directory);
        $path = str_replace('\\', '/', (string) $resolved);
        $compare = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $root = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        if (!$resolved || !is_dir($resolved) || !is_writable($resolved) || is_link($directory)
            || $compare === $root || strpos($compare, rtrim($root, '/') . '/') === 0) {
            throw new \RuntimeException('Throttle requires a private writable directory outside Joomla.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && (fileperms($resolved) & 0077)) {
            throw new \RuntimeException('Throttle directory must have mode 0700.');
        }
        if (!is_string($secret) || strlen($secret) < 32) { throw new \RuntimeException('Throttle secret is too short.'); }
        $this->directory = $resolved;
        $this->secret = $secret;
        $this->clock = $clock ?: function () { return time(); };
        $this->policy = array_merge(array('ip_limit' => 50, 'account_limit' => 20, 'window' => 600,
            'cooldown' => 900, 'max_cooldown' => 3600, 'reservation_ttl' => 60, 'max_entries' => 2048), $policy);
        foreach ($this->policy as $value) {
            if (!is_int($value) || $value < 1 || $value > 86400) { throw new \RuntimeException('Invalid throttle policy.'); }
        }
        if ($this->policy['max_cooldown'] < $this->policy['cooldown'] || $this->policy['max_entries'] > 10000
            || $this->policy['ip_limit'] > 100 || $this->policy['account_limit'] > 100) {
            throw new \RuntimeException('Invalid throttle bounds.');
        }
    }

    /** Integrate without changing the controller's status, redirects, or failure event. */
    public static function authenticate(array $credentials, array $options, callable $authenticate)
    {
        $failure = new AuthenticationResponse;
        $failure->type = 'Joomla';
        $failure->username = isset($credentials['username']) && is_string($credentials['username']) ? $credentials['username'] : '';
        $failure->error_message = \JText::_('JGLOBAL_AUTH_INVALID_PASS');
        $guard = null;
        try {
            $config = \JFactory::getConfig();
            $policy = $config->get('eol_login_throttle_policy', array());
            $guard = new self($config->get('eol_login_throttle_path', ''), $config->get('secret', ''), $policy);
            // Forwarded headers are deliberately ignored. Configure trusted proxies at the web server.
            $ip = self::canonicalIp(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
            if ($guard->blockedIp($ip)) { return $failure; }
            if (strlen($failure->username) > 1024) { return $failure; }
            // The same database collation as Joomla login resolves case/accent/trailing-space aliases.
            $id = \JUserHelper::getUserId($failure->username);
            $account = $id ? 'id:' . (int) $id : 'name:' . \Joomla\String\StringHelper::strtolower($failure->username);
            $ticket = $guard->reserve($ip, $account);
            if ($ticket === null) { return $failure; }
            try { $response = $authenticate(); }
            catch (\Throwable $e) { $guard->complete($ticket, false); return $failure; }
            $success = $response->status === Authentication::STATUS_SUCCESS;
            if (!$guard->complete($ticket, $success) || !$success) { return $failure; }
            return $response;
        } catch (\Throwable $e) {
            // A broken store/configuration must not silently turn protection off.
            if ($guard) { $guard->reportUnavailable(); }
            else { error_log('Joomla login throttle configuration unavailable; password login denied.'); }
            return $failure;
        }
    }

    public static function canonicalIp($ip)
    {
        $binary = is_string($ip) ? @inet_pton($ip) : false;
        if ($binary === false) { throw new \RuntimeException('Invalid peer address.'); }
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $binary = substr($binary, 12);
        }
        return inet_ntop($binary);
    }

    private function ipKey($ip)
    {
        $binary = inet_pton(self::canonicalIp($ip));
        // IPv6 privacy-address rotation inside one /64 must not reset the source budget.
        if (strlen($binary) === 16) { $binary = substr($binary, 0, 8) . str_repeat("\0", 8); }
        return 'ip:' . hash_hmac('sha256', $binary, $this->secret);
    }

    public function blockedIp($ip)
    {
        $key = $this->ipKey($ip);
        return $this->transaction(function (&$entries, $now) use ($key, $ip) {
            if (isset($entries[$key]) && $this->denied($entries[$key], $key, $now, $ip)) { return true; }
            return false;
        });
    }

    /** Reserve budget before hashing so parallel requests cannot all pass a stale counter. */
    public function reserve($ip, $account)
    {
        $ip = self::canonicalIp($ip);
        $keys = array($this->ipKey($ip), 'account:' . hash_hmac('sha256', $account, $this->secret));
        return $this->transaction(function (&$entries, $now) use ($keys, $ip) {
            foreach ($keys as $key) {
                if (isset($entries[$key]) && $this->denied($entries[$key], $key, $now, $ip)) { return null; }
            }
            $missing = array_diff($keys, array_keys($entries));
            if (count($entries) + count($missing) > $this->policy['max_entries']) {
                throw new \RuntimeException('Throttle state capacity reached.');
            }
            $token = bin2hex(random_bytes(16));
            foreach ($keys as $key) {
                if (!isset($entries[$key])) {
                    $entries[$key] = array('start' => $now, 'failed' => 0, 'until' => 0,
                        'strikes' => 0, 'strike_at' => 0, 'denied' => 0, 'reported_at' => 0, 'pending' => array());
                }
                $entries[$key]['pending'][$token] = $now + $this->policy['reservation_ttl'];
            }
            return array('keys' => $keys, 'token' => $token, 'ip' => $ip);
        });
    }

    /** A success cannot clear an active block or erase other users' failures on the same IP. */
    public function complete(array $ticket, $success)
    {
        return $this->transaction(function (&$entries, $now) use ($ticket, $success) {
            $admitted = true;
            foreach ($ticket['keys'] as $key) {
                if (!isset($entries[$key]['pending'][$ticket['token']]) || $entries[$key]['until'] > $now) { $admitted = false; }
            }
            foreach ($ticket['keys'] as $key) {
                if (!isset($entries[$key]['pending'][$ticket['token']])) { continue; }
                $entry =& $entries[$key];
                unset($entry['pending'][$ticket['token']]);
                if (!$success) {
                    $entry['failed'] = min($this->limit($key), $entry['failed'] + 1);
                    $this->blockIfNeeded($entry, $key, $now, $ticket['ip']);
                } elseif ($admitted && strpos($key, 'account:') === 0) {
                    $entry['failed'] = 0;
                    $entry['start'] = $now;
                }
                unset($entry);
            }
            return $admitted;
        });
    }

    private function limit($key) { return $this->policy[strpos($key, 'ip:') === 0 ? 'ip_limit' : 'account_limit']; }

    private function denied(array &$entry, $key, $now, $ip)
    {
        if ($entry['until'] <= $now && $entry['failed'] + count($entry['pending']) < $this->limit($key)) { return false; }
        $entry['denied'] = min(2147483647, $entry['denied'] + 1);
        if ($entry['reported_at'] === 0 || $now - $entry['reported_at'] >= 60) {
            $this->event('denied_summary', $key, $entry, $now, $ip);
            $entry['reported_at'] = $now;
        }
        // Rejected requests never extend blocked_until or count as failed password checks.
        return true;
    }

    private function blockIfNeeded(array &$entry, $key, $now, $ip)
    {
        if ($entry['failed'] < $this->limit($key) || $entry['until'] > $now) { return; }
        $entry['strikes'] = $now - $entry['strike_at'] >= 86400 ? 1 : min(16, $entry['strikes'] + 1);
        $entry['strike_at'] = $now;
        $entry['until'] = $now + min($this->policy['max_cooldown'], $this->policy['cooldown'] * (2 ** ($entry['strikes'] - 1)));
        $this->event('blocked', $key, $entry, $now, $ip);
    }

    private function sweep(array &$entries, $now)
    {
        foreach ($entries as $key => &$entry) {
            if (!preg_match('/^(ip|account):[a-f0-9]{64}$/D', $key) || !is_array($entry)
                || !isset($entry['pending']) || !is_array($entry['pending'])) { throw new \RuntimeException('Invalid throttle state.'); }
            foreach (array('start', 'failed', 'until', 'strikes', 'strike_at', 'denied', 'reported_at') as $field) {
                if (!isset($entry[$field]) || !is_int($entry[$field]) || $entry[$field] < 0) { throw new \RuntimeException('Invalid throttle counter.'); }
            }
            if ($entry['start'] > $now || $entry['strike_at'] > $now) { throw new \RuntimeException('Throttle clock moved backwards.'); }
            if ($entry['until'] > 0 && $entry['until'] <= $now) {
                $this->event('expired', $key, $entry, $now, null);
                $entry['until'] = 0; $entry['failed'] = 0; $entry['start'] = $now;
                $entry['pending'] = array(); $entry['denied'] = 0; $entry['reported_at'] = 0;
            }
            $liveReservation = false;
            foreach ($entry['pending'] as $token => $expires) {
                if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !is_int($expires)) { throw new \RuntimeException('Invalid throttle reservation.'); }
                $liveReservation = $liveReservation || $expires > $now;
            }
            // Dormant sites must not start a fresh block for long-expired abandoned work.
            if (!$entry['until'] && !$liveReservation && $now - $entry['start'] >= $this->policy['window']) {
                $entry['failed'] = 0; $entry['pending'] = array(); $entry['start'] = $now;
                if (!$entry['strikes'] || $now - $entry['strike_at'] >= 86400) { unset($entries[$key]); }
                continue;
            }
            foreach ($entry['pending'] as $token => $expires) {
                if ($expires <= $now) {
                    unset($entry['pending'][$token]);
                    $entry['failed'] = min($this->limit($key), $entry['failed'] + 1);
                    $this->blockIfNeeded($entry, $key, $now, null);
                }
            }
        }
        unset($entry);
    }

    private function event($event, $key, array $entry, $now, $ip)
    {
        $this->events[] = array('event' => $event, 'time' => $now, 'key' => $key,
            'source_ip' => $ip, 'failed_checks' => $entry['failed'], 'denied_requests' => $entry['denied'],
            'blocked_until' => $entry['until']);
    }

    private function path($name)
    {
        $path = $this->directory . '/' . $name;
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new \RuntimeException('Unsafe throttle file.'); }
        return $path;
    }

    private function openLock($name)
    {
        $path = $this->path($name);
        $handle = @fopen($path, 'c');
        if (!$handle) { throw new \RuntimeException('Cannot open throttle lock.'); }
        if ((DIRECTORY_SEPARATOR !== '\\' && !@chmod($path, 0600)) || !flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Throttle lock unavailable.');
        }
        return $handle;
    }

    private function transaction(callable $operation)
    {
        $lock = $this->openLock('state.lock');
        $temporary = null;
        $this->events = array();
        try {
            $path = $this->path('state.json');
            $raw = '';
            if (is_file($path)) {
                $raw = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
                if ($raw === false || strlen($raw) > self::MAX_BYTES) { throw new \RuntimeException('Throttle state too large or unreadable.'); }
            }
            $state = $raw === '' && !is_file($path) ? array('version' => 1, 'entries' => array()) : json_decode($raw, true);
            if (!is_array($state) || !isset($state['version'], $state['entries']) || $state['version'] !== 1
                || !is_array($state['entries']) || count($state['entries']) > $this->policy['max_entries']) {
                throw new \RuntimeException('Invalid throttle store.');
            }
            $now = (int) call_user_func($this->clock);
            $this->sweep($state['entries'], $now);
            $result = $operation($state['entries'], $now);
            $json = json_encode($state, JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > self::MAX_BYTES) { throw new \RuntimeException('Throttle state capacity reached.'); }
            if ($json !== $raw) {
                $temporary = $this->path('state.tmp');
                $handle = @fopen($temporary, 'wb');
                if (!$handle) { throw new \RuntimeException('Cannot prepare throttle state.'); }
                try {
                    if ((DIRECTORY_SEPARATOR !== '\\' && !@chmod($temporary, 0600))
                        || @fwrite($handle, $json) !== strlen($json) || !@fflush($handle)) {
                        throw new \RuntimeException('Cannot write throttle state.');
                    }
                } finally { fclose($handle); }
                if (!@rename($temporary, $path)) { throw new \RuntimeException('Cannot commit throttle state.'); }
                $temporary = null;
            }
        } finally {
            if ($temporary !== null) { @unlink($temporary); }
            flock($lock, LOCK_UN); fclose($lock);
        }
        $this->audit($this->events);
        return $result;
    }

    /** Best-effort aggregated audit, bounded to two 1 MiB files, never credentials. */
    private function audit(array $events)
    {
        if (!$events) { return; }
        $lock = null;
        try {
            $lock = $this->openLock('audit.lock');
            $path = $this->path('audit.jsonl');
            foreach ($events as $event) {
                $line = json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
                clearstatcache(true, $path);
                if (is_file($path) && @filesize($path) + strlen($line) > self::AUDIT_BYTES) {
                    $old = $this->path('audit.previous.jsonl');
                    if (is_file($old) && !@unlink($old)) { return; }
                    if (!@rename($path, $old)) { return; }
                }
                if (@file_put_contents($path, $line, FILE_APPEND) !== strlen($line)) { return; }
                if (DIRECTORY_SEPARATOR !== '\\') { @chmod($path, 0600); }
            }
        } catch (\Throwable $e) { /* Enforcement does not depend on the audit sink. */ }
        finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    }

    public function reportUnavailable()
    {
        // Persistent throttle for operational error logging, separate from the state lock.
        $lock = null;
        try {
            $lock = $this->openLock('health.lock');
            $path = $this->path('health.timestamp');
            $now = (int) call_user_func($this->clock);
            if (!is_file($path) || $now - (int) @file_get_contents($path) >= 60) {
                @file_put_contents($path, (string) $now);
                if (DIRECTORY_SEPARATOR !== '\\') { @chmod($path, 0600); }
                error_log('Joomla login throttle state unavailable; password login denied.');
                $this->audit(array(array('event' => 'store_unavailable', 'time' => $now)));
            }
        } catch (\Throwable $e) { /* A failing store must still deny login. */ }
        finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    }
}
