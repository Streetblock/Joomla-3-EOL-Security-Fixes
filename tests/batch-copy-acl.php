<?php
// Exercise the actual production batchCopy methods with isolated Joomla/DB doubles.
// No database is contacted. An exception marks the first authorized mutation.
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
$root = isset($argv[1]) ? $argv[1] : dirname(__DIR__) . '/files';
$checks = 0; $failures = 0;
function checkAcl($ok, $label) {
    global $checks, $failures; $checks++;
    if (!$ok) { $failures++; echo "FAIL $label\n"; }
}
function productionMethod($file) {
    $tokens = token_get_all(file_get_contents($file));
    $found = false; $started = false; $depth = 0; $code = ''; $function = false;
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (!$found) {
            if (is_array($token) && $token[0] === T_FUNCTION) { $function = true; }
            elseif ($function && is_array($token) && $token[0] === T_STRING) {
                if ($text === 'batchCopy') { $found = true; $code = 'public function batchCopy'; }
                $function = false;
            }
            continue;
        }
        $code .= $text;
        if ($token === '{') { $depth++; $started = true; }
        if ($token === '}' && --$depth === 0 && $started) { return $code; }
    }
    throw new RuntimeException('Method not found: ' . $file);
}
class MutationReached extends RuntimeException {}
class AclUser {
    public $allowed = array(); public $calls = array();
    public function authorise($action, $asset) {
        $key = $action . ':' . $asset; $this->calls[] = $key;
        return in_array($key, $this->allowed, true);
    }
}
class JFactory {
    public static $user;
    public static function getUser() { return self::$user; }
    public static function getApplication() { return (object) array('input' => new AclInput); }
}
class AclInput { public function get($key, $default = null, $type = null) { return 'com_content'; } }
class JText { public static function _($key) { return $key; } public static function sprintf($key, $value) { return $key; } }
class JUcmType { public function getTypeByAlias($alias) { return null; } }
class ArrayHelper { public static function getValue($values, $key, $default, $type = null) { return isset($values[$key]) ? $values[$key] : $default; } }
class AclState { public function get($key) { return 'com_content'; } }
class AclTable {
    public $asset_id = 2; public $catid = 7; public $context = 'com_content.article';
    public $menutype = 'source'; public $hasAsset = true; public $exists = true;
    public $id = 5; public $position = 'top'; public $title = 'Example'; public $lft = 1; public $rgt = 2;
    public function reset() {}
    public function load($id) { return $this->exists; }
    public function getError() { return ''; }
    public function getRootId() { return 1; }
    public function getColumnAlias($name) { return $name; }
    public function hasField($name) { return $this->hasAsset; }
    public function store() { throw new MutationReached; }
}
class AclQuery {
    public function __call($method, $args) { return $this; }
    public function clear() { throw new MutationReached; }
}
class AclDb {
    public function getQuery($new = true) { return new AclQuery; }
    public function quoteName($name) { return $name; }
    public function setQuery($query) {}
    public function loadResult() { return 1; }
}
#[AllowDynamicProperties]
class AclHarness {
    public $table; public $user; public $option = 'com_content'; public $typeAlias = 'com_content.article';
    public $error; public $state;
    public function __construct($table, $user) { $this->table = $table; $this->user = $user; $this->state = new AclState; }
    public function initBatch() {}
    public function checkCategoryId(&$id) { return $this->user->authorise('core.create', 'com_content.category.' . $id); }
    public function getTable() { return $this->table; }
    public function getDbo() { return new AclDb; }
    public function setError($error) { $this->error = $error; }
    public function getMenuTypeId($type) { return $type === 'source' ? 8 : 9; }
    public function generateTitle($id, $table) { throw new MutationReached; }
    public function generateNewTitle($id, $title, $position) { throw new MutationReached; }
    public function cleanCache() {}
}
$files = array(
    'Generic' => 'libraries/src/MVC/Model/AdminModel.php',
    'Categories' => 'administrator/components/com_categories/models/category.php',
    'Fields' => 'administrator/components/com_fields/models/field.php',
    'Menus' => 'administrator/components/com_menus/models/item.php',
    'Modules' => 'administrator/components/com_modules/models/module.php',
);
foreach ($files as $kind => $path) {
    eval('class Acl' . $kind . ' extends AclHarness {' . productionMethod($root . '/' . $path) . '}');
}
function exercise($kind, $allowed, $expectMutation, $label, $contexts = array(5 => 'com_content.article.5'), $changes = array()) {
    $user = new AclUser; $user->allowed = $allowed; JFactory::$user = $user;
    $table = new AclTable;
    foreach ($changes as $key => $value) { $table->$key = $value; }
    $class = 'Acl' . $kind; $model = new $class($table, $user);
    $value = $kind === 'Menus' ? 'destination.1' : 1;
    $mutation = false;
    try { $result = $model->batchCopy($value, array(5), $contexts); }
    catch (MutationReached $e) { $mutation = true; }
    checkAcl($mutation === $expectMutation && ($mutation || $result === false), $label);
    return $user->calls;
}
$create = array(
    'Generic' => 'com_content.category.1', 'Categories' => 'com_content',
    'Fields' => 'com_content.fieldgroup.1', 'Menus' => 'com_menus.menu.9', 'Modules' => 'com_modules',
);
$edit = array('Generic' => 'com_content.article.5', 'Categories' => 'com_content.article.5',
    'Fields' => 'com_content.field.5', 'Menus' => 'com_menus.menu.8', 'Modules' => 'com_content.article.5');
foreach ($files as $kind => $path) {
    exercise($kind, array('core.create:' . $create[$kind]), false, $kind . ' rejects source without edit rights');
    exercise($kind, array('core.edit:' . $edit[$kind]), false, $kind . ' rejects destination without create rights');
    exercise($kind, array('core.create:' . $create[$kind], 'core.edit:' . $edit[$kind]), true, $kind . ' allows both permissions');
}
exercise('Generic', array('core.create:com_content.category.1', 'core.edit:com_content.article.5'), false, 'no asset: denies missing category permission', array(5 => 'com_content.article.5'), array('hasAsset' => false));
exercise('Generic', array('core.create:com_content.category.1', 'core.edit:com_content.category.7'), true, 'no asset: uses actual source category', array(), array('hasAsset' => false));
exercise('Generic', array('core.create:com_content.category.1'), false, 'missing context fails closed', array());
exercise('Menus', array('core.create:com_menus.menu.9', 'core.edit:com_menus.menu.9'), false, 'main system menu cannot be copied', array(), array('menutype' => 'main'));
exercise('Fields', array('core.create:com_fake.fieldgroup.1', 'core.edit:com_fake.field.5'), false, 'field uses stored component instead of request state');
exercise('Fields', array('core.create:com_content.fieldgroup.1', 'core.edit:com_content.field.5'), false, 'nonexistent field rejected', array(), array('exists' => false));
echo "$checks batch-copy checks, $failures failures\n";
exit($failures ? 1 : 0);
