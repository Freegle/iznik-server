<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$dsnfd = "mysql:host={$dbconfig['host']};dbname=republisher;charset=utf8";

$dbhfd = new \PDO($dsnfd, $dbconfig['user'], $dbconfig['pass']);

$g = Group::get($dbhr, $dbhm);
$u = User::get($dbhr, $dbhm);
$count = 0;

$fdusers = $dbhfd->query("SELECT * FROM facebook WHERE accounttype = 'Facebook';");

foreach ($fdusers as $fduser) {
    $uid = $u->findByEmail($fduser['email']);

    if ($uid) {
        $u = new User($dbhr, $dbhm, $uid);
        $u->addLogin(User::LOGIN_FACEBOOK, $fduser['facebookid'], $fduser['facebookaccesstoken']);
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count");
    }
}