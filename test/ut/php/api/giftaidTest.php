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
class giftaidAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic()
    {
        # Get a valid postcode.
        $pafadds = $this->dbhr->preQuery("SELECT id FROM paf_addresses WHERE postcodeid = ? LIMIT 1;", [
            1687412
        ]);
        self::assertEquals(1, count($pafadds));
        $pafid = $pafadds[0]['id'];

        # Logged out - error
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(1, $ret['ret']);

        # Create user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Give them an address we will recognise the postcode for
        $a = new Address($this->dbhr, $this->dbhm);
        $aid = $a->create($uid, $pafid);
        $a = new Address($this->dbhr, $this->dbhm, $aid);
        $pc = $a->getPublic()['postcode']['name'];

        # No consent yet
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertFalse(array_key_exists('giftaid', $ret));

        # Add it with missing parameters
        $ret = $this->call('giftaid', 'POST', [
            'period' => Donations::PERIOD_THIS
        ]);

        assertEquals(2, $ret['ret']);

        # Add it with valid parameters
        $ret = $this->call('giftaid', 'POST', [
            'period' => Donations::PERIOD_THIS,
            'fullname' => 'Test User',
            'homeaddress' => "Somewhere $pc"
        ]);

        assertEquals(0, $ret['ret']);
        $gid = $ret['id'];
        assertNotNull($gid);

        # Get it back.
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals('Test User', $ret['giftaid']['fullname']);

        # Set up the postcode.
        $d = new Donations($this->dbhr, $this->dbhm);
        assertEquals(1, $d->identifyGiftAidPostcode($gid));

        # List without permission - will return ours.
        $ret = $this->call('giftaid', 'GET', [
            'all' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals('Test User', $ret['giftaid']['fullname']);

        assertEquals($pc, $ret['giftaid']['postcode']);

        $u->setPrivate('permissions', User::PERM_GIFTAID);
        $ret = $this->call('giftaid', 'GET', [
            'all' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        $found = NULL;
        foreach ($ret['giftaids'] as $giftaid) {
            if ($giftaid['userid'] == $uid) {
                $found = $giftaid['id'];
            }
        }

        assertNotNull($found);

        # Edit it
        $ret = $this->call('giftaid', 'PATCH', [
            'id' => $found,
            'period' => 'This',
            'fullname' => 'Real Name',
            'homeaddress' => 'Somewhere',
            'reviewed' => TRUE
        ]);

        # Check it's changed.
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals('Real Name', $ret['giftaid']['fullname']);
        assertNotNull(Utils::presdef('reviewed', $ret['giftaid'], NULL));

        # Delete it
        $ret = $this->call('giftaid', 'DELETE', []);
        assertEquals(0, $ret['ret']);

        # Should be absent
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertFalse(array_key_exists('giftaid', $ret));
    }

    public function testDelete()
    {
        # Get a valid postcode.
        $pafadds = $this->dbhr->preQuery("SELECT id FROM paf_addresses WHERE postcodeid = ? LIMIT 1;", [
            1687412
        ]);
        self::assertEquals(1, count($pafadds));
        $pafid = $pafadds[0]['id'];

        # Create user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Give them an address we will recognise the postcode for
        $a = new Address($this->dbhr, $this->dbhm);
        $aid = $a->create($uid, $pafid);
        $a = new Address($this->dbhr, $this->dbhm, $aid);
        $pc = $a->getPublic()['postcode']['name'];

        # Add it with valid parameters
        $ret = $this->call('giftaid', 'POST', [
            'period' => Donations::PERIOD_THIS,
            'fullname' => 'Test User',
            'homeaddress' => "Somewhere $pc"
        ]);

        assertEquals(0, $ret['ret']);
        $gid = $ret['id'];
        assertNotNull($gid);

        # Set up the postcode.
        $d = new Donations($this->dbhr, $this->dbhm);
        assertEquals(1, $d->identifyGiftAidPostcode($gid));

        # Delete it.
        $u->setPrivate('permissions', User::PERM_GIFTAID);

        $ret = $this->call('giftaid', 'GET', [
            'all' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        $found = NULL;
        foreach ($ret['giftaids'] as $giftaid) {
            if ($giftaid['userid'] == $uid) {
                $ret = $this->call('giftaid', 'PATCH', [
                    'id' => $giftaid['id'],
                    'deleted' => TRUE
                ]);

                $found = $giftaid;
            }
        }

        assertNotNull($found);

        # Should be absent from list.
        $ret = $this->call('giftaid', 'GET', [
            'all' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        $found = NULL;

        foreach ($ret['giftaids'] as $giftaid) {
            if ($giftaid['userid'] == $uid) {
                $found = $giftaid;
            }
        }

        assertNull($found);
    }

    public function testPostcode()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Add address.  EH3 6SS is set up in testenv.
        $ret = $this->call('giftaid', 'POST', [
            'period' => Donations::PERIOD_THIS,
            'fullname' => 'Test User',
            'homeaddress' => "Somewhere EH36SS"
        ]);

        assertEquals(0, $ret['ret']);
        $gid = $ret['id'];
        assertNotNull($gid);

        # Set up the postcode.
        $d = new Donations($this->dbhr, $this->dbhm);
        assertEquals(1, $d->identifyGiftAidPostcode($gid));

        # List without permission - will return ours.
        $ret = $this->call('giftaid', 'GET', [
            'all' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals('Test User', $ret['giftaid']['fullname']);
        assertEquals("EH3 6SS", $ret['giftaid']['postcode']);
    }

    public function testHouse()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Add address.  EH3 6SS is set up in testenv.
        $ret = $this->call('giftaid', 'POST', [
            'period' => Donations::PERIOD_THIS,
            'fullname' => 'Test User',
            'homeaddress' => "13-14a Somewhere EH36SS"
        ]);

        assertEquals(0, $ret['ret']);
        $gid = $ret['id'];
        assertNotNull($gid);

        # Set up the postcode.
        $d = new Donations($this->dbhr, $this->dbhm);
        assertEquals(1, $d->identifyGiftAidHouse($gid));

        # List without permission - will return ours.
        $ret = $this->call('giftaid', 'GET', [
            'all' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals('Test User', $ret['giftaid']['fullname']);
        assertEquals("13-14a", $ret['giftaid']['housenameornumber']);
    }
}
