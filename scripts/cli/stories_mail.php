<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:e:');

if (count($opts)) {
    $ids = explode(',', $opts['i']);
    $email = $opts['e'];
    $text = '';

    foreach ($ids as $id) {
        $s = new Story($dbhr, $dbhm, $id);

        if ($s->getId() == $id) {
            $u = new User($dbhr, $dbhm, $s->getPrivate('userid'));
            $text .= "Story #$id\r\n\r\n";
            $text .= "Name: " . $u->getName() . "\r\n";
            $text .= "Email: " . $u->getEmailPreferred() . "\r\n";
            $text .= "Last active: " . $u->getPrivate('lastaccess') . "\r\n";
            $text .= "Location: " . $u->getPublicLocation()['display'] . "\r\n";
            $members = $u->getMemberships();

            foreach ($members as $member) {
                $text .= "Member of: " . $member['namedisplay'] . "\r\n";
            }

            $text .= "\r\n\r\n" . $s->getPrivate('headline') . "\r\n\r\n" . $s->getPrivate('story') . "\r\n\r\n===========\r\n\r\n";
        } else {
            error_log("Invalid id $id");
        }
    }

    $message = \Swift_Message::newInstance()
        ->setSubject("Story information")
        ->setFrom(NOREPLY_ADDR)
        ->setTo($email)
        ->setBody($text);

    Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::STORY);

    list ($transport, $mailer) = Mail::getMailer();

    $mailer->send($message);
}
