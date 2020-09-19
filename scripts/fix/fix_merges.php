<?php

$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/user/User.php');

$merges = $dbhr->preQuery("SELECT * FROM merges WHERE accepted IS NULL AND rejected IS NULL ORDER BY id ASC;");

foreach ($merges as $merge) {
    $user1 = $merge['user1'];
    $user2 = $merge['user2'];
    $email = TRUE;
    $mid = $merge['id'];
    $uid = $merge['uid'];

    $u1 = new User($dbhr, $dbhm, $user1);
    $u2 = new User($dbhr, $dbhm, $user2);

    if (!$u1->getId()) {
        error_log("$user1 no longer exists");
    } else if (!$u2->getId()) {
        error_log("$user2 no longer exists");
    } else {
        $u1mail = $u1->getEmailPreferred();
        $u2mail = $u2->getEmailPreferred();
        error_log("Consider $u1mail $u2mail");
//        $u1mail = 'log@ehibbert.org.uk';
//        $u2mail = 'log@ehibbert.org.uk';
        $doit = FALSE;

        if (!$u1mail || !$u2mail) {
            error_log("...would have failed $user1 $user2 as one has no mail");
            $doit = TRUE;
        } else {
            $relateds = $dbhr->preQuery("SELECT * FROM users_related WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?) AND notified = 0;", [
                $user1,
                $user2,
                $user2,
                $user1
            ]);

            if (count($relateds) == 0) {
                error_log("...seemed to have failed $user1 $user2 as not flagged as notified");
            }

            $doit = TRUE;
        }

        if ($doit) {
            # Create a mailer.
            $spool = new \Swift_FileSpool(IZNIK_BASE . "/spool");
            $spooltrans = \Swift_SpoolTransport::newInstance($spool);
            $smtptrans = \Swift_SmtpTransport::newInstance('localhost');
            $transport = \Swift_FailoverTransport::newInstance([
                $smtptrans,
                $spooltrans
            ]);

            $mailer = \Swift_Mailer::newInstance($transport);

            # Generate the message.
            $url = 'https://' . USER_SITE . '/merge?id=' . $mid . '&uid=' . $uid;
            $subj = "You have multiple Freegle accounts - please read";
            $textbody = "We think you're using two different accounts on Freegle, perhaps by mistake.  Please let us know whether you'd like to combine them by going to $url";

            $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/');
            $twig = new \Twig_Environment($loader);

            $html = $twig->render('merge.html', [
                'name1' => $u1->getName(),
                'email1' => $u1->getEmailPreferred(),
                'name2' => $u2->getName(),
                'email2' => $u2->getEmailPreferred(),
                'url' => $url
            ]);

            if ($u1mail) {
                $message = \Swift_Message::newInstance()
                    ->setSubject($subj)
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setReturnPath($u1->getBounce())
                    ->setBody($textbody);

                $email ? $message->setTo([$u1mail => $u1->getName()]) : 0;

                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($message, Mail::RELEVANT_OFF, $u1->getId());

                $email ? $mailer->send($message) : 0;
                error_log("Sent to $u1mail");
            }

            if ($u2mail) {
                $message = \Swift_Message::newInstance()
                    ->setSubject($subj)
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setReturnPath($u2->getBounce())
                    ->setBody($textbody);

                $email ? $message->setTo([$u2mail => $u2->getName()]) : 0;

                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($message, Mail::RELEVANT_OFF, $u2->getId());

                $email ? $mailer->send($message) : 0;
                error_log("Sent to $u2mail");            }

            # Flag the related users as having been processed.
            $dbhm->preExec("UPDATE users_related SET notified = 1 WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?);", [
                $user1,
                $user2,
                $user2,
                $user1
            ]);
        }
    }
}