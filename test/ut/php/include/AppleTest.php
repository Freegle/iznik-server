<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once(UT_DIR . '/../../include/config.php');
require_once IZNIK_BASE . '/include/session/Apple.php';
require_once IZNIK_BASE . '/include/user/User.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class AppleTest extends IznikTestCase {
    private $dbhr, $dbhm;
    public $people;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function client() {
        return($this);
    }

    public function getEmail() {
        return $this->email;
    }

    public function getUser() {
        return 'UT';
    }

    public function verifyUser() {
        return TRUE;
    }

    public function testBasic() {
        $a = new Apple($this->dbhr, $this->dbhm);
        list($session, $ret) = $a->login("TestUser", []);
        assertEquals(2, $ret['ret']);

        # Basic successful login
        $this->email = 'test@test.com';

        $mock = $this->getMockBuilder('Apple')
            ->setMethods(['getPayload'])
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->getMock();

        $mock->method('getPayload')->willReturn($this);

        $credentials = [
            "authorizationCode" => "UT",
            "email" => "",
            "fullName" => [
                "familyName" => "",
                "givenName" => "",
                "middleName" => "",
                "namePrefix" => "",
                "nameSuffix" => "",
                "nickname" => "",
                "phoneticRepresentation" => []
              ],
            "identityToken" => "UT",
            "state" => "",
            "user" => "UT"
        ];

        error_log("Login");
        list($session, $ret) = $mock->login($credentials['user'], $credentials);
        error_log("Returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        $me = whoAmI($this->dbhr, $this->dbhm);
        $logins = $me->getLogins();
        $this->log("Logins " . var_export($logins, TRUE));
        assertEquals($credentials['user'], $logins[0]['uid']);

        # Log in again with a different email, triggering a merge.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, "Test User2");
        $u->addEmail('test2@test.com');

        $this->email = 'test2@test.com';
        list($session, $ret) = $mock->login($credentials['user'], $credentials);
        assertEquals(0, $ret['ret']);
        $me = whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        assertEquals(2, count($emails));

        # Now delete an email, and log in again - should trigger an add of the email
        $me->removeEmail('test2@test.com');
        list($session, $ret) = $mock->login($credentials['user'], $credentials);
        assertEquals(0, $ret['ret']);
        $me = whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        assertEquals(2, count($emails));

        # Now delete the Apple login, and log in again - should trigger an add of the Appleid.
        assertEquals(1, $me->removeLogin('Apple', 1));
        list($session, $ret) = $mock->login($credentials['user'], $credentials);
        assertEquals(0, $ret['ret']);
        $me = whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        assertEquals(2, count($emails));
        $logins = $me->getLogins();
        $this->log("Logins " . var_export($logins, TRUE));
        assertEquals(1, count($logins));
        assertEquals('UT', $logins[0]['uid']);
    }
}

