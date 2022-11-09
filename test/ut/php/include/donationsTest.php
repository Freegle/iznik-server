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

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE firstname = 'Test' AND lastname = 'User';");
        $dbhm->preExec("DELETE FROM users_donations WHERE TransactionID LIKE 'UT%';");
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
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        # Test the donations show up for Support Tools.
        $mod = new User($this->dbhr, $this->dbhm);
        $mod->create('Test', 'User', NULL);
        $mod->setPrivate('systemrole', User::ROLE_MODERATOR);
        $mod->setPrivate('permissions', User::PERM_GIFTAID);
        assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($mod->login('testpw'));

        $ctx = NULL;
        $searches = $u->search($id , $ctx);
        assertEquals(1, count($searches));
        assertEquals(3, count($searches[0]['donations']));

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_SINCE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        $_SESSION['id'] = $id;
        assertTrue($u->getPublic()['donor']);

        # All three have consent
        assertEquals(3, $d->identifyGiftAidedDonations($gid));

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
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        # Test the donations show up for Support Tools.
        $mod = new User($this->dbhr, $this->dbhm);
        $mod->create('Test', 'User', NULL);
        $mod->setPrivate('systemrole', User::ROLE_MODERATOR);
        $mod->setPrivate('permissions', User::PERM_GIFTAID);
        assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($mod->login('testpw'));

        $ctx = NULL;
        $searches = $u->search($id , $ctx);
        assertEquals(1, count($searches));
        assertEquals(3, count($searches[0]['donations']));

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_PAST_4_YEARS_AND_FUTURE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        $_SESSION['id'] = $id;
        assertTrue($u->getPublic()['donor']);

        # All three have consent
        assertEquals(2, $d->identifyGiftAidedDonations($gid));

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
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_THIS, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        assertEquals(1, $d->identifyGiftAidedDonations($gid));

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
        assertNotNull($did);

        # Should be flagged as a supporter.
        assertTrue($u->getPublic()['supporter']);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0, Donations::TYPE_PAYPAL);
        assertNotNull($did);

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_FUTURE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        assertEquals(2, $d->identifyGiftAidedDonations($gid));

        # Test own donation info.
        $_SESSION['id'] = $id;
        $atts = $u->getPublic();
        assertTrue($atts['donor']);
        assertTrue($atts['donorrecurring']);

        $d->delete($did);
    }
}

