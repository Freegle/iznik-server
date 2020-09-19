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
class yahooTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testException() {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';
        $y = Yahoo::getInstance($this->dbhr, $this->dbhm);
        $rc  = $y->login();
        assertEquals(1, $rc[1]['ret']);
        assertTrue(array_key_exists('redirect', $rc[1]));

        $email = 'test' . microtime() . '@test.com';
        $mock = $this->getMockBuilder('LightOpenID')
            ->disableOriginalConstructor()
            ->setMethods(array('validate', 'getAttributes'))
            ->getMock();
        $mock->method('validate')->willReturn(true);
        $mock->method('getAttributes')->willThrowException(new \Exception());
        $y->setOpenid($mock);

        # Login first time - should work
        list($session, $ret) = $y->login();
        assertNull($session);

        }

    public function testBasic() {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';

        # Check singleton
        $y = Yahoo::getInstance($this->dbhr, $this->dbhm);
        assertEquals($y, Yahoo::getInstance($this->dbhr, $this->dbhm));

        $rc  = $y->login();
        assertEquals(1, $rc[1]['ret']);
        assertTrue(array_key_exists('redirect', $rc[1]));

        $email = 'test' . microtime() . '@test.com';
        $mock = $this->getMockBuilder('LightOpenID')
            ->disableOriginalConstructor()
            ->setMethods(array('validate', 'getAttributes'))
            ->getMock();
        $mock->method('validate')->willReturn(true);
        $mock->method('getAttributes')->willReturn([
            'contact/email' => $email,
            'namePerson' => 'Test User'
        ]);
        $y->setOpenid($mock);

        # Login first time - should work
        list($session, $ret) = $y->login();
        $id = $session->getUserId();
        assertNotNull($session);
        assertEquals(0, $ret['ret']);

        # Login again - should also work
        $this->dbhm->preExec("UPDATE users SET fullname = NULL WHERE id = $id;");
        User::clearCache($id);
        list($session, $ret) = $y->login();
        assertNotNull($session);
        assertEquals(0, $ret['ret']);

        # Create another user and move the email over to simulate a duplicate
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Users $id and $uid");
        $rc = $this->dbhm->preExec("UPDATE users_emails SET userid = $uid WHERE userid = $id;");
        assertEquals(1, $rc);
        list($session, $ret) = $y->login();
        assertNotNull($session);
        assertEquals(0, $ret['ret']);
    }

    public function testYahooLoginWithCode() {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->setPrivate('publishconsent', 1);
        $u->addEmail('test@test.com');

        $u->addEmail('test@test.com');
        $y = Yahoo::getInstance($this->dbhr, $this->dbhm);
        list ($session, $ret) = $y->loginWithCode('invalid',
          json_encode([ 'access_token' => '1234' ]),
          '{"guid":{"value":"1234","uri":"https://social.yahooapis.com/v1/me/guid"}}',
          '{"sub":"1234","name":"Test User","given_name":"Test","family_name":"User","locale":"en-GB","email":"test@test.com"}'
        );

        assertEquals(0, $ret['ret']);
    }
}

