<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Should run via cron on 1st day of the month.
$start = date("Y-m-d", strtotime("first day of last month"));
$end = date("Y-m-d", strtotime("first day of this month"));

$msgs = $dbhr->preQuery("SELECT COUNT(DISTINCT(TRIM(REGEXP_REPLACE(subject, '\\[.*?\\](.*)', '$1')))) AS count, CASE WHEN sourceheader LIKE 'TN-%' THEN 1 ELSE 0 END AS tn FROM `messages` INNER JOIN lovejunk ON lovejunk.msgid = messages.id WHERE lovejunk.timestamp >= ? AND lovejunk.timestamp < ? GROUP BY tn ORDER BY tn ASC;", [
    $start,
    $end
]);

$fd = $msgs[0]['count'];
$tn = $msgs[1]['count'];
$tot = $tn + $fd;

$tnp = round(($tn / $tot) * 100);
$fdp = round(($fd / $tot) * 100);

error_log("$start-end TN $tnp vs FD $fdp");

# Amount we divide is £500.
$tnamount = round($tn / $tot * 500, 2);

error_log("TN invoice amount £$tnamount");

list ($transport, $mailer) = Mail::getMailer();
$message = \Swift_Message::newInstance()
    ->setSubject("Please raise an invoice")
    ->setFrom([GEEKS_ADDR => 'Freegle'])
    ->setTo(TN_ADDR)
    ->setCc('log@ehibbert.org.uk')
    ->setDate(time())
    ->setBody(
        "Please raise an invoice on Freegle Ltd for your share of the LoveJunk advertising income, which is £$tnamount for the period from $start (inclusive) to $end (exclusive).  During this period TN provided $tnp% of the posts sent to LoveJunk.\n\n".
        "The invoice should be emailed as a PDF attachment to " . TREASURER_ADDR . ".  Thanks!"
    );

Mail::addHeaders($dbhr, $dbhm, $message, Mail::MODMAIL);

$mailer->send($message);

Utils::unlockScript($lockh);