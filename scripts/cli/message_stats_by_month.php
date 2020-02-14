<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$months = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$firstyear = 2016;
$lastyear = 2020;

echo "Month, ";
for ($year = $firstyear; $year <= $lastyear; $year++) {
    echo "Year $year, ";
}

for ($month = 1; $month <= 12; $month++) {
    $mpad = $month < 10 ? "0$month" : $month;
    $mppad = ($month + 1) < 10 ? ("0" . ($month + 1)) : ($month + 1);
    echo "{$months[$month - 1]}, ";

    for ($year = $firstyear; $year <= $lastyear; $year++) {
        $start = "$year-$mpad-01";
        $end = "$year-$mppad-01";
        $sql = "SELECT COUNT(*) AS count FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id INNER JOIN groups ON groups.id = messages_groups.groupid WHERE date >= '$start' AND date < '$end' AND collection = 'Approved' AND groups.type = 'Freegle';";
        #error_log($sql);
        $ret = $dbhr->preQuery($sql);
        echo "{$ret[0]['count']}, ";
    }
    echo "\n";
}


