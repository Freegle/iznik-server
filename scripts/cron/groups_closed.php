<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$cs = $dbhr->preQuery("SELECT * FROM groups where settings like '%closed\":1%'");

foreach ($cs as $c) {
    $g = new Group($dbhr, $dbhm, $c['id']);
    $to = $g->getModsEmail();
    error_log($g->getName());

    list ($transport, $mailer) = Mail::getMailer();
    $message = \Swift_Message::newInstance()
        ->setSubject("Reminder: Your Freegle group is currently closed")
        ->setFrom(GEEKS_ADDR)
        ->setTo($g->getModsEmail())
        ->setCc(MENTORS_ADDR)
        ->setDate(time())
        ->setBody(
            "Hi there - just to remind you that your Freegle group is currently closed.  If you now feel it's time to re-open then you can do that from ModTools, in Settings->Community->Features for Members->Closed for COVID-19.  We'll send this automated mail once a week."
        );
    $mailer->send($message);
}