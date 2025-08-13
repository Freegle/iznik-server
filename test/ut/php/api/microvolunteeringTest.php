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
class microvolunteeringAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic()
    {
        list($g, $gid) = $this->createTestGroup('testgroup1', Group::GROUP_FREEGLE);
        $g->setPrivate('microvolunteering', 1);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup1", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        list($u, $uid, $emailid) = $this->createTestUserWithMembership($gid, User::ROLE_MEMBER, 'Test User', 'test@test.com', 'testpw');
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        list($r, $id, $failok, $rc) = $this->createTestMessage($msg, 'testgroup1', 'from@test.com', 'to@test.com', $gid, $uid, TRUE, FALSE);
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->addToSpatialIndex();

        # Ask for a volunteering task from this group - not logged in.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        $this->assertEquals(1, $ret['ret']);

        list($u, $uid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MEMBER, 'Test', 'User', NULL, 'test2@test.com', 'testpw');
        $u->setPrivate('trustlevel', User::TRUST_BASIC);
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $ret['microvolunteering']['type']);
        $this->assertEquals($id, $ret['microvolunteering']['msgid']);

        # Response
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_APPROVE
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Again, but reject.
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_REJECT,
            'msgcategory' => MicroVolunteering::MSGCATEGORY_SHOULDNT_BE_HERE,
            'comments' => 'Fish with a bad face'
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Message should still be approved collection because we require a quorum to move.
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(MessageCollection::APPROVED, $ret['message']['groups'][0]['collection']);

        list($u, $uid_mod, $emailid_mod) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MODERATOR, 'Test', 'User', 'Test User', 'testmod@test.com', 'testpw');
        $u->setPrivate('trustlevel', User::TRUST_BASIC);

        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_REJECT,
            'msgcategory' => MicroVolunteering::MSGCATEGORY_SHOULDNT_BE_HERE,
            'comments' => 'Fish with another bad face'
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Message should now be in spam collection.
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(MessageCollection::PENDING, $ret['message']['groups'][0]['collection']);

        # Should be no messages left as we've given a response, so we'll get a search term.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MicroVolunteering::CHALLENGE_SEARCH_TERM, $ret['microvolunteering']['type']);

        $ret = $this->call('microvolunteering', 'POST', [
            'searchterm1' => $ret['microvolunteering']['terms'][0]['id'],
            'searchterm2' => $ret['microvolunteering']['terms'][0]['id']
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Check the logging.
        $ret = $this->call('microvolunteering', 'GET', [
            'list' => TRUE
        ]);

        $this->assertEquals(3, count($ret['microvolunteerings']));

        # Create two other users and a difference of opinion.
        $uid2 = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_REJECT,
            'msgcategory' => MicroVolunteering::MSGCATEGORY_SHOULDNT_BE_HERE,
            'comments' => 'Fish with another bad face2'
        ]);

        $uid3 = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_APPROVE
        ]);

        $v = new MicroVolunteering($this->dbhr, $this->dbhm);
        $v->score();

        $this->assertEquals(100, $v->getScore($uid));
        $this->assertEquals(100, $v->getScore($uid2));
        $this->assertEquals(0, $v->getScore($uid3));

        $count = $v->promote($uid, 90, 0);
        $this->assertEquals(1, $count);
    }

    public function testModerate()
    {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g->setPrivate('microvolunteering', 1);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $u->addMembership($gid);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);
        $u->addMembership($gid);

        # Message is Pending so shouldn't see it as basic.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MicroVolunteering::CHALLENGE_SEARCH_TERM, $ret['microvolunteering']['type']);

        $u->setPrivate('trustlevel', User::TRUST_MODERATE);
        $this->assertEquals(User::TRUST_MODERATE, $u->getPrivate('trustlevel'));
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $ret['microvolunteering']['type']);
        $this->assertEquals($id, $ret['microvolunteering']['msgid']);
    }

    public function testModFeedback()
    {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g->setPrivate('microvolunteering', 1);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        list($r, $id, $failok, $rc) = $this->createTestMessage($msg, 'testgroup1', 'from@test.com', 'to@test.com', $gid, $uid, TRUE, FALSE);
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->addToSpatialIndex();

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);

        $u->addMembership($gid);
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $ret['microvolunteering']['type']);
        $this->assertEquals($id, $ret['microvolunteering']['msgid']);

        # Response
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_APPROVE
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Log in as a mod.
        $u = User::get($this->dbhr, $this->dbhm);
        $u->create('Test', 'User', NULL);
        $u->addMembership($gid, User::ROLE_MODERATOR);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        # Get the messages.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::APPROVED
        ]);

        $microvolid = $ret['messages'][0]['microvolunteering'][0]['id'];
        $this->assertNotNull($microvolid);

        # Give feedback.
        $ret = $this->call('microvolunteering', 'PATCH', [
            'id' => $microvolid,
            'feedback' => 'Test feedback'
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::APPROVED
        ]);

        $this->assertEquals('Test feedback', $ret['messages'][0]['microvolunteering'][0]['modfeedback']);
    }

    public function testFacebook() {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g->setPrivate('microvolunteering', 1);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addMembership($gid);

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_FACEBOOK, NULL, 'testpw'));
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $this->dbhm->preExec("INSERT INTO `groups_facebook_toshare` (`id`, `sharefrom`, `postid`, `date`, `data`) VALUES
(1, '134117207097', '134117207097_10153929944247098', NOW(), '{\"id\":\"134117207097_10153929944247098\",\"link\":\"https:\\/\\/www.facebook.com\\/Freegle\\/photos\\/a.395738372097.175912.134117207097\\/10153929925422098\\/?type=3\",\"message\":\"TEST DO NOT SHARE\\nhttp:\\/\\/ilovefreegle.org\\/groups\\/\",\"type\":\"photo\",\"icon\":\"https:\\/\\/www.facebook.com\\/images\\/icons\\/photo.gif\",\"name\":\"Photos from Freegle\'s post\"}') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), date=NOW();");
        $rc = $this->dbhm->preExec("INSERT INTO groups_facebook_toshare (sharefrom, postid, data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);", [
            '134117207097',
            '134117207097_10153929944247098',
            json_encode([])
        ]);

        $id = $this->dbhm->lastInsertId();
        self::assertNotNull($id);

        # Test with disabled.
        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'trustlevel' => User::TRUST_DECLINED
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [
                'me'
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(User::TRUST_DECLINED, $ret['me']['trustlevel']);

        $ret = $this->call('microvolunteering', 'GET', [
            'types' => [
                MicroVolunteering::CHALLENGE_FACEBOOK_SHARE
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(array_key_exists('microvolunteering', $ret));

        # Now enable.
        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'trustlevel' => User::TRUST_BASIC
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('microvolunteering', 'GET', [
            'types' => [
                MicroVolunteering::CHALLENGE_FACEBOOK_SHARE
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MicroVolunteering::CHALLENGE_FACEBOOK_SHARE, $ret['microvolunteering']['type']);
        $fbid = $ret['microvolunteering']['facebook']['id'];

        # Response
        $ret = $this->call('microvolunteering', 'POST', [
            'facebook' => $fbid,
            'response' => MicroVolunteering::RESULT_APPROVE
        ]);

        $this->dbhm->preExec("DELETE FROM groups_facebook_toshare WHERE id = ?", [
            $id
        ]);
    }

    public function testPhotoRotate() {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g->setPrivate('microvolunteering', 1);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        list($r, $id, $failok, $rc) = $this->createTestMessage($msg, 'testgroup1', 'from@test.com', 'to@test.com', $gid, $uid, TRUE, FALSE);

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('microvolunteering', 'GET', [
            'types' => [
                MicroVolunteering::CHALLENGE_PHOTO_ROTATE
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['microvolunteering']['photos']));

        # Response with rotate.
        $photoid = $ret['microvolunteering']['photos'][0]['id'];
        $ret = $this->call('microvolunteering', 'POST', [
            'photoid' => $photoid,
            'deg' => 90,
            'response' => MicroVolunteering::RESULT_REJECT
        ]);
        $this->assertFalse($ret['rotated']);

        # Again to trigger actual rotate.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        # Should not be flagged as a supporter.
        $atts = $u->getPublic();
        $this->assertFalse(array_key_exists('supporter', $atts));

        $ret = $this->call('microvolunteering', 'POST', [
            'photoid' => $photoid,
            'deg' => 90,
            'response' => MicroVolunteering::RESULT_REJECT,
            'dup' => 1
        ]);
        $this->assertTrue($ret['rotated']);

        # Should be flagged as a supporter.
        $atts = $u->getPublic();
        $this->assertTrue($atts['supporter']);

        # Ask not to be flagged.
        $u->setSetting('hidesupporter', TRUE);
        $atts = $u->getPublic();
        $this->assertFalse($atts['supporter']);
    }
}
