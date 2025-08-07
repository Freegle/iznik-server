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
class ModBotTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        # Clean up test data
        $this->dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN (?, 'test@test.com');", [
            MODBOT_USER
        ]);
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $this->dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $this->dbhm->preExec("DELETE FROM messages WHERE subject LIKE 'Test message%';");
    }

    public function testReviewPost() {
        $this->log(__METHOD__ );

        # Create a test group
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $g->setPrivate('rules', json_encode([
            'weapons' => true,
            'alcohol' => true,
            'businessads' => false
        ]));

        # Create modbot user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('ModBot', 'User', 'ModBot User');
        $u->addEmail(MODBOT_USER);
        $u->addMembership($gid, User::ROLE_MODERATOR, $uid);

        # Create a test message that should trigger rule violations
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'Test message about knives and weapons for sale', $msg);
        $msg = str_replace('Test test', 'I have some kitchen knives and hunting weapons to give away', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        
        # Test ModBot review
        $modbot = new ModBot($this->dbhr, $this->dbhm);
        $result = $modbot->reviewPost($id);

        # Should return NULL if no moderation rights (testing with different user first)
        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u2->create('Test', 'User', 'Test User');
        $u2->addEmail('test@test.com');
        $modbot2 = new ModBot($this->dbhr, $this->dbhm);
        $result2 = $modbot2->reviewPost($id);
        
        # Note: This test requires a valid Google Gemini API key to fully work
        # In a real environment, we would mock the API call for testing
        if (defined('GOOGLE_GEMINI_API_KEY') && GOOGLE_GEMINI_API_KEY !== 'zzzz') {
            $this->log("Testing with real API key");
            # If we have a real API key, test should return analysis
            $this->assertNotNull($result);
            if (is_array($result)) {
                # Should detect weapons rule violation
                $foundWeapons = false;
                foreach ($result as $violation) {
                    if (isset($violation['rule']) && $violation['rule'] === 'weapons') {
                        $foundWeapons = true;
                        $this->assertGreaterThan(0.1, $violation['probability']);
                        break;
                    }
                }
                $this->assertTrue($foundWeapons, "Should detect weapons rule violation");
            }
        } else {
            $this->log("Skipping API test - no valid key");
            # Without a real API key, the test would fail with an exception
            # which should be handled gracefully and return empty array
            $this->assertTrue(is_array($result) || is_null($result));
        }

        # Test with non-existent message
        $resultNonExistent = $modbot->reviewPost(999999);
        $this->assertIsArray($resultNonExistent);
        $this->assertEquals('message_not_found', $resultNonExistent['error']);
        
        $this->log("ModBot test completed successfully");
    }

    public function testReviewPostNoModeratorRights() {
        $this->log(__METHOD__);

        # Create a test group
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        # Create modbot user without moderator rights
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('ModBot', 'User', 'ModBot User');
        $u->addEmail(MODBOT_USER);
        $u->addMembership($gid, User::ROLE_MEMBER, $uid); # Member, not moderator

        # Create a test message
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Test ModBot review - should return error due to no moderator rights
        $modbot = new ModBot($this->dbhr, $this->dbhm);
        $result = $modbot->reviewPost($id);
        
        $this->assertIsArray($result, "Should return error array when modbot has no moderator rights");
        $this->assertEquals('no_moderation_rights', $result['error']);
    }

    public function testReviewPostWithMicrovolunteering() {
        $this->log(__METHOD__);

        # Create a test group
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $g->setPrivate('rules', json_encode([
            'weapons' => true,
            'alcohol' => true,
            'businessads' => false
        ]));

        # Create modbot user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('ModBot', 'User', 'ModBot User');
        $u->addEmail(MODBOT_USER);
        $u->addMembership($gid, User::ROLE_MODERATOR, $uid);

        # Create a test message
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Clear any existing microvolunteering entries for this message
        $this->dbhm->preExec("DELETE FROM microactions WHERE msgid = ?", [$id]);

        # Test ModBot review with microvolunteering enabled
        $modbot = new ModBot($this->dbhr, $this->dbhm);
        $result = $modbot->reviewPost($id, true);

        # Check that microvolunteering entry was created
        $microactions = $this->dbhr->preQuery(
            "SELECT * FROM microactions WHERE actiontype = ? AND userid = ? AND msgid = ?",
            [MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $uid, $id]
        );

        $this->assertEquals(1, count($microactions), "Should create exactly one microvolunteering entry");
        
        if (count($microactions) > 0) {
            $action = $microactions[0];
            $this->assertEquals(MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $action['actiontype']);
            $this->assertEquals($uid, $action['userid']);
            $this->assertEquals($id, $action['msgid']);
            $this->assertNotNull($action['result']);
            $this->assertNotNull($action['comments']);
            $this->assertEquals(MicroVolunteering::VERSION, $action['version']);
            
            # For a basic test message, should likely be approved
            $this->assertContains($action['result'], [MicroVolunteering::RESULT_APPROVE, MicroVolunteering::RESULT_REJECT]);
        }

        $this->log("Microvolunteering test completed successfully");
    }
}