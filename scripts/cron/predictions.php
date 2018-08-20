<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/Predict.php');

$p = new Predict($dbhr, $dbhm);
error_log("Train");
$p->train();

$mysqltime = date ("Y-m-d", strtotime("7 days ago"));

$users = $dbhr->preQuery("SELECT DISTINCT userid FROM chat_messages WHERE date > '$mysqltime' AND type = ?;", [
    ChatMessage::TYPE_INTERESTED
]);

$total = count($users);
error_log("$total users to predict");
$count = 0;

foreach ($users as $user) {
    $count++;
    $p->predict($user['userid']);

    if ($count % 100 == 0) {
        error_log("...$count / $total");
    }
}