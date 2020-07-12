<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/message/Message.php';
require_once IZNIK_BASE . '/include/misc/Search.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class searchTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DROP TABLE IF EXISTS test_index");
        $this->dbhm->preExec("CREATE TABLE test_index LIKE messages_index");
        $this->s = new Search($this->dbhr, $this->dbhm, 'test_index', 'msgid', 'arrival', 'words', 'groupid', NULL,'words_cache');
        $this->dbhm->preExec("DELETE FROM words WHERE word = 'zzzutzzz';");
        $this->dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $this->dbhm->preExec("DELETE FROM words_cache WHERE search LIKE 'zzzutzzz';");
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->dbhm->preExec("DROP TABLE IF EXISTS test_index");
    }

    public function testBasic()
    {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: Test zzzutzzz', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->setSearch($this->s);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list($id1, $already) = $m->save();
        $m->index();
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m1->setSearch($this->s);
        $this->log("Created message id $id1");

        # Search for various terms
        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        assertEquals($id1, $ret[0]['id']);

        $ctx = NULL;
        $ret = $m->search("Test zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]['id']);

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]['id']);

        # Test restricting by filter.
        $ctx = NULL;
        $this->log("Restrict to $gid");
        $ret = $m->search("Test", $ctx, Search::Limit, NULL, [ $gid ]);
        assertEquals($id1, $ret[0]['id']);

        $ctx = NULL;
        $ret = $m->search("Test", $ctx, Search::Limit, NULL, [ $gid+1 ]);
        assertEquals(0, count($ret));

        # Test fuzzy
        $ctx = NULL;
        $this->log("Test fuzzy");
        $ret = $m->search("tuesday", $ctx);
        $this->log("Fuzzy " . var_export($ctx, true));
        assertEquals($id1, $ret[0]['id']);
        assertNotNull($ctx['SoundsLike']);

        # Test typo
        $ctx = NULL;
        $ret = $m->search("Tets", $ctx);
        $this->log("Typo " . var_export($ctx, true));
        assertEquals($id1, $ret[0]['id']);
        assertNotNull($ctx['Typo']);

        # Too far
        $ctx = NULL;
        $ret = $m->search("Tetx", $ctx);
        assertEquals(0, count($ret));

        # Test restricted search
        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx, Search::Limit, [ $id1 ]);
        assertEquals($id1, $ret[0]['id']);

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx, Search::Limit, []);
        assertEquals($id1, $ret[0]['id']);

        # Search again using the same context - will find starts with
        $this->log("CTX " . var_export($ctx, true));
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]['id']);

        # And again - will find sounds like
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]['id']);

        # And again - will find typo
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]['id']);

        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals(0, count($ret));

        # Delete - shouldn't be returned after that.
        $m1->delete();

        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        assertEquals(0, count($ret));

        }

    public function testMultiple()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test zzzutzzz', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->setSearch($this->s);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list($id1, $already) = $m->save();
        $m->index();
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m1->setSearch($this->s);
        $this->log("Created message id $id1");

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test yyyutyyy', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->setSearch($this->s);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list($id2, $already) = $m->save();
        $m->index();
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $m2->setSearch($this->s);
        $this->log("Created message id $id2");

        # Search for various terms
        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        assertEquals(2, count($ret));
        if ($id2 == $ret[0]['id']) {
            assertEquals($id1, $ret[1]['id']);
        } else if ($id1 == $ret[0]['id']) {
            assertEquals($id2, $ret[1]['id']);
        } else {
            assertTrue(FALSE);
        }

        $ctx = NULL;
        $ret = $m->search("Test zzzutzzz", $ctx);
        assertEquals(2, count($ret));
        assertEquals($id1, $ret[0]['id']);
        assertEquals($id2, $ret[1]['id']);

        $ctx = NULL;
        $ret = $m->search("Test yyyutyyy", $ctx);
        assertEquals(2, count($ret));
        assertEquals($id2, $ret[0]['id']);
        assertEquals($id1, $ret[1]['id']);

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals(1, count($ret));
        assertEquals($id1, $ret[0]['id']);

        $ctx = NULL;
        $ret = $m->search("yyyutyyy", $ctx);
        assertEquals(1, count($ret));
        assertEquals($id2, $ret[0]['id']);

        # Test restricted search
        $ctx = NULL;
        $ret = $m->search("test", $ctx, Search::Limit, [ $id1 ]);
        assertEquals(1, count($ret));
        assertEquals($id1, $ret[0]['id']);

        $m1->delete();
        $m2->delete();

        }

//    public function testSpecial() {
//        $s = new Search($this->dbhr, $this->dbhm, 'messages_index', 'msgid', 'arrival', 'words', 'groupid');
//        $ctx = NULL;
//
//        $ress = $s->search("basket", $ctx, 100, NULL, [ 21467 ]);
//        foreach ($ress as $res) {
//            $m = new Message($this->dbhr, $this->dbhm, $res['id']);
//            $this->log("#{$res['id']} " . $m->getSubject());
//        }
//    }
}
