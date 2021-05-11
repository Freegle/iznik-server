<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('n:');
$spoolname = Utils::presdef('n', $opts, '/spool');

$lockh = Utils::lockScript(basename(__FILE__ . '_' . $spoolname));

$spool = new \Swift_FileSpool(IZNIK_BASE . $spoolname);
$spool->recover(60);
$recover = 300;

do {
    try {
        $spool = new \Swift_FileSpool(IZNIK_BASE . $spoolname);

        $transport = \Swift_SpoolTransport::newInstance($spool);
        $realTransport = \Swift_SmtpTransport::newInstance();

        $spool = $transport->getSpool();

        if ($spool) {
            try {
                $sent = $spool->flushQueue($realTransport);

                echo "Sent $sent emails\n";
            } catch (\TypeError $ex) {
                error_log("Type error " . $ex->getMessage());
            }
        } else {
            error_log("Couldn't get spool, sleep and retry");
        }
    } catch (\Exception $e) {
        error_log("Exception; sleep and retry " . $e->getMessage());
    }

    if (file_exists('/tmp/iznik.mail.abort')) {
        exit(0);
    } else {
        sleep(1);

        $recover--;

        if ($recover <= 0) {
            # Some messages can fail to send, if exim is playing up.
            $spool->recover(60);
            $recover = 300;
        }
    }
} while (true);