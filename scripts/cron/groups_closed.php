<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$cs = $dbhr->preQuery("SELECT * FROM groups where settings like '%closed\":1%'");

foreach ($cs as $c) {
    $g = new Group($dbhr, $dbhm, $c['id']);
    $to = $g->getModsEmail();
    error_log($g->getName());

    list ($transport, $mailer) = getMailer();
    $message = Swift_Message::newInstance()
        ->setSubject("Reminder: Your Freegle group is currently closed")
        ->setFrom(GEEKS_ADDR)
        ->setTo($g->getModsEmail())
        ->setCc(MENTORS_ADDR)
        ->setDate(time())
        ->setBody(
            "Hi there - just to remind you that your Freegle group is currently closed.  You're probably keeping it closed intentionally, but if you now feel it's time to re-open then you can go that from ModTools, in Settings->Community->Features for Members->Closed for COVID-19.  We'll send this automated mail once a week."
        );
    $mailer->send($message);
}