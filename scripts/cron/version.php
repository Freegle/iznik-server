<?php
# TODO Retire once webpack build is live because we no longer use the version to update the code.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/scripts.php');

$lockh = lockScript(basename(__FILE__));

try {
    while (true) {
        function getVersion() {
            $directory = new RecursiveDirectoryIterator(IZNIK_BASE);
            $flattened = new RecursiveIteratorIterator($directory);
            $files = new RegexIterator($flattened, '/.*\.((php)|(html)|(js)|(css))/i');

            $max = 0;

            foreach ($files as $filename=>$cur) {
                $time = $cur->getMTime();
                $max = max($max, $time);
            }

            return($max);
        }

        $version = getVersion();
        file_put_contents("/tmp/iznik.version", $version);
        sleep(1);
    }
} catch (Exception $e) {
    error_log("Top-level exception " . $e->getMessage() . "\n");
}

unlockScript($lockh);