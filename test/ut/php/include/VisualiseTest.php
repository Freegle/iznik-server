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
class VisualiseTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->tidy();
    }

    public function testBasic() {
        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_FREEGLE);

        # Create the sending user
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->log("Created user $uid");
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $u->addMembership($gid);

        $this->assertGreaterThan(0, $u->addEmail('test@test.com'));
        $u->setPrivate('settings', json_encode([
            'mylocation' => [
                'lat' => 8.53333,
                'lng' => 179.2167
            ]
        ]));

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
        $msg = str_replace('Test att', 'OFFER: Test due (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->log("Message id #$origid");

        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->approve($gid);

        # Create a user to receive it.
        list($u, $uid2, $emailid2) = $this->createTestUser(NULL, NULL, 'Test User', 'test2@test.com', 'testpw');
        $u->setPrivate('settings', json_encode([
            'mylocation' => [
                'lat' => 8.63333,
                'lng' => 179.3167
            ]
        ]));

        # Now mark the message as TAKEN.
        $this->log("Mark $origid as TAKEN");
        $m = new Message($this->dbhm, $this->dbhm, $origid);
        $m->mark(Message::OUTCOME_TAKEN, "Thanks", User::HAPPY, $uid2);
        $this->waitBackground();

        # Now scan the messages
        $v = new Visualise($this->dbhr, $this->dbhm);
        $v->scanMessages('1 hour ago');

        # Get the visualised messages.
        $ctx = NULL;
        $viss = $v->getMessages(8.5, 179, 8.7, 180, 100, $ctx);

        $found = FALSE;

        foreach ($viss as $vis) {
            if ($vis['msgid'] == $origid) {
                $found = TRUE;
                $this->log("Found " . var_export($vis, TRUE));
                self::assertEquals(15638, $vis['distance']);
            }
        }

        $this->assertTrue($found);

        }
}

