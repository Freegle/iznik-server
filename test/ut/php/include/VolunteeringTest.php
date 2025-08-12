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
class volunteeringTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test opp';");
        $dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test vacancy';");
        $dbhm->preExec("DELETE FROM volunteering WHERE title LIKE 'Test volunteering%';");
    }

    protected function tearDown() : void {
        parent::tearDown ();
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test opp';");
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test vacancy';");
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title LIKE 'Test volunteering%';");
    }

    public function testBasic() {
        # Create an opportunity and check we can read it back.
        $c = new Volunteering($this->dbhm, $this->dbhm);
        $id = $c->create(NULL, 'Test vacancy', FALSE, 'Test location', NULL, NULL, NULL, NULL, NULL, NULL);
        $this->assertNotNull($id);

        $c->addGroup($this->groupid);
        $start = Utils::ISODate('@' . (time()+600));
        $end = Utils::ISODate('@' . (time()+600));
        $c->addDate($start, $end, NULL);

        $atts = $c->getPublic();
        $this->assertEquals('Test vacancy', $atts['title']);
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
        $volunteerings = $c->listForUser($uid, TRUE, FALSE, $ctx);
        $this->assertEquals(0, count($volunteerings));

        # Right group - shouldn't see pending.
        $u->addMembership($this->groupid);
        $ctx = NULL;
        $volunteerings = $c->listForUser($uid, TRUE, FALSE, $ctx);
        $this->assertEquals(0, count($volunteerings));

        # Mark not pending - should see.
        $c->setPrivate('pending', 0);
        $ctx = NULL;
        $volunteerings = $c->listForUser($uid, FALSE, FALSE, $ctx);
        $this->log("Got when not pending " . var_export($volunteerings, TRUE));
        $this->assertEquals(1, count($volunteerings));
        $this->assertEquals($id, $volunteerings[0]['id']);

        # Remove things.
        $c->removeDate($atts['dates'][0]['id']);
        $c->removeGroup($this->groupid);

        $c = new Volunteering($this->dbhm, $this->dbhm, $id);
        $atts = $c->getPublic();
        $this->assertEquals(0, count($atts['groups']));
        $this->assertEquals(0, count($atts['dates']));

        # Delete event - shouldn't see it.
        $c->addGroup($this->groupid);
        $c->delete();
        $ctx = NULL;
        $volunteerings = $c->listForUser($uid, TRUE, FALSE, $ctx);
        $this->assertEquals(0, count($volunteerings));

        }

    public function testExpire() {
        # Test one with a date.
        $c = new Volunteering($this->dbhr, $this->dbhm);

        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $id = $c->create($this->uid, 'Test vacancy', FALSE, 'Test location', NULL, NULL, NULL, NULL, NULL, NULL);
        $this->assertNotNull($id);
        $c->addGroup($this->groupid);

        $start = Utils::ISODate('@' . (time()-600));
        $end = Utils::ISODate('@' . (time()-600));
        $c->addDate($start, $end, NULL);
        $start = Utils::ISODate('@' . (time()+600));
        $end = Utils::ISODate('@' . (time()+600));
        $did = $c->addDate($start, $end, NULL);
        $c->setPrivate('pending', 0);

        # Should see it as not yet expired.
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', 'Test User', 'test1@test.com', 'testpw');
        $u->addMembership($this->groupid);
        $ctx = NULL;
        $volunteerings = $c->listForUser($uid, FALSE, FALSE, $ctx);
        $this->assertEquals(1, count($volunteerings));

        $ctx = NULL;
        $volunteerings = $c->listForGroup(FALSE, $this->groupid, $ctx);
        $this->assertEquals(1, count($volunteerings));

        $this->dbhm->preExec("DELETE FROM volunteering_dates WHERE id = $did;");

        # Should now expire
        $c->expire($id);
        $volunteerings = $c->listForUser($uid, FALSE, FALSE, $ctx);
        $this->assertEquals(0, count($volunteerings));

        # Now test one with no date.
        $id = $c->create($this->uid, 'Test vacancy', FALSE, 'Test location', NULL, NULL, NULL, NULL, NULL, NULL);
        $this->assertNotNull($id);
        $c->addGroup($this->groupid);
        $c->setPrivate('pending', 0);

        # Should see it as not yet expired.
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', 'Test User', 'test1@test.com', 'testpw');
        $u->addMembership($this->groupid);
        $ctx = NULL;
        $volunteerings = $c->listForUser($uid, FALSE, FALSE, $ctx);
        $this->assertEquals(1, count($volunteerings));
        self::assertEquals($id, $volunteerings[0]['id']);

        # Now make it old enough to expire.
        $c->setPrivate('added', '2017-01-01');

        # Ask them to confirm to check we get the mail sent.
        self::assertEquals(1, $c->askRenew($id));
        $c->expire($id);
        $volunteerings = $c->listForUser($uid, FALSE, FALSE, $ctx);
        $this->assertEquals(0, count($volunteerings));

        }

    public function testSystemWide() {
        $c = new Volunteering($this->dbhr, $this->dbhm);

        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $id = $c->create($this->uid, 'Test vacancy', FALSE, 'Test location', NULL, NULL, NULL, NULL, NULL, NULL);
        $this->assertNotNull($id);

        self::assertGreaterThan(0, $c->systemWideCount());

        $c->setPrivate('pending', 0);

        # Should see it as not yet expired.
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', 'Test User', 'test1@test.com', 'testpw');
        $ctx = NULL;
        $volunteerings = $c->listForUser($uid, FALSE, TRUE, $ctx);
        $this->assertEquals(1, count($volunteerings));
        self::assertEquals($id, $volunteerings[0]['id']);

        }
}


