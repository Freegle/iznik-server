<?php
# Notify by email of unread chats

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$c = new ChatRoom($dbhr, $dbhm);

# We want to exit occasionally to pick up new code.  We'll get restarted by cron.
$max = 120;

do {
    error_log("Start User2Mod " . date("Y-m-d H:i:s", time()));
    $count = $c->notifyByEmail(NULL, ChatRoom::TYPE_USER2MOD, NULL, 0, FALSE, "4 hours ago");
    error_log("Sent $count for User2Mod " . date("Y-m-d H:i:s", time()));

    if (!$count) {
        # Sleep for more to arrive, otherwise keep going.
        $max--;
        sleep(1);

        if (file_exists('/tmp/iznik.mail.abort')) {
            exit(0);
        }
    }
} while ($max > 0);

Utils::unlockScript($lockh);