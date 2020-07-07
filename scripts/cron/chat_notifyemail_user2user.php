<?php
# Notify by email of unread chats

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

$c = new ChatRoom($dbhr, $dbhm);

# We want to exit occasionally to pick up new code.  We'll get restarted by cron.
$max = 120;

do {
    error_log("Start User2User " . date("Y-m-d H:i:s", time()));
    $count = $c->notifyByEmail(NULL, ChatRoom::TYPE_USER2USER, NULL, 0, FALSE, "4 hours ago");
    error_log("Sent $count to User2User " . date("Y-m-d H:i:s", time()));

    if (!$count) {
        # Sleep for more to arrive, otherwise keep going.
        $max--;
        sleep(1);

        if (file_exists('/tmp/iznik.chatnotify.abort')) {
            exit(0);
        }
    }
} while ($max > 0);

unlockScript($lockh);