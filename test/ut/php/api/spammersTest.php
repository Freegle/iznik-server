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
class spammersAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM spam_users WHERE reason LIKE 'Test reason%';");
        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE email IN ('test@test.com', 'test2@test.com', 'test3@test.com', 'test4@test.com');");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_FREEGLE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        $this->user->addEmail('test2@test.com');
        $this->assertEquals(1, $this->user->addMembership($this->groupid));
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
    }

    public function testBasic() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($u->inventEmail());
        $this->assertGreaterThan(0, $u->addEmail('test3@test.com'));
        $this->assertGreaterThan(0, $u->addEmail('test4@test.com'));

        # Add them to a group, so that when they get onto a list we can trigger their removal.
        $this->assertTrue($u->addMembership($this->groupid));

        # And create a message from them, so that gets removed too.
        $this->user->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();
        $this->log("Created message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $this->dbhm->preExec("UPDATE messages SET fromuser = ? WHERE id = ?;", [ $uid, $id ]);
        $this->dbhm->preExec("UPDATE messages_groups SET groupid = ? WHERE msgid = ?;", [ $this->groupid, $id ]);

        $ret = $this->call('spammers', 'GET', [
            'search' => 'Test User'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Things we can't do when not logged in
        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_SPAMMER,
            'reason' => 'Test reason'
        ]);
        $this->assertEquals(1, $ret['ret']);

        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_PENDING_REMOVE,
            'reason' => 'Test reason',
            'dup' => 1
        ]);
        $this->assertEquals(1, $ret['ret']);

        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_PENDING_ADD,
            'reason' => 'Test reason',
            'dup' => 2
        ]);
        $this->assertEquals(1, $ret['ret']);

        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_WHITELIST,
            'reason' => 'Test reason',
            'dup' => 3
        ]);
        $this->assertEquals(1, $ret['ret']);

        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => 'wibble',
            'reason' => 'Test reason'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Anyone logged in can report
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_PENDING_ADD,
            'reason' => 'Test reason',
            'dup' => 4
        ]);

        $this->assertEquals(0, $ret['ret']);
        $sid = $ret['id'];
        $this->assertNotNull($sid);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_SPAMMER,
            'search' => 'Test User'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['spammers']));

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_PENDING_ADD,
        ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        # Check shows in work if we have perms.
        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        $this->log("Work " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertGreaterThanOrEqual(0, $ret['work']['spammerpendingadd']);

        $this->user->setPrivate('permissions', User::PERM_SPAM_ADMIN);
        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        $this->log("Work " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertGreaterThanOrEqual(1, $ret['work']['spammerpendingadd']);
        $this->user->setPrivate('permissions', NULL);

        $ret = $this-> call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_WHITELIST,
            'reason' => 'Test reason',
            'dup' => 66
        ]);

        $this->assertEquals(2, $ret['ret']);

        # Look at the pending queue
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $_SESSION['supportAllowed'] = TRUE;

        # Hold and release.
        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_PENDING_ADD,
            'reason' => 'Test reason',
            'dup' => 5,
            'heldby' => $this->user->getId()
        ]);

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_PENDING_ADD,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['user']['heldby']['id'] == $this->user->getId()) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_PENDING_ADD,
            'reason' => 'Test reason',
            'dup' => 6
        ]);

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_PENDING_ADD,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $found = TRUE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && array_key_exists('heldby', $spammer['user'])) {
                $found = FALSE;
            }
        }

        $this->assertTrue($found);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_SPAMMER,
            'reason' => 'Test reason',
            'dup' => 7,
        ]);

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_SPAMMER,
            'search' => 'Test User'
        ]);
        $this->log("Should be on list ". var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['spammers']));

        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_PENDING_ADD,
            'search' => 'Test User'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertFalse($found);

        # If we fetch that user, should be flagged as a spammer.
        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue($ret['user']['spammer']);

        # Trigger removal
        $membs = $u->getMemberships();
        $this->log("Memberships " . var_export($membs, TRUE));
        $this->assertEquals(User::ROLE_MEMBER, $membs[0]['role']);
        $s = new Spam($this->dbhr, $this->dbhm);
        $this->assertEquals(2, $s->removeSpamMembers($this->groupid));

        # Request removal
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_PENDING_REMOVE,
            'reason' => 'Test reason',
            'dup' => 6
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_PENDING_REMOVE,
            'search' => 'Test User'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_WHITELIST,
            'reason' => 'Test reason',
            'dup' => 7
        ]);

        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('spammers', 'DELETE', [
            'id' => $sid
        ]);

        $this->assertEquals(2, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_WHITELIST,
            'reason' => 'Test reason',
            'dup' => 77
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_PENDING_ADD,
            'reason' => 'Test reason',
            'dup' => 81
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_PENDING_REMOVE,
            'reason' => 'Test reason',
            'dup' => 81
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('spammers', 'PATCH', [
            'id' => $sid,
            'collection' => Spam::TYPE_SPAMMER,
            'reason' => 'Test reason',
            'dup' => 81
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);

        $ret = $this->call('spammers', 'DELETE', [
            'id' => $sid
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_SPAMMER,
            'search' => 'Test User'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertFalse($found);

        # Report directly to whitelist
        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_WHITELIST,
            'reason' => 'Test reason',
            'dup' => 82
        ]);

        $this->assertEquals(0, $ret['ret']);
        $sid = $ret['id'];

        # Get the whitelist to check we can.
        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_WHITELIST
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Search for this user.
        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_WHITELIST,
            'search' => 'Test User'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        # Try reporting as a pending spammer - should fail as on whitelist, leaving them still on the whitelist.
        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid,
            'collection' => Spam::TYPE_PENDING_ADD,
            'reason' => 'Test reason',
            'dup' => 83
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_WHITELIST,
            'search' => 'Test User'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['spammers'] as $spammer) {
            if ($spammer['id'] == $sid && $spammer['userid'] == $uid) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        $ret = $this->call('spammers', 'DELETE', [
            'id' => $sid
        ]);

        $this->assertEquals(0, $ret['ret']);
    }

    public function testExport() {
        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`) VALUES ('UT', ?);", [$key]);
        $this->assertNotNull($id);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $u->addEmail($email);

        $this->dbhm->preExec("INSERT INTO spam_users (userid, collection, reason) VALUES (?, ?, ?);", [
            $uid,
            Spam::TYPE_SPAMMER,
            'UT Test'
        ]);
        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_SPAMMER,
            'partner' => $key,
            'action' => 'export'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertGreaterThan(0, count($ret['spammers']));

        $this->dbhm->preExec("DELETE FROM partners_keys WHERE partner = 'UT';");
    }

    public function testPerf() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('spammers', 'GET', [
            'collection' => Spam::TYPE_PENDING_ADD,
            'modtools' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testReportOwnDomain() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addEmail('test3@' . GROUP_DOMAIN));

        # Log in and report.
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('spammers', 'POST', [
            'userid' => $uid1,
            'collection' => Spam::TYPE_PENDING_ADD,
            'reason' => 'Test reason',
        ]);

        $this->assertEquals(0, $ret['ret']);
        $sid = $ret['id'];
        $this->assertNull($sid);
    }

    public function testSpammerStartsChat() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($u->inventEmail());
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $s = new Spam($this->dbhr, $this->dbhm);
        $s->addSpammer($uid, Spam::TYPE_SPAMMER, 'Test reason');

        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u2->create(NULL, NULL, 'Test User');

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($uid, $uid2);
        self::assertTrue($blocked);
    }

    public function testSpammerSendsChat() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($u->inventEmail());
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u2->create(NULL, NULL, 'Test User');

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($uid, $uid2);
        self::assertFalse($blocked);

        $s = new Spam($this->dbhr, $this->dbhm);
        $s->addSpammer($uid, Spam::TYPE_SPAMMER, 'Test reason');

        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $cm->create($rid, $uid,"Test from spammer");
        self::assertTrue($banned);
    }
}

