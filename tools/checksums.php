<?php
// Run after changing replacement files or the manifest version; commit the updated inventory.
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__);
$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/files', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if (!$entry->isFile() || $entry->isLink()) { throw new RuntimeException('Unexpected package entry'); }
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root . '/files/')));
    $files[$relative] = hash_file('sha256', $entry->getPathname());
}
ksort($files);
$manifest = simplexml_load_file($root . '/joomla3eolsecurityfixes.xml');
$inventory = array('version' => (string) $manifest->version, 'files' => $files);
$dependencies = json_decode(file_get_contents($root . '/DEPENDENCIES.json'), true);
foreach (array('additions', 'removals', 'accepted_targets') as $key) {
    if (!isset($dependencies[$key]) || !is_array($dependencies[$key])) { throw new RuntimeException('Invalid dependency inventory'); }
    $inventory[$key] = $dependencies[$key];
}
foreach ($dependencies['upstream_files'] as $path => $digest) {
    if (!isset($files[$path]) || $files[$path] !== $digest) { throw new RuntimeException('Official dependency file changed: ' . $path); }
}
// Non-vendor additions/removals are maintained separately from upstream provenance.
$plan = json_decode(file_get_contents($root . '/INSTALLATION.json'), true);
foreach (array('additions', 'removals', 'accepted_targets') as $key) {
    if (!isset($plan[$key]) || !is_array($plan[$key])) { throw new RuntimeException('Invalid installation plan'); }
    if ($key === 'additions') {
        $inventory[$key] = array_values(array_unique(array_merge($inventory[$key], $plan[$key])));
    } else {
        if (array_intersect(array_keys($inventory[$key]), array_keys($plan[$key]))) { throw new RuntimeException('Conflicting installation plan'); }
        $inventory[$key] = array_merge($inventory[$key], $plan[$key]);
    }
}
$json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (in_array('--check', $argv, true)) {
    if (@file_get_contents($root . '/checksums.json') !== $json) { fwrite(STDERR, "Package checksums are stale. Run php tools/checksums.php.\n"); exit(1); }
    echo count($files) . " package checksums verified\n";
} else {
    if (file_put_contents($root . '/checksums.json', $json) !== strlen($json)) { throw new RuntimeException('Cannot write inventory'); }
    echo count($files) . " package checksums written\n";
}
