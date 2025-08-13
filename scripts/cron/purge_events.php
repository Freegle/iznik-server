<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

error_log("Purge " . IZNIK_BASE . "/events");

if ($handle = opendir(IZNIK_BASE . "/events")) {
    while (FALSE !== ($file = readdir($handle))) {
        $fn = IZNIK_BASE . "/events/$file";

        $modified = filemtime($fn);

        if (is_file($fn) && (time() - filemtime($fn) > 4 * 3600 || filesize($fn) > 1000000000)) {
            unlink($fn);
        }
    }
    closedir($handle);
}

Utils::unlockScript($lockh);