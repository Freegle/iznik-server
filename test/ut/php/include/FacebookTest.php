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
class FacebookTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function getJavaScriptHelper() {
        return($this);
    }

    public function getAccessToken() {
        return($this);
    }

    public function getOAuth2Client() {
        return($this);
    }

    public function debugToken() {
        return($this);
    }

    public function validateAppId() {
        return(TRUE);
    }

    public function validateExpiration() {
        return(TRUE);
    }

    public function isLongLived() {
        return(FALSE);
    }

    private $getLongLivedAccessTokenException, $getLongLivedAccessFacebookException, $getCanvasHelperException;

    public function getLongLivedAccessToken() {
        $this->log("getLongLivedAccessToken {$this->getLongLivedAccessTokenException}, {$this->getLongLivedAccessFacebookException}");
        if ($this->getLongLivedAccessTokenException) {
            throw new \Exception();
        }

        if ($this->getLongLivedAccessFacebookException) {
            throw new \JanuSoftware\Facebook\Exception\SDKException();
        }

        return($this->accessToken);
    }

    public function getCanvasHelper() {
        $this->log("getCanvasHelper {$this->getCanvasHelperException}");
        if ($this->getCanvasHelperException) {
            throw new \Exception();
        }
        return($this);
    }

    public function get() {
        return($this);
    }

    public function getDecodedBody() {
        if ($this->asArrayException) {
            throw new \Exception();
        }

        return([
            'id' => $this->facebookId,
            'first_name' => $this->facebookFirstName,
            'last_name' => $this->facebookLastName,
            'name' => $this->facebookName,
            'email' => $this->facebookEmail
        ]);
    }

    public function __toString() {
        return($this->accessToken);
    }

    public function testBasic() {
        # Basic successful login
        $mock = $this->getMockBuilder('Freegle\Iznik\Facebook')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(array('getFB'))
            ->getMock();

        $mock->method('getFB')->willReturn($this);
        $this->getLongLivedAccessTokenException = FALSE;
        $this->getLongLivedAccessFacebookException = FALSE;
        $this->asArrayException = FALSE;

        $this->accessToken = '1234';
        $this->facebookId = 1;
        $this->facebookFirstName = 'Test';
        $this->facebookLastName = 'User';
        $this->facebookName = 'Test User';
        $this->facebookEmail = 'test@test.com';

        list($session, $ret) = $mock->login();
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $this->assertEquals('Test User', $me->getPrivate('fullname'));
        $logins = $me->getLogins();
        $this->log("Logins " . var_export($logins, TRUE));
        $this->assertEquals(1, $logins[0]['uid']);

        # Ensure the full name is copied.
        $me->setPrivate('fullname', NULL);
        list($session, $ret) = $mock->login();
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $this->assertEquals('Test User', $me->getPrivate('fullname'));

        # Log in again with a different email, triggering a merge.
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, "Test User2", 'test2@test.com', 'testpw');

        $this->facebookEmail = 'test2@test.com';
        list($session, $ret) = $mock->login();
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        $this->assertEquals(2, count($emails));

        # Now delete an email, and log in again - should trigger an add of the email
        $me->removeEmail('test2@test.com');
        list($session, $ret) = $mock->login();
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        $this->assertEquals(2, count($emails));

        # Now delete the Facebook login, and log in again - should trigger an add of the facebook id.
        $me->removeLogin('Facebook', 1);
        list($session, $ret) = $mock->login();
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        User::clearCache($me->getId());
        $me = User::get($this->dbhr, $this->dbhm, $me->getId());
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        $this->assertEquals(2, count($emails));
        $logins = $me->getLogins();
        $this->log("Logins " . var_export($logins, TRUE));
        $this->assertEquals(1, count($logins));
        $this->assertEquals(1, $logins[0]['uid']);

        # Now a couple of exception cases
        #
        # Getting long-lived access token can fail with a Facebook exception without blocking the login.
        $this->log("getLongLivedAccessToken exception");
        $this->getLongLivedAccessFacebookException = TRUE;
        list($session, $ret) = $mock->login();
        $this->assertEquals(0, $ret['ret']);
        $this->getLongLivedAccessFacebookException = FALSE;

        # But another exception will fail it
        $this->getLongLivedAccessTokenException = TRUE;
        list($session, $ret) = $mock->login();
        $this->assertEquals(2, $ret['ret']);
        $this->getLongLivedAccessTokenException = FALSE;

        # And so will this one.
        $this->asArrayException = TRUE;
        list($session, $ret) = $mock->login();
        $this->assertEquals(1, $ret['ret']);
        $this->asArrayException = FALSE;

        }

    public function testCanvas()
    {
        $this->accessToken = '1234';
        $this->facebookId = 1;
        $this->facebookFirstName = 'Test';
        $this->facebookLastName = 'User';
        $this->facebookName = 'Test User';
        $this->facebookEmail = 'test@test.com';

        $mock = $this->getMockBuilder('Freegle\Iznik\Facebook')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(array('getFB'))
            ->getMock();

        $mock->method('getFB')->willReturn($this);
        $this->getLongLivedAccessTokenException = FALSE;
        $this->getLongLivedAccessFacebookException = FALSE;
        $this->asArrayException = FALSE;

        list($session, $ret) = $mock->loadCanvas();
        self::assertEquals(0, $ret['ret']);

        # Fail login
        $this->log("Fail canvas login");
        $_SESSION['id'] = 0;
        $this->getCanvasHelperException = TRUE;
        list($session, $ret) = $mock->loadCanvas();
        $this->log("loaded canvas");
        $this->assertEquals(2, $ret['ret']);
        $this->asArrayException = FALSE;
    }

    public function testFB() {
        $f = new Facebook($this->dbhr, $this->dbhm);
        $fb = $f->getFB();
        $this->assertNotNull($fb);
    }
}

