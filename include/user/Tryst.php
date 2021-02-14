<?php
namespace Freegle\Iznik;

use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;
use Eluceo\iCal\Property\Event\Organizer;
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
            list ($ics, $id, $title) = $this->createICS($userid, $u1, $u2);
            $ret['ics'] = $ics;
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

    public function createICS($userid, $u1, $u2) {
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $r->createConversation($this->getPrivate('user1'), $this->getPrivate('user2'));
        $title = 'Freegle Handover: ' . $u1->getName() . " and " . $u2->getName();

        $event = new Event();

        // Create a VCALENDAR.  No point creating an alarm as Google ignores them unless they were generated
        // itself.
        $event->setSummary($title);
        $event->setDescription("If anything changes please let them know through Chat - click https://" . USER_SITE . "/chats/" . $rid);
        $event->setDtStart(new \DateTime(Utils::ISODate($this->getPrivate('arrangedfor'))));
        $event->setDuration(new \DateInterval('PT15M'));
        $event->setOrganizer(new Organizer("MAILTO:handover-" . $this->id . '-' . $userid . "@" . USER_DOMAIN, [ 'CN' => SITE_NAME ]));
        $event->addAttendee('MAILTO:' . $u1->getEmailPreferred(), [ 'ROLE' => 'REQ-PARTICIPANT', 'PARTSTAT' => 'ACCEPTED', 'CN' => $u1->getName()]);
        $event->addAttendee('MAILTO:' . $u2->getOurEmail(), [ 'ROLE' => 'REQ-PARTICIPANT', 'PARTSTAT' => 'ACCEPTED', 'CN' => $u2->getName()]);
        $event->setUseTimezone(true);
        $event->setTimezoneString('Europe/London');

        $calendar = new Calendar([$event]);
        $calendar->addComponent($event);
        $calendar->setMethod('REQUEST');
        $op = $calendar->render();
        $op = str_replace("ATTENDEE;", "ATTENDEE;RSVP=TRUE:", $op);

        $id = $event->getUniqueId();

        return [ $op, $id, $title ];
    }

    public function sendCalendar($userid) {
        $ret = NULL;
        $u1 = User::get($this->dbhr, $this->dbhm, $userid);
        $email = $u1->getEmailPreferred();

        $u2 = User::get($this->dbhr, $this->dbhm, $userid == $this->getPrivate('user1') ? $this->getPrivate('user2') : $this->getPrivate('user1'));

        list ($transport, $mailer) = Mail::getMailer();

        try {
            list ($ics, $ret, $title) = $this->createICS($userid, $u1, $u2);

            $message = \Swift_Message::newInstance()
                ->setSubject("Please add to your calendar - $title")
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setTo($email)
                ->setBody('You\'ve arranged a Freegle handover.  Please add this to your calendar to help things go smoothly.')
                ->addPart($ics, 'text/calendar');

            $this->sendIt($mailer, $message);
        } catch (Exception $e) {
            error_log("Failed to send calendar invite for {$this->id}" . $e->getMessage());
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
        $ret = 0;

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
                $time = date('h:i A', strtotime($t->getPrivate('arrangedfor')));
                $r = new ChatRoom($this->dbhr, $this->dbhm);
                $rid = $r->createConversation($u1id, $u2id);
                $url = "https://" . USER_SITE . "/handover/" . $due['id'] . '?src=sms';

                if ($u1phone) {
                    $u1->sms(NULL, NULL, TWILIO_FROM, TWILIO_SID, TWILIO_AUTH, "Reminder: handover with " . $u2->getName() . " at $time.  Click $url to let us know if it's still ok.");
                    $ret++;
                }

                if ($u2phone) {
                    $u1->sms(NULL, NULL, TWILIO_FROM, TWILIO_SID, TWILIO_AUTH, "Reminder: handover with " . $u1->getName() . " on $time.  Click $url to let us know if it's still ok.");
                    $ret++;
                }

                if ($u1phone || $u2phone) {
                    $this->dbhm->preExec("UPDATE trysts SET remindersent = NOW() WHERE id = ?;", [
                        $due['id']
                    ]);
                }
            }
        }

        return $ret;
    }

    public function response($userid, $rsp) {
        if ($userid == $this->getPrivate('user1')) {
            $this->setPrivate('user1response', $rsp);
        } else if ($userid == $this->getPrivate('user2')) {
            $this->setPrivate('user2response', $rsp);
        }
    }

    public function confirm() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;
        $att = ($this->getPrivate('user1') == $myid) ? 'user1confirmed' : 'user2confirmed';
        $this->dbhm->preExec("UPDATE trysts SET $att = NOW() WHERE id = ?", [
            $this->id
        ]);
    }

    public function decline() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;
        $att = ($this->getPrivate('user1') == $myid) ? 'user1declined' : 'user2declined';
        $this->dbhm->preExec("UPDATE trysts SET $att = NOW() WHERE id = ?", [
            $this->id
        ]);
    }
}