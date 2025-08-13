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
        
        # Set up group and user following MessageTest pattern
        list($this->group, $this->gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $this->group->setPrivate('onhere', 1);
        $this->group->setPrivate('rules', json_encode([
            'weapons' => TRUE,
            'alcohol' => TRUE,
            'businessads' => FALSE
        ]));

        list($this->user, $this->uid, $emailid) = $this->createTestUserWithMembership($this->gid, User::ROLE_MEMBER, 'Test', 'User', 'Test User', 'test@test.com', 'testpw');
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();
        
        # Create ModBot user
        list($this->modBotUser, $this->modBotUid, $emailid2) = $this->createTestUserWithMembership($this->gid, User::ROLE_MODERATOR, 'ModBot', 'User', 'ModBot User', MODBOT_USER, 'modbotpw');
        $this->assertGreaterThan(0, $this->modBotUser->addLogin(User::LOGIN_NATIVE, NULL, 'modbotpw'));
        User::clearCache();
    }

    public function testReviewPost() {
        $this->log(__METHOD__ );

        # Create a test message that should trigger rule violations
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'Test message about knives and weapons for sale', $msg);
        $msg = str_replace('Test test', 'I have some kitchen knives and hunting weapons to give away', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Log in as ModBot user before review
        $this->assertTrue($this->modBotUser->login('modbotpw'));
        
        # Test ModBot review
        $modbot = new ModBot($this->dbhr, $this->dbhm);
        $result = $modbot->reviewPost($id);
        
        # Note: This test requires a valid Google Gemini API key to fully work
        # In a real environment, we would mock the API call for testing
        if (defined('GOOGLE_GEMINI_API_KEY') && GOOGLE_GEMINI_API_KEY !== 'zzzz') {
            $this->log("Testing with real API key");
            $this->assertNotNull($result);
            
            if (is_array($result) && isset($result['violations'])) {
                # Should detect weapons rule violation
                $foundWeapons = FALSE;
                foreach ($result['violations'] as $violation) {
                    if (isset($violation['rule']) && $violation['rule'] === 'weapons') {
                        $foundWeapons = TRUE;
                        $this->assertGreaterThan(0.1, $violation['probability']);
                        break;
                    }
                }
                $this->assertTrue($foundWeapons, "Should detect weapons rule violation");
            } elseif (is_array($result) && isset($result['error'])) {
                $this->fail("ModBot returned error: " . $result['error']);
            }
        } else {
            $this->log("Skipping API test - no valid key");
            $this->assertTrue(is_array($result) || is_null($result));
        }

        # Test with non-existent message
        $resultNonExistent = $modbot->reviewPost(999999);
        $this->assertIsArray($resultNonExistent);
        $this->assertEquals('message_not_found', $resultNonExistent['error']);
        
        $this->log("ModBot test completed successfully");
    }

}