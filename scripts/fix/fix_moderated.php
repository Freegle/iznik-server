<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$g = new Group($dbhr, $dbhm, 126668);
error_log("Moderated " . $g->getSetting('moderated', 0). ' close ' . $g->getSetting('close', 0) . " override " .  $g->getPrivate('overridemoderation'));

$m = new Message($dbhr, $dbhm, 101570716);
$s = new Spam($dbhr, $dbhm);
list ($rc, $reason) = $s->checkMessage($m);
error_log("Spam ? $rc, $reason");

$w = new WorryWords($dbhr, $dbhm, 126668);
$worry = $w->checkMessage($m->getID(), $m->getFromuser(), $m->getSubject(), $m->getTextbody());
error_log(var_export($worry, TRUE));

$u = new User($dbhr, $dbhm, 38678104);
$postcoll = ($g->getSetting('moderated', 0) || $g->getSetting('close', 0)) ? MessageCollection::PENDING : $u->postToCollection(126668);
error_log("post to $postcoll," . $u->postToCollection(126668));
