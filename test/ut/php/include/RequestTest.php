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
class RequestTest extends IznikTestCase {
    private $dbhr, $dbhm, $count;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function trueUntil() {
        $this->log("exceptionUntil count " . $this->count);
        $this->count--;
        if ($this->count > 0) {
            $this->log("Exception");
            throw new \Exception('Faked exception');
        } else {
            $this->log("No exception");
            return TRUE;
        }
    }

    public function testCentral() {
        $r = $this->getMockBuilder('Freegle\Iznik\Request')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $r->method('sendIt')->willReturn(TRUE);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $u->addMembership($gid);

        $rid = $r->create($uid, Request::TYPE_BUSINESS_CARDS, NULL, NULL);
        assertNotNull($rid);
        $r->completed($uid);

        $r->delete();
    }

    /**
     * @dataProvider exceptions
     */
    public function testException($count) {
        $r = $this->getMockBuilder('Freegle\Iznik\Request')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm ])
            ->setMethods(array('sendIt'))
            ->getMock();

        $this->count = $count;

        $r->method('sendIt')->will($this->returnCallback(function() {
            return($this->trueUntil());
        }));

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $u->addMembership($gid);

        $rid = $r->create($uid, Request::TYPE_BUSINESS_CARDS, NULL, NULL);
        assertNotNull($rid);
        $r->completed($uid);
    }

    public function exceptions() {
        return ([
            [ 1 ],
            [ 2 ],
            [ 3 ],
            [ 4 ]
        ]);
    }
}

