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

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        parent::setUp();
        $dbhm->preExec("DELETE FROM noticeboards WHERE name LIKE 'UTTest%';");
        $dbhm->preExec("DELETE FROM noticeboards WHERE description LIKE 'Test description';");
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
        $this->assertEquals(0, $n->chaseup($id));

        # Make it old.
        $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = DATE_SUB(added, INTERVAL 31 DAY) WHERE id = $id");

        # Chase up owner.
        $this->assertEquals(1, $n->chaseup($id));

        # Chase up again - too soon.
        $this->assertEquals(0, $n->chaseup($id));

        # Make it older.
        $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = DATE_SUB(added, INTERVAL 41 DAY) WHERE id = $id");

        # Nobody else yet.
        $this->assertEquals(0, $n->chaseup($id));
    }

    public function testChaseupOther() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $id);

        $id2 = $u->create('Test', 'User', NULL);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $u2->addEmail('test@test2.com');

        $n = new Noticeboard($this->dbhr, $this->dbhm);

        $id = $n->create('UTTest', 8.4, 179.15, $id, NULL);

        $n = $this->getMockBuilder('Freegle\Iznik\Noticeboard')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('sendIt'))
            ->getMock();

        $n->method('sendIt')->willReturn(TRUE);

        # Too soon to chase up.
        $this->assertEquals(0, $n->chaseup($id));

        # Make it old.
        $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = DATE_SUB(added, INTERVAL 31 DAY) WHERE id = $id");

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.6, 179.1 8.6, 179.1 8.3))');

        # Should be nobody yet.
        $this->assertEquals(0, $n->chaseup($id, TRUE, $gid));

        $u2->addMembership($gid);

        # Still not, as not microvolunteering.
        $this->assertEquals(0, $n->chaseup($id, TRUE, $gid));

        # Still not, as no location.
        $u2->setPrivate('trustlevel', User::TRUST_BASIC);
        $this->assertEquals(0, $n->chaseup($id, TRUE, $gid));


        $settings = [
            'mylocation' => [
                'lng' => 179.16,
                'lat' => 8.5,
                'name' => 'EH3 6SS'
            ],
        ];

        $u2->setPrivate('settings', json_encode($settings));

        # Finally.
        $this->assertEquals(1, $n->chaseup($id, TRUE, $gid));

        # And not again.
        $this->assertEquals(0, $n->chaseup($id, TRUE, $gid));
    }
}

