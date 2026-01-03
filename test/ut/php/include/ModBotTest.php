<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * Mock response object for Gemini API
 */
class MockGeminiResponse {
    private $text;

    public function __construct($text) {
        $this->text = $text;
    }

    public function text() {
        return $this->text;
    }
}

/**
 * Mock Gemini client for testing ModBot without external API calls
 */
class MockGeminiClient {
    private $responses = [];
    private $currentIndex = 0;

    public function setResponse($response) {
        $this->responses[] = $response;
    }

    public function withV1BetaVersion() {
        return $this;
    }

    public function generativeModel($model) {
        return $this;
    }

    public function withSystemInstruction($instruction) {
        return $this;
    }

    public function generateContent($content) {
        $response = $this->responses[$this->currentIndex] ?? '[]';
        if ($this->currentIndex < count($this->responses) - 1) {
            $this->currentIndex++;
        }
        return new MockGeminiResponse($response);
    }
}

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

    public function testReviewPostWithMock() {
        $this->log(__METHOD__);

        # Create a test message that should trigger rule violations
        list ($r, $id, $failok, $rc) = $this->createCustomTestMessage('OFFER: Hunting rifle and crossbow', 'testgroup', 'test@test.com', 'to@test.com', 'I have a hunting rifle and a crossbow that I want to give away. Also have some ammunition.', MailRouter::PENDING);

        # Log in as ModBot user before review
        $this->assertTrue($this->modBotUser->login('modbotpw'));

        # Create mock client with predefined response
        $mockClient = new MockGeminiClient();
        $mockClient->setResponse(json_encode([
            ['rule' => 'weapons', 'probability' => 0.95, 'reason' => 'Post mentions hunting rifle, crossbow, and ammunition which are weapons.'],
            ['rule' => 'alcohol', 'probability' => 0.0, 'reason' => 'No alcohol mentioned in the post.']
        ]));

        # Test ModBot review with mock client
        $modbot = new ModBot($this->dbhr, $this->dbhm, $mockClient);
        $result = $modbot->reviewPost($id, FALSE, TRUE, TRUE);

        $this->assertNotNull($result);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('violations', $result);
        $this->assertArrayHasKey('debug', $result);

        # Check that weapons violation was detected
        $foundWeapons = FALSE;
        foreach ($result['violations'] as $violation) {
            if ($violation['rule'] === 'weapons' && $violation['probability'] >= 0.8) {
                $foundWeapons = TRUE;
                break;
            }
        }
        $this->assertTrue($foundWeapons, 'Should detect weapons rule violation with mocked response');

        $this->log("ModBot mock test completed successfully");
    }

    public function testReviewPostNoViolations() {
        $this->log(__METHOD__);

        # Create an innocent test message
        list ($r, $id, $failok, $rc) = $this->createCustomTestMessage('OFFER: Children\'s books', 'testgroup', 'test@test.com', 'to@test.com', 'I have a collection of children\'s picture books, all in good condition. Free to a good home!', MailRouter::PENDING);

        # Log in as ModBot user before review
        $this->assertTrue($this->modBotUser->login('modbotpw'));

        # Create mock client with no violations
        $mockClient = new MockGeminiClient();
        $mockClient->setResponse(json_encode([
            ['rule' => 'weapons', 'probability' => 0.0, 'reason' => 'No weapons mentioned.'],
            ['rule' => 'alcohol', 'probability' => 0.0, 'reason' => 'No alcohol mentioned.']
        ]));

        # Test ModBot review with mock client
        $modbot = new ModBot($this->dbhr, $this->dbhm, $mockClient);
        $result = $modbot->reviewPost($id, FALSE, TRUE, TRUE);

        $this->assertNotNull($result);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('violations', $result);
        $this->assertEmpty($result['violations'], 'No violations should be detected for innocent post');

        $this->log("ModBot no violations test completed successfully");
    }

    public function testReviewPostNonExistent() {
        $this->log(__METHOD__);

        # Log in as ModBot user before review
        $this->assertTrue($this->modBotUser->login('modbotpw'));

        # Create mock client (won't be called since message doesn't exist)
        $mockClient = new MockGeminiClient();
        $modbot = new ModBot($this->dbhr, $this->dbhm, $mockClient);

        # Test with non-existent message
        $result = $modbot->reviewPost(999999);
        $this->assertIsArray($result);
        $this->assertEquals('message_not_found', $result['error']);

        $this->log("ModBot non-existent message test completed successfully");
    }

    public function testCostEstimation() {
        $this->log(__METHOD__);

        # Log in as ModBot user
        $this->assertTrue($this->modBotUser->login('modbotpw'));

        # Create mock client
        $mockClient = new MockGeminiClient();
        $modbot = new ModBot($this->dbhr, $this->dbhm, $mockClient);

        # Test cost estimation
        $inputText = "This is a test prompt with about 50 characters.";
        $outputText = "This is a response with about 40 characters.";
        $cost = $modbot->estimateCost($inputText, $outputText);

        $this->assertArrayHasKey('input_tokens', $cost);
        $this->assertArrayHasKey('output_tokens', $cost);
        $this->assertArrayHasKey('input_cost', $cost);
        $this->assertArrayHasKey('output_cost', $cost);
        $this->assertArrayHasKey('total_cost', $cost);
        $this->assertGreaterThan(0, $cost['input_tokens']);
        $this->assertGreaterThan(0, $cost['output_tokens']);

        $this->log("ModBot cost estimation test completed successfully");
    }

}