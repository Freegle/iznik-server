<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');

$groups = $dbhr->preQuery("SELECT id FROM groups WHERE type = 'Other';");

foreach ($groups as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);
    error_log($g->getName());
    $g->delete();
}
