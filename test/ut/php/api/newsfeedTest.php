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
class newsfeedAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;
    private $msgsSent = [];

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test Commenter';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'TV13%';");

        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);

        $l = new Location($this->dbhr, $this->dbhm);
        $this->areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $areaatts = $l->getPublic();
        assertNotNull($this->areaid);
        $this->pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $pcatts = $l->getPublic();
        assertEquals($this->areaid, $pcatts['areaid']);

        $this->user = User::get($this->dbhr, $this->dbhm);
        $this->uid = $this->user->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user->addMembership($gid);
        assertEquals('testgroup1', $this->user->getPublicLocation()['display']);
        $this->user->setPrivate('lastlocation', $this->fullpcid);
        $this->user->setSetting('mylocation', $pcatts);
        assertEquals('Tuvalu Central, testgroup1', $this->user->getPublicLocation()['display']);

        $this->user2 = User::get($this->dbhr, $this->dbhm);
        $this->uid2 = $this->user2->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user2->setPrivate('lastlocation', $this->fullpcid);
        $this->user2->addEmail('test@test.com');

        $this->user3 = User::get($this->dbhr, $this->dbhm);
        $this->uid3 = $this->user3->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $this->user3->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user3->setPrivate('lastlocation', $this->fullpcid);

        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test opp';");
    }

    protected function tearDown() : void {
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $this->dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $this->deleteLocations("DELETE FROM locations  WHERE name LIKE 'Tuvalu%';");
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test opp';");
        parent::tearDown ();
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    private function stripPublicity($arr) {
        return array_filter($arr, function($a) {
            return $a['type'] != Newsfeed::TYPE_CENTRAL_PUBLICITY;
        });
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
        assertEquals(0, count($this->stripPublicity($ret['newsfeed'])));

        # Create an attachment.
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/giveandtake.jpg');
        file_put_contents("/tmp/giveandtake.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/giveandtake.jpg'
            ],
            'newsfeed' => TRUE,
            'ocr' => FALSE
        ]);

        assertEquals(0, $ret['ret']);
        $attid = $ret['id'];

        # Post something.
        $this->log("Post something as {$this->uid}");
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test with url https://google.co.uk',
            'imageid' => $attid
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        # Pin for coverage.
        $n = new Newsfeed($this->dbhr, $this->dbhm, $nid);
        $n->setPrivate('pinned', TRUE);

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test reply',
            'replyto' => $nid
        ]);
        assertEquals(0, $ret['ret']);

        # Get this individual one
        $n->updatePreviews();

        $ret = $this->call('newsfeed', 'GET', [
            'id' => $nid
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals($nid, $ret['newsfeed']['id']);
        assertEquals($attid, $ret['newsfeed']['imageid']);
        self::assertTrue($ret['newsfeed']['preview']['title'] == 'Google' || $ret['newsfeed']['preview']['title'] == 'Before you continue to Google Search');
        self::assertEquals('Test with url https://google.co.uk', $ret['newsfeed']['message']);
        assertEquals($this->user->getId(), $ret['newsfeed']['user']['id']);

        # Get it back as part of the feed.
        $found = FALSE;
        $ret = $this->call('newsfeed', 'GET', []);
        assertEquals(0, $ret['ret']);
        foreach ($ret['newsfeed'] as $n) {
            if ($n['id'] == $nid) {
                $found = TRUE;
            }
        }
        assertTrue($found);

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

        $ret = $this->call('stories', 'PUT', [
            'headline' => 'Test story, nice and long so it gets included',
            'story' => 'Test',
            'dup' => TRUE
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
        $n = $this->getMockBuilder('Freegle\Iznik\Newsfeed')
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
        $m->parse(Message::EMAIL, 'from@test.com', 'testgroup1@yahoogroups.com', $msg);
        $mid = $m->save();
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
            'id' => $nid,
            'lovelist' => TRUE
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        self::assertEquals(1, $ret['newsfeed']['loves']);
        self::assertTrue($ret['newsfeed']['loved']);
        assertEquals($this->user2->getId(), $ret['newsfeed']['lovelist'][0]['id']);

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
        assertEquals('/chitchat/' . $nid, $route);
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
            ],
            'context' => [
                'pinned' => '[0]'
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

        # Unhide it - should fail, not support
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Unhide'
        ]);
        assertEquals(2, $ret['ret']);

        # Unhide - should work , support.
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $_SESSION['id'] = NULL;
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('newsfeed', 'POST', [
            'id' => $nid,
            'action' => 'Unhide',
            'dup' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

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
        $newtime = Utils::ISODate('@' . $time);
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
        assertNotFalse(Utils::pres('postid', $ret['newsfeed'][0]['publicity']));

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
        assertFalse(Utils::pres('unfollowed', $ret['newsfeed']));

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
        assertTrue(Utils::pres('unfollowed', $ret['newsfeed']));

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
        assertFalse(Utils::pres('unfollowed', $ret['newsfeed']));

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

        $n = $this->getMockBuilder('Freegle\Iznik\Newsfeed')
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

        # Shouldn't duplicate.
        assertEquals(0, $n->modnotif($this->uid2));
    }

    public function testReplyToReply() {
        assertTrue($this->user->login('testpw'));

        # Post something.
        $this->log("Post something as {$this->uid}");
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test - please ignore'
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $threadhead = $ret['id'];

        assertTrue($this->user2->login('testpw'));

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test reply',
            'replyto' => $threadhead
        ]);
        assertEquals(0, $ret['ret']);

        $replyid = $ret['id'];

        assertTrue($this->user3->login('testpw'));

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test reply to reply',
            'replyto' => $replyid
        ]);
        assertEquals(0, $ret['ret']);

        # Get it to check thread structure
        $ret = $this->call('newsfeed', 'GET', [
            'id' => $threadhead
        ]);

        assertEquals($threadhead, $ret['newsfeed']['id']);

        assertEquals($this->user2->getId(), $ret['newsfeed']['replies'][0]['user']['id']);
        assertEquals($threadhead, $ret['newsfeed']['replies'][0]['replyto']);
        assertEquals($threadhead, $ret['newsfeed']['replies'][0]['threadhead']);

        assertEquals($this->user3->getId(), $ret['newsfeed']['replies'][0]['replies'][0]['user']['id']);
        assertEquals($replyid, $ret['newsfeed']['replies'][0]['replies'][0]['replyto']);
        assertEquals($threadhead, $ret['newsfeed']['replies'][0]['replies'][0]['threadhead']);
    }

    public function testSuppressed() {
        $this->log("Logged in - empty");
        assertTrue($this->user->login('testpw'));

        # Suppress them
        $this->user->setPrivate('newsfeedmodstatus', User::NEWSFEED_SUPPRESSED);

        # Post something.
        $this->log("Post something as {$this->uid}");
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test with url https://google.co.uk'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        # Get it - should show for us.
        $ret = $this->call('newsfeed', 'GET', [
            'ctx' => [
                'distance' => PHP_INT_MAX
            ]
        ]);

        assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['newsfeed'] as $entry) {
            if ($nid == $entry['id']) {
                $found = TRUE;
            }
        }

        assertTrue($found);

        # Shouldn't show for someone else.
        assertTrue($this->user2->login('testpw'));

        $ret = $this->call('newsfeed', 'GET', [
            'ctx' => [
                'distance' => PHP_INT_MAX
            ]
        ]);

        assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['newsfeed'] as $entry) {
            if ($nid == $entry['id']) {
                $found = TRUE;
            }
        }

        assertFalse($found);
    }

    public function testDuplicate() {
        assertTrue($this->user->login('testpw'));

        # Post something.
        $this->log("Post something as {$this->uid}");
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test for duplicate'
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test for duplicate',
            'dup' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created dup feed {$ret['id']}");
        assertEquals($nid, $ret['id']);

        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test for non-duplicate',
            'dup' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created non dup feed {$ret['id']}");
        assertNotEquals($nid, $ret['id']);
    }

    public function testOwnPosts() {
        assertTrue($this->user->login('testpw'));

        $settings = [
            'mylocation' => [
                'lat' => 52.57,
                'lng' => -2.03,
            ],
        ];

        $this->user->setPrivate('settings', json_encode($settings));

        # Post something.
        $this->log("Post something as {$this->uid}");
        $ret = $this->call('newsfeed', 'POST', [
            'message' => 'Test with url https://google.co.uk',
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $this->log("Created feed {$ret['id']}");
        $nid = $ret['id'];

        # Get it back as part of the feed.
        $found = FALSE;
        $ret = $this->call('newsfeed', 'GET', []);
        assertEquals(0, $ret['ret']);
        foreach ($ret['newsfeed'] as $n) {
            if ($n['id'] == $nid) {
                $found = TRUE;
            }
        }
        assertTrue($found);

        # Move to another location.
        $settings = [
            'mylocation' => [
                'lat' => 53.57,
                'lng' => -3.03,
            ],
        ];

        $this->user->setPrivate('settings', json_encode($settings));

        # Should still be visible as it's ours.
        $found = FALSE;
        $ret = $this->call('newsfeed', 'GET', []);
        assertEquals(0, $ret['ret']);
        foreach ($ret['newsfeed'] as $n) {
            if ($n['id'] == $nid) {
                $found = TRUE;
            }
        }
        assertTrue($found);
    }
//
//    public function testEH() {
//        $u = new User($this->dbhr, $this->dbhm);
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//
//        $u = new User($this->dbhr, $this->dbhm);
//
//        $uid = $u->findByEmail('edward@ehibbert.org.uk');
//        $u = new User($this->dbhr, $this->dbhm, $uid);
//        $_SESSION['id'] = $uid;
//        $ret = $this->call('newsfeed', 'GET', [
//            'id' => 56827,
//            'modtools' => FALSE,
//        ]);
//    }
}

