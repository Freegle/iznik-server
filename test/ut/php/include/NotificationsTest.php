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
class notificationsTest extends IznikTestCase {
    private $dbhr, $dbhm;
    private $msgsSent = [];

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test Original Poster';", []);
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test Commenter';", []);

        # We test around Tuvalu.  If you're setting up Tuvalu Freegle you may need to change that.
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 179.1 AND swlat <= 179.3;");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'TV13%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE '??%';");
        for ($swlat = 8.3; $swlat <= 8.6; $swlat += 0.1) {
            for ($swlng = 179.1; $swlng <= 179.3; $swlng += 0.1) {
                $nelat = $swlat + 0.1;
                $nelng = $swlng + 0.1;

                # Use lng, lat order for geometry because the OSM data uses that.
                $dbhm->preExec("INSERT IGNORE INTO locations_grids (swlat, swlng, nelat, nelng, box) VALUES (?, ?, ?, ?, GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))'));",
                    [
                        $swlat,
                        $swlng,
                        $nelat,
                        $nelng
                    ]);
            }
        }

        $grids = $dbhr->preQuery("SELECT * FROM locations_grids WHERE swlng >= 179.1 AND swlng <= 179.3;");
        foreach ($grids as $grid) {
            $sql = "SELECT id FROM locations_grids WHERE MBRTouches (GeomFromText('POLYGON(({$grid['swlng']} {$grid['swlat']}, {$grid['swlng']} {$grid['nelat']}, {$grid['nelng']} {$grid['nelat']}, {$grid['nelng']} {$grid['swlat']}, {$grid['swlng']} {$grid['swlat']}))'), box);";
            $touches = $dbhr->preQuery($sql);
            foreach ($touches as $touch) {
                $sql = "INSERT IGNORE INTO locations_grids_touches (gridid, touches) VALUES (?, ?);";
                $rc = $dbhm->preExec($sql, [ $grid['id'], $touch['id'] ]);
            }
        }
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    public function testEmail() {
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        assertNotNull($lid);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test Original Poster');
        $this->log("Created user $uid1");
        $u = User::get($this->dbhr, $this->dbhm, $uid1);
        $u->setPrivate('lastlocation', $lid);
        $email = 'test1@test.com';
        $this->log("Added email " . $u->addEmail($email) . " vs " . $u->getEmailPreferred());

        $uid2 = $u->create(NULL, NULL, 'Test Commenter');
        $this->log("Created user $uid2");
        $u = User::get($this->dbhr, $this->dbhm, $uid2);
        $u->setPrivate('lastlocation', $lid);
        $email = 'test2@test.com';
        $this->log("Added email " . $u->addEmail($email));

        $n = $this->getMockBuilder('Freegle\Iznik\Notifications')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('sendIt'))
            ->getMock();

        $n->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        $f = new Newsfeed($this->dbhm, $this->dbhm);
        $nid = $f->create(Newsfeed::TYPE_MESSAGE, $uid1, 'Test message');
        $this->log("Reply by $uid2 to $nid should notify $uid1");
        $rid = $f->create(Newsfeed::TYPE_MESSAGE, $uid2, 'Test reply', NULL, NULL, $nid);
        $this->log("Reply $rid");
        $n->add($uid2, $uid1, Notifications::TYPE_LOVED_POST, $nid);

        # We have the "about me" notification as well as the expected 2.
        self::assertEquals(3, $n->sendEmails($uid1, '0 seconds ago', '7 days ago'));
        $rid2 = $f->create(Newsfeed::TYPE_MESSAGE, $uid1, 'Test reply to reply', NULL, NULL, $nid);
        $this->log("Reply $rid2");

        $n->add($uid1, $uid2, Notifications::TYPE_LOVED_COMMENT, $rid);
        assertTrue($n->haveSent($uid2, Notifications::TYPE_LOVED_COMMENT, '24 hours ago'));

