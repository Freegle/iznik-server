<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

$s = new Story($dbhr, $dbhm);
$mysqltime = date("Y-m-d", max(strtotime("06-sep-2016"), strtotime("Midnight 90 days ago")));
$groups = $dbhr->preQuery("SELECT groups.id, groups.nameshort FROM groups WHERE groups.type = 'Freegle' AND publish = 1 ORDER BY LOWER(nameshort) ASC;");
$count = 0;
foreach ($groups as $group) {
    error_log("Check group {$group['nameshort']}");
    $count += $s->askForStories($mysqltime, NULL, 3, 5, $group['id']);
}
error_log("Sent $count requests");

Utils::unlockScript($lockh);