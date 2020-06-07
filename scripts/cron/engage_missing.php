<?php
# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/Engage.php');

$e = new Engage($dbhr, $dbhm);
$uids = $e->findUsers(NULL, Engage::FILTER_DONORS);
$e->checkSuccess();
$e->sendUsers(Engage::ATTEMPT_MISSING, $uids, "We miss you!", "We don't think you've freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org", 'missing.html');
