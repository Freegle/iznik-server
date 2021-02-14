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
class authorityAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM authorities WHERE name LIKE 'UTAuth%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'TV13%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'TV1 %';");
    }

    protected function tearDown()
    {
//        $this->dbhm->preExec("DELETE FROM authorities WHERE name LIKE 'UTAuth%';");
        parent::tearDown();
    }

    public function testBasic()
    {
        # Create a group with an OFFER, WANTED and a search on it.
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)', 0);
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)', 0);

        $this->log("Postcode $pcid full $fullpcid Area $areaid Location $locid");

        # Create a group there
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('publish', 1);
        $this->group->setPrivate('onmap', 1);
        $this->group->setPrivate('polyofficial', 'POLYGON((179.25 8.5, 179.27 8.5, 179.27 8.6, 179.2 8.6, 179.25 8.5))');

        # Set it to have a default location.
        $this->group->setPrivate('defaultlocation', $fullpcid);

        $r = new MailRouter($this->dbhr, $this->dbhm);

        $this->user = User::get($this->dbhr, $this->dbhm);
        $this->user->create(NULL, NULL, 'Test User');
        $this->user->addEmail('test@test.com');
        $this->user->addMembership($this->groupid);
        $this->user->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Add an OFFER and a WANTED
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: Test (TV13 1HH)', $msg);

        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('locationid', $fullpcid);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'WANTED: Test (TV13 1HH)', $msg);

        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('locationid', $fullpcid);

        # Add a search.  Need to be logged in.
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $m = new Message($this->dbhr, $this->dbhm);
        $ctx = NULL;
        $m->search("Test", $ctx, Search::Limit, NULL, NULL, $fullpcid, FALSE);

        # Create an authority which covers that group.
        $a = new Authority($this->dbhr, $this->dbhm);
        $id = $a->create("UTAuth", 'GLA', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');

        $ret = $this->call('authority', 'GET', [
            'id' => $id,
            'stats' => TRUE
        ]);

        $this->log("Get returned " . var_export($ret, TRUE));

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['authority']['id']);
        assertEquals('UTAuth', $ret['authority']['name']);
        assertEquals(1, count($ret['authority']['stats']));
        assertEquals(1, count($ret['authority']['groups']));

        foreach ($ret['authority']['stats'] as $key => $stat) {
            assertEquals('TV13 1', $key);
            assertEquals(1, $stat[Message::TYPE_OFFER]);
            assertEquals(1, $stat[Message::TYPE_WANTED]);
            assertEquals(1, $stat[Stats::SEARCHES]);
        }

        $ret = $this->call('authority', 'GET', [
            'search' => 'utau'
        ]);

        $this->log("Search returned " . var_export($ret, TRUE));

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['authorities']));
        assertEquals($id, $ret['authorities'][0]['id']);

    }

    public function testStory() {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        assertNotNull($areaid);

        $a = new Authority($this->dbhr, $this->dbhm);
        $id = $a->create("UTAuth", 'GLA', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');

        # Create a user within that authority.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->setSetting('mylocation', [
            'lng' => 179.2167,
            'lat' => 8.53333,
            'name' => 'TV13 1HH'
        ]);

        # Create a story for that user, hence within the authority.
        $s = new Story($this->dbhr, $this->dbhm);
        $sid = $s->create($uid, 1, "Test", "Test");
        $s->setAttributes([
                              'newsletterreviewed' => 1,
                              'newsletter' => 1,
                              'reviewed' => 1,
                              'public' => 1
                          ]);

        $this->dbhm->preExec("UPDATE users_stories SET newsletterreviewed = 1, newsletter = 1 WHERE id = ?;", [ $sid ]);

        # Should be able to get it.
        $ret = $this->call('stories', 'GET', [
            'authorityid' => $id
        ]);

        assertEquals(1, count($ret['stories']));
        assertEquals($sid, $ret['stories'][0]['id']);
    }
//
//    public function testEH()
//    {
//        //
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//        $a = new Authority($this->dbhr, $this->dbhm);
//
//        $ret = $this->call('authority', 'GET', [
//            'id' => 73214
//        ]);
//
//        $this->log("Get returned " . var_export($ret, TRUE));
//
//        //    }
}
