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
class communityEventTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $dbhm->preExec("DELETE FROM communityevents WHERE title = 'Test event';");
    }

    public function testBasic() {
        # Create an event and check we can read it back.
        list($c, $id) = $this->createTestCommunityEvent('Test event', 'Test location');
        $this->assertNotNull($id);

        $c->addGroup($this->groupid);
        $start = Utils::ISODate('@' . (time()+600));
        $end = Utils::ISODate('@' . (time()+600));
        $c->addDate($start, $end);

        $atts = $c->getPublic();
        $this->assertEquals('Test event', $atts['title']);
        $this->assertEquals('Test location', $atts['location']);
        $this->assertEquals(1, count($atts['groups']));
        $this->assertEquals($this->groupid, $atts['groups'][0]['id']);
        $this->assertEquals(1, count($atts['dates']));
        $this->assertEquals($start, $atts['dates'][0]['start']);
        $this->assertEquals($start, $atts['dates'][0]['end']);

        # Check that a user sees what we want them to see.
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', 'Test User', 'test@test.com', 'testpw');

        # Not in the right group - shouldn't see.
        $ctx = NULL;
        $events = $c->listForUser($uid, TRUE, $ctx);
        $this->assertEquals(0, count($events));

        # Right group - shouldn't see pending.
        $u->addMembership($this->groupid);
        $ctx = NULL;
        $events = $c->listForUser($uid, TRUE, $ctx);
        $this->assertEquals(0, count($events));

        # Mark not pending - should see.
        $c->setPrivate('pending', 0);
        $ctx = NULL;
        $events = $c->listForUser($uid, FALSE, $ctx);
        $this->assertEquals(1, count($events));
        $this->assertEquals($id, $events[0]['id']);

        # Remove things.
        $c->removeDate($atts['dates'][0]['id']);
        $c->removeGroup($this->groupid);

        $c = new CommunityEvent($this->dbhm, $this->dbhm, $id);
        $atts = $c->getPublic();
        $this->assertEquals(0, count($atts['groups']));
        $this->assertEquals(0, count($atts['dates']));

        # Delete event - shouldn't see it.
        $c->addGroup($this->groupid);
        $c->delete();
        $ctx = NULL;
        $events = $c->listForUser($uid, TRUE, $ctx);
        $this->assertEquals(0, count($events));

        }
}


