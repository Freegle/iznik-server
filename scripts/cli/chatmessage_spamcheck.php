<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

use spamc;

if (!class_exists('spamc')) {
    require_once(IZNIK_BASE . '/lib/spamc.php');
}

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php chatmessage_spamcheck.php -i <id of message>\n";
} else {
    $id = $opts['i'];
    $m = new ChatMessage($dbhr, $dbhm, $id);

    $s = new Spam($dbhr, $dbhm);
    list ($spam, $reason, $text) = $s->checkSpam($m->getPrivate('message'), [ Spam::ACTION_SPAM, Spam::ACTION_REVIEW ]);

    if ($spam) {
        error_log("Spam: $reason $text");
    } else {
        $u = new User($dbhr, $dbhm, $m->getPrivate('userid'));
        list ($spam, $reason, $text) = $s->checkSpam($u->getName(), [ Spam::ACTION_SPAM ]);

        if ($spam) {
            error_log("Name spam: $reason $text");
        } else
        {
            $reason = $s->checkReview($m->getPrivate('message'), TRUE, TRUE);

            if ($reason)
            {
                error_log("Review: $reason");
            }
        }
    }
}
