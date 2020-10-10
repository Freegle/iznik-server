<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$u = new User($dbhr, $dbhm, 3750906);
$to = $u->getEmailPreferred();
$msgid = 71169008;
$m = new Message($dbhr, $dbhm, $msgid);
$subj = $m->getSubject();
$g = new Group($dbhr, $dbhm, 126647);
$gatts = $g->getPublic();

$loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
$twig = new \Twig_Environment($loader);

# Remove any group tag.
$subj = trim(preg_replace('/^\[.*?\](.*)/', "$1", $subj));

$completed = $u->loginLink(USER_SITE, $u->getId(), "/mypost/$msgid/completed", User::SRC_CHASEUP);
$withdraw = $u->loginLink(USER_SITE, $u->getId(), "/mypost/$msgid/withdraw", User::SRC_CHASEUP);
$repost = $u->loginLink(USER_SITE, $u->getId(), "/mypost/$msgid/repost", User::SRC_CHASEUP);
$othertype = $m->getType() == Message::TYPE_OFFER ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
$text = "Can you let us know what happened with this?  Click $repost to post it again, or $completed to mark as $othertype, or $withdraw to withdraw it.  Thanks.";

$html = $twig->render('chaseup.html', [
'subject' => $subj,
'name' => $u->getName(),
'email' => $to,
'type' => $othertype,
'repost' => $repost,
'completed' => $completed,
'withdraw' => $withdraw
]);

list ($transport, $mailer) = Mail::getMailer();

if (\Swift_Validate::email($to)) {
$message = \Swift_Message::newInstance()
->setSubject("Re: " . $subj)
->setFrom([$g->getAutoEmail() => $gatts['namedisplay']])
->setReplyTo([$g->getModsEmail() => $gatts['namedisplay']])
//->setTo($to)
    ->setTo('edward@ehibbert.org.uk')
->setBody($text);

# Add HTML in base-64 as default quoted-printable encoding leads to problems on
# Outlook.
$htmlPart = \Swift_MimePart::newInstance();
$htmlPart->setCharset('utf-8');
$htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
$htmlPart->setContentType('text/html');
$htmlPart->setBody($html);
$message->attach($htmlPart);

$mailer->send($message);
}
