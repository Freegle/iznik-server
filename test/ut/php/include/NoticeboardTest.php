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
class NoticeboardTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        parent::setUp();
        $dbhm->preExec("DELETE FROM noticeboards WHERE name LIKE 'UTTest%';");
        $dbhm->preExec("DELETE FROM noticeboards WHERE description LIKE 'Test description';");
    }

    protected function tearDown()
    {
        parent::tearDown();
//        $this->dbhm->preExec("DELETE FROM noticeboards WHERE name LIKE 'UTTest%';");
    }

    public function testChaseupOwner() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');

        $n = new Noticeboard($this->dbhr, $this->dbhm);

        $id = $n->create('UTTest', 52, -1, $id, NULL);

        $n = $this->getMockBuilder('Freegle\Iznik\Noticeboard')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('sendIt'))
            ->getMock();

        $n->method('sendIt')->willReturn(TRUE);

        # Too soon to chase up.
        assertEquals(0, $n->chaseup($id));

        # Make it old.
        $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = DATE_SUB(added, INTERVAL 31 DAY) WHERE id = $id");

        # Chase up owner.
        assertEquals(1, $n->chaseup($id));

        # Chase up again - too soon.
        assertEquals(0, $n->chaseup($id));

        # Make it older.
        $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = DATE_SUB(added, INTERVAL 41 DAY) WHERE id = $id");

        # Nobody else yet.
        assertEquals(0, $n->chaseup($id));
    }
}

