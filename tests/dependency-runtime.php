<?php
// Run in a fresh process against a fixture prepared by package-install.php.
if (PHP_SAPI !== 'cli' || !isset($argv[1])) { exit(2); }
error_reporting(E_ALL & ~E_DEPRECATED);
if (isset($argv[2]) && $argv[2] === 'require-native' && !extension_loaded('sodium')) {
    throw new RuntimeException('Requested native sodium extension failed to load');
}
$root = realpath($argv[1]);
require $root . '/libraries/vendor/autoload.php';
$checks = 0;
function verifyDependency($ok, $label) {
    global $checks;
    $checks++;
    if (!$ok) { throw new RuntimeException($label); }
}
foreach (array('paragonie/sodium_compat' => 'v1.24.2', 'symfony/yaml' => 'v5.4.53', 'symfony/deprecation-contracts' => 'v2.5.4') as $name => $version) {
    verifyDependency(Composer\InstalledVersions::getPrettyVersion($name) === $version, 'installed version ' . $name);
}
verifyDependency(function_exists('trigger_deprecation'), 'Composer files autoload for contracts');
verifyDependency(realpath((new ReflectionClass('Symfony\Component\Yaml\Parser'))->getFileName()) === realpath($root . '/libraries/vendor/symfony/yaml/Parser.php'), 'updated parser autoloaded');
$format = new Joomla\Registry\Format\Yaml;
$object = $format->stringToObject("name: Example\nitems: [1, 2]\n");
verifyDependency($object->name === 'Example' && $object->items === array(1, 2), 'original Registry YAML reader');
verifyDependency($format->stringToObject($format->objectToString($object)) == $object, 'original Registry YAML writer roundtrip');
// RFC 8032 vector 1: verify complete public APIs, with native sodium bypassed.
ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
$seed = hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60');
$pk = hex2bin('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a');
$sig = hex2bin('e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b');
verifyDependency(ParagonIE_Sodium_Compat::crypto_sign_verify_detached($sig, '', $pk), 'RFC signature verification');
verifyDependency(!ParagonIE_Sodium_Compat::crypto_sign_verify_detached($sig, 'tampered', $pk), 'tampered signature rejected');
$pair = ParagonIE_Sodium_Compat::crypto_sign_seed_keypair($seed);
verifyDependency(ParagonIE_Sodium_Compat::crypto_sign_publickey($pair) === $pk, 'RFC public key');
verifyDependency(ParagonIE_Sodium_Compat::crypto_sign_detached('', ParagonIE_Sodium_Compat::crypto_sign_secretkey($pair)) === $sig, 'RFC signature generation');
$key = str_repeat("\x01", 32); $nonce = str_repeat("\x02", 24);
$box = ParagonIE_Sodium_Compat::crypto_secretbox('test message', $nonce, $key);
verifyDependency(ParagonIE_Sodium_Compat::crypto_secretbox_open($box, $nonce, $key) === 'test message', 'secretbox roundtrip');
// On-curve mixed-order point B + (0,-1), outside the main subgroup.
$mixedOrder = hex2bin('95' . str_repeat('99', 31));
// Match the architecture selection in Compat.php. Forcing Core32 on a 64-bit
// interpreter is not a substitute for testing an actual 32-bit PHP runtime.
foreach (array(PHP_INT_SIZE === 4 ? 'ParagonIE_Sodium_Core32_Ed25519' : 'ParagonIE_Sodium_Core_Ed25519') as $class) {
    verifyDependency($class::verify_detached($sig, '', $pk), $class . ' valid RFC signature');
    verifyDependency(strlen($class::pk_to_curve25519($pk)) === 32, $class . ' valid conversion');
    $rejected = false;
    try { $class::pk_to_curve25519($mixedOrder); } catch (SodiumException $e) { $rejected = true; }
    verifyDependency($rejected, $class . ' mixed-order conversion rejected');
}
ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = false;
verifyDependency(ParagonIE_Sodium_Compat::crypto_sign_verify_detached($sig, '', $pk), 'normal-dispatch RFC verification');
verifyDependency(!ParagonIE_Sodium_Compat::crypto_sign_verify_detached($sig, 'tampered', $pk), 'normal-dispatch tampering rejected');
if (extension_loaded('sodium')) {
    verifyDependency(sodium_crypto_sign_verify_detached($sig, '', $pk), 'native sodium RFC verification');
    echo "Native sodium extension exercised\n";
} else { echo "Native sodium extension unavailable; normal dispatch uses PHP fallback\n"; }
echo "$checks installed dependency checks passed on PHP " . PHP_VERSION . ' / ' . (PHP_INT_SIZE * 8) . " bit\n";
