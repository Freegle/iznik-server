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
class storiesAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users_stories WHERE headline LIKE 'Test%';");
    }

    public function testBasic() {
        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $this->user->addMembership($this->groupid);

        $this->user->setSetting('mylocation', [
            'lng' => 179.2167,
            'lat' => 8.53333,
            'name' => 'TV13 1HH'
        ]);

        # Create logged out - should fail
        $ret = $this->call('stories', 'PUT', [
            'headline' => 'Test',
            'story' => 'Test'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create logged in - should work
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('stories', 'PUT', [
            'headline' => 'Test',
            'story' => 'Test2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $id = $ret['id'];
        $this->assertNotNull($id);

        # Get with id - should work
        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['story']['id']);
        self::assertEquals('Test', $ret['story']['headline']);
        self::assertEquals('Test2', $ret['story']['story']);

        # Edit
        $ret = $this->call('stories', 'PATCH', [
            'id' => $id,
            'headline' => 'Test2',
            'story' => 'Test2',
            'public' => 0,
            'newsletter' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        self::assertEquals('Test2', $ret['story']['headline']);
        self::assertEquals('Test2', $ret['story']['story']);

        # List stories - should be none as we're not a mod.
        $ret = $this->call('stories', 'GET', [ 'groupid' => $this->groupid ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['stories']));

        # Get logged out - should fail, not public
        $_SESSION['id'] = NULL;
        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        $this->assertEquals(2, $ret['ret']);

        # Make us a mod
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('stories', 'GET', [
            'reviewed' => 0
        ]);
        $this->log("Get as mod " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['stories']));
        self::assertEquals($id, $ret['stories'][0]['id']);

        $ret = $this->call('stories', 'PATCH', [
            'id' => $id,
            'reviewed' => 1,
            'public' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # This should have created a newsfeed entry.
        $nf = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM newsfeed WHERE storyid = ?", [
            $id
        ]);
        $this->assertEquals(1, $nf[0]['count']);

        # Get logged out - should work.
        $_SESSION['id'] = NULL;
        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['story']['id']);
        $this->assertEquals(0, $ret['story']['likes']);
        $this->assertFalse($ret['story']['liked']);

        # List for this group - should work.
        $ret = $this->call('stories', 'GET', [ 'groupid' => $this->groupid ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['stories'][0]['id']);

        # List for newsletter - should not be there as not yet reviewed for it.
        $ret = $this->call('stories', 'GET', [ 'reviewnewsletter' => TRUE ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;
        foreach ($ret['stories'] as $story) {
            if ($story['id'] == $id) {
                $found = TRUE;
            }
        }
        $this->assertFalse($found);

        # Flag as reviewed for inclusion in the newsletter.
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('stories', 'PATCH', [
            'id' => $id,
            'reviewed' => 1,
            'public' => 1,
            'newsletter' => 1,
            'newsletterreviewed' => 1,
            'mailedtomembers' => 0
        ]);
        $this->assertEquals(0, $ret['ret']);
        $_SESSION['id'] = NULL;

        # Should now show up for mods to like their favourites.
        $ret = $this->call('stories', 'GET', [ 'reviewnewsletter' => TRUE ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;
        foreach ($ret['stories'] as $story) {
            if ($story['id'] == $id) {
                $found = TRUE;
            }
        }
        $this->assertTrue($found);

        # Like it.
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('stories', 'POST', [
            'id' => $id,
            'action' => Story::LIKE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['story']['likes']);
        $this->assertTrue($ret['story']['liked']);

        $ret = $this->call('stories', 'POST', [
            'id' => $id,
            'action' => Story::UNLIKE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['story']['likes']);
        $this->assertFalse($ret['story']['liked']);

        # Delete logged out - should fail
        $_SESSION['id'] = NULL;
        $ret = $this->call('stories', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->assertTrue($this->user->login('testpw'));

        # Delete - should work
        $ret = $this->call('stories', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Delete - fail
        $ret = $this->call('stories', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(2, $ret['ret']);

        }

    function testAsk() {
        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertGreaterThan(0, $u->addEmail('test@test.com'));
        $g = new Group($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $this->assertEquals(1, $u->addMembership($this->groupid));
        $u->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Shouldn't yet appear.
        $s = new Story($this->dbhr, $this->dbhm);
        self::assertEquals(0, $s->askForStories('2017-01-01', $uid, 0, 2, NULL));

        # Now mark the message as complete
        $this->log("Mark $origid as TAKEN");
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->mark(Message::OUTCOME_TAKEN, "Thanks", User::HAPPY, $uid);
        $this->waitBackground();

        # Now should ask.
        self::assertEquals(1, $s->askForStories('2017-01-01', $uid, 0, 0, NULL));

        # But not a second time
        self::assertEquals(0, $s->askForStories('2017-01-01', $uid, 0, 0, NULL));
    }
}

