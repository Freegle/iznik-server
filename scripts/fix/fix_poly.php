<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');

$groups = $dbhr->preQuery("SELECT id FROM `groups` ORDER BY id;");

foreach ($groups as $group) {
    error_log($group['id']);
    $g = $dbhr->preQuery("SELECT  ST_AsText(ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'))) AS geomtext FROM `groups` WHERE id = ?;", [
        $group['id']
    ]);

    $dbhm->preExec("UPDATE `groups` SET polyindex = ST_GeomFromText(?, 3857) WHERE id = ?;", [
        $g[0]['geomtext'],
        $group['id']
    ]);

    #error_log("...{$g[0]['geomtext']}");
}
