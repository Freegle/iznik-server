<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$groups = $dbhr->preQuery("SELECT id, nameshort FROM `groups` ORDER BY id;");

foreach ($groups as $group) {
    $g = $dbhr->preQuery("SELECT ST_AsText(polyindex) AS current, ST_AsText(ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'))) AS geomtext FROM `groups` WHERE id = ?;", [
        $group['id']
    ]);

    if ($g[0]['current'] != $g[0]['geomtext']) {
        error_log("Wrong for {$group['nameshort']}");
        $dbhm->preExec("UPDATE `groups` SET polyindex = ST_GeomFromText(?, {$dbhr->SRID()}) WHERE id = ?;", [
            $g[0]['geomtext'],
            $group['id']
        ]);
    }
}
