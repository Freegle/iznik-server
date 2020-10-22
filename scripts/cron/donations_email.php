<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$donations = $dbhr->preQuery("SELECT * FROM `users_donations` WHERE DATE(users_donations.timestamp) = DATE(NOW()) ORDER BY timestamp DESC;");
$summ = '<table><tbody>';
$total = 0;

foreach ($donations as $donation) {
    $summ .= "<tr><td>{$donation['timestamp']}</td><td><b>&pound;{$donation['GrossAmount']}</b></td><td>{$donation['Payer']}</td></tr>\n";
    $total += $donation['GrossAmount'];
}

$summ .= "</tbody></table>";

$htmlPart = \Swift_MimePart::newInstance();
$htmlPart->setCharset('utf-8');
$htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
$htmlPart->setContentType('text/html');
$htmlPart->setBody($summ);

$msg = \Swift_Message::newInstance()
    ->setSubject("Donation total today £$total")
    ->setFrom([NOREPLY_ADDR ])
    ->setTo(FUNDRAISING_ADDR);

$msg->attach($htmlPart);

$transport = \Swift_SmtpTransport::newInstance('localhost');
$mailer = \Swift_Mailer::newInstance($transport);
$mailer->send($msg);
