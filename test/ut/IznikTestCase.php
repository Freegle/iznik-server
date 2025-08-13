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
     * Create a test group with standard settings
     */
    protected function createTestGroup($name, $type) {
        $g = Group::get($this->dbhr, $this->dbhm);
        $this->assertNotNull($g, "Failed to get Group instance");
        
        $groupid = $g->create($name, $type);
        $this->assertGreaterThan(0, $groupid, "Failed to create group '$name'");
        
        // Verify the group was created correctly
        $createdGroup = Group::get($this->dbhr, $this->dbhm, $groupid);
        $this->assertNotNull($createdGroup, "Failed to retrieve created group");
        $this->assertEquals($name, $createdGroup->getPrivate('nameshort'), "Group name mismatch");
        $this->assertEquals($type, $createdGroup->getPrivate('type'), "Group type mismatch");
        
        return [$g, $groupid];
    }

    /**
     * Create a test user with email and login - supports both original patterns:
     * - createTestUser('Test', 'User', NULL, 'email', 'pass') for firstname/lastname
     * - createTestUser(NULL, NULL, 'Test User', 'email', 'pass') for fullname only
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
        
        // Add email - match original behavior exactly
        $emailid = $user->addEmail($email);
        // Don't assert on email addition - let the calling test handle the result as needed
        // The original tests had different expectations for email addition success
        
        $loginid = $user->addLogin(User::LOGIN_NATIVE, NULL, $password);
        $this->assertGreaterThan(0, $loginid, "Failed to add login for user");
        
        return [$user, $uid, $emailid];
    }

    /**
     * Create a test message using MailRouter
     * @param string|null $content Message content (uses basic test message if null)
     * @param string $groupname Group name to replace in message
     * @param string $fromEmail From email address
     * @param string $toEmail To email address  
     * @param int|null $groupid Group ID for user membership (needed for approval)
     * @param int|null $userid User ID to set up membership for (creates user if null)
     * @param bool $expectSuccess Whether message creation should succeed (default: TRUE)
     * @param bool $expectFailok Whether MailRouter should return failok=TRUE (default: TRUE)
     */
    protected function createTestMessage($content, $groupname, $fromEmail, $toEmail, $groupid, $userid, $expectSuccess = TRUE, $expectFailok = FALSE) {
        if ($content === null) {
            $basicMsgPath = IZNIK_BASE . '/test/ut/php/msgs/basic';
            $this->assertFileExists($basicMsgPath, "Basic message file not found");
            $content = $this->unique(file_get_contents($basicMsgPath));
        }
        $this->assertNotEmpty($content, "Message content is empty");
        
        $content = str_ireplace('freegleplayground', $groupname, $content);
        $content = str_ireplace('Subject: Basic test', 'Subject: OFFER: Test item (Edinburgh EH3)', $content);
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
     * Create a test user, add login credentials, and call login() method
     * @param string|null $firstname User's first name  
     * @param string|null $lastname User's last name
     * @param string|null $fullname User's full name (if not using first/last)
     * @param string|null $email User's email address (optional, defaults to test@test.com)
     * @param string|null $password Login password (optional, defaults to testpw)
     * @return array [User instance, user ID, email ID]
     */
    protected function createTestUserAndLogin($firstname = NULL, $lastname = NULL, $fullname = NULL, $email = NULL, $password = NULL) {
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
        
        // Login the user using the login() method
        $loginResult = $user->login($password);
        $this->assertTrue($loginResult, "Failed to login user with password");
        
        return [$user, $uid, $emailid];
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
}

