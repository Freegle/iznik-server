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
class twitterTest extends IznikTestCase {
    private $dbhr, $dbhm;

    private $msgsSent = [];

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        if (!$gid) {
            $gid = $g->create('FreeglePlayground', Group::GROUP_REUSE);
        }

        $t = new Twitter($this->dbhr, $this->dbhm, $gid);
        $atts = $t->getPublic();

        if (!Utils::pres($atts, 'secret')) {
            $t->set('FreeglePlaygrnd', PLAYGROUND_TOKEN, PLAYGROUND_SECRET);
        }

        $this->tidy();
    }

    public function testBasic() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        if (!$gid) {
            $gid = $g->create('FreeglePlayground', Group::GROUP_REUSE);
            $t = new Twitter($this->dbhr, $this->dbhm, $gid);
            $t->set('FreeglePlaygrnd', getenv('PLAYGROUND_TOKEN'), getenv('PLAYGROUND_SECRET'));
        }

        $t = new Twitter($this->dbhr, $this->dbhm, $gid);
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $t->tweet('Test - ignore', $data);

        $gid = $g->create('testgroup', Group::GROUP_UT);
        $t = new Twitter($this->dbhr, $this->dbhm, $gid);
        $t->set('test', 'test', 'test');
        $atts = $t->getPublic();
        $this->assertEquals('test', $atts['name']);
        $this->assertEquals('test', $atts['token']);
        $this->assertEquals('test', $atts['secret']);

        }

    public function testMessages() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        $u = new User($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved message id $id");

        # Ensure we have consent to see this message
        $a = new Message($this->dbhr, $this->dbhm, $id);
        $sender = User::get($this->dbhr, $this->dbhm, $a->getFromuser());

        $t = new Twitter($this->dbhm, $this->dbhm, $gid);

        # Fake message onto group.
        $this->dbhm->preExec("UPDATE messages_groups SET collection = ? WHERE msgid = ? AND groupid = ?;", [
            MessageCollection::APPROVED,
            $id,
            $gid
        ]);

        $mock = $this->getMockBuilder('TwitterOAuth')
            ->setMethods(['post', 'get', 'setTimeouts'])
            ->getMock();

        $mock->method('get')->willReturn(true);
        $mock->method('setTimeouts')->willReturn(true);
        $mock->method('post')->willReturn([
            'test' => TRUE
        ]);
        $t->setTw($mock);

        $count = $t->tweetMessages();
        $this->assertGreaterThanOrEqual(1, $count);

        # Should be none to tweet now.
        $count = $t->tweetMessages();
        $this->assertGreaterThanOrEqual(0, $count);
        
        }

    public function testErrors() {
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $t = new Twitter($this->dbhr, $this->dbhm, $gid);

        # Fake a fail
        $mock = $this->getMockBuilder('TwitterOAuth')
            ->setMethods(['post', 'get', 'setTimeouts'])
            ->getMock();

        $mock->method('get')->willReturn(true);
        $mock->method('setTimeouts')->willReturn(true);
        $mock->method('post')->willReturn([
            'errors' => [
                [
                    'code' => 220
                ]
            ]
        ]);

        $t->setTw($mock);

        $this->assertFalse($t->tweet('test', NULL));
        $atts = $t->getPublic();
        $this->log("After fail " . var_export($atts, TRUE));
        $this->assertFalse($atts['valid']);

        # Now fake a lock
        $mock = $this->getMockBuilder('TwitterOAuth')
            ->setMethods(['post', 'get', 'setTimeouts'])
            ->getMock();

        $mock->method('get')->willReturn(true);
        $mock->method('setTimeouts')->willReturn(true);
        $mock->method('post')->willReturn([
            'errors' => [
                [
                    'code' => 326
                ]
            ]
        ]);
        $t->setTw($mock);

        $this->assertFalse($t->tweet('test', NULL));
        $atts = $t->getPublic();
        $this->log("After lock " . var_export($atts, TRUE));
        $this->assertTrue($atts['locked']);

        $this->log("Now tweet successfully and reset");
        $mock = $this->getMockBuilder('TwitterOAuth')
            ->setMethods(['post', 'get', 'setTimeouts'])
            ->getMock();

        $mock->method('get')->willReturn(true);
        $mock->method('setTimeouts')->willReturn(true);
        $mock->method('post')->willReturn([
            'test' => TRUE
        ]);
        $t->setTw($mock);

        $this->assertTrue($t->tweet('test', NULL));
        $atts = $t->getPublic();
        $this->assertTrue($atts['valid']);
        $this->assertFalse($atts['locked']);

        }

    public function testEvents() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        $e = new CommunityEvent($this->dbhr, $this->dbhm);
        $eid = $e->create(NULL, 'Test Event', 'Test location', NULL, NULL, NULL, NULL, 'Test Event');
        $e->addGroup($gid);
        $start = date("Y-m-d H:i:s", strtotime('+3 hours'));
        $end = date("Y-m-d H:i:s", strtotime('+4 hours'));
        $e->addDate($start, $end);

        $mock = $this->getMockBuilder('Freegle\Iznik\Twitter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $gid))
            ->setMethods(['tweet'])
            ->getMock();
        $mock->method('tweet')->willReturn(true);

        $count = $mock->tweetEvents();
        $this->assertGreaterThanOrEqual(1, $count);

        }

    public function testStories() {
        $this->dbhm->preExec("DELETE FROM users_stories WHERE headline LIKE 'Test%';");

        $s = new Story($this->dbhr, $this->dbhm);
        $sid = $s->create(NULL, 1, 'Test Story', 'Test Story');
        $s->setPrivate('reviewed', 1);
        $s->setPrivate('newsletterreviewed', 1);

        $mock = $this->getMockBuilder('Freegle\Iznik\Twitter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, NULL))
            ->setMethods(['tweet'])
            ->getMock();
        $mock->method('tweet')->willReturn(true);

        self::assertEquals(1, $mock->tweetStory($sid));

        $s->delete();

        }
}

