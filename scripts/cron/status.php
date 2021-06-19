<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# This is run from cron to check status, which can then be returned from the API.
function status()
{
    global $dbhr, $dbhm;

    $ret = ['ret' => 100, 'status' => 'Unknown verb'];

    $hosts = explode(',', SERVER_LIST);

    $info = [];

    $overallerror = FALSE;
    $overallwarning = FALSE;

    foreach ($hosts as $host) {
        # Each host runs monit, so we ssh in and see what's happening.
        error_log("Check $host");
        $error = FALSE;
        $warning = FALSE;
        $warningtext = NULL;
        $errortext = NULL;

        # Check for security patches
        $cmd = 'ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@' . $host . ' "nice apt-get upgrade -s | grep -i security"';
        $op = shell_exec($cmd);

        if (strpos($op, 'security') !== FALSE) {
            $warning = TRUE;
            $overallwarning = TRUE;
            $warningtext = "Security patches to apply";
        }

        # Check for reboot required
        $cmd = 'ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@' . $host . ' cat /var/run/reboot-required';
        $op = shell_exec($cmd);

        if (strpos($op, 'required') !== FALSE) {
            $warning = TRUE;
            $overallwarning = TRUE;
            $warningtext = "Server reboot required";
        }

        # Get beanstalk queue length.
        $cmd = 'ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@' . $host . ' "echo -e \"stats\\\\\\\\r\\\\\\\\nquit\\\\\\\\r\\\\\\\\n\" | nc localhost 11300 | grep current-jobs-ready 2>&1"';
        $op = shell_exec($cmd);
        $info[$host]['beanstalk'] = $op;
        $count = intval(substr($op, 20));

        if ($count > 10000) {
            $warning = TRUE;
            $overallwarning = TRUE;
            $warningtext = "beanstalkd queue large ($count)";
        }

        $op = shell_exec("ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@$host monit summary 2>&1");
        #error_log("$host returned $op err " );
        $info[$host]['monit'] = $op;

        if (strpos($op, "Monit") === FALSE) {
            # Failed to monit.  That doesn't necessarily mean we're in trouble as the underlying components might
            # be ok.
            $warning = TRUE;
            $overallwarning = TRUE;
        } else {
            $lines = explode("\n", $op);

            for ($i = 2; $i < count($lines); $i++) {
                if (strlen(trim($lines[$i]))> 0) {
                    if (preg_match('/(Not monitored)/', $lines[$i])) {
                        error_log("Failed on $host - $lines[$i]");
                        $warning = TRUE;
                        $warningtext = "$host - $lines[$i]";
                        $overallwarning = TRUE;
                    } else if (!preg_match('/(Online with all services)|(Running)|(Accessible)|(Status ok)|(OK)/', $lines[$i])) {
                        error_log("Failed on $host - $lines[$i]");
                        $error = TRUE;
                        $errortext = "$host - $lines[$i]";
                        $overallerror = TRUE;
                    }
                }
            }
        }

        # Get the exim mail count in case it's too large
        $queuesize = trim(shell_exec("ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@$host exim -bpc 2>&1"));

        if (strpos($queuesize, "exim: command not found") !== FALSE) {
            # That's fine - no exim on this box.
        } else if (!is_numeric($queuesize)) {
            $error = TRUE;
            $overallerror = TRUE;
            $errortext = "Couldn't get queue size on host $host, returned $queuesize";
        } else if (intval($queuesize) > 5000) {
            $warning = TRUE;
            $overallwarning = TRUE;
            $warningtext = "exim mail queue large on $host ($queuesize)";
        }

        # Get the postfix mail count in case it's too large
        $queuesize = trim(shell_exec("ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@$host \"/usr/sbin/postqueue -p | /usr/bin/tail -n1 | /usr/bin/gawk '{print $5}'\" 2>&1"));
        error_log("Postfix queue $queuesize");

        if (strpos($queuesize, "Total") === FALSE) {
            # That's fine - no postfix on this box.
        } else {
            $size = substr($queuesize, 6);
            error_log("Size is $size");

            if (intval($size) > 100000) {
                $warning = TRUE;
                $overallwarning = TRUE;
                $warningtext = "postfix mail queue large on $host ($size)";
            }
        }

        # Get the spool folder count in case it's too large
        $queuesize = trim(shell_exec("ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@$host \"ls -1 /var/www/iznik/spool*/* | wc -l\" 2>&1"));
        error_log("Spool queue $queuesize");

        if (intval($queuesize) > 2000) {
            $warning = TRUE;
            $overallwarning = TRUE;
            $warningtext = "Mail spool folder large ($queuesize)";
        }

        # Zero length files cause the spooler to choke.
        $zeros = trim(shell_exec("ssh -o ConnectTimeout=10 -oStrictHostKeyChecking=no root@$host \"find /var/www/iznik/ -size 0  | grep spool | wc -l\" 2>&1"));
        error_log("Zero length spool files $zeros");

        if (intval($queuesize) > 10000) {
            $warning = TRUE;
            $overallwarning = TRUE;
            $warningtext = "Mail spool contains zero length files ($zeros)";
        }

        $info[$host]['error'] = $error;
        $info[$host]['errortext'] = $errortext;
        $info[$host]['warning'] = $warning;
        $info[$host]['warningtext'] = $warningtext;
    }

    $info["Mailer"]['error'] = FALSE;
    $info["Mailer"]['errortext'] = FALSE;
    $info["Mailer"]['monit'] = NULL;
    $info["Mailer"]['warning'] = FALSE;
    $info['Mailer']['warningtext'] = FALSE;

    if (!$overallwarning) {
        # Check whether we have a backlog sending digests.  This is less important than other warnings.
        error_log("Check mail backlogs");
        $sql = "SELECT groupid, frequency, backlog FROM (SELECT DISTINCT TIMESTAMPDIFF(HOUR, started, NOW()) AS backlog, groups_digests.* FROM `groups_digests` INNER JOIN groups ON groups.id = groups_digests.groupid WHERE type = 'Freegle' AND onhere = 1 AND publish = 1 HAVING backlog > frequency * 1.5 AND frequency > 0 AND backlog > 0) t
ORDER BY backlog DESC LIMIT 1;";
        $backlogs = $dbhr->preQuery($sql);

        foreach ($backlogs as $backlog) {
            $g = new Group($dbhr, $dbhm, $backlog['groupid']);

            if (!$g->getSetting('closed', FALSE)) {
                $sql = "SELECT count(DISTINCT groupid) AS count FROM (SELECT DISTINCT TIMESTAMPDIFF(HOUR, started, NOW()) AS backlog, groups_digests.* FROM `groups_digests` INNER JOIN groups ON groups.id = groups_digests.groupid WHERE type = 'Freegle' AND onhere = 1 AND publish = 1 HAVING backlog > frequency * 1.5 AND frequency > 0 AND backlog > 0) t;";
                $counts = $dbhr->preQuery($sql);
                $overallwarning = TRUE;
                $info["Mailer"]['warning'] = TRUE;
                $info['Mailer']['warningtext'] = "Backlog sending group mails; worst example is {$backlog['backlog']} hours, should be sent every {$backlog['frequency']} hours.  {$counts[0]['count']} groups affected.";
                error_log($info['Mailer']['warningtext']);
            }
        }
    }

    $ret = [
        'ret' => 0,
        'status' => 'Success',
        'error' => $overallerror,
        'warning' => $overallwarning,
        'info' => $info
    ];


    # Set up the plain text HTML file.
    $updated = date(DATE_RSS, time());

    $html = "<!DOCTYPE HTML>
<html>
    <head>
        <title>Status</title>
        
        <link rel=\"stylesheet\" href=\"/css/bootstrap.min.css\">
        <link rel=\"stylesheet\" href=\"/css/bootstrap-theme.min.css\">
        <link rel=\"stylesheet\" href=\"/css/glyphicons.css\">
        <link rel=\"stylesheet\" type=\"text/css\" href=\"/css/style.css?a=177\">
        <link rel=\"stylesheet\" type=\"text/css\" href=\"/css/modtools.css?a=135\">
    </head>
    <body>
        <h1>System Status</h1>
        <p>Last updated at $updated.</p>
         <h2>Overall Status</h2>";

    if ($overallerror) {
        $html .= "<div class=\"alert alert-danger\">There is a serious problem.  Please make sure the Geeks are investigating if this persists for more than a few minutes (unless it says 'security patches to apply', which is OK).</div>";
    } else if ($overallwarning) {
        $html .= "<div class=\"alert alert-warning\">There is a problem.  Please alert the Geeks if this persists for more than an hour.</div>";
    } else {
        $html .= "<div class=\"alert alert-success\">Everything seems fine.</div>";
    }

    $hosts[] = 'Mailer';

    foreach ($hosts as $host) {
        $html .= "<h2>$host</h2>";

        $i = $info[$host];

        if ($i['error']) {
            $html .= "<div class=\"alert alert-danger\">There is a serious problem with $host.</div>";
        } else if ($i['warning']) {
            $html .= "<div class=\"alert alert-warning\">There is a problem with $host.</div>";
        } else {
            $html .= "<div class=\"alert alert-success\">$host seems fine.</div>";
        }

        if ($i['error'] || $i['warning']) {
            $html .= "<p>Details:</p>";
            $html .= nl2br($i['monit']);
            $html .= '<p>' . $i['errortext'] . $i['warningtext'];
        }
    }

    $html .="
    <script>
        window.setTimeout(function() {
            document.location = '/status.html?' + (new Date()).getTime();
        }, 30000);
    </script>
    </body>
</html>";

    file_put_contents(IZNIK_BASE . '/http/status.html', $html);

    return($ret);
}

# Put into cache file for API call.
file_put_contents('/tmp/iznik.status', json_encode(status()));
chmod('/tmp/iznik.status', 0644);

Utils::unlockScript($lockh);