<?php
namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

class Tryst extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = [ 'id', 'user1', 'user2', 'arrangedat', 'arrangedfor' ];
    var $settableatts = [ 'user1', 'user2', 'arrangedfor' ];

    const ACCEPTED = 'Accepted';
    const DECLINED = 'Declined';
    const OTHER = 'Other';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'trysts', 'tryst', $this->publicatts);
    }

    public function create($user1, $user2, $arrangedfor) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?,?,?) ON DUPLICATE KEY update arrangedat = NOW();", [
            $user1,
            $user2,
            $arrangedfor
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'trysts', 'tryst', $this->publicatts);
            }
        }

        return($id);
    }

    public function getPublic($getaddress = TRUE, $userid = NULL)
    {
        $ret = parent::getPublic();
        $ret['arrangedat'] = Utils::ISODate($ret['arrangedat']);
        $ret['arrangedfor'] = Utils::ISODate($ret['arrangedfor']);

        if ($userid) {
            $u1 = User::get($this->dbhr, $this->dbhm, $userid);
            $u2 = User::get($this->dbhr, $this->dbhm, $userid == $this->getPrivate('user1') ? $this->getPrivate('user2') : $this->getPrivate('user1'));
            $ret['calendarLink'] = $this->getCalendarLink($userid, $u1, $u2);
        }

        return($ret);
    }

    public function listForUser($userid, $future = TRUE) {
        $ret = [];

        $mysqltime = $future ? date("Y-m-d H:i:s", time()) : '1970-01-01';

        $trysts = $this->dbhr->preQuery("SELECT id FROM trysts WHERE (user1 = ? OR user2 = ?) AND arrangedfor >= ?;", [
            $userid,
            $userid,
            $mysqltime
        ]);

        foreach ($trysts as $tryst) {
            $r = new Tryst($this->dbhr, $this->dbhm, $tryst['id']);
            $ret[] = $r->getPublic(FALSE, $userid);
        }

        return($ret);
    }

    public function canSee($userid) {
        return $this->id && ($this->tryst['user1'] == $userid || $this->tryst['user2'] == $userid);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM trysts WHERE id = ?;", [ $this->id ]);
        return($rc);
    }

    public function getCalendarLink($userid, $u1, $u2) {
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($this->getPrivate('user1'), $this->getPrivate('user2'));
        $title = 'Handover: ' . $u1->getName() . " and " . $u2->getName();

        $arrangedfor = new \DateTime(Utils::ISODate($this->getPrivate('arrangedfor')));
        $arrangedfor->setTimezone(new \DateTimeZone('Europe/London'));
        $endtime = clone $arrangedfor;
        $endtime->add(new \DateInterval('PT15M'));

        $eventData = [
            'name' => $title,
            'description' => "Please add to your calendar.  If anything changes please let them know through Chat - click https://" . USER_SITE . "/chats/" . $rid,
            'startDate' => $arrangedfor->format('Y-m-d'),
            'startTime' => $arrangedfor->format('H:i'),
            'endTime' => $endtime->format('H:i'),
            'timeZone' => 'Europe/London',
            'location' => ''
        ];

        $encodedData = base64_encode(json_encode($eventData));
        return 'https://' . USER_SITE . '/calendar?data=' . $encodedData;
    }

    public function sendCalendar($userid, $emailOverride = NULL) {
        $ret = NULL;
        $u1 = User::get($this->dbhr, $this->dbhm, $userid);

        if ($u1->notifsOn(User::NOTIFS_EMAIL)) {
            $email = $emailOverride ? $emailOverride : $u1->getEmailPreferred();

            $u2 = User::get($this->dbhr, $this->dbhm, $userid == $this->getPrivate('user1') ? $this->getPrivate('user2') : $this->getPrivate('user1'));

            list ($transport, $mailer) = Mail::getMailer();

            try {
                $calendarLink = $this->getCalendarLink($userid, $u1, $u2);
                $title = 'Handover: ' . $u1->getName() . " and " . $u2->getName();

                // Render MJML template
                $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
                $twig = new \Twig_Environment($loader);

                $html = $twig->render('calendar_invite.html', [
                    'title' => $title,
                    'calendarLink' => $calendarLink,
                    'unsubscribe' => $u1->getUnsubLink(USER_SITE, $userid, User::SRC_CALENDAR)
                ]);

                $message = \Swift_Message::newInstance()
                    ->setSubject("Please add to your calendar - $title")
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setTo($email)
                    ->setBody("You've arranged a handover. Click this link to add it to your calendar: $calendarLink")
                    ->addPart($html, 'text/html');

                Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::CALENDAR, $u1->getId());

                $this->sendIt($mailer, $message);
                $ret = $calendarLink;
            } catch (\Exception $e) {
                error_log("Failed to send calendar invite for {$this->id}" . $e->getMessage());
            }
        }

        return $ret;
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function sendCalendarsDue($id = NULL) {
        $ret = 0;

        $idq = $id ? (" AND id = " . intval($id)) : '';

        # Don't send them if the arrangement is for the same day.  They're less likely to forget and it'll just be
        # annoying.
        $mysqltime = (new \DateTime())->setTime(0,0)->add(new \DateInterval('P1D'))->format('Y-m-d');
        $dues = $this->dbhr->preQuery("SELECT id, arrangedfor FROM trysts WHERE icssent = 0 AND arrangedfor >= ? AND arrangedfor NOT LIKE '% 00:00:00' $idq;", [
            $mysqltime
        ]);

        foreach ($dues as $due) {
            $t = new Tryst($this->dbhr, $this->dbhm, $due['id']);

            if ($t->getPrivate('user1') && $t->getPrivate('user2')) {
                $uid1 = $t->sendCalendar($t->getPrivate('user1'));
                $uid2 = $t->sendCalendar($t->getPrivate('user2'));
                $this->dbhm->preExec("UPDATE trysts SET icssent = 1, ics1uid = ?, ics2uid = ? WHERE id = ?;", [
                    $uid1,
                    $uid2,
                    $due['id']
                ]);
                $ret++;
            }
        }

        return $ret;
    }

    public function sendRemindersDue($id = NULL) {
        $sms = 0;
        $chat = 0;

        $idq = $id ? (" AND id = " . intval($id)) : '';

        # Send reminders for any handovers arranged before today and where the handover is due to happen between
        # 30 minutes and 4 hours from now.  That gives them time to notice the reminder.
        $arrangedat = (new \DateTime())->setTime(0,0)->format('Y-m-d');
        $arrangedfor1 = (new \DateTime())->add(new \DateInterval('PT30M'))->format('Y-m-d H:i:s');
        $arrangedfor2 = (new \DateTime())->add(new \DateInterval('PT4H'))->format('Y-m-d H:i:s');
        $sql = "SELECT id, arrangedfor FROM trysts WHERE remindersent IS NULL AND arrangedat < '$arrangedat' AND arrangedfor BETWEEN '$arrangedfor1' AND '$arrangedfor2' $idq;";
        $dues = $this->dbhr->preQuery($sql);

        foreach ($dues as $due) {
            $t = new Tryst($this->dbhr, $this->dbhm, $due['id']);

            $u1id = $t->getPrivate('user1');
            $u2id = $t->getPrivate('user2');

            if ($u1id && $u2id) {
                $u1 = User::get($this->dbhr, $this->dbhm, $u1id);
                $u2 = User::get($this->dbhr, $this->dbhm, $u2id);

                $u1phone = $u1->getPhone();
                $u2phone = $u2->getPhone();

                $tz1 = new \DateTimeZone('UTC');
                $tz2 = new \DateTimeZone('Europe/London');

                $datetime = new \DateTime('@' . strtotime($t->getPrivate('arrangedfor')), $tz1);
                $datetime->setTimezone($tz2);
                $time = $datetime->format('h:i A');

                $r = new ChatRoom($this->dbhr, $this->dbhm);
                list ($rid, $blocked) = $r->createConversation($u1id, $u2id);
                $url = "https://" . USER_SITE . "/chats/$rid?src=sms";

                if ($u1phone) {
                    $u1->sms(NULL, NULL, TWILIO_FROM, TWILIO_SID, TWILIO_AUTH, "Reminder: handover with " . $u2->getName() . " at $time.  Click $url to let us know if it's still ok.");
                    $sms++;
                }

                if ($u2phone) {
                    $u1->sms(NULL, NULL, TWILIO_FROM, TWILIO_SID, TWILIO_AUTH, "Reminder: handover with " . $u1->getName() . " on $time.  Click $url to let us know if it's still ok.");
                    $sms++;
                }

                if (!$u1phone || !$u2phone) {
                    # No phone number to send to - add into chat which will trigger notifications
                    # and emails.
                    #
                    # By setting platform = FALSE we ensure that we will notify both
                    # parties.  We need to provide a userid for table integrity but it doesn't matter
                    # which we use.
                    $cm = new ChatMessage($this->dbhr, $this->dbhm);
                    $cm->create($rid, $u1id,
                                "Automatic reminder: Handover at $time.  Please confirm that's still ok or let them know if things have changed.  Everybody hates a no-show...",
                                ChatMessage::TYPE_REMINDER,
                    NULL, FALSE);
                    error_log("Chat reminder sent in $rid for users $u1id, $u2id");
                    $chat++;
                }

                $this->dbhm->preExec("UPDATE trysts SET remindersent = NOW() WHERE id = ?;", [
                    $due['id']
                ]);
            }
        }

        return [ $sms, $chat ];
    }

    public function response($userid, $rsp) {
        if ($userid == $this->getPrivate('user1')) {
            $this->setPrivate('user1response', $rsp);
        } else if ($userid == $this->getPrivate('user2')) {
            $this->setPrivate('user2response', $rsp);
        }
    }

    public function confirm() {
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);
        $att = ($this->getPrivate('user1') == $myid) ? 'user1confirmed' : 'user2confirmed';
        $this->dbhm->preExec("UPDATE trysts SET $att = NOW() WHERE id = ?", [
            $this->id
        ]);
    }

    public function decline() {
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);
        $att = ($this->getPrivate('user1') == $myid) ? 'user1declined' : 'user2declined';
        $this->dbhm->preExec("UPDATE trysts SET $att = NOW() WHERE id = ?", [
            $this->id
        ]);
    }
}