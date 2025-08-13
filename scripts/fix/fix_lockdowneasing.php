<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery(
    "SELECT id FROM messages WHERE arrival >= '2021-03-22' AND lastroute = ?;",
    [
        'AwaitCovid'
    ]
);

foreach ($msgs as $msg) {
    $msgid = $msg['id'];

    # Dispatch the message on its way.
    $r = new MailRouter($dbhr, $dbhm);
    $m = new Message($dbhr, $dbhm, $msgid);
    $mgroups = $m->getGroups(TRUE, FALSE);

    if (count($mgroups) == 0) {
        # Will be a chat.
        #error_log("Chat: " . $m->getSubject());
        #$r->route($m);
    } else {
        # Will be for a group.
        foreach ($mgroups as $mgroup) {
            if ($mgroup['collection'] == MessageCollection::INCOMING) {
                error_log("Post: " . $m->getSubject());
                $r->route($m);
            }
        }
    }
}
