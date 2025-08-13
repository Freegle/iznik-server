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
class LoveJunkTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM returnpath_seedlist WHERE email LIKE 'test@test.com';");
    }

    public function trueFalseProvider() {
        return [
            [ TRUE ],
            [ FALSE ]
        ];
    }

    /**
     * @dataProvider trueFalseProvider
     */
    public function testSend($promise)
    {
        $email = 'test-' . rand() . '@blackhole.io';
        list($u, $uid, $emailid) = $this->createTestUser(null, null, 'Test User', $email, 'testpw');

        $settings = [
            'mylocation' => [
                'lat' => 55.957571,
                'lng' => -3.205333,
                'name' => 'EH3 6SS'
            ],
        ];

        $u->setPrivate('settings', json_encode($settings));
        $this->addLoginAndLogin($u, 'testpw');

        list($g, $group1) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);

        $u->addEmail('test@test.com');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: sofa (EH3 6SS)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $msg = str_replace('Hey.', 'Testing', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 55.957571);
        $m->setPrivate('lng', -3.205333);

        $l = new LoveJunk($this->dbhr, $this->dbhm);
        LoveJunk::$mock = TRUE;
        $this->assertTrue($l->send($id));

        // Edit
        $l->edit($id, 1);

        if ($promise) {
            # Promise this to a LoveJunk user.
            $u2 = new User($this->dbhr, $this->dbhm);
            $uid2 = $u2->create(null, null, 'Test User');
            $u2->setPrivate('ljuserid', 1);
            $m->promise($uid2);

            $r = new ChatRoom($this->dbhr, $this->dbhm);
            list ($rid, $banned) = $r->createConversation($m->getFromuser(), $uid2);
            $r = new ChatRoom($this->dbhr, $this->dbhm, $rid);
            $r->setPrivate('ljofferid', 1);
            $this->assertNotNull($rid);
            $cm = new ChatMessage($this->dbhr, $this->dbhm);
            list ($mid, $banned) = $cm->create($rid, $uid2, NULL, ChatMessage::TYPE_PROMISED, $m->getID());
            $this->assertNotNull($mid);
            error_log("Created conversation $rid between $uid2 and " . $m->getFromuser() . " with message $mid");

            # Promise to a LJ user so we expect this to return completed.
            $this->assertTrue($l->completeOrDelete($id));
        } else {
            # Not promised so we expect this to return not completed.
            $this->assertFalse($l->completeOrDelete($id));
        }
    }

    public function testChatMessage() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create(null, null, 'Test User');
        $u1 = new User($this->dbhr, $this->dbhm, $uid1);
        $uid2 = $u->create(null, null, 'Test User');
        $u2 = new User($this->dbhr, $this->dbhm, $uid2);
        $u2->setPrivate('ljuserid', 456);

        // u1 is FD user who created a message.
        // u2 is LJ user who replied to a message.
        // Create a chat between them and set an ljofferid on the room.  That setting is done in live by the Go API.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($uid1, $uid2);
        $r->setPrivate('ljofferid', 1234);

        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $cm->create($rid, $uid1, 'Reply from FD to LJ');

        // Now send the digest.  This will send the message to the LJ API.
        $l = new LoveJunk($this->dbhr, $this->dbhm);
        LoveJunk::$mock = TRUE;

        $count = $r->notifyByEmail($rid, ChatRoom::TYPE_USER2USER, NULL, 0, "4 hours ago");
        $this->assertEquals(1, $count);

        // String that will be split.
        $str = '';

        while (strlen($str) < 350) {
            $str .= 'This is a sentence.';
        }

        $cm->create($rid, $uid1, $str);
        $count = $r->notifyByEmail($rid, ChatRoom::TYPE_USER2USER, NULL, 0, "4 hours ago");
        $this->assertEquals(3, $count);

        // String that should not be split.
        $str = 'A question?';
        $cm->create($rid, $uid1, $str);
        $count = $r->notifyByEmail($rid, ChatRoom::TYPE_USER2USER, NULL, 0, "4 hours ago");
        $this->assertEquals(1, $count);
    }

    public function testPromise() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create(null, null, 'Test User');
        $u1 = new User($this->dbhr, $this->dbhm, $uid1);
        $uid2 = $u->create(null, null, 'Test User');
        $u2 = new User($this->dbhr, $this->dbhm, $uid2);
        $u2->setPrivate('ljuserid', 456);

        // u1 is FD user who created a message.
        // u2 is LJ user who replied to a message.
        // Create a chat between them and set an ljofferid on the room.  That setting is done in live by the Go API.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($uid1, $uid2);
        $r->setPrivate('ljofferid', 1234);

        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $cm->create($rid, $uid1, NULL, ChatMessage::TYPE_PROMISED);

        // Now send the digest.  This will send the message to the LJ API.
        $l = new LoveJunk($this->dbhr, $this->dbhm);
        LoveJunk::$mock = TRUE;

        $count = $r->notifyByEmail($rid, ChatRoom::TYPE_USER2USER, NULL, 0, "4 hours ago");
        $this->assertEquals(1, $count);

        $cm->create($rid, $uid1, NULL, ChatMessage::TYPE_RENEGED);

        $count = $r->notifyByEmail($rid, ChatRoom::TYPE_USER2USER, NULL, 0, "4 hours ago");
        $this->assertEquals(1, $count);
    }
}