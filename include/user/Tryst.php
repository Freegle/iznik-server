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

    public function getPublic($getaddress = TRUE)
    {
        $ret = parent::getPublic();
        $ret['arrangedat'] = Utils::ISODate($ret['arrangedat']);
        $ret['arrangedfor'] = Utils::ISODate($ret['arrangedfor']);
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
            $ret[] = $r->getPublic(FALSE);
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

    public function sendCalendar($userid) {
        $event = new Event();
        $u1 = User::get($this->dbhr, $this->dbhm, $userid);
        $email = $u1->getEmailPreferred();

        $u2 = User::get($this->dbhr, $this->dbhm, $userid == $this->getPrivate('user1') ? $this->getPrivate('user2') : $this->getPrivate('user1'));
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $r->createConversation($this->getPrivate('user1'), $this->getPrivate('user2'));

        $title = 'Please add to calendar - Freegle Handover: ' . $u1->getName() . " and " . $u2->getName();

        // Create a VCALENDAR.  No point creating an alarm as Google ignores them unless they were generated
        // itself.
        $event->setSummary($title);
        $event->setDescription("Please add this to your calendar to help things go smoothly.\r\n\r\nIf anything changes please let them know through Chat - click https://www.ilovefreegle.org/chat/" . $rid);
        $event->setDtStart(new \DateTime(Utils::ISODate($this->getPrivate('arrangedfor'))));
        $event->setDuration(new \DateInterval('PT15M'));
        $event->setOrganizer(new Organizer("MAILTO:" . NOREPLY_ADDR, [ 'CN' => SITE_NAME ]));
        $event->addAttendee('MAILTO:' . $u1->getEmailPreferred(), [ 'ROLE' => 'REQ-PARTICIPANT', 'PARTSTAT' => 'ACCEPTED', 'CN' => $u1->getName()]);
        $event->addAttendee('MAILTO:' . $u2->getOurEmail(), [ 'ROLE' => 'REQ-PARTICIPANT', 'PARTSTAT' => 'ACCEPTED', 'CN' => $u2->getName()]);
        $event->setUseTimezone(true);
        $event->setTimezoneString('Europe/London');

        $calendar = new Calendar([$event]);
        $calendar->addComponent($event);
        $calendar->setMethod('REQUEST');

        list ($transport, $mailer) = Mail::getMailer();

        try {
            $message = \Swift_Message::newInstance()
                ->setSubject($title)
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setTo($email)
                ->setContentType('text/calendar')
                ->setBody($calendar->render());

            $this->sendIt($mailer, $message);
        } catch (Exception $e) {
            error_log("Failed to send calendar invite $rid " . $e->getMessage());
        }
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function sendCalendarsDue($id = NULL) {
        $idq = $id ? (" AND id = " . intval($id)) : '';

        # Don't send them if the arrangement is for the same day.  They're less likely to forget and it'll just be
        # annoying.
        $mysqltime = (new \DateTime())->setTime(0,0)->add(new \DateInterval('P1D'))->format('Y-m-d');
        $dues = $this->dbhr->preQuery("SELECT id, arrangedfor FROM trysts WHERE icssent = 0 AND arrangedfor >= ? AND arrangedfor NOT LIKE '% 00:00:00' $idq;", [
            $mysqltime
        ]);

        foreach ($dues as $due) {
            $t = new Tryst($this->dbhr, $this->dbhm, $due['id']);
            $t->sendCalendar($t->getPrivate('user1'));
            $t->sendCalendar($t->getPrivate('user2'));
            $t->setPrivate('icssent', 1);
        }
    }
}