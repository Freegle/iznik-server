<?php
# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/Engage.php');

$e = new Engage($dbhr, $dbhm);
$uids = $e->findUsers(NULL, Engage::FILTER_INACTIVE);
$e->checkSuccess();
$e->sendUsers(Engage::ATTEMPT_INACTIVE, $uids, "We'll stop sending you emails soon...", "It looks like you’ve not been active on Freegle for a while. So that we don’t clutter your inbox, and to reduce the load on our servers, we’ll stop sending you emails soon.

If you’d still like to get them, then just go to www.ilovefreegle.org and log in to keep your account active.

Maybe you’ve got something lying around that someone else could use, or perhaps there’s something someone else might have?", 'inactive.html');
