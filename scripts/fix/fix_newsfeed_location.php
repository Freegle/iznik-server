<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$newsfeeds = $dbhr->preQuery("SELECT id, userid FROM newsfeed WHERE location IS NULL AND userid IS NOT NULL;");

foreach ($newsfeeds as $newsfeed) {
    $u = new User($dbhr, $dbhm, $newsfeed['userid']);

    $loc = $u->getPublicLocation()['display'];

    if (strlen($loc)) {
        $dbhm->preExec("UPDATE newsfeed SET location = ? WHERE id = ?;", [
            $loc,
            $newsfeed['id']
        ]);
    }
}