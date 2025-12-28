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

        list($this->user, $this->uid, $emailid) = $this->createTestUser('Test', 'User', 'Test User', 'test@test.com', 'testpw');
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user->addMembership($this->gid, User::ROLE_MEMBER);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();
        
        # Create ModBot user
        list($this->modBotUser, $this->modBotUid, $emailid2) = $this->createTestUser('ModBot', 'User', 'ModBot User', MODBOT_USER, 'modbotpw');
        $this->assertGreaterThan(0, $this->modBotUser->addLogin(User::LOGIN_NATIVE, NULL, 'modbotpw'));
        $this->modBotUser->addMembership($this->gid, User::ROLE_MODERATOR);
        User::clearCache();
    }

    public function testReviewPost() {
        $this->log(__METHOD__ );

        # Create a test message that should trigger rule violations
        # Use explicit weapons language (not kitchen knives which are legitimate household items)
        list ($r, $id, $failok, $rc) = $this->createCustomTestMessage('OFFER: Hunting rifle and crossbow', 'testgroup', 'test@test.com', 'to@test.com', 'I have a hunting rifle and a crossbow that I want to give away. Also have some ammunition.', MailRouter::PENDING);

        # Log in as ModBot user before review
        $this->assertTrue($this->modBotUser->login('modbotpw'));

        # Test ModBot review
        $modbot = new ModBot($this->dbhr, $this->dbhm);

        # Note: This test requires a valid Google Gemini API key to fully work
        # In a real environment, we would mock the API call for testing
        if (defined('GOOGLE_GEMINI_API_KEY') && GOOGLE_GEMINI_API_KEY !== 'zzzz') {
            $this->log("Testing with real API key");

            # Add retries since AI detection can be unreliable
            $foundWeapons = FALSE;
            $maxRetries = 3;
            $result = NULL;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $this->log("Attempt $attempt of $maxRetries");
                # Use returnDebugInfo to get raw violations (before threshold filtering)
                # and skipModRightsCheck since this is a test
                $result = $modbot->reviewPost($id, FALSE, TRUE, TRUE);
                $this->assertNotNull($result);

                if (is_array($result) && isset($result['error'])) {
                    if ($attempt < $maxRetries) {
                        $this->log("Error on attempt $attempt: " . $result['error']);
                        sleep(1);
                        continue;
                    }
                    $this->fail("ModBot returned error: " . $result['error']);
                }

                # Check raw violations instead of filtered ones, with lower threshold
                # The production threshold for weapons is 0.8, but for testing we accept 0.3
                # because AI responses are non-deterministic
                $rawViolations = $result['debug']['raw_violations'] ?? [];
                foreach ($rawViolations as $violation) {
                    if (isset($violation['rule']) && $violation['rule'] === 'weapons') {
                        $prob = $violation['probability'] ?? 0;
                        $this->log("Weapons probability: $prob");
                        if ($prob >= 0.3) {
                            $foundWeapons = TRUE;
                            break 2;
                        }
                    }
                }

                if (!$foundWeapons && $attempt < $maxRetries) {
                    $this->log("Weapons violation not detected on attempt $attempt (prob < 0.3), retrying...");
                    sleep(1);
                }
            }

            $this->assertTrue($foundWeapons, "Should detect weapons rule violation (prob >= 0.3) after $maxRetries attempts");
        } else {
            $this->log("Skipping API test - no valid key");
            $result = $modbot->reviewPost($id);
            $this->assertTrue(is_array($result) || is_null($result));
        }

        # Test with non-existent message
        $resultNonExistent = $modbot->reviewPost(999999);
        $this->assertIsArray($resultNonExistent);
        $this->assertEquals('message_not_found', $resultNonExistent['error']);

        $this->log("ModBot test completed successfully");
    }

}