<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# We want to parse the exim logs and correlate them to users, and then put that into the DB.  This allows people
# to see that we have actually sent messages.
$fn = '/var/log/mail.log';
$fh = fopen($fn, 'r');
$msgs = [];

$u = new User($dbhr, $dbhm);

function logIt($msg) {
    global $dbhm, $dbhr, $u;
    $timestamp = date("Y-m-d H:i:s", strtotime($msg['date']));
    $uid = Utils::pres('to', $msg) ? $u->findByEmail($msg['to']) : NULL;

    # We might already have a row for this eximid.
    $logs = $dbhr->preQuery("SELECT * FROM logs_emails WHERE eximid = ?;", [
        $msg['eximid']
    ]);

    if (count($logs) == 0) {
        # We don't - just insert.  Use IGNORE in case we have a stupid long eximid.
        $dbhm->preExec("INSERT IGNORE INTO logs_emails (timestamp, eximid, userid, `from`, `to`, messageid, subject, status) VALUES (?,?,?,?,?,?,?,?);", [
            $timestamp,
            $msg['eximid'],
            $uid,
            Utils::presdef('from', $msg, NULL),
            Utils::presdef('to', $msg, NULL),
            Utils::presdef('messageid', $msg, NULL),
            Utils::presdef('subject', $msg, NULL),
            Utils::presdef('status', $msg, NULL)
        ], FALSE);
    } else {
        # We do.  We might have extra info.
        foreach ($logs as $log) {
            foreach (['userid', 'from', 'to', 'messageid', 'subject'] as $key) {
                if (!Utils::pres($key, $log) && Utils::pres($key, $msg)) {
                    error_log("...add $key = {$msg[$key]} to {$log['id']} for {$msg['eximid']}");
                    $dbhm->preExec("UPDATE logs_emails SET `$key` = ? WHERE id = ?;", [
                        $msg[$key],
                        $log['id']
                    ], FALSE);
                }
            }
        }
    }
}

# We store the time we're upto to speed reparsing.
$lasttime = @file_get_contents('/tmp/iznik.eximlogs.lasttime');
$maxtime = NULL;

while ($line = fgets($fh)) {
    if (preg_match('/(disconnect from)|(connect to)|(connect from)|(timeout after)|(lost connection after)|(configuration reloaded)/', $line)) {
        # Ignore these
    } else if (preg_match('/(...............) (.*?) (.*?)\: (.*?)\: (.*)$/', $line, $matches)) {
        $date = $matches[1];
        $host = $matches[2];
        $proc = $matches[3];
        $msgid = $matches[4];
        $log = $matches[5];

        $i = strtotime($date);

        if ($i >= $lasttime) {
            $maxtime = $i;
            #error_log("Date $date host $host proc $proc msgid $msgid log $log");

            if (!array_key_exists($msgid, $msgs)) {
                $msgs[$msgid] = [
                    'date' => $date,
                    'eximid' => $msgid
                ];
            }

            if ($log == 'removed') {
                # This is the end of this message - we should log it.
                $msgs[$msgid]['date'] = $date;
                logIt($msgs[$msgid]);
                unset($msgs[$msgid]);
            } else {
                if (preg_match('/info\: header Subject\: (.*) from localhost/', $line, $matches)) {
                    $msgs[$msgid]['subject'] = $matches[1];
                } else if (preg_match('/message-id=\<(.*)\>/', $line, $matches)) {
                    $msgs[$msgid]['messageid'] = $matches[1];
                } else if (preg_match('/from=\<(.*)\>,/', $line, $matches)) {
                    $msgs[$msgid]['from'] = $matches[1];
                } else if (preg_match('/to=\<(.*)>.*status=(.*)$/', $line, $matches)) {
                    $msgs[$msgid]['to'] = $matches[1];
                    $msgs[$msgid]['status'] = $matches[2];
                } else if (preg_match('/(no signature data)|(no signing table match for)|(client=localhost)|(key data is not secure)|(daemon started)/', $line)) {
                    # Just ignore.
                } else {
                    #error_log("Unknown $line");
                }
            }
        }
    } else {
        error_log("Unmatched line $line");
    }
}

# We will have some messages which we've not managed to send out.
error_log("Remaining messages " . count($msgs));
foreach ($msgs as $msgid => $msg) {
    logIt($msg);
}

file_put_contents('/tmp/iznik.eximlogs.lasttime', $maxtime . '');

Utils::unlockScript($lockh);