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

        list ($r, $id, $failok, $rc) = $this->createCustomTestMessage('OFFER: sofa (EH3 6SS)', 'testgroup', $email, 'to@test.com', 'Testing', MailRouter::APPROVED);

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
            list($u2, $uid2, $emailid2) = $this->createTestUser(null, null, 'Test User', 'test2@test.com', 'testpw2');
            $u2->setPrivate('ljuserid', 1);
            $m->promise($uid2);

            $r = new ChatRoom($this->dbhr, $this->dbhm);
            list ($rid, $banned) = $r->createConversation($m->getFromuser(), $uid2);
            $r = new ChatRoom($this->dbhr, $this->dbhm, $rid);
            $r->setPrivate('ljofferid', 1);
            $this->assertNotNull($rid);
            list ($cm, $mid, $banned) = $this->createTestChatMessage($rid, $uid2, NULL, ChatMessage::TYPE_PROMISED, $m->getID());
            $this->assertNotNull($mid);
            error_log("Created conversation $rid between $uid2 and " . $m->getFromuser() . " with message $mid");

            # Promise to a LJ user so we expect this to return completed.
            $this->assertTrue($l->completeOrDelete($id));
        } else {
            # Not promised so we expect this to return not completed.
            $this->assertFalse($l->completeOrDelete($id));
        }
    }

}