<?php
// Exercises the real model with a query recorder; never connects to a database.
if (PHP_SAPI !== 'cli') { exit; }
define('_JEXEC', 1);
$root = isset($argv[1]) ? $argv[1] : dirname(__DIR__) . '/files';
class TagState {
    public $params;
    public $direction;
    public function __construct() { $this->params = $this; }
    public function get($key, $default = null) {
        if ($key === 'all_tags_orderby_direction') { return $this->direction; }
        if ($key === 'tag.language') { return 'all'; }
        return $default;
    }
}
class TagQuery {
    public $ordering;
    public function __call($name, $arguments) { return $this; }
    public function order($value) { $this->ordering = $value; return $this; }
}
class TagDatabase {
    public function getQuery($new) { return new TagQuery; }
    public function quoteName($value) { return '`' . $value . '`'; }
}
class JModelList {
    public $state;
    public function getState($key) { return $this->state->get($key); }
    public function setState($key, $value) {}
    public function getDbo() { return new TagDatabase; }
}
class JFactory {
    public $input;
    public function __construct() { $this->input = $this; }
    public static function getApplication($client = null) { return new self; }
    public static function getUser() { return new self; }
    public function getAuthorisedViewLevels() { return array(1); }
    public function getWord($key) { return 'html'; }
    public function get($key, $default = null, $filter = null) { return $default; }
}
require $root . '/components/com_tags/models/tags.php';
class TagModelProbe extends TagsModelTags { public function query() { return $this->getListQuery(); } }
$model = new TagModelProbe;
$model->state = new TagState;
$checks = 0; $failures = 0;
foreach (array('ASC' => 'ASC', 'DESC' => 'DESC', 'desc' => 'DESC', 'ASC /* review-marker */' => 'ASC', '' => 'ASC', 'unexpected' => 'ASC') as $input => $expected) {
    $model->state->direction = $input;
    $checks++;
    if ($model->query()->ordering !== '`title` ' . $expected . ', a.title ASC') { $failures++; echo "FAIL query direction: $input\n"; }
}
$xml = simplexml_load_file($root . '/administrator/components/com_tags/config.xml');
foreach (array('tag_list_orderby_direction', 'all_tags_orderby_direction') as $name) {
    $checks++;
    $fields = $xml->xpath('//field[@name="' . $name . '"]');
    if (count($fields) !== 1 || (string) $fields[0]['validate'] !== 'options') { $failures++; echo "FAIL options validation: $name\n"; }
}
echo $checks . ' tag ordering checks, ' . $failures . " failures\n";
exit($failures ? 1 : 0);
