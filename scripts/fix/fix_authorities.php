<?php
define('SQLLOG', FALSE);

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$auths = $dbhr->preQuery("SELECT id, name, COALESCE(simplified, polygon) AS poly, AsText(COALESCE(simplified, polygon)) AS polytext FROM authorities WHERE id = ?;", [ 72890 ]);

foreach ($auths as $auth) {
    error_log("Auth {$auth['id']}");
    $groups = $dbhr->preQuery("SELECT id, GeomFromText(COALESCE(poly, polyofficial)) AS poly, COALESCE(poly, polyofficial) AS polytext FROM groups WHERE type = 'Freegle' AND publish = 1 AND onhere = 1;");

    foreach ($groups as $group) {
        error_log("...group {$group['id']}");
        try {
            $simps = $dbhr->preQuery("SELECT ST_Intersection(?, ?);", [
                $group['poly'],
                $auth['poly']
            ]);
            error_log("{$auth['id']} {$group['id']} worked");
        } catch (Exception $e) {
            error_log("{$auth['id']} {$auth['polytext']} vs {$group['id']} {$group['polytext']} failed");
            exit(0);
        }
    }
}