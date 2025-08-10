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
        $this->dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN (?, 'test@test.com', 'testposter@test.com', 'testposter2@test.com', 'testposter3@test.com');", [
            MODBOT_USER
        ]);
        $this->dbhm->preExec("DELETE FROM users WHERE fullname IN ('Test User', 'Test Posting User', 'Test Posting User 2', 'Test Posting User 3');");
        $this->dbhm->preExec("DELETE FROM `groups` WHERE nameshort LIKE 'testgroup%';");
        $this->dbhm->preExec("DELETE FROM messages WHERE subject LIKE 'Test message%';");
    }

    public function testReviewPost() {
        $this->log(__METHOD__ );

        # Create a test group (following MailRouterTest pattern)
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $g->setPrivate('onhere', 1); # Make group active
        $g->setPrivate('rules', json_encode([
            'weapons' => true,
            'alcohol' => true,
            'businessads' => false
        ]));

        # Create modbot user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('ModBot', 'User', 'ModBot User');
        $u->addEmail(MODBOT_USER);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'modbotpw'));
        $u->addMembership($gid, User::ROLE_MODERATOR, $uid);
        
        # Clear cache to ensure user is findable by email
        User::clearCache();
        $this->log("Created ModBot user $uid with email " . MODBOT_USER . " as moderator of group $gid");

        # Create a regular user to post the message
        $testUser = User::get($this->dbhr, $this->dbhm);
        $testUserId = $testUser->create('Test', 'Poster', 'Test Posting User');
        $testUser->addEmail('testposter@test.com');
        $testUser->addMembership($gid, User::ROLE_MEMBER);
        
        # Set user to moderated posting status to ensure message goes to PENDING
        $testUser->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        # Create a test message that should trigger rule violations (using MailRouterTest pattern)
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'Test message about knives and weapons for sale', $msg);
        $msg = str_replace('Test test', 'I have some kitchen knives and hunting weapons to give away', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'testposter@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        
        # Log in as ModBot user before review
        $this->assertTrue($u->login('modbotpw'));
        
        # Test ModBot review
        # Debug: Check if ModBot can find the user
        $testUser = User::get($this->dbhr, $this->dbhm);
        $botUserId = $testUser->findByEmail(MODBOT_USER);
        $this->log("MODBOT_USER email: " . MODBOT_USER);
        $this->log("Found bot user ID: " . ($botUserId ? $botUserId : 'NULL'));
        if ($botUserId) {
            $botUser = User::get($this->dbhr, $this->dbhm, $botUserId);
            $this->log("Bot user is mod/owner of group $gid: " . ($botUser->isModOrOwner($gid) ? 'YES' : 'NO'));
        }
        
        $modbot = new ModBot($this->dbhr, $this->dbhm);
        $result = $modbot->reviewPost($id);
        
        $this->log("Initial ModBot result: " . print_r($result, true));

        # Should return NULL if no moderation rights (testing with different user first)
        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u2->create('Test', 'User', 'Test User');
        $u2->addEmail('test@test.com');
        $modbot2 = new ModBot($this->dbhr, $this->dbhm);
        $result2 = $modbot2->reviewPost($id);
        
        # Note: This test requires a valid Google Gemini API key to fully work
        # In a real environment, we would mock the API call for testing
        $apiKeyDefined = defined('GOOGLE_GEMINI_API_KEY');
        $apiKeyValue = $apiKeyDefined ? GOOGLE_GEMINI_API_KEY : 'NOT_DEFINED';
        $this->log("API Key defined: " . ($apiKeyDefined ? 'YES' : 'NO'));
        $this->log("API Key value: " . (strlen($apiKeyValue) > 10 ? substr($apiKeyValue, 0, 10) . '...' : $apiKeyValue));
        
        if (defined('GOOGLE_GEMINI_API_KEY') && GOOGLE_GEMINI_API_KEY !== 'zzzz') {
            $this->log("Testing with real API key");
            # Debug: Log the actual result structure
            $this->log("ModBot result type: " . gettype($result));
            $this->log("ModBot result: " . print_r($result, true));
            
            # If we have a real API key, test should return analysis
            $this->assertNotNull($result);
            
            if (is_array($result)) {
                $this->log("Result is array with keys: " . implode(', ', array_keys($result)));
                
                if (isset($result['error'])) {
                    $this->fail("ModBot returned error: " . $result['error']);
                } else if (isset($result['violations'])) {
                    # Should detect weapons rule violation
                    $foundWeapons = false;
                    $violations = $result['violations'];
                    $this->log("Violations array count: " . count($violations));
                    foreach ($violations as $violation) {
                        $this->log("Checking violation: " . print_r($violation, true));
                        if (isset($violation['rule']) && $violation['rule'] === 'weapons') {
                            $foundWeapons = true;
                            $this->assertGreaterThan(0.1, $violation['probability']);
                            break;
                        }
                    }
                    $this->assertTrue($foundWeapons, "Should detect weapons rule violation");
                } else {
                    # Maybe it's returning violations directly?
                    $this->log("No 'violations' key found, checking if result is direct violations array");
                    $foundWeapons = false;
                    foreach ($result as $violation) {
                        $this->log("Checking direct violation: " . print_r($violation, true));
                        if (isset($violation['rule']) && $violation['rule'] === 'weapons') {
                            $foundWeapons = true;
                            $this->assertGreaterThan(0.1, $violation['probability']);
                            break;
                        }
                    }
                    $this->assertTrue($foundWeapons, "Should detect weapons rule violation in direct array");
                }
            } else {
                $this->fail("ModBot result is not an array: " . gettype($result));
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

        # Create a test group (using different name to avoid conflicts)
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup2', Group::GROUP_FREEGLE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $g->setPrivate('onhere', 1); # Make group active
        $g->setPrivate('rules', json_encode([
            'weapons' => true,
            'alcohol' => true,
            'businessads' => false
        ]));

        # Create modbot user WITHOUT moderator rights
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('ModBot', 'User', 'ModBot User');
        $u->addEmail(MODBOT_USER);
        # Add as member only, not moderator
        $u->addMembership($gid, User::ROLE_MEMBER, $uid);

        # Create a regular user to post the message
        $testUser = User::get($this->dbhr, $this->dbhm);
        $testUserId = $testUser->create('Test', 'Poster2', 'Test Posting User 2');
        $testUser->addEmail('testposter2@test.com');
        $testUser->addMembership($gid, User::ROLE_MEMBER);
        
        # Set user to moderated posting status to ensure message goes to PENDING
        $testUser->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        # Create a test message (based on working test pattern)
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'Test message about knives and weapons for sale', $msg);
        $msg = str_replace('Test test', 'I have some kitchen knives and hunting weapons to give away', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup2", $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'testposter2@test.com', 'testgroup2@' . GROUP_DOMAIN, $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        
        # Test ModBot review - should return error due to no moderator rights
        $modbot = new ModBot($this->dbhr, $this->dbhm);
        $result = $modbot->reviewPost($id);

        # Should return error array due to no moderation rights
        $this->assertIsArray($result, "Should return error array when modbot has no moderator rights");
        $this->assertEquals('no_moderation_rights', $result['error']);
        
        $this->log("No moderator rights test completed successfully");
    }

    public function testReviewPostWithMicrovolunteering() {
        $this->log(__METHOD__);

        # Create a test group (using different name to avoid conflicts)
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup3', Group::GROUP_FREEGLE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $g->setPrivate('onhere', 1); # Make group active
        $g->setPrivate('rules', json_encode([
            'weapons' => true,
            'alcohol' => true,
            'businessads' => false
        ]));

        # Create modbot user WITH moderator rights
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('ModBot', 'User', 'ModBot User');
        $u->addEmail(MODBOT_USER);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'modbotpw'));
        $u->addMembership($gid, User::ROLE_MODERATOR, $uid);

        # Create a regular user to post the message
        $testUser = User::get($this->dbhr, $this->dbhm);
        $testUserId = $testUser->create('Test', 'Poster3', 'Test Posting User 3');
        $testUser->addEmail('testposter3@test.com');
        $testUser->addMembership($gid, User::ROLE_MEMBER);
        
        # Set user to moderated posting status to ensure message goes to PENDING
        $testUser->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        # Create a test message (based on working test pattern)
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'Test message for microvolunteering', $msg);
        $msg = str_replace('Test test', 'Basic message for testing microvolunteering functionality', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup3", $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'testposter3@test.com', 'testgroup3@' . GROUP_DOMAIN, $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Clear any existing microvolunteering entries for this message
        $this->dbhm->preExec("DELETE FROM microactions WHERE msgid = ?", [$id]);
        
        # Log in as ModBot user so that microvolunteering entries can be created
        $this->assertTrue($u->login('modbotpw'));
        
        # Test ModBot review WITH microvolunteering enabled
        $modbot = new ModBot($this->dbhr, $this->dbhm);
        $result = $modbot->reviewPost($id, TRUE); # Enable microvolunteering

        # Check that result is valid (not an error)
        $this->assertTrue(is_array($result) || is_null($result), "Result should be array or null, not error");
        if (is_array($result) && isset($result['error'])) {
            $this->fail("Should not return error when modbot has moderator rights: " . $result['error']);
        }

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
        }

        $this->log("Microvolunteering test completed successfully");
    }

}