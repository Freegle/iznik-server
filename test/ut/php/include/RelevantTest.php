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
class RelevantTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    private $msgsSent = [];

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->msgsSent = [];

        # We test around Tuvalu.  If you're setting up Tuvalu Freegle you may need to change that.
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 179.1 AND swlat <= 179.3;");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE '??%';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'TV13%';");
        for ($swlat = 8.3; $swlat <= 8.6; $swlat += 0.1) {
            for ($swlng = 179.1; $swlng <= 179.3; $swlng += 0.1) {
                $nelat = $swlat + 0.1;
                $nelng = $swlng + 0.1;

                # Use lng, lat order for geometry because the OSM data uses that.
                $dbhm->preExec("INSERT IGNORE INTO locations_grids (swlat, swlng, nelat, nelng, box) VALUES (?, ?, ?, ?, ST_GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))', {$this->dbhr->SRID()}));",
                    [
                        $swlat,
                        $swlng,
                        $nelat,
                        $nelng
                    ]);
            }
        }

        $this->tidy();
    }

    public function sendMock($mailer, $message)
    {
        $this->msgsSent[] = $message->toString();
    }

    public function testInterested()
    {
        $earliest = date('Y-m-d H:i:s');

        # Create two locations
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');

        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_FREEGLE);
        $g->setPrivate('lat', 8.53333);
        $g->setPrivate('lng', 179.2167);
        $g->setPrivate('poly', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $g->setPrivate('onhere', 1);

        list($u, $uid_temp, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Create a user
        $email = 'test1@test.com';
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', $email, 'testpw');
        $this->log("Created user $uid");
        $u->addEmail('ut-' . rand() . '@' . USER_DOMAIN, 0);
        $this->assertTrue($u->login('testpw'));
        $this->log("Emails before first " . var_export($u->getEmails(), TRUE));

        # Post a WANTED and an OFFER.
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $r = new MailRouter($this->dbhr, $this->dbhm);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'testgroup@yahoogroups.com', $msg, $gid);
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $this->log("User $uid $email message $id");
        $this->log("Emails after first " . var_export($u->getEmails(), TRUE));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'WANTED: Another thing (location)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'testgroup@yahoogroups.com', $msg, $gid);
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $this->log("User $uid $email message $id");

        $this->log("Emails after second " . var_export($u->getEmails(), TRUE));

        $l = new Location($this->dbhr, $this->dbhm);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->updateSpatialIndex();

        $rl = new Relevant($this->dbhr, $this->dbhm);
        $ints = $rl->findRelevant($uid, Group::GROUP_FREEGLE, NULL, 'tomorrow');
        $this->log("Found interested 1 " . var_export($ints, TRUE));
        $this->assertEquals(2, count($ints));

        # Now search - no relevant messages at the moment.
        $msgs = $rl->getMessages($uid, $ints, $earliest);
        $this->log("Should be none " . var_export($msgs, TRUE));
        $this->assertEquals(0, count($msgs));

        # Add two relevant messages.
        $this->log("Add two relevant");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: thing (location)', $msg);
        $msg = str_ireplace('Date: Sat, 22 Aug 2015 10:45:58 +0000', 'Date: ' . gmdate(DATE_RFC822, time()), $msg);
        $msg = str_replace('420816297', '420816298', $msg);
        $msg = str_replace('edwhuk', 'edwhuk1', $msg);
       list ($id1, $failok) = $r->received(Message::EMAIL, 'from2@test.com', 'to2@test.com', $msg, $gid);
        $m = new Message($this->dbhr, $this->dbhm, $id1);
        $this->log("Relevant $id1 from " . $m->getFromuser());
        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
        $this->log("From user emails " . var_export($u->getEmails(), TRUE));
        $this->assertNotNull($id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', "OFFER: objets d'art (location)", $msg);
        $msg = str_ireplace('Date: Sat, 22 Aug 2015 10:45:58 +0000', 'Date: ' . gmdate(DATE_RFC822, time()), $msg);
        $msg = str_replace('420816297', '42081629', $msg);
        $msg = str_replace('edwhuk', 'edwhuk2', $msg);
       list ($id2, $failok) = $r->received(Message::EMAIL, 'from2@test.com', 'to2@test.com', $msg, $gid);
        $m = new Message($this->dbhr, $this->dbhm, $id2);
        $this->log("Relevant $id2 from " . $m->getFromuser());
        $this->assertNotNull($id2);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $this->log("Relevant messages $id1 and $id2");

        # View the message to show we're interested.
        $m->like($u->getId(), Message::LIKE_VIEW);

        # Now send messages - should find these.
        $u->setPrivate('lastrelevantcheck', NULL);
        $mock = $this->getMockBuilder('Freegle\Iznik\Relevant')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->setPrivate('lastrelevantcheck', NULL);
        self::assertEquals(0, $mock->sendMessages($uid, NULL, '24 hours ago'));
        self::assertEquals(1, $mock->sendMessages($uid, NULL, 'tomorrow'));

        $msgs = $this->msgsSent;
        $this->log("Should return just $id1");
        $this->assertEquals(1, count($msgs));

        # Long line might split id - hack out QP encoding.
        $msgs = preg_replace("/\=\r\n/", "", $msgs[0]);
        $this->log($msgs);
        self::assertNotFalse(strpos($msgs, $id1));

        # Record the check.  Sleep to ensure that the messages we already have are longer ago than when we
        # say the check happened, otherwise we might get them back again - which is ok in real messages
        # but not for UT where it needs to be predictable.
        sleep(2);
        $rl->recordCheck($uid);
        sleep(2);

        # Now shouldn't find any.
        $msgs = $rl->getMessages($uid, $ints, $earliest);
        $this->log("Should be none " . var_export($msgs, TRUE));
        $this->assertEquals(0, count($msgs));

        # Now another user who has viewed $id2 and therefore should get notified about it.
        $email = 'test2@test.com';
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', $email, 'testpw');
        $this->log("Created user $uid");
        $u->addEmail('ut-' . rand() . '@' . USER_DOMAIN, 0);
        $u->addMembership($gid);
        $m->like($uid, Message::LIKE_VIEW);
        $this->waitBackground();

        $this->msgsSent = [];
        self::assertEquals(1, $mock->sendMessages($uid, NULL, 'tomorrow'));
        $msgs = $this->msgsSent;
        $this->assertEquals(1, count($msgs));
        $msgs = preg_replace("/\=\r\n/", "", $msgs[0]);
        self::assertNotFalse(strpos($msgs, $id2));

        # Exception
        $mock = $this->getMockBuilder('Freegle\Iznik\Relevant')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->willThrowException(new \Exception());

        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->setPrivate('lastrelevantcheck', NULL);
        self::assertEquals(0, $mock->sendMessages($uid, NULL, 'tomorrow'));
    }

    public function testOff() {
        $email = 'test3@test.com';
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', $email, 'testpw');
        $this->log("Created user $uid");

        $mock = $this->getMockBuilder('Freegle\Iznik\Relevant')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        $mock->off($uid);
        $this->assertEquals(1, count($this->msgsSent));
    }
}

