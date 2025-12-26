<?php

namespace Freegle\Iznik;

use Pheanstalk\Pheanstalk;
use Redis;

require_once IZNIK_BASE . '/composer/vendor/phpunit/phpunit/src/Framework/TestCase.php';
require_once IZNIK_BASE . '/composer/vendor/phpunit/phpunit/src/Framework/Assert/Functions.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
abstract class IznikTestCase extends \PHPUnit\Framework\TestCase {
    const LOG_SLEEP = 600;
    const DEBUG = FALSE;

    private $dbhr, $dbhm;
    public static $unique = 1;

    public function log($str) {
        if (IznikTestCase::DEBUG) {
            error_log($str);
        }
    }

    public function tidy() {
        $this->dbhm->preExec("DELETE FROM messages WHERE fromaddr = ?;", ['test@test.com' ]);
        $this->dbhm->preExec("DELETE FROM messages WHERE fromaddr = ?;", ['sender@example.net' ]);
        $this->dbhm->preExec("DELETE FROM messages WHERE fromaddr = ? OR fromip = ?;", ['from@test.com', '1.2.3.4']);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromaddr = ? OR fromip = ?;", ['from@test.com', '1.2.3.4']);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE prunedsubject LIKE ?;", ['Test spam mail']);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE prunedsubject LIKE ?;", ['Basic test']);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE prunedsubject LIKE 'OFFER: Test%';");
        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromaddr IN (?,?,?) OR fromip = ?;", ['test@test.com', 'GTUBE1.1010101@example.net', 'to@test,com', '1.2.3.4']);
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';", []);
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'test@test.com';", []);
        $this->dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.backwards LIKE 'moctset%';");
        $this->dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email LIKE 'test1@" . USER_DOMAIN . "';");
        $this->dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email LIKE 'test2@" . USER_DOMAIN . "';");
        $this->dbhm->preExec("DELETE FROM messages WHERE messageid = ?;", [ 'emff7a66f1-e0ed-4792-b493-17a75d806a30@edward-x1' ]);
        $this->dbhm->preExec("DELETE FROM messages WHERE messageid = ?;", [ 'em01169273-046c-46be-b8f7-69ad036067d0@edward-x1' ]);
        $this->dbhm->preExec("DELETE FROM messages WHERE messageid = ?;", [ 'em47d9afc0-8c92-4fc8-b791-f63ff69360a2@edward-x1' ]);
        $this->dbhm->preExec("DELETE FROM messages WHERE messageid = ?;", [ 'GTUBE1.1010101@example.net' ]);
        $this->dbhm->preExec("DELETE FROM users WHERE firstname = 'Test' AND lastname = 'User';");
        $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE subscription = 'Test';");
        $this->dbhm->preExec("DELETE FROM users_emails WHERE users_emails.backwards LIKE 'moctset%';");
        $this->dbhm->preExec("DELETE FROM users_emails WHERE userid IS NULL;");
        $this->dbhm->preExec("DELETE FROM messages WHERE fromip = '4.3.2.1';");
        $this->dbhm->preExec("DELETE FROM messages where subject LIKE 'OFFER: a double moderation test%';");
        $this->dbhm->preExec("DELETE FROM teams WHERE name = 'Test Team';");
        $this->dbhm->preExec("DELETE FROM `groups` WHERE nameshort LIKE 'testgroup%';", []);
        $this->dbhm->preExec("DELETE FROM users_notifications WHERE title LIKE 'Test';");
        $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE subscription LIKE 'Test%';");
        $this->dbhm->preExec("DELETE FROM users_replytime;");

        if (defined('_SESSION')) {
            unset($_SESSION['id']);
        }

        $ip = Utils::presdef('REMOTE_ADDR', $_SERVER, NULL);
        $lockkey = "POST_LOCK_$ip";
        $datakey = "POST_DATA_$ip";
        $predis = new \Redis();
        $predis->pconnect(REDIS_CONNECT);
        $predis->del($lockkey, $datakey);
    }

    protected function setUp() : void {
        parent::setUp ();

        $this->log(__METHOD__);

        // Output a clear test execution marker for monitoring
        $className = get_class($this);
        $testName = $this->getName();
        echo "##PHPUNIT_TEST_STARTED##:{$className}::{$testName}\n";

        putenv('UT=1');

        if (file_exists(IZNIK_BASE . '/standalone')) {
            # Probably in Docker.
            putenv('STANDALONE=1');
        }

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->tidy();

        @session_destroy();
        global $sessionPrepared;
        $sessionPrepared = FALSE;
        @session_start();

        # Clear duplicate protection.
        $datakey = 'POST_DATA_' . session_id();
        $predis = new \Redis();
        $predis->pconnect(REDIS_CONNECT);
        $predis->del($datakey);

        User::clearCache();

        set_time_limit(600);
    }

    protected function tearDown() : void {
        parent::tearDown ();
        try {
            $this->dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
            $this->dbhm->preExec("DELETE FROM messages WHERE subject = 'OFFER: a thing (Tuvalu)';");
            $this->dbhm->preExec("DELETE FROM communityevents WHERE title = 'Test Event';");

            $users = $this->dbhr->preQuery("SELECT userid FROM users_emails WHERE backwards LIKE 'moctset@%';");
            foreach ($users as $user) {
                $this->dbhm->preExec("DELETE FROM users WHERE id = ?;", [ $user['userid'] ]);
            }
            @session_destroy();
            unset($_SESSION);
        } catch (\Exception $e) {
            $this->log("Session exception " . $e->getMessage());
        }

        }

    protected function stopBackgroundScripts() {
        # Create abort file to signal background scripts to exit
        touch('/tmp/iznik.mail.abort');

        # Wait for background scripts to exit, checking every 100ms.
        # Scripts check the abort file roughly every second, so they should exit quickly.
        $maxWaitMs = 3000;
        $waitedMs = 0;
        $sleepMs = 100;

        while ($waitedMs < $maxWaitMs) {
            # Check if any background mail/chat scripts are still running.
            $output = [];
            @exec('pgrep -f "scripts/cron/(chat_|spool|digest)" 2>/dev/null', $output);

            if (empty($output)) {
                # No background scripts running, we can proceed.
                return;
            }

            usleep($sleepMs * 1000);
            $waitedMs += $sleepMs;
        }
    }

    protected function startBackgroundScripts() {
        # Remove abort file to allow background scripts to restart
        @unlink('/tmp/iznik.mail.abort');
    }

    public function unique($msg) {

        $unique = time() . rand(1,1000000) . IznikTestCase::$unique++;
        $newmsg1 = preg_replace('/X-Yahoo-Newman-Id: (.*)\-m\d*/i', "X-Yahoo-Newman-Id: $1-m$unique", $msg);
        #assertNotEquals($msg, $newmsg1, "Newman-ID");
        $newmsg2 = preg_replace('/Message-Id:.*\<.*\>/i', 'Message-Id: <' . $unique . "@test>", $newmsg1);
        #assertNotEquals($newmsg2, $newmsg1, "Message-Id");
        #$this->log("Unique $newmsg2");
        return($newmsg2);
    }

    public function waitBackground() {
        # We wait until either the queue is empty, or the first item on it has been put there since we started
        # waiting (and therefore anything we put on has been handled).
        $start = microtime(TRUE);

        $pheanstalk = Pheanstalk::create(PHEANSTALK_SERVER);
        $count = 0;
        do {
            $stats = $pheanstalk->stats();
            $ready = $stats['current-jobs-ready'];
            $reserved = $stats['current-jobs-reserved'];

            $this->log("...waiting for background work, current $ready/$reserved, try $count");

            if ($ready + $reserved == 0) {
                $this->log("Queue is empty, exit");
                break;
            }

            try {
                $job = $pheanstalk->peekReady();

                if ($job) {
                    $data = json_decode($job->getData(), TRUE);

                    if ($data['queued'] > $start) {
                        $this->log("Queue now newer than when we started");
                        sleep(2);
                        break;
                    }
                }
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), "NOT_FOUND: There are no jobs in the 'ready' status") !== FALSE) {
                    if ($reserved) {
                        $this->log("...no jobs ready but $reserved reserved, continue");
                    } else {
                        $this->log("...no jobs ready and no reserved");
                        break;
                    }
                } else {
                    error_log("Exception waiting for background " . $e->getMessage());
                }
            }

            sleep(5);
            $count++;

        } while ($count < IznikTestCase::LOG_SLEEP);

        if ($count >= IznikTestCase::LOG_SLEEP) {
            $this->assertFalse(TRUE, 'Failed to complete background work');
        }
    }

    public function findLog($type, $subtype, $logs) {
        foreach ($logs as $log) {
            if ($log['type'] == $type && $log['subtype'] == $subtype) {
                $this->log("Found log " . var_export($log, TRUE));
                return($log);
            }
        }

        $this->log("Failed to find log $type $subtype in " . var_export($logs, TRUE));
        return(NULL);
    }

    public function deleteLocations($query) {
        # Now that we have Postgresql, we need to delete from there too.  So rather than doing a raw SQL delete,
        # find the locations and delete them propertly.
        $query = str_ireplace('DELETE FROM', 'SELECT id, name FROM', $query);
        $locations = $this->dbhr->preQuery($query);

        foreach ($locations as $location) {
            $l = new Location($this->dbhr, $this->dbhm, $location['id']);
            $l->delete();
        }
    }

    public function trueFalseProvider() {
        return [
            [ TRUE ],
            [ FALSE ]
        ];
    }

    /**
     * Create a test user with login credentials
     * @param string $fullname User's full name
     * @param string $password Login password
     * @return array [User instance, user ID]
     */
    protected function createTestUserWithLogin($fullname, $password) {
        $u = User::get($this->dbhr, $this->dbhm);
        // Parse fullname into firstname/lastname for original UserTest.php compatibility
        // Original pattern was: $u->create('Test', 'User', NULL)
        $parts = explode(' ', $fullname, 2);
        $firstname = isset($parts[0]) ? $parts[0] : NULL;
        $lastname = isset($parts[1]) ? $parts[1] : NULL;
        $uid = $u->create($firstname, $lastname, NULL);
        self::assertNotNull($uid);
        $user = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals($user->getId(), $uid);
        $this->assertGreaterThan(0, $user->addLogin(User::LOGIN_NATIVE, NULL, $password));
        return [$user, $uid];
    }

    /**
     * Create a test group with standard settings, or return existing one
     */
    protected function createTestGroup($name, $type) {
        $g = Group::get($this->dbhr, $this->dbhm);
        $this->assertNotNull($g, "Failed to get Group instance");

        // First check if the group already exists using findByShortName
        $groupid = $g->findByShortName($name);

        if ($groupid) {
            // Group exists, return it
            $existingGroup = Group::get($this->dbhr, $this->dbhm, $groupid);
            $this->assertNotNull($existingGroup, "Failed to retrieve existing group '$name'");
            return [$existingGroup, $groupid];
        }

        // Group doesn't exist, create it
        $groupid = $g->create($name, $type);
        $this->assertGreaterThan(0, $groupid, "Failed to create group '$name'");

        // Verify the group was created correctly
        $createdGroup = Group::get($this->dbhr, $this->dbhm, $groupid);
        $this->assertNotNull($createdGroup, "Failed to retrieve created group");
        $this->assertEquals($name, $createdGroup->getPrivate('nameshort'), "Group name mismatch");
        $this->assertEquals($type, $createdGroup->getPrivate('type'), "Group type mismatch");

        return [$createdGroup, $groupid];
    }

    /**
     * Create a test user with optional email and login - supports both original patterns:
     * - createTestUser('Test', 'User', NULL, 'email', 'pass') for firstname/lastname
     * - createTestUser(NULL, NULL, 'Test User', 'email', 'pass') for fullname only
     * - createTestUser(NULL, NULL, 'Test User', NULL, 'pass') for no email initially
     */
    protected function createTestUser($firstname, $lastname, $fullname, $email, $password) {
        $u = User::get($this->dbhr, $this->dbhm);
        $this->assertNotNull($u, "Failed to get User instance");
        
        // Create user with exact same parameters as original tests
        $uid = $u->create($firstname, $lastname, $fullname);
        $this->assertGreaterThan(0, $uid, "Failed to create user '$fullname'");
        
        $user = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertNotNull($user, "Failed to retrieve created user");
        $this->assertEquals($uid, $user->getId(), "User ID mismatch");
        
        // Add email only if provided - allows for tests that need users with no email initially
        $emailid = NULL;
        if ($email !== NULL) {
            $emailid = $user->addEmail($email);
            // Don't assert on email addition - let the calling test handle the result as needed
            // The original tests had different expectations for email addition success
        }
        
        $loginid = $user->addLogin(User::LOGIN_NATIVE, NULL, $password);
        $this->assertGreaterThan(0, $loginid, "Failed to add login for user");
        
        return [$user, $uid, $emailid];
    }

    /**
     * Create a test message using MailRouter
     * @param string|null $sourceFile Message source file name (e.g. 'basic', 'spam') or full content if prefixed with full path
     * @param string $groupname Group name to replace in message
     * @param string $fromEmail From email address
     * @param string $toEmail To email address  
     * @param int|null $groupid Group ID for user membership (needed for approval)
     * @param int|null $userid User ID to set up membership for (creates user if null)
     * @param array $substitutions Array of find=>replace substitutions to apply to message
     * @param bool $expectSuccess Whether message creation should succeed (default: TRUE)
     * @param bool $expectFailok Whether MailRouter should return failok=TRUE (default: FALSE)
     * @param int|null $expectedRC Expected routing result (default: null, no assertion)
     */
    protected function createTestMessage($sourceFile = 'basic', $groupname = 'testgroup', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $groupid = NULL, $userid = NULL, $substitutions = [], $expectSuccess = TRUE, $expectFailok = FALSE, $expectedRC = NULL) {
        // Check if first parameter is already message content (contains newlines and headers)
        if (is_string($sourceFile) && (strpos($sourceFile, "\n") !== FALSE || strpos($sourceFile, "From:") !== FALSE || strpos($sourceFile, "Subject:") !== FALSE)) {
            // First parameter is already message content, use it directly
            $content = $sourceFile;
            // Don't apply the unique() method again as it was likely already applied
        } else {
            // Load source file
            if ($sourceFile === null || $sourceFile === 'basic') {
                $msgPath = IZNIK_BASE . '/test/ut/php/msgs/basic';
            } elseif (strpos($sourceFile, '/') !== FALSE) {
                // Full path provided
                $msgPath = $sourceFile;
            } else {
                // Source file name provided
                $msgPath = IZNIK_BASE . '/test/ut/php/msgs/' . $sourceFile;
            }
            
            $content = $this->unique(file_get_contents($msgPath));
        }
        
        $this->assertNotEmpty($content, "Message content is empty");
        
        // Apply default substitutions
        $content = str_ireplace('freegleplayground', $groupname, $content);
        
        // Apply custom substitutions
        foreach ($substitutions as $find => $replace) {
            $content = str_ireplace($find, $replace, $content);
        }
        
        $this->assertStringContainsString($groupname, $content, "Group name replacement failed");
        
        // Set up user membership for message approval if group and user provided
        if ($groupid !== null && $userid !== null) {
            $user = User::get($this->dbhr, $this->dbhm, $userid);
            $this->assertNotNull($user, "User not found for message creation");
            
            // Ensure the user has the fromEmail - this is crucial for message routing
            $userEmails = $user->getEmails();
            $hasFromEmail = FALSE;
            foreach ($userEmails as $email) {
                if ($email['email'] === $fromEmail) {
                    $hasFromEmail = TRUE;
                    break;
                }
            }
            
            if (!$hasFromEmail) {
                $user->addEmail($fromEmail);
            }
            
            // Add user to group if not already a member
            if ($user->isApprovedMember($groupid) === null) {
                $user->addMembership($groupid);
            }
            
            // Note: Not setting ourPostingStatus automatically as tests may expect different behaviors
        }
        
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $this->assertNotNull($r, "Failed to create MailRouter instance");
        
        list ($id, $failok) = $r->received(Message::EMAIL, $fromEmail, $toEmail, $content);
        
        if ($expectSuccess) {
            $this->assertGreaterThan(0, $id, "Failed to create message via MailRouter");
        }
        
        if ($expectFailok) {
            $this->assertTrue($failok, "MailRouter received() returned failure");
        }
        
        // Route the message
        $rc = $r->route();
        
        // Verify the message was created (only if expecting success and $id > 0)
        if ($expectSuccess && $id > 0) {
            $message = new Message($this->dbhr, $this->dbhm, $id);
            $this->assertNotNull($message, "Failed to retrieve created message");
            $this->assertEquals($id, $message->getId(), "Message ID mismatch");
        }
        
        return [$r, $id, $failok, $rc];
    }

    /**
     * Helper to create a simple test message with custom subject 
     * @param string $subject The subject/title for the message
     * @param string $groupname The group name to use
     * @param string $fromEmail The from email address
     * @param string $toEmail The to email address  
     * @param int $expectedRC Expected routing result (default: PENDING)
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createSimpleTestMessage($subject, $groupname, $fromEmail, $toEmail, $expectedRC = MailRouter::PENDING) {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', $subject, $msg);
        $msg = str_ireplace('freegleplayground', $groupname, $msg);
        
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, $fromEmail, $toEmail, $msg);
        $rc = $r->route();
        
        if ($expectedRC !== null) {
            $this->assertEquals($expectedRC, $rc);
        }
        
        return [$r, $id, $failok, $rc];
    }

    /**
     * Helper to create a test message with full customization options
     * @param string $subject The subject/title for the message  
     * @param string $groupname The group name to use
     * @param string $fromEmail The from email address
     * @param string $toEmail The to email address
     * @param string|null $bodyReplacement Optional replacement for message body content
     * @param int|null $expectedRC Expected routing result (null = no assertion)
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createCustomTestMessage($subject, $groupname, $fromEmail, $toEmail, $bodyReplacement = NULL, $expectedRC = NULL) {
        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', $groupname, $msg);
        $msg = str_replace('Basic test', $subject, $msg);
        $msg = str_replace('test@test.com', $fromEmail, $msg);
        
        if ($bodyReplacement !== NULL) {
            $msg = str_replace('Test test', $bodyReplacement, $msg);
        }
        
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, $fromEmail, $toEmail, $msg);
        $rc = $r->route();
        
        if ($expectedRC !== NULL) {
            $this->assertEquals($expectedRC, $rc);
        }
        
        return [$r, $id, $failok, $rc];
    }

    /**
     * Create a conversation between two users
     * @param int $user1 First user ID
     * @param int $user2 Second user ID
     * @return array [ChatRoom_object, conversation_id, blocked_status]
     */
    protected function createTestConversation($user1, $user2) {
        $this->assertGreaterThan(0, $user1, "User 1 ID must be valid");
        $this->assertGreaterThan(0, $user2, "User 2 ID must be valid");
        
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($id, $blocked) = $r->createConversation($user1, $user2);
        $this->assertNotNull($id, "Failed to create conversation");
        
        // Return the ChatRoom object for the conversation
        $conversation = new ChatRoom($this->dbhr, $this->dbhm, $id);
        return [$conversation, $id, $blocked];
    }

    /**
     * Create a test location
     * @param string|null $parent Parent location ID (optional)
     * @param string $name Location name
     * @param string $type Location type (default: 'Road') 
     * @param string $geometry Geometry string (default: UK point)
     * @return array [Location, location_id]
     */
    protected function createTestLocation($parent = NULL, $name = 'Test Location', $type = 'Road', $geometry = 'POINT(-1.0 52.0)') {
        $l = new Location($this->dbhr, $this->dbhm);
        $id = $l->create($parent, $name, $type, $geometry);
        $this->assertNotNull($id, "Failed to create location");
        
        return [$l, $id];
    }

    /**
     * Create a test attachment with an image
     * @param string $imageFile Image file path (default: chair.jpg)
     * @param string $type Attachment type (default: TYPE_CHAT_MESSAGE)
     * @return array [Attachment, attachment_id, uid]
     */
    protected function createTestImageAttachment($imageFile = '/test/ut/php/images/chair.jpg', $type = Attachment::TYPE_CHAT_MESSAGE) {
        $data = file_get_contents(IZNIK_BASE . $imageFile);
        $this->assertNotFalse($data, "Failed to read image file: $imageFile");
        
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, $type);
        list ($attid, $uid) = $a->create(NULL, $data);
        $this->assertNotNull($attid, "Failed to create attachment");
        
        return [$a, $attid, $uid];
    }

    /**
     * Create a test community event
     * @param string $title Event title (default: 'Test Event')
     * @param string $location Event location (default: 'Test Location')
     * @param int|null $userid User ID who creates the event (optional)
     * @param int|null $groupid Group ID for the event (optional)
     * @return array [CommunityEvent, event_id]
     */
    protected function createTestCommunityEvent($title = 'Test Event', $location = 'Test Location', $userid = NULL, $groupid = NULL) {
        $c = new CommunityEvent($this->dbhr, $this->dbhm);
        $id = $c->create($userid, $title, $location, NULL, NULL, NULL, NULL, 'Test description');
        $this->assertNotNull($id, "Failed to create community event");
        
        if ($groupid !== NULL) {
            $c->addGroup($groupid);
        }
        
        return [$c, $id];
    }

    /**
     * Create a message object with parsing and saving
     * @param string $subject The subject/title for the message
     * @param string $fromEmail From email address
     * @param string $toEmail To email address
     * @param string|null $bodyReplacement Optional body replacement
     * @return array [Message, message_id, save_result]
     */
    protected function createParsedTestMessage($subject = 'Test Message', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $bodyReplacement = NULL) {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', $subject, $msg);
        
        if ($bodyReplacement !== NULL) {
            $msg = str_replace('Test test', $bodyReplacement, $msg);
        }
        
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, $fromEmail, $toEmail, $msg);
        list ($id, $failok) = $m->save();
        
        return [$m, $id, $failok];
    }

    /**
     * Create a chat message in a chat room
     * @param int $chatRoomId Chat room ID
     * @param int $userId User ID sending the message
     * @param string $message Message content
     * @param string $type Message type (default: TYPE_DEFAULT)
     * @param int|null $refMsgId Referenced message ID (optional)
     * @return array [ChatMessage, message_id, banned_status]
     */
    protected function createTestChatMessage($chatRoomId, $userId, $message = 'Test message', $type = ChatMessage::TYPE_DEFAULT, $refMsgId = NULL) {
        $this->assertGreaterThan(0, $chatRoomId, "Chat room ID must be valid");
        $this->assertGreaterThan(0, $userId, "User ID must be valid");
        
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $cm->create($chatRoomId, $userId, $message, $type, $refMsgId);
        $this->assertNotNull($mid, "Failed to create chat message");
        
        return [$cm, $mid, $banned];
    }

    /**
     * Create and route a message via MailRouter (most common pattern)
     * @param string $content Message content (or null for basic message)
     * @param string $fromEmail From email address
     * @param string $toEmail To email address  
     * @param int|null $expectedRC Expected routing result (null = no assertion)
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createAndRouteMessage($content = NULL, $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $expectedRC = NULL) {
        if ($content === NULL) {
            $content = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        }
        
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, $fromEmail, $toEmail, $content);
        $rc = $r->route();
        
        if ($expectedRC !== NULL) {
            $this->assertEquals($expectedRC, $rc);
        }
        
        return [$r, $id, $failok, $rc];
    }

    /**
     * Create a test volunteering opportunity
     * @param int|null $userid User ID creating the opportunity
     * @param string $title Opportunity title
     * @param string $location Location
     * @param string $contactName Contact name
     * @param string $contactPhone Contact phone
     * @param string $contactEmail Contact email
     * @param string $description Description
     * @param string $timeCommitment Time commitment
     * @return array [Volunteering, opportunity_id]
     */
    protected function createTestVolunteering($userid = NULL, $title = 'Test Volunteering', $location = 'Test Location', $contactName = 'Test Contact', $contactPhone = '000 000 000', $contactEmail = 'test@test.com', $description = 'A test volunteering opportunity', $timeCommitment = 'Some time') {
        $e = new Volunteering($this->dbhr, $this->dbhm);
        $id = $e->create($userid, $title, 0, $location, $contactName, $contactPhone, $contactEmail, 'http://ilovefreegle.org', $description, $timeCommitment);
        $this->assertNotNull($id, "Failed to create volunteering opportunity");
        
        return [$e, $id];
    }

    /**
     * Create a test volunteering opportunity with automatic group assignment and approval
     * @param int|null $userid User ID creating the opportunity
     * @param string $title Opportunity title
     * @param int $groupid Group ID to assign to
     * @param bool $approve Whether to approve immediately (default: TRUE)
     * @return array [Volunteering, opportunity_id]
     */
    protected function createTestVolunteeringWithGroup($userid = NULL, $title = 'Test Volunteering', $groupid = NULL, $approve = TRUE) {
        list($e, $id) = $this->createTestVolunteering($userid, $title);
        
        if ($groupid !== NULL) {
            $e->addGroup($groupid);
        }
        
        if ($approve) {
            $e->setPrivate('pending', 0);
        }
        
        return [$e, $id];
    }

    /**
     * Create a test user and automatically log them in
     * @param int|null $facebookId Facebook ID (optional)
     * @param string|null $yahooId Yahoo ID (optional) 
     * @param string $fullname Full name
     * @param string $email Email address
     * @param string $password Password
     * @return array [User, user_id, email_id]
     */
    protected function createTestUserAndLogin($facebookId = NULL, $yahooId = NULL, $fullname = 'Test User', $email = 'test@test.com', $password = 'testpw') {
        list($user, $uid, $emailid) = $this->createTestUser($facebookId, $yahooId, $fullname, $email, $password);
        $this->assertTrue($user->login($password), "Failed to login created user");
        return [$user, $uid, $emailid];
    }

    /**
     * Create a test authority with polygon boundary
     * @param string $name Authority name
     * @param string $abbreviation Authority abbreviation
     * @param string $polygon Polygon boundary (WKT format)
     * @return array [Authority, authority_id]
     */
    protected function createTestAuthority($name = 'UTAuth', $abbreviation = 'GLA', $polygon = 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))') {
        $a = new Authority($this->dbhr, $this->dbhm);
        $aid = $a->create($name, $abbreviation, $polygon);
        $this->assertNotNull($aid, "Failed to create authority");
        return [$a, $aid];
    }

    /**
     * Create a test message that gets automatically approved
     */
    protected function createApprovedTestMessage($groupname, $content, $fromEmail, $toEmail) {
        // Create group and user for approval
        list($group, $groupid) = $this->createTestGroup($groupname, Group::GROUP_FREEGLE);
        list($user, $userid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', $fromEmail);
        
        // Ensure user is properly set up for message approval
        $user->addMembership($groupid);
        $user->setMembershipAtt($groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        
        // Create approved message
        list($r, $id, $failok, $rc) = $this->createTestMessage($content, $groupname, $fromEmail, $toEmail, $groupid, $userid);
        
        // Assert it was approved
        $this->assertEquals(MailRouter::APPROVED, $rc, "Message was not approved - got: $rc");
        
        return [$r, $id, $failok, $rc, $groupid, $userid];
    }

    /**
     * Create a test chat room
     * @param mixed $groupid_or_userid For group chats: groupid. For user2mod: userid. For user2user: array of [userid1, userid2]
     * @param string $type ChatRoom::TYPE_GROUP (creates mod2mod), ChatRoom::TYPE_USER2MOD, or ChatRoom::TYPE_USER2USER
     */
    protected function createTestChatRoom($groupid_or_userid, $type) {
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $this->assertNotNull($c, "Failed to create ChatRoom instance");
        
        switch ($type) {
            case ChatRoom::TYPE_GROUP:
                $groupid = $groupid_or_userid;
                if ($groupid === null) {
                    list($g, $groupid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
                }
                $this->assertGreaterThan(0, $groupid, "Invalid group ID for group chat");
                
                // Get the group name to use as chat room name
                $group = Group::get($this->dbhr, $this->dbhm, $groupid);
                $this->assertNotNull($group, "Failed to retrieve group for chat room");
                $groupName = $group->getPrivate('nameshort');
                $this->assertNotEmpty($groupName, "Group name is empty");
                
                $cid = $c->createGroupChat($groupName, $groupid);
                $this->assertGreaterThan(0, $cid, "Failed to create group chat room");
                // Note: createGroupChat() actually creates TYPE_MOD2MOD, not TYPE_GROUP
                $type = ChatRoom::TYPE_MOD2MOD;
                break;
                
            case ChatRoom::TYPE_USER2MOD:
                $userid = $groupid_or_userid;
                if ($userid === null) {
                    list($user, $userid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
                }
                $this->assertGreaterThan(0, $userid, "Invalid user ID for user2mod chat");
                
                $cid = $c->createUser2Mod($userid);
                $this->assertGreaterThan(0, $cid, "Failed to create user2mod chat room");
                break;
                
            case ChatRoom::TYPE_USER2USER:
                $userids = $groupid_or_userid;
                if ($userids === null) {
                    list($user1, $uid1, $emailid1) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
                    list($user2, $uid2, $emailid2) = $this->createTestUser(NULL, NULL, 'Test User 2', 'test2@test.com', 'testpw');
                    $userids = [$uid1, $uid2];
                }
                $this->assertIsArray($userids, "User IDs must be an array for user2user chat");
                $this->assertCount(2, $userids, "User2user chat requires exactly 2 user IDs");
                $this->assertGreaterThan(0, $userids[0], "First user ID is invalid");
                $this->assertGreaterThan(0, $userids[1], "Second user ID is invalid");
                $this->assertNotEquals($userids[0], $userids[1], "User IDs must be different for user2user chat");
                
                $cid = $c->createConversation($userids[0], $userids[1]);
                $this->assertGreaterThan(0, $cid, "Failed to create user2user chat room");
                break;
                
            default:
                throw new \InvalidArgumentException("Invalid chat room type: $type");
        }
        
        // Verify the chat room was created correctly
        $createdRoom = new ChatRoom($this->dbhr, $this->dbhm, $cid);
        $this->assertNotNull($createdRoom, "Failed to retrieve created chat room");
        $this->assertEquals($cid, $createdRoom->getId(), "Chat room ID mismatch");
        $this->assertEquals($type, $createdRoom->getPrivate('chattype'), "Chat room type mismatch");
        
        return [$c, $cid];
    }

    /**
     * Create a test user with membership and moderator role
     */
    protected function createTestUserWithMembership($groupid, $role, $fullname, $email, $password) {
        $this->assertGreaterThan(0, $groupid, "Invalid group ID for user membership");
        
        list($user, $uid, $emailid) = $this->createTestUser(NULL, NULL, $fullname, $email, $password);
        
        $membershipId = $user->addMembership($groupid, $role, $emailid);
        $this->assertGreaterThan(0, $membershipId, "Failed to add user membership to group");
        
        // Verify membership was created correctly
        $this->assertNotNull($user->isApprovedMember($groupid), "User is not an approved member after creation");
        
        // Verify the group exists
        $group = Group::get($this->dbhr, $this->dbhm, $groupid);
        $this->assertNotNull($group, "Group does not exist for membership");
        
        return [$user, $uid];
    }

    /**
     * Add login to existing user and call login() method
     * @param User $user The user object to add login to
     * @param string $password The password for login
     * @param string|null $uid Optional external user ID for login
     * @return int Login ID
     */
    protected function addLoginAndLogin($user, $password, $uid = NULL) {
        $this->assertNotNull($user, "User object cannot be null");
        $this->assertNotEmpty($password, "Password cannot be empty");
        
        $loginid = $user->addLogin(User::LOGIN_NATIVE, $uid, $password);
        $this->assertGreaterThan(0, $loginid, "Failed to add login for user");
        
        $loginResult = $user->login($password);
        $this->assertTrue($loginResult, "Failed to login user with password");
        
        return $loginid;
    }


    /**
     * Create a test user with membership and login
     * @param int $groupid Group ID to add membership to
     * @param string $role User role (default: ROLE_MEMBER)
     * @param string $firstname User's first name  
     * @param string $lastname User's last name
     * @param string $fullname User's full name (if not using first/last)
     * @param string $email User's email address
     * @param string $password Login password
     * @return array [User instance, user ID, email ID]
     */
    protected function createTestUserWithMembershipAndLogin($groupid, $role = User::ROLE_MEMBER, $firstname = NULL, $lastname = NULL, $fullname = NULL, $email = NULL, $password = NULL) {
        // Set default values if not provided
        if ($fullname === NULL && $firstname === NULL && $lastname === NULL) {
            $fullname = 'Test User';
        }
        if ($email === NULL) {
            $email = 'test@test.com';
        }
        if ($password === NULL) {
            $password = 'testpw';
        }
        
        list($user, $uid, $emailid) = $this->createTestUser($firstname, $lastname, $fullname, $email, $password);
        $user->addMembership($groupid, $role);
        $this->addLoginAndLogin($user, $password);
        
        return [$user, $uid, $emailid];
    }

    /**
     * Create an OFFER message with default item and location
     * @param string $item Item being offered (default: 'Test item')
     * @param string $location Location for the offer (default: 'Test location')
     * @param string $sourceFile Source message file (default: 'basic')
     * @param string $groupname Group name (default: 'testgroup')
     * @param string $fromEmail From email (default: 'from@test.com')
     * @param string $toEmail To email (default: 'to@test.com')
     * @param int|null $groupid Group ID for membership
     * @param int|null $userid User ID for membership
     * @param array $substitutions Additional substitutions
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createOfferMessage($item = 'Test item', $location = 'Test location', $sourceFile = 'basic', $groupname = 'testgroup', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $groupid = NULL, $userid = NULL, $substitutions = []) {
        $subject = "OFFER: $item ($location)";
        $substitutions['Basic test'] = $subject;
        return $this->createTestMessage($sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $substitutions);
    }

    /**
     * Create a TAKEN message with default item and location
     * @param string $item Item that was taken (default: 'Test item')
     * @param string $location Location for the item (default: 'Test location')
     * @param string $sourceFile Source message file (default: 'basic')
     * @param string $groupname Group name (default: 'testgroup')
     * @param string $fromEmail From email (default: 'from@test.com')
     * @param string $toEmail To email (default: 'to@test.com')
     * @param int|null $groupid Group ID for membership
     * @param int|null $userid User ID for membership
     * @param array $substitutions Additional substitutions
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createTakenMessage($item = 'Test item', $location = 'Test location', $sourceFile = 'basic', $groupname = 'testgroup', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $groupid = NULL, $userid = NULL, $substitutions = []) {
        $subject = "TAKEN: $item ($location)";
        $substitutions['Basic test'] = $subject;
        return $this->createTestMessage($sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $substitutions);
    }

    /**
     * Create a WANTED message with default item and location
     * @param string $item Item being wanted (default: 'Test item')
     * @param string $location Location for the want (default: 'Test location')
     * @param string $sourceFile Source message file (default: 'basic')
     * @param string $groupname Group name (default: 'testgroup')
     * @param string $fromEmail From email (default: 'from@test.com')
     * @param string $toEmail To email (default: 'to@test.com')
     * @param int|null $groupid Group ID for membership
     * @param int|null $userid User ID for membership
     * @param array $substitutions Additional substitutions
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createWantedMessage($item = 'Test item', $location = 'Test location', $sourceFile = 'basic', $groupname = 'testgroup', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $groupid = NULL, $userid = NULL, $substitutions = []) {
        $subject = "WANTED: $item ($location)";
        $substitutions['Basic test'] = $subject;
        return $this->createTestMessage($sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $substitutions);
    }

    /**
     * Convert a message subject from one type to another
     * @param string $originalSubject Original message subject
     * @param string $fromType Source message type (OFFER, TAKEN, WANTED)
     * @param string $toType Target message type (OFFER, TAKEN, WANTED)
     * @return string Converted subject
     */
    protected function convertMessageType($originalSubject, $fromType, $toType) {
        $fromType = strtoupper($fromType);
        $toType = strtoupper($toType);
        
        // Extract item and location from subject like "OFFER: item (location)"
        $pattern = '/^' . preg_quote($fromType) . ':\s*(.+?)\s*(\([^)]+\))?$/i';
        if (preg_match($pattern, $originalSubject, $matches)) {
            $item = trim($matches[1]);
            $location = isset($matches[2]) ? $matches[2] : '';
            return "$toType: $item $location";
        }
        
        // Fallback: simple string replacement
        return str_ireplace($fromType . ':', $toType . ':', $originalSubject);
    }

    /**
     * Create multiple message variations from a base message
     * @param string $baseItem Base item name
     * @param string $baseLocation Base location
     * @param array $types Array of message types to create (default: ['OFFER', 'TAKEN', 'WANTED'])
     * @param string $sourceFile Source message file (default: 'basic')
     * @param string $groupname Group name (default: 'testgroup') 
     * @param string $fromEmail From email (default: 'from@test.com')
     * @param string $toEmail To email (default: 'to@test.com')
     * @param int|null $groupid Group ID for membership
     * @param int|null $userid User ID for membership
     * @param array $additionalSubstitutions Additional substitutions for all messages
     * @return array Array of [type => [MailRouter, message_id, failok, routing_result]]
     */
    protected function createMessageVariations($baseItem = 'Test item', $baseLocation = 'Test location', $types = ['OFFER', 'TAKEN', 'WANTED'], $sourceFile = 'basic', $groupname = 'testgroup', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $groupid = NULL, $userid = NULL, $additionalSubstitutions = []) {
        $results = [];
        
        foreach ($types as $type) {
            $type = strtoupper($type);
            switch ($type) {
                case 'OFFER':
                    $results[$type] = $this->createOfferMessage($baseItem, $baseLocation, $sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $additionalSubstitutions);
                    break;
                case 'TAKEN':
                    $results[$type] = $this->createTakenMessage($baseItem, $baseLocation, $sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $additionalSubstitutions);
                    break;
                case 'WANTED':
                    $results[$type] = $this->createWantedMessage($baseItem, $baseLocation, $sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $additionalSubstitutions);
                    break;
                default:
                    // For custom types, create with the type prefix
                    $subject = "$type: $baseItem ($baseLocation)";
                    $substitutions = array_merge(['Basic test' => $subject], $additionalSubstitutions);
                    $results[$type] = $this->createTestMessage($sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $substitutions);
                    break;
            }
        }
        
        return $results;
    }

    /**
     * Create message content with substitutions (without routing)
     * @param string $sourceFile Source message file (default: 'basic')
     * @param array $substitutions Text substitutions to apply
     * @return string Message content
     */
    protected function createMessageContent($sourceFile = 'basic', $substitutions = []) {
        // Load source file
        if ($sourceFile === 'basic') {
            $msgPath = IZNIK_BASE . '/test/ut/php/msgs/basic';
        } else {
            $msgPath = IZNIK_BASE . '/test/ut/php/msgs/' . $sourceFile;
        }
        
        $content = $this->unique(file_get_contents($msgPath));
        
        // Apply substitutions
        foreach ($substitutions as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
        
        return $content;
    }

    /**
     * Create a reply message to an existing message
     * @param string $originalSubject Original message subject
     * @param string $replyPrefix Reply prefix (default: 'Re:')
     * @param string $sourceFile Source message file (default: 'basic')
     * @param string $groupname Group name (default: 'testgroup')
     * @param string $fromEmail From email (default: 'from@test.com')
     * @param string $toEmail To email (default: 'to@test.com')
     * @param int|null $groupid Group ID for membership
     * @param int|null $userid User ID for membership
     * @param array $substitutions Additional substitutions
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createReplyMessage($originalSubject, $replyPrefix = 'Re:', $sourceFile = 'basic', $groupname = 'testgroup', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $groupid = NULL, $userid = NULL, $substitutions = []) {
        $replySubject = "$replyPrefix $originalSubject";
        $substitutions['Basic test'] = $replySubject;
        return $this->createTestMessage($sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $substitutions);
    }

    /**
     * Get common substitution patterns for OFFER messages
     * @param string $item Item being offered (default: 'Test item')
     * @param string $location Location (default: 'Test location')
     * @param array $additionalSubstitutions Additional custom substitutions
     * @return array Substitution patterns array
     */
    protected function getOfferSubstitutions($item = 'Test item', $location = 'Test location', $additionalSubstitutions = []) {
        $substitutions = [
            'Basic test' => "OFFER: $item ($location)",
            'freegleplayground' => 'testgroup',
            'from@test.com' => 'from@test.com',
            'to@test.com' => 'to@test.com'
        ];
        
        return array_merge($substitutions, $additionalSubstitutions);
    }

    /**
     * Get common substitution patterns for spam testing
     * @param string $spamSubject Spam subject line (default: 'Test spam mail')
     * @param string $spamEmail Spam email address (default: 'GTUBE1.1010101@example.net')
     * @param array $additionalSubstitutions Additional custom substitutions
     * @return array Substitution patterns array
     */
    protected function getSpamTestSubstitutions($spamSubject = 'Test spam mail', $spamEmail = 'GTUBE1.1010101@example.net', $additionalSubstitutions = []) {
        $substitutions = [
            'Basic test' => $spamSubject,
            'from@test.com' => $spamEmail,
            'freegleplayground' => 'testgroup',
            'Test test' => 'This is spam content for testing'
        ];
        
        return array_merge($substitutions, $additionalSubstitutions);
    }

    /**
     * Get substitution patterns for multi-user scenarios
     * @param array $userMappings Array of email mappings like ['from@test.com' => 'user1@test.com', 'to@test.com' => 'user2@test.com']
     * @param string $groupname Group name (default: 'testgroup')
     * @param array $additionalSubstitutions Additional custom substitutions
     * @return array Substitution patterns array
     */
    protected function getMultiUserSubstitutions($userMappings = [], $groupname = 'testgroup', $additionalSubstitutions = []) {
        $defaultMappings = [
            'from@test.com' => 'user1@test.com',
            'to@test.com' => 'user2@test.com'
        ];
        
        $userMappings = array_merge($defaultMappings, $userMappings);
        $substitutions = array_merge($userMappings, [
            'freegleplayground' => $groupname
        ]);
        
        return array_merge($substitutions, $additionalSubstitutions);
    }

    /**
     * Get substitution patterns for temporal/date replacements
     * @param string $dateFormat Date format (default: 'Y-m-d H:i:s')
     * @param string|null $specificDate Specific date to use (default: current time)
     * @param array $additionalSubstitutions Additional custom substitutions
     * @return array Substitution patterns array
     */
    protected function getTemporalSubstitutions($dateFormat = 'Y-m-d H:i:s', $specificDate = NULL, $additionalSubstitutions = []) {
        $timestamp = $specificDate ? strtotime($specificDate) : time();
        $substitutions = [
            'DATE_PLACEHOLDER' => date($dateFormat, $timestamp),
            'YESTERDAY' => date($dateFormat, $timestamp - 86400),
            'TOMORROW' => date($dateFormat, $timestamp + 86400),
            'CURRENT_YEAR' => date('Y', $timestamp)
        ];
        
        return array_merge($substitutions, $additionalSubstitutions);
    }

    /**
     * Get substitution patterns for content/body modifications
     * @param string $bodyContent New body content (default: 'Test message body')
     * @param string $signature Message signature (default: 'Test User')
     * @param array $additionalSubstitutions Additional custom substitutions
     * @return array Substitution patterns array
     */
    protected function getContentSubstitutions($bodyContent = 'Test message body', $signature = 'Test User', $additionalSubstitutions = []) {
        $substitutions = [
            'Test test' => $bodyContent,
            'SIGNATURE_PLACEHOLDER' => $signature,
            'BODY_PLACEHOLDER' => $bodyContent
        ];
        
        return array_merge($substitutions, $additionalSubstitutions);
    }

    /**
     * Get substitution patterns for technical/routing modifications
     * @param string $messageId Custom message ID (default: auto-generated)
     * @param string $ipAddress IP address (default: '1.2.3.4')
     * @param string $userAgent User agent (default: 'Test-Agent/1.0')
     * @param array $additionalSubstitutions Additional custom substitutions
     * @return array Substitution patterns array
     */
    protected function getTechnicalSubstitutions($messageId = NULL, $ipAddress = '1.2.3.4', $userAgent = 'Test-Agent/1.0', $additionalSubstitutions = []) {
        if ($messageId === NULL) {
            $messageId = 'test-' . time() . '@test.com';
        }
        
        $substitutions = [
            'MESSAGE_ID_PLACEHOLDER' => $messageId,
            'IP_ADDRESS_PLACEHOLDER' => $ipAddress,
            'USER_AGENT_PLACEHOLDER' => $userAgent,
            'ROUTING_PLACEHOLDER' => 'test-routing-header'
        ];
        
        return array_merge($substitutions, $additionalSubstitutions);
    }

    /**
     * Create a message with comprehensive substitution patterns
     * @param string $subject Message subject
     * @param array $substitutionSets Array of substitution pattern methods to apply
     * @param string $sourceFile Source message file (default: 'basic')
     * @param string $groupname Group name (default: 'testgroup')
     * @param string $fromEmail From email (default: 'from@test.com')
     * @param string $toEmail To email (default: 'to@test.com')
     * @param int|null $groupid Group ID for membership
     * @param int|null $userid User ID for membership
     * @return array [MailRouter, message_id, failok, routing_result]
     */
    protected function createMessageWithPatterns($subject = 'Test message', $substitutionSets = ['getOfferSubstitutions'], $sourceFile = 'basic', $groupname = 'testgroup', $fromEmail = 'from@test.com', $toEmail = 'to@test.com', $groupid = NULL, $userid = NULL) {
        $allSubstitutions = ['Basic test' => $subject];
        
        foreach ($substitutionSets as $methodName) {
            if (method_exists($this, $methodName)) {
                $patternSubstitutions = $this->$methodName();
                $allSubstitutions = array_merge($allSubstitutions, $patternSubstitutions);
            }
        }
        
        return $this->createTestMessage($sourceFile, $groupname, $fromEmail, $toEmail, $groupid, $userid, $allSubstitutions);
    }
}

