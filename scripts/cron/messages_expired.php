<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# We might have messages indexed which have expired because of group repost settings.  If so, add an actual
# expired outcome and remove them from the index.
$msgs = $dbhr->preQuery("SELECT msgid FROM messages_spatial WHERE successful = 0;");

$count = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);
    $atts = $m->getPublic(FALSE, FALSE);

    if (Utils::pres('outcomes', $atts)) {
        foreach ($atts['outcomes'] as $outcome) {
            if ($outcome['outcome'] == Message::OUTCOME_EXPIRED) {
                error_log("#{$msg['msgid']} " . $m->getPrivate('arrival') . " " . $m->getSubject() . " expired");
                $m->deleteFromSpatialIndex();
                $m->mark(Message::OUTCOME_TAKEN, "Expired", NULL, NULL);
            }
        }
    }
    $count++;

    if ($count % 100 == 0) {
        error_log("$count / " . count($msgs));
    }
}

Utils::unlockScript($lockh);