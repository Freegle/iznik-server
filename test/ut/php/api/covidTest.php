<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/user/Story.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class covidAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $covids = $this->dbhr->preQuery("SELECT * FROM covid WHERE type = 'NeedHelp' AND info IS NOT NULL LIMIT 1");

        foreach ($covids as $covid) {
            $ret = $this->call('covid', 'GET', [
//                'id' => $covid['userid']
                'id' => 38678104
            ]);

            error_log(var_export($ret, TRUE));
        }
        assertTRue(TRUE);
    }
}

