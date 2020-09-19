<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('n:');
$spoolname = Utils::presdef('n', $opts, '/spool');

$lockh = Utils::lockScript(basename(__FILE__ . '_' . $spoolname));

# Some messages can fail to send, if exim is playing up.
$spool = new \Swift_FileSpool(IZNIK_BASE . $spoolname);
$spool->recover(60);

do {
    try {
        $spool = new \Swift_FileSpool(IZNIK_BASE . $spoolname);

        $transport = \Swift_SpoolTransport::newInstance($spool);
        $realTransport = \Swift_SmtpTransport::newInstance();

        $spool = $transport->getSpool();

        if ($spool) {
            $sent = $spool->flushQueue($realTransport);

            echo "Sent $sent emails\n";
            break;
        } else {
            error_log("Couldn't get spool, sleep and retry");
            sleep(1);
        }
    } catch (\Exception $e) {
        error_log("Exception; sleep and retry " . $e->getMessage());
        sleep(1);
    }
} while (true);