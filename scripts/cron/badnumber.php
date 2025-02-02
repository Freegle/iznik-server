<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Notify by email of unread chats
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

$bads = $dbhr->preQuery("SELECT * FROM users_phones WHERE (lastresponse NOT LIKE 'SM%' OR laststatus IN ('failed', 'undelivered')) AND valid = 1");

foreach ($bads as $bad) {
    error_log("Bad number {$bad['number']} for user #{$bad['userid']} status {$bad['laststatus']}");
    $dbhm->preExec("UPDATE users_phones SET valid = 0 WHERE id = ?;", [
        $bad['id']
    ]);

    $u = new User($dbhr, $dbhm, $bad['userid']);

    $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/notifications');
    $twig = new \Twig_Environment($loader);

    $html = $twig->render('badnumber.html', [
        'number' => $bad['number'],
        'email' => $u->getEmailPreferred(),
    ]);
    $text = "We tried to send a text your your mobile number {$bad['number']}, but it failed.  Usually this is because it wasn't typed in quite right.  We'll stop sending texts to this number, but you can change or remove it in the Notifications of Settings.";

    $message = \Swift_Message::newInstance()
        ->setSubject("Please check your mobile number")
        ->setFrom([NOREPLY_ADDR => SITE_NAME])
        ->setTo([$u->getEmailPreferred() => $u->getName()])
        ->setBody($text);

    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
    # Outlook.
    $htmlPart = \Swift_MimePart::newInstance();
    $htmlPart->setCharset('utf-8');
    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
    $htmlPart->setContentType('text/html');
    $htmlPart->setBody($html);
    $message->attach($htmlPart);

    Mail::addHeaders($dbhr, $dbhm, $message, Mail::BAD_SMS, $u->getId());

    list ($transport, $mailer) = Mail::getMailer();
    $mailer->send($message);
}