//        $n->add($uid2, $uid1, Notifications::TYPE_COMMENT_ON_YOUR_POST, $nid);
//        $n->add($uid1, $uid2, Notifications::TYPE_COMMENT_ON_COMMENT, $rid);

        sleep(1);
        self::assertEquals(3, $n->sendEmails($uid2, '0 seconds ago', '7 days ago'));
    }

    public function testDeleted1() {
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        assertNotNull($lid);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test Original Poster');
        $this->log("Created user $uid1");
        $u = User::get($this->dbhr, $this->dbhm, $uid1);
        $u->setPrivate('lastlocation', $lid);
        $email = 'test1@test.com';
        $this->log("Added email " . $u->addEmail($email) . " vs " . $u->getEmailPreferred());

        $uid2 = $u->create(NULL, NULL, 'Test Commenter');
        $this->log("Created user $uid2");
        $u = User::get($this->dbhr, $this->dbhm, $uid2);
        $u->setPrivate('lastlocation', $lid);

        $n = new Notifications($this->dbhr, $this->dbhm);

        $f = new Newsfeed($this->dbhm, $this->dbhm);

        $nid = $f->create(Newsfeed::TYPE_MESSAGE, $uid1, 'Test message');
        $this->log("Reply by $uid2 to $nid should notify $uid1");
        $rid = $f->create(Newsfeed::TYPE_MESSAGE, $uid2, 'Test reply', NULL, NULL, $nid);
        $this->log("Reply $rid");
        $n->add($uid1, $uid2, Notifications::TYPE_LOVED_COMMENT, $rid);

        # Check notification counts for each.
        $ctx = NULL;
        $nots = $n->get($uid1, $ctx);
        assertEquals(2, count($nots));

        $ctx = NULL;
        $nots = $n->get($uid2, $ctx);
        assertEquals(2, count($nots));

        # Delete the comment.
        $_SESSION['id'] = $uid2;
        $f = new Newsfeed($this->dbhr, $this->dbhm, $rid);
        $f->delete();

        # Notification for comment shouldn't appear.
        $ctx = NULL;
        $nots = $n->get($uid1, $ctx);
        assertEquals(1, count($nots));

        $ctx = NULL;
        $nots = $n->get($uid2, $ctx);
        assertEquals(1, count($nots));
        assertEquals(Notifications::TYPE_ABOUT_ME, $nots[0]['type']);
    }

    public function testDeleted2() {
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        assertNotNull($lid);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test Original Poster');
        $this->log("Created user $uid1");
        $u = User::get($this->dbhr, $this->dbhm, $uid1);
        $u->setPrivate('lastlocation', $lid);
        $email = 'test1@test.com';
        $this->log("Added email " . $u->addEmail($email) . " vs " . $u->getEmailPreferred());

        $uid2 = $u->create(NULL, NULL, 'Test Commenter');
        $this->log("Created user $uid2");
        $u = User::get($this->dbhr, $this->dbhm, $uid2);
        $u->setPrivate('lastlocation', $lid);

        $n = new Notifications($this->dbhr, $this->dbhm);

        $f = new Newsfeed($this->dbhm, $this->dbhm);

        $nid = $f->create(Newsfeed::TYPE_MESSAGE, $uid1, 'Test message');
        $this->log("Reply by $uid2 to $nid should notify $uid1");
        $rid = $f->create(Newsfeed::TYPE_MESSAGE, $uid2, 'Test reply', NULL, NULL, $nid);
        $this->log("Reply $rid");
        $n->add($uid1, $uid2, Notifications::TYPE_LOVED_COMMENT, $rid);

        # Check notification counts for each.
        $ctx = NULL;
        $nots = $n->get($uid1, $ctx);
        assertEquals(2, count($nots));

        $ctx = NULL;
        $nots = $n->get($uid2, $ctx);
        assertEquals(2, count($nots));

        # Delete the thread.  Don't delete the notifs, so that we can test coverage.
        $_SESSION['id'] = $uid1;
        $f = new Newsfeed($this->dbhr, $this->dbhm, $nid);
        $f->delete(FALSE);

        # Notification for comment shouldn't appear.
        $ctx = NULL;
        $nots = $n->get($uid1, $ctx);
        assertEquals(1, count($nots));

        $ctx = NULL;
        $nots = $n->get($uid2, $ctx);
        assertEquals(1, count($nots));
        assertEquals(Notifications::TYPE_ABOUT_ME, $nots[0]['type']);
    }

    public function testOff() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test Original Poster');
        $this->log("Created user $uid1");
        $u = User::get($this->dbhr, $this->dbhm, $uid1);
        $email = 'test1@test.com';
        $this->log("Added email " . $u->addEmail($email) . " vs " . $u->getEmailPreferred());

        $n = $this->getMockBuilder('Freegle\Iznik\Notifications')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('sendIt'))
            ->getMock();

        $n->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        $n->off($uid1);
        assertEquals(1, count($this->msgsSent));
    }
}

