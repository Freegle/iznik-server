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

# Some messages can fail to send, if exim is playing up.
$spool->recover(60);
$restart = 600;

do {
    try {
        $spool = new \Swift_FileSpool(IZNIK_BASE . $spoolname);

        $transport = \Swift_SpoolTransport::newInstance($spool);

        # Configure SMTP transport with config defines for MailHog
        $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
        $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : 25;
        $realTransport = \Swift_SmtpTransport::newInstance($smtpHost, $smtpPort);

        $spool = $transport->getSpool();

        if ($spool) {
            try {
                $sent = $spool->flushQueue($realTransport);
                if ($sent) {
                    echo "Sent $sent emails\n";
                }
            } catch (\Throwable $e) {
                // Don't log to Sentry - this can happen.
                error_log("Flush error " . $e->getMessage());
            }
        } else {
            error_log("Couldn't get spool, sleep and retry");
        }
    } catch (\Exception $e) {
        error_log("Exception; sleep and retry " . $e->getMessage());
        \Sentry\captureException($e);
    }

    if (file_exists('/tmp/iznik.mail.abort')) {
        error_log("Aborting");
        exit(0);
    } else {
        $restart--;
        sleep(1);

        if ($restart <= 0) {
            # Exit and restart.  Picks up any code changes and will force another flush of the spool when we
            # next start running due to cron.
            error_log("Exiting");
            exit(0);
        }
    }
} while (TRUE);