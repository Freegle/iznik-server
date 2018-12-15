<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/misc/Authority.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/message/MessageCollection.php';
require_once IZNIK_BASE . '/include/misc/Location.php';
require_once IZNIK_BASE . '/include/misc/Search.php';

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
    }

    protected function tearDown()
    {
//        $this->dbhm->preExec("DELETE FROM authorities WHERE name LIKE 'UTAuth%';");
        parent::tearDown();
    }

    public function testBasic()
    {
        error_log(__METHOD__);

        # Create a group with an OFFER, WANTED and a search on it.
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)', 0);
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)', 0);

        error_log("Postcode $pcid full $fullpcid Area $areaid Location $locid");

        # Create a group there
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_REUSE);
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);

        # Set it to have a default location.
        $this->group->setPrivate('defaultlocation', $fullpcid);

        $r = new MailRouter($this->dbhr, $this->dbhm);

        # Add an OFFER and a WANTED
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: Test (TV13 1HH)', $msg);

        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('locationid', $fullpcid);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'WANTED: Test (TV13 1HH)', $msg);

        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('locationid', $fullpcid);

        # Add a search.
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

        error_log("Get returned " . var_export($ret, TRUE));

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['authority']['id']);
        assertEquals('UTAuth', $ret['authority']['name']);
        assertEquals(1, count($ret['authority']['stats']));
        foreach ($ret['authority']['stats'] as $key => $stat) {
            assertEquals('TV13 1', $key);
            assertEquals(1, $stat[Message::TYPE_OFFER]);
            assertEquals(1, $stat[Message::TYPE_WANTED]);
            assertEquals(1, $stat[Stats::SEARCHES]);
        }

        $ret = $this->call('authority', 'GET', [
            'search' => 'utau'
        ]);

        error_log("Search returned " . var_export($ret, TRUE));

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['authorities']));
        assertEquals($id, $ret['authorities'][0]['id']);

        error_log(__METHOD__ . " end");
    }
//
//    public function testEH()
//    {
//        error_log(__METHOD__);
//
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//        $a = new Authority($this->dbhr, $this->dbhm);
//
//        $ret = $this->call('authority', 'GET', [
//            'id' => 73214
//        ]);
//
//        error_log("Get returned " . var_export($ret, TRUE));
//
//        error_log(__METHOD__ . " end");
//    }
}
