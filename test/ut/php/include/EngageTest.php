<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class engageTest extends IznikTestCase {
    private $dbhr, $dbhm, $msgsSent;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->msgsSent = [];

        $this->tidy();

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $g->create("testgroup", Group::GROUP_FREEGLE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    public function enabled() {
        return [
            [ TRUE ],
            [ FALSE]
        ];
    }

    /**
     * @dataProvider enabled
     */
    public function testAtRisk($enabled) {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $sqltime =  date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 24 * 60 * 60)));
        $u->setPrivate('lastaccess', $sqltime);

        $e = new Engage($this->dbhm, $this->dbhm);

        $this->assertEquals(0, $e->process($uid));

        $sqltime = date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 7 * 24 * 60 * 60)));
        $u->setPrivate('lastaccess', $sqltime);
        $this->assertEquals(0, $e->process($uid));
        $u->addMembership($this->gid);
        $this->group->setSettings([
          'engagement' => $enabled
        ]);

        $this->assertEquals($enabled ? 1 : 0, $e->process($uid));

        # Record success.
        $eids = $this->dbhr->preQuery("SELECT * FROM engage WHERE userid = ?;", [
            $uid
        ]);
        $this->assertEquals($enabled ? 1 : 0, count($eids));

        if ($enabled) {
            $e->recordSuccess($eids[0]['id']);
        }

        $sqltime = date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE - 24 * 60 * 60)));
        $u->setPrivate('lastaccess', $sqltime);
        $this->assertEquals(0, $e->process($uid));
    }

    public function testEngagement() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $u->addMembership($this->gid);

        $this->assertEquals(NULL, $u->getPrivate('engagement'));

        # Created user - should update to null
        $e = new Engage($this->dbhm, $this->dbhm);
        $e->updateEngagement($uid);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(Engage::ENGAGEMENT_NEW, $u->getPrivate('engagement'));

        # Idle - should update to inactive
        $u->setPrivate('lastaccess', date("Y-m-d", strtotime("15 days ago")));
        $e->updateEngagement($uid);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(Engage::ENGAGEMENT_INACTIVE, $u->getPrivate('engagement'));

        # Dormant
        $u->setPrivate('lastaccess', date("Y-m-d", strtotime("190 days ago")));
        $e->updateEngagement($uid);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(Engage::ENGAGEMENT_DORMANT, $u->getPrivate('engagement'));

        # Post, should become occasional.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Thing 1 (Place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id1, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $e->updateEngagement($uid);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(Engage::ENGAGEMENT_OCCASIONAL, $u->getPrivate('engagement'));

        # Post more, should become frequent.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Thing 2 (Place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
       list ($id2, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();

        $this->assertEquals(MailRouter::PENDING, $rc);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Thing 3 (Place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
       list ($id3, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Thing 4 (Place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
       list ($id4, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $e->updateEngagement($uid);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(Engage::ENGAGEMENT_OBSESSED, $u->getPrivate('engagement'));

        # Remove posts so it looks like they've become less active.
        $this->dbhm->preExec("DELETE FROM messages WHERE id in (?, ?);", [
            $id1,
            $id2
        ]);
        $e->updateEngagement($uid);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(Engage::ENGAGEMENT_FREQUENT, $u->getPrivate('engagement'));
    }
}

