<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$start = date('Y-m-d', strtotime("365 days ago"));
$users = $dbhr->preQuery("SELECT DISTINCT(userid) FROM chat_messages WHERE chat_messages.date >= ? AND type = 'Interested' AND platform = 1;", [
    $start
]);

$mobileup = 0;
$mobilecount = 0;
$nomobileup = 0;
$nomobilecount = 0;

foreach ($users as $user) {
    $ratings  = $dbhr->preQuery("SELECT COUNT(*) AS count FROM ratings WHERE ratee = ? AND visible = 1", [
        $user['userid']
    ]);

    $rating = $ratings[0]['count'];

    $phones = $dbhr->preQuery("SELECT COUNT(*) AS count FROM users_phones WHERE userid = ?", [
        $user['userid']
    ]);

    $phone = $phones[0]['count'];

    if ($phone) {
        $mobileup += $rating;
        $mobilecount++;
    } else {
        $nomobileup += $rating;
        $nomobilecount++;
    }

    error_log(round($mobileup / $mobilecount, 1) . " from $mobilecount vs " . round($nomobileup / $nomobilecount, 1) . " from $nomobilecount");
}