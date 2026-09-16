<?php
// Local regression cases for gaps found by the September 2026 inventory.
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
define('_JEXEC', 1); define('JPATH_PLATFORM', __DIR__);
$root = isset($argv[1]) ? $argv[1] : dirname(__DIR__) . '/files';
require $root . '/libraries/vendor/joomla/filter/src/InputFilter.php';
require $root . '/libraries/src/Filter/InputFilter.php';
$checks = 0; $failures = 0;
function verifyRegression($condition, $label) {
    global $checks, $failures; $checks++;
    if (!$condition) { $failures++; echo 'FAIL ' . $label . "\n"; }
}
$unstripped = Joomla\CMS\Filter\InputFilter::getInstance(array(), array(), 0, 0, 1, 0);
$stripped = Joomla\CMS\Filter\InputFilter::getInstance(array(), array(), 0, 0, 1, 1);
verifyRegression($unstripped !== $stripped, 'different security settings do not share an instance');
verifyRegression($stripped->stripUSC === 1, 'requested stripping retained');
verifyRegression($stripped->clean("A\xf0\x9f\x98\x80B", 'RAW') === "A\xe2\xaf\x91B", 'supplementary character removed');
verifyRegression($unstripped->clean("A\xf0\x9f\x98\x80B", 'RAW') === "A\xf0\x9f\x98\x80B", 'non-stripping policy preserved');
verifyRegression(Joomla\CMS\Filter\InputFilter::getInstance(array(), array(), 0, 0, 1, 1) === $stripped, 'identical policy cache reused');
foreach (array("java\tscript:marker", 'java&#x09;script:marker', 'java script:marker', 'data:image/svg+xml;base64,PHN2Zy8+') as $value) {
    verifyRegression(Joomla\Filter\InputFilter::checkAttribute(array('src', $value)) === true, 'existing filter rejects obfuscation/data SVG');
}
verifyRegression(Joomla\Filter\InputFilter::checkAttribute(array('href', 'https://example.invalid/')) === false, 'safe URL retained');
class JHtml { public static function _($name) {} }
class JFactory { public static function getLanguage() { return new self; } public function isRtl() { return false; } }
class JText { public static function sprintf($key, $value) { return $key . ' ' . $value; } }
$rows = array((object) array('title' => 'Previous'), (object) array('title' => 'Current'), (object) array('title' => 'Next'));
$location = 1;
foreach (array('Ordinary & readable', '<b data-review="marker">title</b>') as $label) {
    $row = (object) array('prev' => '/previous', 'next' => '/next', 'prev_label' => $label, 'next_label' => $label);
    ob_start(); require $root . '/plugins/content/pagenavigation/tmpl/default.php'; $output = ob_get_clean();
    verifyRegression(substr_count($output, htmlspecialchars($label, ENT_QUOTES, 'UTF-8')) === 2, 'navigation labels escaped in both directions');
    verifyRegression(strpos($output, 'href="/previous"') !== false && strpos($output, 'href="/next"') !== false, 'navigation links retained');
}
echo $checks . ' post-EOL checks, ' . $failures . " failures\n";
exit($failures ? 1 : 0);
