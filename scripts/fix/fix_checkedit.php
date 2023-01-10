<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$groups = $dbhr->preQuery("SELECT id FROM `groups` WHERE publish = 1 AND type = 'Freegle';");

$allow = 0;
$forbid = 0;

foreach ($groups as $group) {
    $g = new Group($dbhr, $dbhm, $group['id']);
    $allowedits = $g->getSetting('allowedits', [ 'moderated' => TRUE, 'group' => TRUE ]);

    if (!$allowedits['moderated'] || !$allowedits['group']) {
        error_log($g->getPrivate('nameshort'));
        $forbid++;
    } else {
        $allow++;
    }
}

error_log("Allow $allow vs $forbid");