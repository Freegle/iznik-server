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
class visualiseTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->tidy();
    }

    public function testBasic() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_FREEGLE);

        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addEmail('test@test.com');
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $u->addMembership($gid);

        assertGreaterThan(0, $u->addEmail('test@test.com'));
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
        $origid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->log("Message id #$origid");

        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->approve($gid);

        # Create a user to receive it.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
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

        assertTrue($found);

        }
}

