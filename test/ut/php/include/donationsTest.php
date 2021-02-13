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

    protected function setUp() {
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
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 1', 0);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0);
        assertNotNull($did);

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_SINCE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        # All three have consent
        assertEquals(3, $d->identifyGiftAidedDonations($gid));

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
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 1', 0);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0);
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
        $d = new Donations($this->dbhr, $this->dbhm);
        $mysqltime = date("Y-m-d H:i:s", strtotime('yesterday'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 1', 0);
        assertNotNull($did);

        # Should be flagged as a supporter.
        assertTrue($u->getPublic()['supporter']);

        $mysqltime = date("Y-m-d H:i:s", time());
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 2', 0);
        assertNotNull($did);

        $mysqltime = date("Y-m-d H:i:s", strtotime('tomorrow'));
        $did = $d->add($id, 'test@test.com', 'Test User', $mysqltime, 'UT 3', 0);
        assertNotNull($did);

        # Add consent.
        $gid = $d->setGiftAid($id, Donations::PERIOD_FUTURE, 'Test User', 'Nowheresville');
        $d->editGiftAid($gid, NULL, NULL, NULL, NULL, NULL, TRUE, FALSE);

        assertEquals(2, $d->identifyGiftAidedDonations($gid));

        $d->delete($did);
    }
}

