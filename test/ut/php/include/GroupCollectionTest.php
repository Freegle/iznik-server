<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/group/GroupCollection.php';


/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class groupCollectionTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->exec("DELETE FROM groups WHERE nameshort = 'testgroup1';");
        $this->dbhm->exec("DELETE FROM groups WHERE nameshort = 'testgroup2';");
        $this->dbhm->exec("DELETE FROM groups WHERE nameshort = 'testgroup3';");
    }

    public function testDefaults() {
        error_log(__METHOD__);

        $g = new Group($this->dbhr, $this->dbhm);
        $g1 = $g->create("testgroup1", Group::GROUP_REUSE);
        $g2 = $g->create("testgroup2", Group::GROUP_REUSE);
        $g3 = $g->create("testgroup3", Group::GROUP_REUSE);

        # Collection with no groups.
        error_log("No groups");
        $c = new GroupCollection($this->dbhr, $this->dbhm, []);

        error_log("1 group");
        $c = new GroupCollection($this->dbhr, $this->dbhm, [ $g1 ] );
        $gs = $c->get();
        assertEquals(1, count($gs));
        assertEquals($g1, $gs[0]->getId());

        error_log("2 groups");
        $c = new GroupCollection($this->dbhr, $this->dbhm, [ $g1, $g2 ] );
        $gs = $c->get();
        assertEquals(2, count($gs));
        assertEquals($g1, $gs[0]->getId());
        assertEquals($g2, $gs[1]->getId());

        error_log("3 groups");
        $c = new GroupCollection($this->dbhr, $this->dbhm, [ $g2, $g3, $g1 ] );
        $gs = $c->get();
        assertEquals(3, count($gs));
        assertEquals($g2, $gs[0]->getId());
        assertEquals($g3, $gs[1]->getId());
        assertEquals($g1, $gs[2]->getId());

        error_log(__METHOD__ . " end");
    }

}
