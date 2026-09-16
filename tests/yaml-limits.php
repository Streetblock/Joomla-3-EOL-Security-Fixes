<?php
// Usage: php -n tests/yaml-limits.php /path/to/original-joomla-3.10.12 [alternate-patched-root]
// Bounded local fixtures, no load generator and no server interaction.
if (PHP_SAPI !== 'cli' || !isset($argv[1])) { exit(2); }
error_reporting(E_ALL & ~E_DEPRECATED);
$original = realpath($argv[1]);
$patched = isset($argv[2]) ? realpath($argv[2]) : dirname(__DIR__) . '/files';
spl_autoload_register(function ($class) use ($original, $patched) {
    $prefix = 'Symfony\\Component\\Yaml\\';
    if (strpos($class, $prefix) === 0) {
        $path = '/libraries/vendor/symfony/yaml/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        require (is_file($patched . $path) ? $patched : $original) . $path;
    }
});
$checks = 0; $failures = 0;
function verifyYaml($condition, $label) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures++; echo 'FAIL ' . $label . "\n"; }
}
function mustReject($call, $label) {
    try { $call(); verifyYaml(false, $label); }
    catch (Symfony\Component\Yaml\Exception\ParseException $e) {
        verifyYaml(strpos($e->getMessage(), 'Maximum') !== false, $label . ' (limit exception)');
    }
}
$parser = new Symfony\Component\Yaml\Parser;
verifyYaml($parser->parse("name: 'hello'\nvalues: [1, 2, 3]\n") === array('name' => 'hello', 'values' => array(1, 2, 3)), 'ordinary YAML');
verifyYaml($parser->parse("%YAML 1.2\n# comment\n---\nkey: value\n...\n") === array('key' => 'value'), 'directives and comments');
verifyYaml($parser->parse("a: &ref [1, 2]\nb: *ref\n") === array('a' => array(1, 2), 'b' => array(1, 2)), 'ordinary collection alias');
verifyYaml($parser->parse("base: &base {a: 1}\nmerged:\n  <<: *base\n  b: 2\n")['merged'] === array('a' => 1, 'b' => 2), 'merge alias');
foreach (array(1, 16, 128) as $depth) {
    $yaml = str_repeat('[', $depth) . '0' . str_repeat(']', $depth);
    verifyYaml(is_array($parser->parse($yaml)), 'inline depth allowed ' . $depth);
}
mustReject(function () use ($parser) { $parser->parse(str_repeat('[', 129) . '0' . str_repeat(']', 129)); }, 'inline sequence depth');
mustReject(function () { Symfony\Component\Yaml\Inline::parse(str_repeat('{a: ', 129) . '0' . str_repeat('}', 129)); }, 'direct inline mapping depth');
$block = '';
for ($i = 0; $i < 130; $i++) { $block .= str_repeat(' ', $i * 2) . "key:\n"; }
$block .= str_repeat(' ', 260) . "value\n";
mustReject(function () use ($parser, $block) { $parser->parse($block); }, 'block depth');
$mixed = '';
for ($i = 0; $i < 80; $i++) { $mixed .= str_repeat(' ', $i * 2) . "key:\n"; }
$mixed .= str_repeat(' ', 160) . str_repeat('[', 60) . '0' . str_repeat(']', 60) . "\n";
mustReject(function () use ($parser, $mixed) { $parser->parse($mixed); }, 'shared block and inline depth');
foreach (array(128, 129) as $count) {
    $yaml = "a: &ref [1]\nitems: [" . implode(', ', array_fill(0, $count, '*ref')) . "]\n";
    if ($count === 128) { verifyYaml(count($parser->parse($yaml)['items']) === 128, '128 collection aliases accepted'); }
    else { mustReject(function () use ($parser, $yaml) { $parser->parse($yaml); }, 'inline collection aliases'); }
}
$yaml = "a: &ref [1]\n";
for ($i = 0; $i < 129; $i++) { $yaml .= 'key' . $i . ": *ref\n"; }
mustReject(function () use ($parser, $yaml) { $parser->parse($yaml); }, 'block collection aliases');
$merge = "base: &ref {a: 1}\n";
for ($i = 0; $i < 129; $i++) { $merge .= 'key' . $i . ":\n  <<: *ref\n"; }
mustReject(function () use ($parser, $merge) { $parser->parse($merge); }, 'merge collection aliases');
$yaml = "a: &ref scalar\nitems: [" . implode(', ', array_fill(0, 150, '*ref')) . "]\n";
verifyYaml(count($parser->parse($yaml)['items']) === 150, 'scalar aliases remain allowed');
verifyYaml($parser->parse('ok: true') === array('ok' => true), 'parser reusable after rejection');
$cleanup = new ReflectionMethod($parser, 'cleanup');
$cleanup->setAccessible(true);
$oldLimit = ini_set('pcre.backtrack_limit', '10000');
$oldJit = ini_set('pcre.jit', '0');
foreach (array('%YAML ' . str_repeat('.', 4000), '#' . str_repeat('x', 4000), '---' . str_repeat('x', 4000), "---\nkey: value\n..." . str_repeat(' ', 4000)) as $index => $input) {
    $clean = $cleanup->invoke($parser, $input);
    verifyYaml(is_string($clean) && preg_last_error() === PREG_NO_ERROR && ($index >= 3 || $clean === $input), 'bounded cleanup without backtracking exhaustion ' . $index);
}
ini_set('pcre.backtrack_limit', $oldLimit); ini_set('pcre.jit', $oldJit);
echo $checks . ' YAML checks, ' . $failures . " failures\n";
exit($failures ? 1 : 0);
