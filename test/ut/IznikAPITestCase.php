<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once(UT_DIR . '/../../include/config.php');
require_once(IZNIK_BASE . '/include/session/Session.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
abstract class IznikAPITestCase extends IznikTestCase {
    public $dbhr, $dbhm;

    private $lastOutput = NULL;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhr->errorLog = FALSE;
        $this->dbhm->errorLog = FALSE;

        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';
        $_SESSION['id'] = NULL;

        # Initialize lastOutput to capture any output from setUp (like the test marker)
        $this->lastOutput = $this->getActualOutput();

        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com', 'tes2t@test.com', 'sender@example.net');");
        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.backwards LIKE 'oielohkcalb@%';");
        $dbhm->preExec("DELETE users, users_logins FROM users INNER JOIN users_logins ON users.id = users_logins.userid WHERE uid IN ('testid', '1234');");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort LIKE 'testgroup%';");
    }

    public function call($call, $type, $params, $decode = TRUE) {
        $_REQUEST = array_merge($params);

        $_SERVER['REQUEST_METHOD'] = $type;
        $_SERVER['REQUEST_URI'] = "/api/$call.php";
        $_REQUEST['call'] = $call;

        if (!array_key_exists('modtools', $_REQUEST)) {
            // Assume TRUE for UT unless otherwise specified.
            $_REQUEST['modtools'] = TRUE;
        }

        # API calls have to run from the api directory, as they would from the web server.
        chdir(IZNIK_BASE . '/http/api');
        API::call();

        # Get the output since we last did this.
        $op = $this->getActualOutput();

        if ($this->lastOutput) {
            $len = strlen($this->lastOutput);
            $this->lastOutput = $op;
            $op = substr($op, $len);
        } else {
            $this->lastOutput = $op;
        }

        if ($decode) {
            $ret = json_decode($op, TRUE);
        } else {
            $ret = $op;
        }

        return($ret);
    }
}

