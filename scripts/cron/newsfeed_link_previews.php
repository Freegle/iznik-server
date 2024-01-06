<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

$recent = $dbhr->preQuery("SELECT id, message FROM newsfeed WHERE DATEDIFF(NOW(), `timestamp`) < 2;");

$urls = [];

foreach ($recent as $r) {
    if (preg_match_all(Utils::URL_PATTERN, $r['message'], $matches)) {
        foreach ($matches as $val) {
            foreach ($val as $url) {
                if (strlen($url)) {
                    if (!Utils::pres($url, $urls)) {
                        $urls[$url] = TRUE;
                        $p = new Preview($dbhr, $dbhm);
                        $id = $p->get($url);
                        error_log("Url $url has preview $id");
                    }
                }
            }
        }
    }
}

Utils::unlockScript($lockh);