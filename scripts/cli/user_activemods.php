<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$start = date('Y-m-d', strtotime("1000 days ago"));
$recent = date('Y-m-d', strtotime("30 days ago"));

$recents = $dbhr->preQuery("SELECT DISTINCT(byuser) FROM logs INNER JOIN groups ON logs.groupid = groups.id LEFT OUTER JOIN teams_members ON teams_members.userid = logs.byuser WHERE timestamp >= '$recent' AND logs.type = 'Message' AND subtype = 'Approved' AND groups.type = 'Freegle' AND teams_members.userid IS NULL;");
$userids = array_column($recents, 'byuser');

$counts = $dbhr->preQuery("SELECT COUNT(DISTINCT(CONCAT(YEAR(timestamp), '-', MONTH(timestamp)))) AS months, byuser FROM logs WHERE timestamp >= '$start' AND type = 'Message' AND subtype = 'Approved' AND byuser IN (-1" . implode(',', $userids) . ") GROUP BY byuser;");
uasort($counts, function($a, $b) {
    return($b['months'] - $a['months']);
});
$counts = array_slice($counts, 0, 100);

foreach ($counts as $count) {
    $u = new User($dbhr, $dbhm, $count['byuser']);
    error_log("#{$count['byuser']} " . $u->getName() . " (" . $u->getEmailPreferred() . ")");
}