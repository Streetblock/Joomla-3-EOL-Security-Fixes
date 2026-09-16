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
$json = json_encode(array('version' => (string) $manifest->version, 'files' => $files), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (in_array('--check', $argv, true)) {
    if (@file_get_contents($root . '/checksums.json') !== $json) { fwrite(STDERR, "Package checksums are stale. Run php tools/checksums.php.\n"); exit(1); }
    echo count($files) . " package checksums verified\n";
} else {
    if (file_put_contents($root . '/checksums.json', $json) !== strlen($json)) { throw new RuntimeException('Cannot write inventory'); }
    echo count($files) . " package checksums written\n";
}
