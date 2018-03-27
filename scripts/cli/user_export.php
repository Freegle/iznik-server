<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$opts = getopt('i:f:');

if (count($opts) < 1) {
    echo "Usage: hhvm user_export -i <id of user> -f <file to export to>\n";
} else {
    $f = $opts['f'];
    $id = intval($opts['i']);
    $u = User::get($dbhr, $dbhm, $id);
    error_log("...get all user data");
    $data = $u->export();

    file_put_contents("$f.raw", var_export($data, TRUE));

    error_log("...filter");
    filterResult($data);
    file_put_contents("$f.filt", var_export($data, TRUE));

    error_log("...dump");
    $encoded = json_encode($data);
    error_log("...encoded length " . strlen($encoded));
    file_put_contents($f, $encoded);

    error_log("\r\n\r\nDone");
}
