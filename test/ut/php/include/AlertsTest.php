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
class AlertTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM `groups` WHERE `type` = 'UnitTest';");
    }

    protected function tearDown() : void {
        parent::tearDown ();
        $this->dbhm->preExec("DELETE FROM alerts WHERE subject LIKE 'UT - please ignore';");
    }

    public function testMultiple() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_UT);

        $g->setPrivate('contactmail', 'test@test.com');

        $a = new Alert($this->dbhr, $this->dbhm);
        $id = $a->create(NULL, 'geeks', Alert::MODS, 'UT - please ignore', 'UT', 'UT', FALSE, FALSE);

        # Send - one external.
        self::assertEquals(1, $a->process($id, Group::GROUP_UT));

        # Again - should complete as no more UT groups.
        self::assertEquals(0, $a->process($id, Group::GROUP_UT));

        $a = new Alert($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($a->getPrivate('complete'));
    }

    public function testErrors() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_UT);

        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->user->addMembership($gid, User::ROLE_MODERATOR);

        $a = new Alert($this->dbhr, $this->dbhm);
        $id = $a->create(NULL, 'UT', Alert::MODS, 'UT - please ignore', 'UT', 'UT', FALSE, FALSE);

        global $dbconfig;

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([$dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new \Exception());
        $a->setDbhm($mock);

        self::assertEquals(0, $a->mailMods($id, $gid));

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([$dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE])
            ->setMethods(array('lastInsertId'))
            ->getMock();
        $mock->method('lastInsertId')->willThrowException(new \Exception());
        $a->setDbhm($mock);

        self::assertEquals(0, $a->mailMods($id, $gid));
    }
}

