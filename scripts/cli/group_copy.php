<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$opts = getopt('f:t:');

if (count($opts) < 2) {
    echo "Usage: php group_copy -f <shortname of source group> -t <short name of destination group>\n";
} else {
    $from = $opts['f'];
    $to = $opts['t'];
    $g = Group::get($dbhr, $dbhm);

    $srcid = $g->findByShortName($from);
    $dstid = $g->findByShortName($to);

    if ($dstid) {
        error_log("$to already exists");
    } else {
        $fromg = new Group($dbhr, $dbhm, $srcid);
        $dstid = $g->create($to, Group::GROUP_FREEGLE);
        $tog = new Group($dbhr, $dbhm, $dstid);

        $tog->setPrivate('publish', 0);
        $tog->setPrivate('onmap', 0);
        $tog->setPrivate('listable', 0);
        $tog->setPrivate('onhere', 1);

        foreach (['settings', 'region', 'authorityid', 'tagline', 'description', 'welcomemail'] as $att) {
            $tog->setPrivate($att, $fromg->getPrivate($att));
        }
    }
}
