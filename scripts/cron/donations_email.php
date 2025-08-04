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
    $summ .= "<tr><td>{$donation['timestamp']}</td><td><b>&pound;{$donation['GrossAmount']}</b></td><td>{$donation['Payer']}</td>";

    $recurring = $donation['TransactionType'] == 'recurring_payment' || $donation['TransactionType'] == 'subscr_payment';
    
    # Check if donor is member of a group that had a birthday in the last 2 days
    $birthday = false;
    if ($donation['userid']) {
        $twoDaysAgo = date('m-d', strtotime('-2 days'));
        $yesterday = date('m-d', strtotime('-1 day'));
        $today = date('m-d');
        
        $birthdayGroups = $dbhr->preQuery("SELECT DISTINCT g.id 
                                         FROM `groups` g
                                         INNER JOIN memberships m ON g.id = m.groupid
                                         WHERE m.userid = ?
                                         AND g.type = ?
                                         AND g.publish = 1
                                         AND g.onmap = 1
                                         AND (DATE_FORMAT(g.founded, '%m-%d') = ? 
                                              OR DATE_FORMAT(g.founded, '%m-%d') = ?
                                              OR DATE_FORMAT(g.founded, '%m-%d') = ?)
                                         AND YEAR(NOW()) - YEAR(g.founded) > 0", [
            $donation['userid'],
            Group::GROUP_FREEGLE,
            $twoDaysAgo,
            $yesterday,
            $today
        ]);
        
        $birthday = count($birthdayGroups) > 0;
    }

    $statusCell = '';
    if ($recurring) {
        $statusCell .= 'Recurring';
    }
    if ($birthday) {
        if ($statusCell) $statusCell .= ', ';
        $statusCell .= 'Birthday?';
    }
    
    $summ .= "<td>$statusCell</td>";

    $summ .= "</tr>\n";
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

Mail::addHeaders($dbhr, $dbhm, $msg, Mail::DONATE_IPN);

list ($transport, $mailer) = Mail::getMailer();
$mailer->send($msg);
