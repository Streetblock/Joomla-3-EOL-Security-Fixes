<?php
/**
 * Isolated regression tests for CVE-2026-71572. Run with PHP CLI only.
 * An optional first argument selects another Joomla source root for a negative control.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('_JEXEC', 1);
error_reporting(E_ALL & ~E_DEPRECATED);

class JViewLegacy
{
    public $data = array();
    public function get($key) { return $this->data[$key]; }
}
class ReviewApplication
{
    public $headers = array();
    public $messages = array();
    public function setHeader($name, $value, $replace) { $this->headers[strtolower($name)] = $value; return $this; }
    public function enqueueMessage($message, $type) { $this->messages[] = array($message, $type); }
}
class ReviewDocument
{
    public function setMimeEncoding($type, $sync = false) {}
}
class ReviewUser
{
    public function getAuthorisedViewLevels() { return array(1); }
}
class ReviewDate
{
    public function toRFC822() { return 'Wed, 16 Sep 2026 12:00:00 +0000'; }
}
class JFactory
{
    public static $app;
    public static function getApplication() { return self::$app; }
    public static function getDocument() { return new ReviewDocument; }
    public static function getUser() { return new ReviewUser; }
    public static function getDate() { return new ReviewDate; }
}
class JText
{
    public static function _($key) { return $key; }
}
class JError
{
    public static function raiseWarning($code, $message) { throw new RuntimeException($message); }
}

$root = isset($argv[1]) ? $argv[1] : dirname(__DIR__) . '/files';
require $root . '/components/com_contact/views/contact/view.vcf.php';
require $root . '/administrator/components/com_banners/views/tracks/view.raw.php';
$failures = 0;
$checks = 0;
function checkResult($condition, $label)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) { $failures++; }
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function renderView($view)
{
    JFactory::$app = new ReviewApplication;
    ob_start();
    try { $view->display(); return ob_get_contents(); }
    finally { ob_end_clean(); }
}

$contactNames = array(
    array('Erika Muster', 'Erika Muster'),
    array('Jörg Müller', 'Jörg Müller'),
    array('Erika "filename=other" Muster', 'Erika filename=other Muster'),
    array('Muster, Erika', 'Erika Muster'),
);
foreach ($contactNames as $index => $names)
{
    $item = (object) array_fill_keys(array('con_position', 'telephone', 'fax', 'mobile', 'address', 'suburb', 'state', 'postcode', 'country', 'email_to', 'webpage'), '');
    $item->name = $names[0];
    $item->modified = '2026-09-16 12:00:00';
    $item->access = 1;
    $item->category_access = 1;
    $view = new ContactViewContact;
    $view->data = array('Item' => $item, 'Errors' => array());
    $output = renderView($view);
    checkResult(JFactory::$app->headers['content-disposition'] === 'attachment; filename="' . $names[1] . '.vcf"', 'contact header ' . $index);
    checkResult(strpos($output, 'FN:' . $names[0] . "\n") !== false, 'contact content preserved ' . $index);
}

foreach (array('access', 'category_access') as $accessField)
{
    $item = (object) array('access' => 1, 'category_access' => 1);
    $item->$accessField = 2;
    $view = new ContactViewContact;
    $view->data = array('Item' => $item, 'Errors' => array());
    $output = renderView($view);
    checkResult($output === '' && !isset(JFactory::$app->headers['content-disposition']) && JFactory::$app->headers['status'] === 403, 'contact denied ' . $accessField);
}

$bannerNames = array(
    array('tracking-report', 'tracking-report'),
    array('Übersicht September', 'Übersicht September'),
    array('report"; filename="other', 'report; filename=other'),
);
foreach ($bannerNames as $index => $names)
{
    $view = new BannersViewTracks;
    $view->data = array('BaseName' => $names[0], 'FileType' => 'csv', 'MimeType' => 'text/csv', 'Content' => "a,b\n1,2\n", 'Errors' => array());
    $output = renderView($view);
    checkResult(JFactory::$app->headers['content-disposition'] === 'attachment; filename="' . $names[1] . '.csv"; creation-date="Wed, 16 Sep 2026 12:00:00 +0000"', 'banner header ' . $index);
    checkResult($output === "a,b\n1,2\n", 'banner content preserved ' . $index);
}
echo $checks . ' checks, ' . $failures . " failures\n";
exit($failures ? 1 : 0);
