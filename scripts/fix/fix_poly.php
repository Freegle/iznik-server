<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$groups = $dbhr->preQuery("SELECT id FROM groups ORDER BY id;");

foreach ($groups as $group) {
    error_log($group['id']);
    $g = $dbhr->preQuery("SELECT AsText(GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'))) AS geomtext FROM groups WHERE id = ?;", [
        $group['id']
    ]);

    $dbhm->preExec("UPDATE groups SET polyindex = GeomFromText(?) WHERE id = ?;", [
        $g[0]['geomtext'],
        $group['id']
    ]);

    #error_log("...{$g[0]['geomtext']}");
}
