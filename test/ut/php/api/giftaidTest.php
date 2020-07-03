<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';

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
        # Logged out - error
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(1, $ret['ret']);

        # Create user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

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
            'homeaddress' => 'Somewhere'
        ]);

        assertEquals(0, $ret['ret']);

        # Get it back.
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals('Test User', $ret['giftaid']['fullname']);

        # List without permission - will return ours.
        $ret = $this->call('giftaid', 'GET', [
            'all' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals('Test User', $ret['giftaid']['fullname']);

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
        assertNotNull(presdef('reviewed', $ret['giftaid'], NULL));

        # Delete it
        $ret = $this->call('giftaid', 'DELETE', []);
        assertEquals(0, $ret['ret']);

        # Should be absent
        $ret = $this->call('giftaid', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertFalse(array_key_exists('giftaid', $ret));
    }
}
