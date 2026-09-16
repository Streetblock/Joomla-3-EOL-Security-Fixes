<?php
// Real filesystem + actual guard/authentication/application login code; no website or DB.
namespace Joomla\CMS\Plugin {
    class PluginHelper {
        public static function importPlugin($type) { return true; }
        public static function getPlugin($type) { return array((object) array('name' => 'joomla', 'type' => 'authentication')); }
    }
}
namespace Joomla\String { class StringHelper { public static function strtolower($s) { return strtolower($s); } } }
namespace {
    if (PHP_SAPI !== 'cli') { exit(2); }
    define('JPATH_PLATFORM', __DIR__);
    define('JPATH_ROOT', dirname(__DIR__));
    require dirname(__DIR__) . '/files/libraries/src/Authentication/LoginThrottle.php';
    use Joomla\CMS\Authentication\LoginThrottle;
    if (isset($argv[1]) && $argv[1] === '--worker') {
        $guard = new LoginThrottle($argv[2], str_repeat('s', 32), array('ip_limit' => 5, 'account_limit' => 5));
        for ($i = 0; $i < 100; $i++) {
            try {
                $ticket = $guard->reserve('192.0.2.50', 'parallel');
                if ($ticket === null) { echo 'denied'; exit; }
                // Hold the reservation across process overlap, without holding the store lock.
                usleep(20000);
                for ($j = 0; $j < 100; $j++) {
                    try { $guard->complete($ticket, false); echo 'admitted'; exit; }
                    catch (\RuntimeException $e) { usleep(2000); }
                }
                exit(3);
            } catch (\RuntimeException $e) { usleep(2000); }
        }
        exit(3);
    }
    $original = isset($argv[1]) ? realpath($argv[1]) : realpath(dirname(__DIR__) . '/../reference/joomla-source/joomla-cms-3.10.12');
    if (!$original || !is_file($original . '/libraries/src/Application/CMSApplication.php')) {
        fwrite(STDERR, "Usage: php -n tests/login-throttle.php ORIGINAL_JOOMLA_3_10_12_ROOT\n"); exit(2);
    }
    $base = sys_get_temp_dir() . '/joomla-throttle-' . bin2hex(random_bytes(12));
    mkdir($base, 0700);
    $clock = 100000; $counter = 0; $checks = 0;
    function expectThrottle($ok, $label) {
        global $checks; $checks++;
        if (!$ok) { throw new \RuntimeException($label); }
    }
    function fixtureThrottle(array $policy = array()) {
        global $base, $clock, $counter;
        $directory = $base . '/case-' . ++$counter;
        mkdir($directory, 0700);
        return array(new LoginThrottle($directory, str_repeat('s', 32), array_merge(array('ip_limit' => 3,
            'account_limit' => 3, 'window' => 60, 'cooldown' => 10, 'max_cooldown' => 40, 'reservation_ttl' => 5), $policy),
            function () use (&$clock) { return $clock; }), $directory);
    }
    function failThrottle($guard, $ip, $account) {
        $ticket = $guard->reserve($ip, $account);
        expectThrottle($ticket !== null, 'expected an admitted attempt');
        $guard->complete($ticket, false);
    }
    function cleanupThrottle($path, $base) {
        if ($path !== $base && strpos($path, $base . '/') !== 0) { throw new \RuntimeException('Unsafe fixture cleanup'); }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { cleanupThrottle($path . '/' . $name, $base); } }
            rmdir($path);
        } else { unlink($path); }
    }
    try {
        list($guard, $dir) = fixtureThrottle();
        foreach (array('a', 'b', 'c') as $name) { failThrottle($guard, '192.0.2.1', $name); }
        expectThrottle($guard->reserve('192.0.2.1', 'unrelated') === null, 'IP blocks other accounts after threshold');
        $reloaded = new LoginThrottle($dir, str_repeat('s', 32), array('ip_limit' => 3, 'account_limit' => 3), function () use (&$clock) { return $clock; });
        expectThrottle($reloaded->blockedIp('192.0.2.1'), 'state persists across instances');
        $before = json_decode(file_get_contents($dir . '/state.json'), true);
        for ($i = 0; $i < 100; $i++) { expectThrottle($guard->blockedIp('192.0.2.1'), 'repeated denial'); }
        $after = json_decode(file_get_contents($dir . '/state.json'), true);
        $key = array_keys($before['entries'])[0];
        expectThrottle($before['entries'][$key]['until'] === $after['entries'][$key]['until'], 'denials do not extend block');
        expectThrottle($after['entries'][$key]['failed'] === 3, 'denials are not failed password checks');
        expectThrottle(count(file($dir . '/audit.jsonl')) <= 3, 'blocked traffic audit is aggregated');
        $clock += 10;
        expectThrottle(!$guard->blockedIp('192.0.2.1'), 'exact cooldown boundary permits retry');
        foreach (array('a', 'b', 'c') as $name) { failThrottle($guard, '192.0.2.1', $name); }
        $state = json_decode(file_get_contents($dir . '/state.json'), true);
        expectThrottle($state['entries'][$key]['until'] === $clock + 20, 'repeat offence doubles cooldown');
        $clock += 20;
        foreach (array('a', 'b', 'c') as $name) { failThrottle($guard, '192.0.2.1', $name); }
        $clock += 40;
        foreach (array('a', 'b', 'c') as $name) { failThrottle($guard, '192.0.2.1', $name); }
        $state = json_decode(file_get_contents($dir . '/state.json'), true);
        expectThrottle($state['entries'][$key]['until'] === $clock + 40, 'cooldown is capped');

        list($guard, $dir) = fixtureThrottle(array('ip_limit' => 10));
        foreach (array('192.0.2.1', '192.0.2.2', '192.0.2.3') as $ip) { failThrottle($guard, $ip, 'same-account'); }
        expectThrottle($guard->reserve('198.51.100.9', 'same-account') === null, 'distributed attempts share account budget');
        $ticket = $guard->reserve('198.51.100.9', 'other-account');
        expectThrottle($ticket !== null && $guard->complete($ticket, true), 'unrelated account/IP still works');

        list($guard, $dir) = fixtureThrottle(array('ip_limit' => 10));
        failThrottle($guard, '192.0.2.1', 'account');
        $clock += 60;
        $ticket = $guard->reserve('192.0.2.1', 'account');
        $state = json_decode(file_get_contents($dir . '/state.json'), true);
        expectThrottle($state['entries'][$ticket['keys'][1]]['failed'] === 0, 'observation window resets old failures');
        $guard->complete($ticket, false);
        $ticket = $guard->reserve('192.0.2.1', 'account');
        expectThrottle($guard->complete($ticket, true), 'valid login before threshold accepted');
        $state = json_decode(file_get_contents($dir . '/state.json'), true);
        expectThrottle($state['entries'][$ticket['keys'][1]]['failed'] === 0, 'success resets account failures');
        expectThrottle($state['entries'][$ticket['keys'][0]]['failed'] === 1, 'success does not erase IP failures');

        list($guard, $dir) = fixtureThrottle();
        $tickets = array();
        for ($i = 0; $i < 3; $i++) { $tickets[] = $guard->reserve('192.0.2.1', 'parallel'); }
        expectThrottle($guard->reserve('192.0.2.1', 'parallel') === null, 'in-flight attempts reserve capacity');
        $clock += 5;
        expectThrottle($guard->blockedIp('192.0.2.1'), 'abandoned reservations consume failure budget');
        expectThrottle(!$guard->complete($tickets[0], true), 'late success cannot bypass an active block');
        $clock += 10;
        expectThrottle(!$guard->blockedIp('192.0.2.1'), 'expired in-flight attempts cannot extend cooldown indefinitely');

        list($guard, $dir) = fixtureThrottle(array('ip_limit' => 1));
        $oldTicket = $guard->reserve('192.0.2.1', 'abandoned');
        $clock += 86400;
        expectThrottle(!$guard->blockedIp('192.0.2.1'), 'long-expired abandoned request does not start a fresh block');
        expectThrottle(!$guard->complete($oldTicket, true), 'long-expired successful response cannot authenticate');
        expectThrottle($guard->reserve('192.0.2.1', 'abandoned') !== null, 'fresh attempt after dormant window allowed');

        list($guard, $dir) = fixtureThrottle(array('ip_limit' => 1, 'account_limit' => 10));
        failThrottle($guard, '::ffff:192.0.2.2', 'v4');
        expectThrottle($guard->blockedIp('192.0.2.2'), 'IPv4 mapped IPv6 shares source bucket');
        failThrottle($guard, '2001:db8:1:2::1', 'v6');
        expectThrottle($guard->blockedIp('2001:0db8:0001:0002::abcd'), 'IPv6 addresses in one /64 share bucket');
        expectThrottle(!$guard->blockedIp('2001:db8:1:3::1'), 'different IPv6 /64 remains independent');

        list($guard, $dir) = fixtureThrottle(array('max_entries' => 2));
        $guard->reserve('192.0.2.1', 'bounded');
        $thrown = false;
        try { $guard->reserve('192.0.2.2', 'new'); } catch (\RuntimeException $e) { $thrown = true; }
        expectThrottle($thrown && count(json_decode(file_get_contents($dir . '/state.json'), true)['entries']) === 2, 'capacity cannot evict active counters');
        file_put_contents($dir . '/state.json', '{broken');
        $thrown = false;
        try { $guard->blockedIp('192.0.2.1'); } catch (\RuntimeException $e) { $thrown = true; }
        expectThrottle($thrown, 'corrupt state fails closed');
        expectThrottle(file_get_contents($dir . '/state.json') === '{broken', 'corruption is not silently reset');
        file_put_contents($dir . '/state.json', '');
        $thrown = false;
        try { $guard->blockedIp('192.0.2.1'); } catch (\RuntimeException $e) { $thrown = true; }
        expectThrottle($thrown, 'empty existing state fails closed');
        file_put_contents($dir . '/state.json', str_repeat('x', LoginThrottle::MAX_BYTES + 1));
        $thrown = false;
        try { $guard->blockedIp('192.0.2.1'); } catch (\RuntimeException $e) { $thrown = true; }
        expectThrottle($thrown, 'oversized existing state fails closed');
        list($guard, $dir) = fixtureThrottle();
        $lock = fopen($dir . '/state.lock', 'c'); flock($lock, LOCK_EX);
        $thrown = false;
        try { $guard->blockedIp('192.0.2.1'); } catch (\RuntimeException $e) { $thrown = true; }
        expectThrottle($thrown, 'busy store fails closed without waiting for a lock');
        flock($lock, LOCK_UN); fclose($lock);
        list($guard, $dir) = fixtureThrottle();
        mkdir($dir . '/state.tmp', 0700);
        $thrown = false;
        try { $guard->reserve('192.0.2.1', 'write-failure'); } catch (\RuntimeException $e) { $thrown = true; }
        expectThrottle($thrown && !is_file($dir . '/state.json'), 'uncommittable reservation cannot admit authentication');

        list($guard, $dir) = fixtureThrottle(array('ip_limit' => 1));
        file_put_contents($dir . '/audit.jsonl', str_repeat('x', LoginThrottle::AUDIT_BYTES));
        failThrottle($guard, '192.0.2.1', 'no-plaintext-account');
        expectThrottle(is_file($dir . '/audit.previous.jsonl') && filesize($dir . '/audit.jsonl') < LoginThrottle::AUDIT_BYTES, 'audit rotates at size limit');
        expectThrottle(strpos(file_get_contents($dir . '/state.json'), 'no-plaintext-account') === false, 'state stores HMAC account identity');
        $thrown = false;
        try { new LoginThrottle(JPATH_ROOT, str_repeat('s', 32)); } catch (\RuntimeException $e) { $thrown = true; }
        expectThrottle($thrown, 'web-root storage refused');
        foreach (array('not-an-ip', "192.0.2.1\r\nspoof", '192.0.2.1,198.51.100.1') as $invalidIp) {
            $thrown = false;
            try { LoginThrottle::canonicalIp($invalidIp); } catch (\RuntimeException $e) { $thrown = true; }
            expectThrottle($thrown, 'invalid peer address rejected');
        }

        // Actual Authentication plugin loop and CMSApplication::login, with Joomla services doubled.
        class JObject {}
        class JText { public static function _($key) { return $key; } }
        class TestConfig {
            public $values = array();
            public function get($key, $default = null) { return isset($this->values[$key]) ? $this->values[$key] : $default; }
        }
        class JFactory {
            public static $config;
            public static function getConfig() { return self::$config; }
            public static function getUser() { return new class { public function set($key, $value) {} }; }
        }
        class JUserHelper {
            public static $lookups = 0;
            public static function getUserId($username) {
                self::$lookups++;
                return in_array($username, array('Alice', 'alice', 'Álice', 'Alice '), true) ? 42 : null;
            }
        }
        class JEventDispatcher {
            public static function getInstance() { return new self; }
            public function trigger($event, $arguments) { return array(); }
        }
        class JLog { const WARNING = 2; public static function add($message, $level, $category) {} }
        class plgauthenticationjoomla {
            public static $calls = 0;
            public function __construct($subject, $config) {}
            public function onUserAuthenticate($credentials, $options, &$response) {
                self::$calls++;
                if (isset($credentials['password']) && $credentials['password'] === 'throw') { throw new \RuntimeException('provider failed'); }
                $response->status = isset($credentials['password']) && $credentials['password'] !== 'correct' ? 4 : 1;
                $response->error_message = $response->status === 1 ? '' : 'JGLOBAL_AUTH_INVALID_PASS';
            }
        }
        require dirname(__DIR__) . '/files/libraries/src/Authentication/Authentication.php';
        require $original . '/libraries/src/Authentication/AuthenticationResponse.php';
        class_alias('Joomla\CMS\Authentication\Authentication', 'JAuthentication');
        class_alias('Joomla\CMS\Plugin\PluginHelper', 'JPluginHelper');
        function actualLoginMethod() {
            global $original;
            $source = $original . '/libraries/src/Application/CMSApplication.php';
            $started = false; $found = false; $nextName = false; $depth = 0; $code = '';
            foreach (token_get_all(file_get_contents($source)) as $token) {
                $text = is_array($token) ? $token[1] : $token;
                if (!$found) {
                    if (is_array($token) && $token[0] === T_FUNCTION) { $nextName = true; }
                    elseif ($nextName && is_array($token) && $token[0] === T_STRING) {
                        if ($text === 'login') { $found = true; $code = 'public function login'; }
                        $nextName = false;
                    }
                    continue;
                }
                $code .= $text;
                if ($token === '{') { $depth++; $started = true; }
                if ($token === '}' && --$depth === 0 && $started) { return $code; }
            }
            throw new \RuntimeException('Login method not found');
        }
        eval('class TestLoginApplication { public $events = array(); public $messages = array(); '
            . 'public function triggerEvent($event, $args) { $this->events[] = $event; return array(); } '
            . 'public function getLogger() { return $this; } public function warning($message, $context) { $this->messages[] = $message; } '
            . actualLoginMethod() . '}');
        list($guard, $dir) = fixtureThrottle();
        JFactory::$config = new TestConfig;
        JFactory::$config->values = array('eol_login_throttle' => true, 'eol_login_throttle_path' => $dir,
            'secret' => str_repeat('s', 32), 'eol_login_throttle_policy' => array('ip_limit' => 2, 'account_limit' => 2));
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $app = new TestLoginApplication;
        $credentials = array('username' => 'Alice', 'password' => 'wrong-secret');
        expectThrottle($app->login($credentials) === false, 'ordinary failed login');
        $firstMessage = $app->messages;
        expectThrottle($app->login($credentials) === false, 'threshold failed login');
        $calls = plgauthenticationjoomla::$calls; $lookups = JUserHelper::$lookups;
        $app = new TestLoginApplication;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
        $credentials['password'] = 'correct';
        expectThrottle($app->login($credentials) === false, 'correct password from blocked source denied');
        expectThrottle(plgauthenticationjoomla::$calls === $calls && JUserHelper::$lookups === $lookups, 'blocked source skips plugin chain and identity query');
        expectThrottle($app->messages === $firstMessage, 'blocked and incorrect responses use same stock message');
        expectThrottle($app->events === array('onUserLoginFailure'), 'blocked request cannot create authenticated session or success events');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
        $credentials['username'] = 'Álice';
        expectThrottle($app->login($credentials) === false && plgauthenticationjoomla::$calls === $calls, 'new IP and username alias cannot bypass account limit');
        $app = new TestLoginApplication;
        expectThrottle($app->login($credentials, array('silent' => true)) === false && !$app->messages, 'silent login convention retained');
        $credentials['username'] = 'Bob';
        expectThrottle($app->login($credentials) === true, 'unrelated account remains usable');
        expectThrottle(in_array('onUserAfterLogin', $app->events, true), 'successful login follows stock application path');
        $audit = file_get_contents($dir . '/audit.jsonl');
        expectThrottle(strpos($audit, 'wrong-secret') === false && strpos($audit, 'Alice') === false, 'audit excludes passwords and plaintext usernames');
        expectThrottle(strpos($audit, '192.0.2.10') !== false, 'audit records canonical source IP');
        JFactory::$config->values['eol_login_throttle'] = false;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $credentials['username'] = 'Alice';
        expectThrottle($app->login($credentials) === true, 'disabled feature preserves original login path');
        JFactory::$config->values['eol_login_throttle'] = true;
        $calls = plgauthenticationjoomla::$calls;
        JAuthentication::getInstance()->authenticate(array('username' => ''), array('silent' => true));
        expectThrottle(plgauthenticationjoomla::$calls === $calls + 1, 'remember-me without a password stays on stock path');
        file_put_contents($dir . '/state.json', 'broken');
        expectThrottle($app->login($credentials) === false, 'broken state cannot fail open to correct credentials');
        expectThrottle(plgauthenticationjoomla::$calls === $calls + 1, 'broken state does not execute authentication plugins');
        list($guard, $dir) = fixtureThrottle();
        JFactory::$config->values['eol_login_throttle_path'] = $dir;
        JFactory::$config->values['eol_login_throttle_policy'] = array('ip_limit' => 1, 'account_limit' => 2);
        $bad = JAuthentication::getInstance()->authenticate(array('username' => 'Alice', 'password' => 'incorrect'));
        $blocked = JAuthentication::getInstance()->authenticate(array('username' => 'Alice', 'password' => 'correct'));
        expectThrottle(get_object_vars($bad) === get_object_vars($blocked), 'entire failure response matches for wrong and blocked-correct credentials');
        expectThrottle($blocked->password === '', 'denied response carries no password');

        list($guard, $dir) = fixtureThrottle();
        JFactory::$config->values['eol_login_throttle_path'] = $dir;
        $response = JAuthentication::getInstance()->authenticate(array('username' => 'Alice', 'password' => 'throw'));
        expectThrottle($response->status === 4 && $response->error_message === 'JGLOBAL_AUTH_INVALID_PASS', 'provider exception uses ordinary failure');
        $response = JAuthentication::getInstance()->authenticate(array('username' => 'Alice', 'password' => 'correct'));
        expectThrottle($response->status === 4, 'provider exception does not leak a reserved slot');

        // Multiple PHP processes contend on a shared store, including pending reservations.
        list($guard, $dir) = fixtureThrottle();
        $workers = array();
        for ($i = 0; $i < 12; $i++) {
            $process = proc_open(array(PHP_BINARY, '-n', __FILE__, '--worker', $dir), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            if (!is_resource($process)) { throw new \RuntimeException('Cannot start concurrency worker'); }
            fclose($pipes[0]);
            $workers[] = array($process, $pipes);
        }
        $admitted = 0;
        foreach ($workers as list($process, $pipes)) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            expectThrottle(proc_close($process) === 0 && $err === '', 'concurrency worker completed');
            expectThrottle(in_array($out, array('admitted', 'denied'), true), 'valid worker result');
            $admitted += $out === 'admitted' ? 1 : 0;
        }
        expectThrottle($admitted === 5, 'exactly five of twelve concurrent attempts admitted');
        $state = json_decode(file_get_contents($dir . '/state.json'), true);
        foreach ($state['entries'] as $entry) {
            expectThrottle($entry['failed'] === 5 && !$entry['pending'], 'parallel failure counts committed without lost updates');
        }
        echo "$checks login throttle checks passed on PHP " . PHP_VERSION . "\n";
    } catch (\Throwable $e) { fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); $failed = true; }
    finally { cleanupThrottle($base, $base); }
    exit(!empty($failed) ? 1 : 0);
}
