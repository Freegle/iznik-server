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
class MailTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM returnpath_seedlist WHERE email LIKE 'test@test.com';");
    }

    public function testBasic() {
        list($user, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->dbhm->preExec("INSERT INTO `returnpath_seedlist` (`id`, `timestamp`, `email`, `userid`, `type`, `active`, `oneshot`) VALUES (NULL, CURRENT_TIMESTAMP, 'test@test.com', $uid, 'ReturnPath', '1', '1')");
        $seeds = Mail::getSeeds($this->dbhr, $this->dbhm);
        $found = FALSE;

        foreach ($seeds as $seed) {
            if (strcmp($seed['email'], 'test@test.com') === 0) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);
    }
}

