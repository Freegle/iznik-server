<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$groups = $dbhr->preQuery("SELECT id, nameshort, rules FROM `groups` WHERE `type` = ? AND onhere = 1 AND publish = 1 ORDER BY nameshort ASC;", [
    Group::GROUP_FREEGLE
]);

$rules = [];

foreach ($groups as $group) {
    if ($group['rules']) {
        $thisrules = json_decode($group['rules'], true);

        foreach ($thisrules as $key => $value) {
            #error_log("{$group['nameshort']} rule $key is $value");

            if ($key == 'other') {
                if ($value) {
                    if (!isset($rules[$key])) {
                        $rules[$key] = [];
                    }

                    $rules['other'][] = $value;
                }
            } else {
                if (!isset($rules[$key])) {
                    $rules[$key] = [
                        'true' => 0,
                        'false' => 0
                    ];
                }

                if ($value == 'true' || $value == '1') {
                    $rules[$key]['true']++;
                } else if (!$value || $value == 'false' || $value == '0') {
                    $rules[$key]['false']++;
                }
            }
        }
    }
}

foreach ($rules as $key => $counts) {
    if ($key != 'other') {
        error_log("$key, {$counts['true']}, {$counts['false']}");
    } else {
        foreach ($counts as $count) {
            if ($count != 'None') {
                error_log("Other: $count");
            }
        }
    }
}