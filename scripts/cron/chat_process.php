<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

error_log("chat_process started. PID=" . getmypid() . ", UT=" . getenv('UT') . ", lockh=" . $lockh);

# We want to exit occasionally to pick up new code.  We'll get restarted by cron.
$max = 120;

do {
    Utils::checkAbortFile();

    $msgs = $dbhr->preQuery("SELECT * FROM `chat_messages` WHERE chat_messages.processingrequired = 1 ORDER BY id ASC;");

    if (count($msgs) > 0) {
        error_log("DEBUG chat_process: First message ID: " . $msgs[0]['id'] . ", chatid: " . $msgs[0]['chatid']);
    }

    if (!count($msgs)) {
        # Sleep for more to arrive, otherwise keep going.
        $max--;
        sleep(1);
    } else {
        foreach ($msgs as $msg) {
            if ($msg['processingrequired']) {
                error_log("DEBUG chat_process: Processing message {$msg['id']} at " . date("Y-m-d H:i:s") . ", PID=" . getmypid());
                $cm = new ChatMessage($dbhr, $dbhm, $msg['id']);
                $result = $cm->process();
                error_log("DEBUG chat_process: Processed message {$msg['id']}, result=" . ($result ? 'TRUE' : 'FALSE'));
            }
        }
    }
} while ($max > 0);

Utils::unlockScript($lockh);