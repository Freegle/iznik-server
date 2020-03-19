<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Alerts.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

# Some messages can fail to send, if exim is playing up.
$spool = new Swift_FileSpool(IZNIK_BASE . "/spool");
$spool->recover(60);

do {
    try {
        $spool = new Swift_FileSpool(IZNIK_BASE . "/spool");

        $transport = Swift_SpoolTransport::newInstance($spool);
        $realTransport = Swift_SmtpTransport::newInstance();

        $spool = $transport->getSpool();

        if ($spool) {
            $sent = $spool->flushQueue($realTransport);

            echo "Sent $sent emails\n";
            break;
        } else {
            error_log("Couldn't get spool, sleep and retry");
            sleep(1);
        }
    } catch (Exception $e) {
        error_log("Exception; sleep and retry " . $e->getMessage());
        sleep(1);
    }
} while (true);