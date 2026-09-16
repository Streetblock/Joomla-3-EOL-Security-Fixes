<?php
/**
 * CVE-2026-73373 regression tests. CLI only; no actual upload or SSI execution.
 * Optional first argument: alternate Joomla source root (negative control).
 * Real packaged validators; test doubles only for application/settings/filesystem services.
 */
namespace Joomla\CMS\Component {
    class ComponentHelper {
        public static function getParams($component) { return new \ReviewUploadParams; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
    define('_JEXEC', 1);
    define('JPATH_PLATFORM', __DIR__);
    error_reporting(E_ALL & ~E_DEPRECATED);

    class ReviewUploadParams {
        public function get($key, $default = null) {
            // Deliberately permissive configuration: denial must come from the security checks.
            $values = array(
                'upload_extensions' => 'txt,jpg,pdf,css,shtml,shtm,sht,stm',
                'ignore_extensions' => '', 'upload_maxsize' => 0, 'restrict_uploads' => 0,
                'image_formats' => 'jpg', 'source_formats' => 'txt,css,shtml,shtm,sht,stm',
                'font_formats' => '', 'compressed_formats' => 'zip', 'upload_limit' => 0,
            );
            return isset($values[$key]) ? $values[$key] : $default;
        }
    }
    class JComponentHelper {
        public static function getParams($component) { return new ReviewUploadParams; }
    }
    class JFactory {
        public static function getApplication() { return new self; }
        public function enqueueMessage($message, $type) {}
    }
    class JText { public static function _($message) { return $message; } }
    class JFile {
        public static function makeSafe($name) { return $name; }
        public static function getExt($name) { return pathinfo($name, PATHINFO_EXTENSION); }
    }
    function jimport($path) {}

    $root = isset($argv[1]) ? $argv[1] : dirname(__DIR__) . '/files';
    require $root . '/libraries/vendor/joomla/filter/src/InputFilter.php';
    require $root . '/libraries/src/Filter/InputFilter.php';
    require $root . '/libraries/src/Helper/MediaHelper.php';
    require $root . '/administrator/components/com_templates/helpers/template.php';

    $checks = 0;
    $failures = 0;
    function verifyUpload($actual, $expected, $label) {
        global $checks, $failures;
        $checks++;
        if ($actual !== $expected) { $failures++; echo 'FAIL ' . $label . "\n"; }
    }
    $temporary = tempnam(sys_get_temp_dir(), 'ssi_review_');
    if ($temporary === false) { throw new \RuntimeException('Cannot create fixture'); }
    file_put_contents($temporary, str_repeat('Harmless test content. ', 20));
    $file = array('name' => '', 'tmp_name' => $temporary, 'type' => 'text/plain', 'error' => 0, 'size' => filesize($temporary));
    $media = new \Joomla\CMS\Helper\MediaHelper;
    try {
        foreach (array('shtml', 'shtm', 'sht', 'stm') as $extension) {
            foreach (array($extension, strtoupper($extension), ucfirst($extension)) as $spelling) {
                foreach (array('document.' . $spelling, 'document.' . $spelling . '.txt', 'document.txt.' . $spelling, 'document.' . $spelling . '.' . $spelling . '.txt') as $name) {
                    $file['name'] = $name;
                    verifyUpload(\Joomla\CMS\Filter\InputFilter::isSafeFile($file), false, 'central ' . $name);
                    verifyUpload($media->canUpload($file), false, 'media ' . $name);
                    verifyUpload(\TemplateHelper::canUpload($file), false, 'template ' . $name);
                }
            }
        }
        foreach (array('readme.txt', 'photo.jpg', 'styles.css', 'report.v2.txt', 'shtml.txt', 'shtm.txt', 'sht.txt', 'stm.txt') as $name) {
            $file['name'] = $name;
            verifyUpload(\Joomla\CMS\Filter\InputFilter::isSafeFile($file), true, 'central safe ' . $name);
            verifyUpload($media->canUpload($file), true, 'media safe ' . $name);
            verifyUpload(\TemplateHelper::canUpload($file), true, 'template safe ' . $name);
        }
        $file['name'] = 'readme.txt';
        $unsafe = $file;
        $unsafe['name'] = 'document.StM.txt';
        verifyUpload(\Joomla\CMS\Filter\InputFilter::isSafeFile(array('safe' => $file, 'nested' => array('unsafe' => $unsafe))), false, 'central nested descriptor');
        $multiple = array('name' => array('safe.txt', 'document.SHTM'), 'tmp_name' => array($temporary, $temporary), 'type' => array('text/plain', 'text/plain'), 'error' => array(0, 0), 'size' => array(1, 1));
        verifyUpload(\Joomla\CMS\Filter\InputFilter::isSafeFile($multiple), false, 'central multiple upload descriptor');
        $file['name'] = 'document.php.txt';
        verifyUpload(\Joomla\CMS\Filter\InputFilter::isSafeFile($file), false, 'existing PHP extension protection');
        $file['name'] = 'document.txt';
        file_put_contents($temporary, '<?php /* harmless marker */');
        verifyUpload(\Joomla\CMS\Filter\InputFilter::isSafeFile($file), false, 'existing PHP content protection');
    } finally {
        unlink($temporary);
    }
    echo $checks . ' checks, ' . $failures . " failures\n";
    exit($failures ? 1 : 0);
}
