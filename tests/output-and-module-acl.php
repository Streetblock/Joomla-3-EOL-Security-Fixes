<?php
// Render replacement layouts locally with harmless markup markers and Joomla doubles.
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
define('_JEXEC', 1); define('JPATH_PLATFORM', __DIR__); define('JPATH_ADMINISTRATOR', __DIR__);
define('JPATH_COMPONENT_ADMINISTRATOR', __DIR__);
define('JPATH_COMPONENT', __DIR__);
$original = isset($argv[1]) ? $argv[1] : null;
if (!$original) { fwrite(STDERR, "Usage: php tests/output-and-module-acl.php ORIGINAL_JOOMLA_ROOT [PATCH_ROOT]\n"); exit(2); }
$root = isset($argv[2]) ? $argv[2] : dirname(__DIR__) . '/files';
require $original . '/libraries/vendor/joomla/string/src/phputf8/utf8.php';
require $original . '/libraries/vendor/joomla/string/src/phputf8/trim.php';
require $original . '/libraries/vendor/joomla/string/src/StringHelper.php';
require $root . '/libraries/cms/html/string.php';
$checks = 0; $failures = 0;
function verifyOutput($ok, $label) {
    global $checks, $failures; $checks++;
    if (!$ok) { $failures++; echo "FAIL $label\n"; }
}
class Params { public $values = array(); public function get($key, $default = null, $type = null) { return isset($this->values[$key]) ? $this->values[$key] : $default; } }
class Language { public function isRtl() { return false; } public function load($key, $path) {} }
class JFactory {
    public static $user; public static $app;
    public static function getLanguage() { return new Language; }
    public static function getUser() { return self::$user; }
    public static function getApplication() { return self::$app; }
}
class JText { public static function _($key) { return $key; } public static function sprintf($key, ...$args) { return $key . ' ' . implode(' ', $args); } }
class JRoute { public static function _($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); } }
class JHtml {
    public static function addIncludePath($path) {}
    public static function _($key, ...$args) {
        if ($key === 'string.truncate') { return call_user_func_array(array('JHtmlString', 'truncate'), $args); }
        return '';
    }
}
class JArrayHelper {
    public static function toString($values) {
        $result = array(); foreach ($values as $key => $value) { $result[] = $key . '="' . $value . '"'; }
        return implode(' ', $result);
    }
}
#[AllowDynamicProperties]
class OutputView {
    public function escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    public function getInput($name) { return ''; }
    public function removeField($name, $group) {}
    public function render($file, $displayData = array()) { ob_start(); include $file; return ob_get_clean(); }
    public function expressions($source, $item) { $profile = $item; ob_start(); eval('?>' . $source); return ob_get_clean(); }
}
$view = new OutputView;
$marker = '" data-review="marker"><b>review</b>';
$image = $view->render($root . '/layouts/joomla/html/image.php', array('src' => '/image?a=1&b=2', 'alt' => false, 'class' => $marker, 'data-id' => '123', 'bad"name' => 'x'));
verifyOutput(strpos($image, 'class="' . $view->escape($marker) . '"') !== false, 'image arbitrary attribute value escaped');
verifyOutput(strpos($image, 'bad"name') === false, 'image invalid attribute name omitted');
verifyOutput(strpos($image, 'alt=') === false && strpos($image, 'data-id="123"') !== false, 'image optional alt and valid data attributes retained');
verifyOutput(strpos($image, '/image?a=1&amp;b=2') !== false && strpos($image, '&amp;amp;') === false, 'image URL escaped exactly once');
$params = new Params; $params->values = array('access-view' => true, 'show_readmore_title' => 1, 'readmore_limit' => 200);
foreach (array('', 'Read on') as $alternative) {
    $item = (object) array('title' => '<b data-review="marker">Ordinary title</b>', 'alternative_readmore' => $alternative);
    $out = $view->render($root . '/layouts/joomla/content/readmore.php', array('params' => $params, 'item' => $item, 'link' => '/article'));
    verifyOutput(strpos($out, '<b data-review=') === false && strpos($out, 'Ordinary title') !== false, 'readmore title strips markup in each branch');
    verifyOutput(strpos($out, 'href="/article"') !== false, 'readmore link retained');
}
$view->app = (object) array('input' => new Params); $view->form = $view; $view->itemtype = 'com_content.article';
$view->editUri = '/edit'; $view->defaultTargetSrc = '/target';
foreach (array('typeName', 'referenceId', 'referenceTitle', 'referenceTitleValue', 'referenceLanguage', 'targetAction', 'targetId', 'targetTitle', 'targetLanguage') as $prop) { $view->$prop = $marker; }
$out = $view->render($root . '/administrator/components/com_associations/views/association/tmpl/edit.php');
foreach (array('item', 'id', 'title', 'language') as $attribute) {
    verifyOutput(substr_count($out, 'data-' . $attribute . '="' . $view->escape($marker) . '"') === 2, 'association ' . $attribute . ' escaped in both frames');
}
verifyOutput(strpos($out, 'data-action="' . $view->escape($marker) . '"') !== false, 'association target action escaped');
// Evaluate actual output expressions from large admin templates without bootstrapping their UI.
$item = (object) array('version' => $marker, 'current_version' => $marker, 'detailsurl' => $marker, 'infourl' => $marker);
$source = file_get_contents($root . '/administrator/components/com_installer/views/update/tmpl/default.php');
foreach (array('version', 'current_version', 'detailsurl', 'infourl') as $prop) {
    preg_match_all('/<\?php echo [^;\n]*\$item->' . $prop . '[^;\n]*; \?>/', $source, $matches);
    verifyOutput(count($matches[0]) > 0, 'installer output expression found: ' . $prop);
    foreach ($matches[0] as $expression) { verifyOutput($view->expressions($expression, $item) === $view->escape($marker), 'installer ' . $prop . ' escaped'); }
}
$view->source = (object) array('filename' => $marker); $view->template = (object) array('element' => 'example');
$source = file_get_contents($root . '/administrator/components/com_templates/views/template/tmpl/default.php');
preg_match_all('/<\?php echo [^;\n]*\$this->source->filename[^;\n]*; \?>/', $source, $matches);
verifyOutput(count($matches[0]) === 2, 'template filename expressions found');
foreach ($matches[0] as $expression) { verifyOutput(strpos($view->expressions($expression, null), '<b>') === false, 'template filename escaped'); }
// A conversion double emits markup to verify escaping happens AFTER conversion.
class JStringPunycode {
    public static function emailToUTF8($value) { return '<b>converted</b>'; }
    public static function urlToUTF8($value) { return '<b>converted</b>'; }
}
$view->contact = (object) array('webpage' => 'https://example.invalid/');
$item = (object) array('email' => 'example@example.invalid', 'text' => 'https://example.invalid/');
foreach (array('administrator/components/com_users/views/users/tmpl/default.php',
    'components/com_contact/views/contact/tmpl/default_address.php',
    'components/com_contact/views/contact/tmpl/default_profile.php') as $path) {
    $source = file_get_contents($root . '/' . $path);
    preg_match_all('/echo [^;\n]*JStringPunycode::[^;\n]*;/', $source, $matches);
    verifyOutput(count($matches[0]) > 0, 'Punycode expressions found: ' . $path);
    foreach ($matches[0] as $expression) {
        $out = $view->expressions('<?php ' . $expression . ' ?>', $item);
        verifyOutput(strpos($out, '<b>converted</b>') === false && strpos($out, '&lt;b&gt;converted&lt;/b&gt;') !== false, 'converted address escaped');
    }
}
class ModuleUser { public $allow; public $calls = array(); public function authorise($action, $asset) { $this->calls[] = "$action:$asset"; return $this->allow; } }
class ModuleApp { public $input; public $site = true; public function enqueueMessage($text, $level) {} public function isClient($client) { return $client === 'site' && $this->site; } }
class JControllerLegacy {
    public static $executed = false;
    public static function getInstance($name, $config) { return new self; }
    public function execute($task) { self::$executed = true; }
    public function redirect() {}
}
foreach (array(false, true) as $allow) {
    foreach (array(array('view' => 'modules', 'layout' => 'modal'), array('view' => 'module'), array('task' => 'module.orderPosition')) as $request) {
        JFactory::$user = new ModuleUser; JFactory::$user->allow = $allow;
        JFactory::$app = new ModuleApp; JFactory::$app->input = new Params; JFactory::$app->input->values = $request;
        JControllerLegacy::$executed = false;
        include $root . '/components/com_modules/modules.php';
        verifyOutput(JControllerLegacy::$executed === $allow, 'module dispatcher respects permission for all request shapes');
        verifyOutput(JFactory::$user->calls === array('module.edit.frontend:com_modules'), 'module uses frontend edit permission');
    }
}
class JSession { public static function getFormToken() { return 'testToken'; } }
class ModalPagination { public $extra = array(); public function setAdditionalUrlParam($key, $value) { $this->extra[$key] = $value; } }
#[AllowDynamicProperties]
class JViewLegacy {
    public static $data;
    public function get($key) { return self::$data[$key]; }
    public function getLayout() { return 'modal'; }
    public function display($tpl = null) { return true; }
}
require $root . '/administrator/components/com_modules/views/modules/view.html.php';
foreach (array(false, true) as $site) {
    JFactory::$app->site = $site;
    $pagination = new ModalPagination;
    JViewLegacy::$data = array('Items' => array(), 'Pagination' => $pagination, 'State' => new Params,
        'Total' => 0, 'FilterForm' => new OutputView, 'ActiveFilters' => array(), 'Errors' => array());
    $modulesView = new ModulesViewModules; $modulesView->display();
    verifyOutput($pagination->extra === ($site ? array('testToken' => '1') : array()), 'module modal pagination retains frontend CSRF token only');
}
echo "$checks output/module checks, $failures failures\n";
exit($failures ? 1 : 0);
