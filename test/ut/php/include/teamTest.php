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
class teamTest extends IznikTestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid2 = $u->create('Test', 'User', NULL);
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
    }

    public function testVolunteers() {
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_FREEGLE);

        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->user->setSetting('showmod', TRUE);
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->user2->setSetting('showmod', TRUE);

        $t = new Team($this->dbhr, $this->dbhm);
        $vols = $t->getVolunteers();

        $found = FALSE;

        foreach ($vols as $vol) {
            if ($vol['userid'] == $this->uid) {
                $found = TRUE;
            }
        }

        assertTrue($found);
    }
}

