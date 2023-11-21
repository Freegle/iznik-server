<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT settings FROM users WHERE users.added >= '2022-07-16';");
$oldinitial = 0;
$newinitial = 0;
$oldfinal = 0;
$newfinal = 0;

foreach ($users as $user) {
    $settings = Utils::presdef('settings', $user, NULL);

    if ($settings) {
        $settings = json_decode($settings, TRUE);

            if ($settings['browseView'] == 'mygroups') {
                $oldfinal++;
            } else if ($settings['browseView'] == 'nearby') {
                $newfinal++;
            }
    } else {
        $oldfinal++;
    }
}

error_log("Old initial $oldinitial set initially, now $oldfinal");
error_log("New initial $newinitial set initially, now $newfinal");