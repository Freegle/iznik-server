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
class donationsTest extends IznikTestCase {
    private $dbhr, $dbhm;
    private $msgsSent = [];

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE firstname = 'Test' AND lastname = 'User';");
        $dbhm->preExec("DELETE FROM users_donations WHERE TransactionID LIKE 'UT%' OR Payer LIKE '%test.com';");
        $dbhm->preExec("DELETE FROM giftaid;");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort LIKE 'testbirthday%';");
        
        $this->msgsSent = [];
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
        return true;
    }

    public function testRecord() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $d = new Donations($this->dbhr, $this->dbhm);
        $d->recordAsk($id);
        self::assertNotNull($d->lastAsk($id));
    }

    public function testSince() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $this->log("Created $id");

        # Add three donations - one before, one on, and one after the consent date.
        $d = new Donations($this->dbhr, $this->dbhm);
        $mysqltime = date("Y-m-d H:i:s", strtotime('yesterday'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 1', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        # Test the donations show up for Support Tools.
        $mod = new User($this->dbhr, $this->dbhm);
        $mod->create('Test', 'User', NULL);
        $mod->setPrivate('systemrole', User::ROLE_MODERATOR);
        $mod->setPrivate('permissions', User::PERM_GIFTAID);
        $this->assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($mod->login('testpw'));

        $ctx = NULL;
        $searches = $u->search($id , $ctx);
        $this->assertEquals(1, count($searches));
        $this->assertEquals(3, count($searches[0]['donations']));

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_SINCE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        $_SESSION['id'] = $id;
        $this->assertTrue($u->getPublic()['donor']);

        # All three have consent
        $this->assertEquals(3, $d->identifyGiftAidedDonations($gid));

        $d->delete($did);
    }

    public function testPast4YearsAndFuture() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $this->log("Created $id");

        # Add three donations - one too old, one past, one future
        $d = new Donations($this->dbhr, $this->dbhm);
        $mysqltime = date("Y-m-d H:i:s", strtotime('five years ago'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 1', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        # Test the donations show up for Support Tools.
        $mod = new User($this->dbhr, $this->dbhm);
        $mod->create('Test', 'User', NULL);
        $mod->setPrivate('systemrole', User::ROLE_MODERATOR);
        $mod->setPrivate('permissions', User::PERM_GIFTAID);
        $this->assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($mod->login('testpw'));

        $ctx = NULL;
        $searches = $u->search($id , $ctx);
        $this->assertEquals(1, count($searches));
        $this->assertEquals(3, count($searches[0]['donations']));

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_PAST_4_YEARS_AND_FUTURE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        $_SESSION['id'] = $id;
        $this->assertTrue($u->getPublic()['donor']);

        # All three have consent
        $this->assertEquals(2, $d->identifyGiftAidedDonations($gid));

        $d->delete($did);
    }

    public function testThis() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $this->log("Created $id");

        # Add three donations - one before, one on, and one after the consent date.
        $d = new Donations($this->dbhr, $this->dbhm);
        $d = new Donations($this->dbhr, $this->dbhm);
        $mysqltime = date("Y-m-d H:i:s", strtotime('yesterday'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 1', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_THIS, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        $this->assertEquals(1, $d->identifyGiftAidedDonations($gid));

        $d->delete($did);
    }

    public function testFuture() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $this->log("Created $id");

        # Add three donations - one before, one on, and one after the consent date.
        $d = new Donations($this->dbhr, $this->dbhm);
        $mysqltime = date("Y-m-d H:i:s", strtotime('yesterday'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 1', 0, Donations::TYPE_PAYPAL, 'subscr_payment');
        $this->assertNotNull($did);

        # Should be flagged as a supporter.
        $this->assertTrue($u->getPublic()['supporter']);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        $this->assertNotNull($did);

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_FUTURE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        $this->assertEquals(2, $d->identifyGiftAidedDonations($gid));

        # Test own donation info.
        $_SESSION['id'] = $id;
        $atts = $u->getPublic();
        $this->assertTrue($atts['donor']);
        $this->assertTrue($atts['donorrecurring']);

        $d->delete($did);
    }

    public function testBirthdayEmails() {
        # Create two test groups both founded 1 year ago on today's date
        $oneYearAgo = date('Y-m-d', strtotime('-1 year'));
        
        $g1 = Group::get($this->dbhr, $this->dbhm);
        $gid1 = $g1->create("testbirthday1", Group::GROUP_FREEGLE);
        $g1->setPrivate('founded', $oneYearAgo);
        $g1->setPrivate('publish', 1);
        $g1->setPrivate('onmap', 1);
        
        $g2 = Group::get($this->dbhr, $this->dbhm);
        $gid2 = $g2->create("testbirthday2", Group::GROUP_FREEGLE);
        $g2->setPrivate('founded', $oneYearAgo);
        $g2->setPrivate('publish', 1);
        $g2->setPrivate('onmap', 1);
        
        # Create a test user who is member of both groups
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@birthday.com');
        $u->setPrivate('marketingconsent', 1);
        
        # Add user to both groups
        $u->addMembership($gid1, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        $u->addMembership($gid2, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        
        # Create a mock Donations class that intercepts the mailer->send() call
        $mock = $this->getMockBuilder('Freegle\Iznik\Donations')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['sendBirthdayEmailToUser'])
            ->getMock();
            
        # Override the method that sends emails to capture them
        $emailsSent = [];
        $mock->method('sendBirthdayEmailToUser')->will($this->returnCallback(function($user, $group, $volunteers, $html) use (&$emailsSent) {
            $emailsSent[] = [
                'user' => $user['id'],
                'group' => $group['nameshort'],
                'email' => $user['email'] ?? 'test@birthday.com'
            ];
            
            # Simulate recording the lastbirthdayappeal setting
            $u = User::get($this->dbhr, $this->dbhm, $user['id']);
            $settings = $u->getPrivate('settings');
            $settings = $settings ? json_decode($settings, TRUE) : [];
            $settings['lastbirthdayappeal'] = date('Y-m-d H:i:s');
            $u->setPrivate('settings', json_encode($settings));
            
            return true;
        }));
        
        $d = new Donations($this->dbhr, $this->dbhm);
        
        # Test that user settings are properly updated to prevent duplicate emails
        # First, clear any existing lastbirthdayappeal setting
        $settings = $u->getPrivate('settings');
        $settings = $settings ? json_decode($settings, TRUE) : [];
        unset($settings['lastbirthdayappeal']);
        $u->setPrivate('settings', json_encode($settings));
        
        # Test sending for only the first group
        $count1 = $d->sendBirthdayEmails(null, [$gid1]);
        $this->assertEquals(1, $count1, "Should send 1 email for first group");
        
        # Check that lastbirthdayappeal was recorded
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $settings = $u->getPrivate('settings');
        $settings = $settings ? json_decode($settings, TRUE) : [];
        $this->assertArrayHasKey('lastbirthdayappeal', $settings, "Should record lastbirthdayappeal");
        $this->assertNotNull($settings['lastbirthdayappeal'], "lastbirthdayappeal should have a value");
        
        # Try to send for the second group immediately - should send 0 emails due to 31-day limit
        $count2 = $d->sendBirthdayEmails(null, [$gid2]);
        $this->assertEquals(0, $count2, "Should not send emails within 31 days even for different group");
        
        # Test sending for both groups - should still send 0 due to 31-day limit
        $count3 = $d->sendBirthdayEmails(null, [$gid1, $gid2]);
        $this->assertEquals(0, $count3, "Should not send emails within 31 days for any groups");
        
        # Test that if we manually set the last appeal to 32 days ago, it will send again
        $settings['lastbirthdayappeal'] = date('Y-m-d H:i:s', strtotime('-32 days'));
        $u->setPrivate('settings', json_encode($settings));
        
        # Now should send for the second group
        $count4 = $d->sendBirthdayEmails(null, [$gid2]);
        $this->assertEquals(1, $count4, "Should send emails after 31 days have passed");
        
        # Clean up
        $this->dbhm->preExec("DELETE FROM `groups` WHERE id IN (?, ?);", [$gid1, $gid2]);
        $this->dbhm->preExec("DELETE FROM users WHERE id = ?;", [$uid]);
    }
}

