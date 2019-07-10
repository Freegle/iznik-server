<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/newsfeed/Newsfeed.php';
require_once IZNIK_BASE . '/include/misc/Location.php';
require_once IZNIK_BASE . '/include/group/CommunityEvent.php';
require_once IZNIK_BASE . '/include/group/Volunteering.php';
require_once IZNIK_BASE . '/include/group/Facebook.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class newsfeedAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;
    private $msgsSent = [];

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");

        $l = new Location($this->dbhr, $this->dbhm);
        $this->areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        $areaatts = $l->getPublic();
        assertNotNull($this->areaid);
        $this->pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)', 0);

        $this->user = User::get($this->dbhr, $this->dbhm);
        $this->uid = $this->user->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user->setPrivate('lastlocation', $this->fullpcid);
        $this->user->setSetting('mylocation', $areaatts);
        assertEquals('Tuvalu Central', $this->user->getPublicLocation()['display']);

        $this->user2 = User::get($this->dbhr, $this->dbhm);
        $this->uid2 = $this->user2->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user2->setPrivate('lastlocation', $this->fullpcid);
        $this->user2->addEmail('test@test.com');

        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test opp';");
    }

    protected function tearDown() {
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $this->dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $this->dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test opp';");
        parent::tearDown ();
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    public function testBasic() {
        # Logged out.
        $this->log("Logged out");
        $ret = $this->call('newsfeed', 'GET', []);
        assertEquals(1, $ret['ret']);

        # Logged in - empty
        $this->log("Logged in - empty");
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('newsfeed', 'GET', []);
        $this->log("Returned " . gettype($ret['newsfeed']));
        assertEquals(0, $ret['ret']);
        assertEquals(0, count((array)$ret['ret']['newsfeed']));
        assertEquals(0, count((array)$ret['ret']['users']));

        # Post something.
        $this->log("Post something as {$this->uid}");
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test with url https://google.co.uk'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test reply',
            'replyto' => $nid
        ]);
        assertEquals(0, $ret['ret']);

        # Get this individual one
        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals($nid, $ret['newsfeed']['id']);
        self::assertEquals('Google', $ret['newsfeed']['preview']['title']);
        self::assertEquals('Test with url https://google.co.uk', $ret['newsfeed']['message']);

        # Edit it.
        $newsfeedtext = 'Test2 with url https://google.co.uk with some extra length to make sure it gets digested';
        $ret = $this->call('newsfeed', 'PATCH', [
            'id' => $nid,
            'message' => $newsfeedtext
        ]);
        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals($newsfeedtext, $ret['newsfeed']['message']);

        # Generate some other activity which will result in newsfeed entries
        # - aboutme
        # - noticeboard
        # - story
        $ret = $this->call('session', 'PATCH', [
            'aboutme' => "Something long about me which will be interesting enough for the digest"
        ]);
        self::assertEquals(0, $ret['ret']);

        $ret = $this->call('noticeboard', 'POST', [
            'lat' => 8.535,
            'lng' => 179.215,
            'description' => 'Test description'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('noticeboard', 'PATCH', [
            'id' => $ret['id'],
            'name' => 'UTTest2'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('stories', 'PUT', [
            'headline' => 'Test story, nice and long so it gets included',
            'story' => 'Test'
        ]);
        assertEquals(0, $ret['ret']);

        $this->user->setPrivate('systemrole', User::ROLE_MODERATOR);
        $ret = $this->call('stories', 'PATCH', [
            'id' => $ret['id'],
            'reviewed' => 1,
            'public' => 1
        ]);
        assertEquals(0, $ret['ret']);

        # Should mail out to the other user.
        $n = $this->getMockBuilder('Newsfeed')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('sendIt'))
            ->getMock();

        $n->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        assertEquals(4, $n->digest($this->uid2));
        $this->waitBackground();
        assertEquals(0, $n->digest($this->uid2));

        # Hack it to have a message for coverage
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.5);

        $m = new Message($this->dbhr, $this->dbhm);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'testgroup1@yahoogroups.com', $msg);
        list($mid, $already) = $m->save();
        $this->dbhm->preExec("UPDATE newsfeed SET msgid = ? WHERE id = ?;", [
            $mid,
            $nid
        ]);

        # Hide it - should only show to the poster.
        $this->dbhm->preExec("UPDATE newsfeed SET hidden = NOW() WHERE id = ?;", [
            $nid
        ]);

        $this->log("Logged in - one item");
        $ret = $this->call('newsfeed', 'GET', [
            'types' => [
                Newsfeed::TYPE_MESSAGE
            ]
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['newsfeed']));
        self::assertEquals($newsfeedtext, $ret['newsfeed'][0]['message']);
        assertEquals(1, count($ret['users']));
        self::assertEquals($this->uid, array_pop($ret['users'])['id']);
        self::assertEquals($mid, $ret['newsfeed'][0]['refmsg']['id']);

        # Like
        assertTrue($this->user2->login('testpw'));
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Love'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        self::assertEquals(1, $ret['newsfeed']['loves']);
        self::assertTrue($ret['newsfeed']['loved']);

        # Will have generated a notification, plus the one for "about me".
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('notification', 'GET', [
            'count' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals(2, $ret['count']);

        # Notification payload
        $u = new User($this->dbhr, $this->dbhm, $this->user->getId());
        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route) = $u->getNotificationPayload(FALSE);
        $this->log("Payload $title for $total");
        assertEquals(2, $total);
        assertEquals("Test User loved your post 'Test2 with url https://google.co.uk with some extra length...' +1 more...", $title);

        $ret = $this->call('notification', 'GET', []);
        $this->log("Notifications " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        self::assertEquals(2, count($ret['notifications']));
        self::assertEquals($this->uid2, $ret['notifications'][0]['fromuser']['id']);
        $notifid = $ret['notifications'][0]['id'];

        # Mark it as seen
        $ret = $this->call('notification', 'POST', [
            'id' => $notifid,
            'action' => 'Seen'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('notification', 'POST', [
            'id' => $notifid,
            'action' => 'AllSeen'
        ]);
        assertEquals(0, $ret['ret']);

        assertTrue($this->user2->login('testpw'));
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Seen'
        ]);
        assertEquals(0, $ret['ret']);

        assertTrue($this->user2->login('testpw'));
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Unlove'
        ]);
        assertEquals(0, $ret['ret']);

        # Shouldn't show as hidden
        $ret = $this->call('newsfeed', 'GET', [
            'types' => [
                Newsfeed::TYPE_MESSAGE
            ]
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals(0, count($ret['newsfeed']));

        assertTrue($this->user->login('testpw'));
        $ret = $this->call('newsfeed', 'GET', [
            'count' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        assertTrue($this->user->login('testpw'));
        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals(0, $ret['newsfeed']['loves']);
        self::assertFalse($ret['newsfeed']['loved']);

        # Reply
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test',
            'replyto' => $nid
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'types' => [
                Newsfeed::TYPE_MESSAGE
            ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['newsfeed']));
        assertEquals(2, count($ret['newsfeed'][0]['replies']));

        # Refer it to WANTED - generates another reply.
        $this->log("Refer to WANTED");
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'ReferToWanted'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(3, count($ret['newsfeed']['replies']));

        # Refer it to OFFER - generates another reply.
        $this->log("Refer to OFFER");
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'ReferToOffer'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(4, count($ret['newsfeed']['replies']));

        # Refer it to TAKEN - generates another reply.
        $this->log("Refer to TAKEN");
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'ReferToTaken'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(5, count($ret['newsfeed']['replies']));

        # Refer it to RECEIVED - generates another reply.
        $this->log("Refer to RECEIVED");
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'ReferToReceived'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(6, count($ret['newsfeed']['replies']));

        # Report it
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Report',
            'reason' => "Test"
        ]);
        assertEquals(0, $ret['ret']);

        # Delete it
        $this->user->addMembership($gid, User::ROLE_MODERATOR);

        $ret = $this->call('newsfeed', 'DELETE', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);

        }

    public function testCommunityEvent() {
        assertTrue($this->user->login('testpw'));

        # Create an event - should result in a newsfeed item
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.5);

        $e = new CommunityEvent($this->dbhr, $this->dbhm);
        $eid = $e->create($this->uid, 'Test event', 'Test location', NULL, NULL, NULL, NULL, NULL);
        $e->addGroup($gid);
        $e->setPrivate('pending', 0);

        $ret = $this->call('newsfeed', 'GET', [
            'types' => [
                Newsfeed::TYPE_COMMUNITY_EVENT
            ]
        ]);

        $this->log("Feed " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['newsfeed']));
        self::assertEquals('Test event', $ret['newsfeed'][0]['communityevent']['title']);

        }

    public function testVolunteering() {
        assertTrue($this->user->login('testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.5);

        $e = new Volunteering($this->dbhr, $this->dbhm);
        $eid = $e->create($this->uid, 'Test opp', FALSE, 'Test location', NULL, NULL, NULL, NULL, NULL, NULL);
        $this->log("Created $eid");
        $e->addGroup($gid);
        $e->setPrivate('pending', 0);

        $ret = $this->call('newsfeed', 'GET', [
            'types' => [
                Newsfeed::TYPE_VOLUNTEER_OPPORTUNITY
            ]
        ]);

        $this->log("Feed " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['newsfeed']));
        self::assertEquals('Test opp', $ret['newsfeed'][0]['volunteering']['title']);

        }

    public function testPublicity() {
        assertTrue($this->user->login('testpw'));

        # Create a publicity post so that we can issue the API call from that point.  Use a real example with
        # ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id) for the standalone test.
        $this->dbhm->preExec("INSERT INTO `groups_facebook_toshare` (`id`, `sharefrom`, `postid`, `date`, `data`) VALUES
(1, '134117207097', '134117207097_10153929944247098', '2016-08-19 13:00:36', '{\"id\":\"134117207097_10153929944247098\",\"link\":\"https:\\/\\/www.facebook.com\\/Freegle\\/photos\\/a.395738372097.175912.134117207097\\/10153929925422098\\/?type=3\",\"message\":\"Give away and find clothes on your local Freegle group. It\'s free and easy and good for planet, people and pocket!\\nhttp:\\/\\/ilovefreegle.org\\/groups\\/\",\"type\":\"photo\",\"icon\":\"https:\\/\\/www.facebook.com\\/images\\/icons\\/photo.gif\",\"name\":\"Photos from Freegle\'s post\"}') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);");
        $rc = $this->dbhm->preExec("INSERT INTO groups_facebook_toshare (sharefrom, postid, data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);", [
            '134117207097',
            '134117207097_10153929944247098',
            json_encode([])
        ]);

        $id = $this->dbhm->lastInsertId();
        self::assertNotNull($id);
        $n = new Newsfeed($this->dbhr, $this->dbhm);
        $fid = $n->create(Newsfeed::TYPE_CENTRAL_PUBLICITY, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, $id);
        self::assertNotNull($fid);

        $posts = $this->dbhr->preQuery("SELECT id, timestamp FROM newsfeed WHERE `type` = ? ORDER BY timestamp DESC LIMIT 1;", [
            Newsfeed::TYPE_CENTRAL_PUBLICITY
        ]);

        self::assertEquals(1, count($posts));
        $time = strtotime($posts[0]['timestamp']);
        $time++;
        $newtime = ISODate('@' . $time);
        $this->log("{$posts[0]['timestamp']} => $newtime");

        $ctx = [
            'distance' => 0,
            'timestamp' => $newtime
        ];

        $ret = $this->call('newsfeed', 'GET', [
            'context' => $ctx,
            'types' => [
                Newsfeed::TYPE_CENTRAL_PUBLICITY
            ]
        ]);

        $this->log("Feed " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertGreaterThan(0, count($ret['newsfeed']));
        self::assertEquals(Newsfeed::TYPE_CENTRAL_PUBLICITY, $ret['newsfeed'][0]['type']);
        assertNotFalse(pres('postid', $ret['newsfeed'][0]['publicity']));

        }

    public function checkSpammer() {
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test with (miraabeller44@gmail.com)'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        # Get this individual one
        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals($nid, $ret['newsfeed']['id']);

        $n = new Newsfeed($this->dbhr, $this->dbhm, $nid);
        assertNotNull($n->getPrivate('hidden'));

        }

    public function testMention() {
        $this->log("Log in as {$this->uid}");
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test for mention'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        # Get mentions - should show first user
        $this->log("Log in as {$this->uid2}");
        assertTrue($this->user2->login('testpw'));

        $ret = $this->call('mentions', 'GET', [
            'id' => $nid,
            'query' => 'T'
        ]);
        $this->log("Mentions " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['mentions']));
        self::assertEquals($this->uid, $ret['mentions'][0]['id']);

        # Filter out
        $ret = $this->call('mentions', 'GET', [
            'id' => $nid,
            'query' => 'H'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['mentions']));

        # Reply
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test',
            'replyto' => $nid
        ]);
        assertEquals(0, $ret['ret']);

        # Should show second user
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('mentions', 'GET', [
            'id' => $nid,
            'query' => 'T'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['mentions']));
        self::assertEquals($this->uid2, $ret['mentions'][0]['id']);

        }

    public function testFollow() {
        $this->log("Log in as {$this->uid}");
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test for mention'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid,
            'unfollowed' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertFalse(pres('unfollowed', $ret['newsfeed']));

        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Unfollow'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid,
            'unfollowed' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertTrue(pres('unfollowed', $ret['newsfeed']));

        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Follow'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid,
            'unfollowed' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertFalse(pres('unfollowed', $ret['newsfeed']));

        }

    public function testAttach()
    {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $this->user->addMembership($gid, User::ROLE_MODERATOR);
        
        $this->log("Log in as {$this->uid}");
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test for attach'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        
        $attachto = $ret['id'];

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test to be attached'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        
        $attachee = $ret['id'];

        $ret = $this->call('newsfeed', 'POST', [
            'id' => $attachee,
            'action' => 'AttachToThread',
            'attachto' =>$attachto
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $attachee
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals($attachto, $ret['newsfeed']['replyto']);
    }

    public function testModNotif() {
        assertTrue($this->user->login('testpw'));

        $this->user->setSetting('mylocation', [
            'lng' => 179.15,
            'lat' => 8.5
        ]);

        $this->log("Post something as {$this->uid}");
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test with url https://google.co.uk'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        $n = $this->getMockBuilder('Newsfeed')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('sendIt'))
            ->getMock();

        $n->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # uid2 not a mod - nothing to do.
        assertEquals(0, $n->modnotif($this->uid2));

        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $this->log("Created group $gid");
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.5);
        $g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.6, 179.1 8.6, 179.1 8.3))');

        $this->user2->addMembership($gid, User::ROLE_MODERATOR);

        # uid2 doesn't want them - nothing to do.
        $this->user2->setSetting('modnotifnewsfeed', FALSE);
        assertEquals(0, $n->modnotif($this->uid2));

        # uid2 does want them - send 1.
        $this->user2->setSetting('modnotifnewsfeed', TRUE);
        assertEquals(1, $n->modnotif($this->uid2));

        }

//    public function testEH() {
//        $u = new User($this->dbhr, $this->dbhm);
//
//        $uid = $u->findByEmail('edward@ehibbert.org.uk');
//        $_SESSION['id'] = $uid;
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//        $ret = $this->call('newsfeed', 'GET', [
//            'types' => [
//                Newsfeed::TYPE_MESSAGE,
//                Newsfeed::TYPE_COMMUNITY_EVENT,
//                Newsfeed::TYPE_VOLUNTEER_OPPORTUNITY,
//                Newsfeed::TYPE_ALERT,
//                Newsfeed::TYPE_STORY,
//                Newsfeed::TYPE_ABOUT_ME
//            ],
//            'context' => [
//                'distance' => 0
//            ]
//        ]);
//
//        assertEquals(0, $ret['ret']);
//        $this->log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//        $this->log(var_export($ret, TRUE));
//    }
}

