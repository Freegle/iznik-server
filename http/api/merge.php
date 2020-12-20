<?php
namespace Freegle\Iznik;

function merge() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET':
        {
            $id = (Utils::presint('id', $_REQUEST, NULL));
            $uid = Utils::presdef('uid', $_REQUEST, NULL);

            $ret = ['ret' => 2, 'status' => 'Invalid parameters'];

            if ($id && $uid) {
                $merges = $dbhr->preQuery("SELECT * FROM merges WHERE id = ? AND uid = ?;", [
                    $id,
                    $uid
                ]);

                foreach ($merges as $merge) {
                    # We found it.
                    $u1 = new User($dbhr, $dbhm, $merge['user1']);
                    $u2 = new User($dbhr, $dbhm, $merge['user2']);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'merge' => [
                            'id' => $id,
                            'uid' => $uid,
                            'user1' => [
                                'id' => $u1->getId(),
                                'name' => $u1->getName(),
                                'email'=> $u1->getEmailPreferred(),
                                'logins' => $u1->getLogins(FALSE)
                            ],
                            'user2' => [
                                'id' => $u2->getId(),
                                'name' => $u2->getName(),
                                'email'=> $u2->getEmailPreferred(),
                                'logins' => $u2->getLogins(FALSE)
                            ]
                        ]
                    ];
                }
            }
            break;
        }
        case 'POST': {
            $id = (Utils::presint('id', $_REQUEST, NULL));
            $uid = Utils::presdef('uid', $_REQUEST, NULL);
            $user1 = (Utils::presint('user1', $_REQUEST, NULL));
            $user2 = (Utils::presint('user2', $_REQUEST, NULL));
            $action = Utils::presdef('action', $_REQUEST, NULL);

            $ret = [ 'ret' => 2, 'status' => 'Invalid parameters' ];

            if ($id && $uid) {
                $merges = $dbhr->preQuery("SELECT * FROM merges WHERE id = ? AND uid = ?;", [
                    $id,
                    $uid
                ]);

                foreach ($merges as $merge) {
                    # We found it.
                    if (($user1 == $merge['user1'] && $user2 == $merge['user2']) ||
                        ($user2 == $merge['user1'] && $user1 == $merge['user2']) ) {
                        # Wouldn't want to let them merge other users.
                        if ($action == 'Accept') {
                            $dbhm->preExec("UPDATE merges SET accepted = NOW() WHERE id = ?;", [
                                $id
                            ]);

                            $u = new User($dbhr, $dbhm);
                            $rc = $u->merge($user1, $user2, 'User requested');

                            $ret = $rc ? [ 'ret' => 0, 'status' => 'Success'] :
                                [ 'ret' => 3, 'status' => 'Merge failed'];
                        } else if ($action == 'Reject') {
                            $dbhm->preExec("UPDATE merges SET rejected = NOW() WHERE id = ?;", [
                                $id
                            ]);

                            $ret = [ 'ret' => 0, 'status' => 'Success'];
                        }
                    }
                }
            }

            break;
        }

        case 'PUT': {
            $user1 = (Utils::presint('user1', $_REQUEST, NULL));
            $user2 = (Utils::presint('user2', $_REQUEST, NULL));
            $email = array_key_exists('email', $_REQUEST) ? filter_var($_REQUEST['email'], FILTER_VALIDATE_BOOLEAN) : TRUE;

            $ret = ['ret' => 2, 'status' => 'Invalid parameters'];

            if ($me && $me->isModerator()) {
                # We're allowed to offer a merge.
                $uid = Utils::randstr(32);

                $dbhm->preExec("INSERT INTO merges (user1, user2, offeredby, uid) VALUES (?, ?, ?, ?);", [
                    $user1,
                    $user2,
                    $me->getId(),
                    $uid
                ]);

                $mid = $dbhm->lastInsertId();

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
                $u1 = new User($dbhr, $dbhm, $user1);
                $u2 = new User($dbhr, $dbhm, $user2);
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

                $u1mail = $u1->getEmailPreferred();

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
                }

                $u2mail = $u2->getEmailPreferred();

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
                }

                # Flag the related users as having been processed.
                $dbhm->preExec("UPDATE users_related SET notified = 1 WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?);", [
                    $user1,
                    $user2,
                    $user2,
                    $user1
                ]);

                $ret = [ 'ret' => 0, 'status' => 'Success', 'id' => $mid, 'uid' => $uid ];
            }

            break;
        }

        case 'DELETE': {
            # Flag not to offer these users for merge.
            $user1 = (Utils::presint('user1', $_REQUEST, NULL));
            $user2 = (Utils::presint('user2', $_REQUEST, NULL));

            $ret = ['ret' => 2, 'status' => 'Invalid parameters'];

            if ($me && $me->isModerator()) {
                $dbhm->preExec("UPDATE users_related SET notified = 1 WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?);", [
                    $user1,
                    $user2,
                    $user2,
                    $user1
                ]);

                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            }

            break;
        }
    }

    return($ret);
}
