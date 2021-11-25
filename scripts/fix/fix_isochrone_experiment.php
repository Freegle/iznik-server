<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$variants = $dbhr->preQuery("SELECT * FROM abtest WHERE uid = 'browsepage';");

$oldrate = null;
$newrate = null;

foreach ($variants as $variant) {
    if ($variant['variant'] == 'oldskool') {
        $oldrate = $variant;
    } else if ($variant['variant'] = 'newskool') {
        $newrate = $variant;
    }
}

error_log("Old rate {$oldrate['rate']} from {$oldrate['shown']}");
error_log("New rate {$newrate['rate']} from {$newrate['shown']}");

$users = $dbhr->preQuery("SELECT settings FROM users WHERE users.added >= '2021-11-10';");
$oldinitial = 0;
$newinitial = 0;
$oldfinal = 0;
$newfinal = 0;

foreach ($users as $user) {
    $settings = Utils::presdef('settings', $user, NULL);

    if ($settings) {
        $settings = json_decode($settings, TRUE);

        if (Utils::pres('browseViewInitial', $settings)) {
            if ($settings['browseViewInitial'] == 'mygroups') {
                $oldinitial++;
            } else if ($settings['browseViewInitial'] == 'nearby') {
                $newinitial++;
            }
            if ($settings['browseView'] == 'mygroups') {
                $oldfinal++;
            } else if ($settings['browseView'] == 'nearby') {
                $newfinal++;
            }
        }
    }
}

error_log("Old initial $oldinitial set initially, now $oldfinal");
error_log("New initial $newinitial set initially, now $newfinal");