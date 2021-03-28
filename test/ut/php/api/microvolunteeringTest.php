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

    protected function setUp()
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

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->addToSpatialIndex();

        # Ask for a volunteering task from this group - not logged in.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        assertEquals(1, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);

        # Ask again - logged in with membership.
        $u->addMembership($gid);
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $ret['microvolunteering']['type']);
        assertEquals($id, $ret['microvolunteering']['msgid']);

        # Response
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_APPROVE
        ]);

        assertEquals(0, $ret['ret']);

        # Again, but reject.
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_REJECT,
            'msgcategory' => MicroVolunteering::MSGCATEGORY_SHOULDNT_BE_HERE,
            'comments' => 'Fish with a bad face'
        ]);

        assertEquals(0, $ret['ret']);

        # Message should still be approved collection because we require a quorum to move.
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals(MessageCollection::APPROVED, $ret['message']['groups'][0]['collection']);

        $u = User::get($this->dbhr, $this->dbhm);
        $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $u->addMembership($gid, User::ROLE_MODERATOR);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);

        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_REJECT,
            'msgcategory' => MicroVolunteering::MSGCATEGORY_SHOULDNT_BE_HERE,
            'comments' => 'Fish with another bad face'
        ]);

        assertEquals(0, $ret['ret']);

        # Message should now be in spam collection.
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals(MessageCollection::SPAM, $ret['message']['groups'][0]['collection']);

        # Should be no messages left as we've given a response, so we'll get a search term.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(MicroVolunteering::CHALLENGE_SEARCH_TERM, $ret['microvolunteering']['type']);

        $ret = $this->call('microvolunteering', 'POST', [
            'searchterm1' => $ret['microvolunteering']['terms'][0]['id'],
            'searchterm2' => $ret['microvolunteering']['terms'][0]['id']
        ]);

        assertEquals(0, $ret['ret']);

        # Check the logging.
        $ret = $this->call('microvolunteering', 'GET', [
            'list' => TRUE
        ]);

        assertEquals(3, count($ret['microvolunteerings']));

        # Create two other users and a difference of opinion.
        $uid2 = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_REJECT,
            'msgcategory' => MicroVolunteering::MSGCATEGORY_SHOULDNT_BE_HERE,
            'comments' => 'Fish with another bad face2'
        ]);

        $uid3 = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_APPROVE
        ]);

        $v = new MicroVolunteering($this->dbhr, $this->dbhm);
        $v->score();

        assertEquals(100, $v->getScore($uid));
        assertEquals(100, $v->getScore($uid2));
        assertEquals(0, $v->getScore($uid3));

        $count = $v->promote($uid, 90, 0);
        assertEquals(1, $count);
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
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $u->setPrivate('trustlevel', User::TRUST_BASIC);
        $u->addMembership($gid);

        # Message is Pending so shouldn't see it as basic.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(MicroVolunteering::CHALLENGE_SEARCH_TERM, $ret['microvolunteering']['type']);

        $u->setPrivate('trustlevel', User::TRUST_MODERATE);
        assertEquals(User::TRUST_MODERATE, $u->getPrivate('trustlevel'));
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $ret['microvolunteering']['type']);
        assertEquals($id, $ret['microvolunteering']['msgid']);
    }

    public function testFacebook() {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g->setPrivate('microvolunteering', 1);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addMembership($gid);

        assertGreaterThan(0, $u->addLogin(User::LOGIN_FACEBOOK, NULL, 'testpw'));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

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
        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [
                'me'
            ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(User::TRUST_DECLINED, $ret['me']['trustlevel']);

        $ret = $this->call('microvolunteering', 'GET', [
            'types' => [
                MicroVolunteering::CHALLENGE_FACEBOOK_SHARE
            ]
        ]);
        assertEquals(0, $ret['ret']);
        error_log(var_export($ret, TRUE));
        assertFalse(array_key_exists('microvolunteering', $ret));

        # Now enable.
        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'trustlevel' => User::TRUST_BASIC
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('microvolunteering', 'GET', [
            'types' => [
                MicroVolunteering::CHALLENGE_FACEBOOK_SHARE
            ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(MicroVolunteering::CHALLENGE_FACEBOOK_SHARE, $ret['microvolunteering']['type']);
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

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();

        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('microvolunteering', 'GET', [
            'types' => [
                MicroVolunteering::CHALLENGE_PHOTO_ROTATE
            ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['microvolunteering']['photos']));

        # Response with rotate.
        $photoid = $ret['microvolunteering']['photos'][0]['id'];
        $ret = $this->call('microvolunteering', 'POST', [
            'photoid' => $photoid,
            'deg' => 90,
            'response' => MicroVolunteering::RESULT_REJECT
        ]);
        assertFalse($ret['rotated']);

        # Again to trigger actual rotate.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Should not be flagged as a supporter.
        $atts = $u->getPublic();
        assertFalse(array_key_exists('supporter', $atts));

        $ret = $this->call('microvolunteering', 'POST', [
            'photoid' => $photoid,
            'deg' => 90,
            'response' => MicroVolunteering::RESULT_REJECT,
            'dup' => 1
        ]);
        assertTrue($ret['rotated']);

        # Should be flagged as a supporter.
        $atts = $u->getPublic();
        assertTrue($atts['supporter']);

        # Ask not to be flagged.
        $u->setSetting('hidesupporter', TRUE);
        $atts = $u->getPublic();
        assertFalse($atts['supporter']);
    }
}
