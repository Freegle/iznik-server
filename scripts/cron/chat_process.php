<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# We want to exit occasionally to pick up new code.  We'll get restarted by cron.
$max = 120;

do {
    $msgs = $dbhr->preQuery("SELECT * FROM `chat_messages` WHERE chat_messages.processingrequired = 1;");
    if (!count($msgs)) {
        # Sleep for more to arrive, otherwise keep going.
        $max--;
        sleep(1);

        if (file_exists('/tmp/iznik.mail.abort')) {
            exit(0);
        }
    } else {
        error_log("Start chat processing at " . date("Y-m-d H:i:s", time()) . " for " . count($msgs));

        foreach ($msgs as $msg) {
            error_log("..." . $msg['id']);
            $cm = new ChatMessage($dbhr, $dbhm, $msg['id']);
            $cm->process();
        }
    }
} while ($max > 0);

Utils::unlockScript($lockh);