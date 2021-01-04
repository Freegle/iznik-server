<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$dbhback = new LoggedPDO('localhost:3309', $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);

$groupsback = $dbhback->preQuery("SELECT id, nameshort, settings FROM groups WHERE type = 'Freegle' ORDER BY LOWER(nameshort) ASC;");

foreach ($groupsback as $groupback) {
    $groupslive = $dbhr->preQuery("SELECT id, settings FROM groups WHERE id = ?;", [
        $groupback['id']
    ]);

    foreach ($groupslive as $grouplive) {
        $settingsback = json_decode($groupback['settings'], TRUE);
        $moderatedback = $settingsback && array_key_exists('moderated', $settingsback) ? $settingsback['moderated'] : 0;
        $settingslive = json_decode($grouplive['settings'], TRUE);
        $moderatedlive = $settingslive && array_key_exists('moderated', $settingslive) ? $settingslive['moderated'] : 0;

        if ($moderatedlive && !$moderatedback) {
            error_log("{$groupback['nameshort']} backup $moderatedback vs live $moderatedlive");
            $settingslive['moderated'] = 0;
            $dbhm->preExec("UPDATE groups SET settings = ? WHERE id = ?", [
                json_encode($settingslive),
                $grouplive['id']
            ]);
        }
    }
}
