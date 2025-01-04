<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# We might have messages which have an explicit deadline.
$earliestmessage = date("Y-m-d", strtotime("Midnight " . Message::EXPIRE_TIME . " days ago"));
$msgs = $dbhr->preQuery("SELECT messages.id, messages_groups.groupid FROM messages
    INNER JOIN messages_groups ON messages_groups.msgid = messages.id
    LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
    WHERE messages.arrival >= ? AND deadline IS NOT NULL AND deadline < CURDATE() AND messages_outcomes.id IS NULL
;", [
    $earliestmessage
]);

foreach ($msgs AS $msg) {
    $m = new Message($dbhr, $dbhm, $msg['id']);
    error_log("Deadline expired for #{$msg['id']} " . $m->getSubject());
    $m->mark(Message::OUTCOME_EXPIRED, "reached deadline", NULL, NULL);

    # Mail them to trigger action.
    $u = new User($dbhr, $dbhm, $m->getFromuser());

    $completed = $u->loginLink(
        USER_SITE,
        $u->getId(),
        "/mypost/{$msg['id']}/completed",
        User::SRC_CHASEUP
    );
    $withdraw = $u->loginLink(
        USER_SITE,
        $u->getId(),
        "/mypost/{$msg['id']}/withdraw",
        User::SRC_CHASEUP
    );

    $extend = $u->loginLink(
        USER_SITE,
        $u->getId(),
        "/mypost/{$msg['id']}/extend",
        User::SRC_CHASEUP
    );

    $othertype = $m->getType() == Message::TYPE_OFFER ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;

    $text = "Your message has now reached the deadline you set.  Click $extend to extend the deadline, or $completed to mark as $othertype, or $withdraw to withdraw it.  Thanks.";

    $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
    $twig = new \Twig_Environment($loader);

    $html = $twig->render(
        'chaseup.html',
        [
            'subject' => "Your post has reached its deadline",
            'name' => $m->getFromname(),
            'email' => $u->getEmailPreferred(),
            'type' => $othertype,
            'extend' => $extend,
            'completed' => $completed,
            'withdraw' => $withdraw
        ]
    );

    list ($transport, $mailer) = Mail::getMailer();

    if (\Swift_Validate::email($u->getEmailPreferred())) {
        $subj = $m->getSubject();

        $g = new Group($dbhr, $dbhm, $msg['groupid']);
        $gatts = $g->getPublic();

        $message = \Swift_Message::newInstance()
            ->setSubject("Deadline reached: " . $subj)
            ->setFrom([$g->getAutoEmail() => $gatts['namedisplay']])
            ->setReplyTo([$g->getModsEmail() => $gatts['namedisplay']])
            ->setTo($u->getEmailPreferred())
            ->setBody($text);

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        Mail::addHeaders($dbhr, $dbhm, $message,Mail::CHASEUP, $u->getId());

        $mailer->send($message);
    }
}

# We might have messages indexed which have expired because of group repost settings.  If so, add an actual
# expired outcome and remove them from the index.
$msgs = $dbhr->preQuery("SELECT msgid FROM messages_spatial WHERE successful = 0;");

$count = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);
    $m->processExpiry();
    $count++;

    if ($count % 100 == 0) {
        error_log("$count / " . count($msgs));
    }
}

Utils::unlockScript($lockh);