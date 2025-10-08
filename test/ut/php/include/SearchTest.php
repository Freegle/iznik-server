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
class SearchTest extends IznikTestCase
{
    private $dbhr, $dbhm;
    private $lockh;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");

        list($g, $this->gid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);

        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Prevent the message_spatial.php cron job from running during tests by holding its lock.
        # If the script is already running, retry until we can get the lock.
        $lock = "/tmp/iznik_lock_message_spatial.php.lock";
        $this->lockh = fopen($lock, 'wa');
        $block = 0;
        $maxRetries = 50;
        $retryCount = 0;

        while (!flock($this->lockh, LOCK_EX | LOCK_NB, $block)) {
            $retryCount++;
            if ($retryCount >= $maxRetries) {
                $this->fail("Could not acquire lock on message_spatial.php after $maxRetries attempts - script may be stuck");
            }
            usleep(100000); # Sleep for 100ms before retrying
        }
    }

    protected function tearDown() : void
    {
        # Release the lock to allow message_spatial.php to run again.
        if ($this->lockh) {
            flock($this->lockh, LOCK_UN);
            fclose($this->lockh);
        }
//        parent::tearDown();
//        $this->dbhm->preExec("DROP TABLE IF EXISTS test_index");
    }

    public function testBasic()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: Test zzzutzzz (location)', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id1, $failok) = $m->save();
        $m->index();
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m1->setPrivate('lat', 8.4);
        $m1->setPrivate('lng', 179.15);
        $m1->addToSpatialIndex();
        $this->log("Created message id $id1");

        # Search for various terms
        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        $ctx = NULL;
        $ret = $m->search("Test zzzutzzz", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # Test restricting by filter.
        $ctx = NULL;
        $this->log("Restrict to {$this->gid}");
        $ret = $m->search("Test", $ctx, Search::Limit, NULL, [ $this->gid ]);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        $ctx = NULL;
        $ret = $m->search("Test", $ctx, Search::Limit, NULL, [ $this->gid+1 ]);
        $this->assertEquals(0, count($ret));

        # Test fuzzy
        $ctx = NULL;
        $this->log("Test fuzzy");
        $ret = $m->search("tuesday", $ctx);
        $this->log("Fuzzy " . var_export($ctx, TRUE));
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # Test typo
        $ctx = NULL;
        $ret = $m->search("Tess", $ctx);
        $this->log("Typo " . var_export($ctx, TRUE));
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # Too far
        $ctx = NULL;
        $ret = $m->search("Tetx", $ctx);
        $this->assertEquals(0, count($ret));

        # Test restricted search
        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx, Search::Limit, [ $id1 ]);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx, Search::Limit, []);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # Search again using the same context - will find starts with
        $this->log("CTX " . var_export($ctx, TRUE));
        $ret = $m->search("zzzutzzz", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # And again - will find sounds like
        $ret = $m->search("zzzutzzz", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # And again - will find typo
        $ret = $m->search("zzzutzzz", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # Context no longer used so will return again.
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # Edit subject.
        $rc = $m->edit('OFFER: Test ppputppp (location)',
                       NULL,
                       NULL,
                       NULL,
                       NULL,
                       NULL,
                       FALSE,
                       NULL);

        $ctx = NULL;
        $ret = $m->search("ppputppp", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));

        # Delete - shouldn't be returned after that.
        $m1->deleteItems();
        $m1->delete();

        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        $this->assertEquals(0, count(array_filter($ret, function($a) use ($id1) {
            return $a['id'] == $id1;
        })));
    }

    public function testMultiple()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test zzzutzzz (location)', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id1, $failok) = $m->save();
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m1->index();
        $m1->setPrivate('lat', 8.4);
        $m1->setPrivate('lng', 179.15);
        $m1->addToSpatialIndex();
        $this->log("Created message id $id1");
        #error_log("Indexed ? " . var_export($this->dbhr->preQuery("SELECT DISTINCT items_index.itemid, items_index.popularity, wordid FROM items_index INNER JOIN messages_items ON items_index.itemid = messages_items.itemid  WHERE `wordid` IN (13449318)"), TRUE));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test yyyutyyy (location)', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id2, $failok) = $m->save();
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $m2->index();
        $m2->setPrivate('lat', 8.4);
        $m2->setPrivate('lng', 179.15);
        $m2->addToSpatialIndex();
        $this->log("Created message id $id2");

        # Search for various terms
        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        $this->assertEquals(2, count(array_filter($ret, function($a) use ($id1, $id2) {
            return $a['id'] == $id1 || $a['id'] == $id2;
        })));

        $ctx = NULL;
        $ret = $m->search("Test zzzutzzz", $ctx);
        $this->assertEquals(2, count(array_filter($ret, function($a) use ($id1, $id2) {
            return $a['id'] == $id1 || $a['id'] == $id2;
        })));

        $ctx = NULL;
        $ret = $m->search("Test yyyutyyy", $ctx);
        $this->assertEquals(2, count(array_filter($ret, function($a) use ($id1, $id2) {
            return $a['id'] == $id1 || $a['id'] == $id2;
        })));

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1, $id2) {
            return $a['id'] == $id1;
        })));

        $ctx = NULL;
        $ret = $m->search("yyyutyyy", $ctx);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1, $id2) {
            return $a['id'] == $id2;
        })));

        # Test restricted search
        $ctx = NULL;
        $ret = $m->search("test", $ctx, Search::Limit, [ $id1 ]);
        $this->assertEquals(1, count(array_filter($ret, function($a) use ($id1, $id2) {
            return $a['id'] == $id1;
        })));

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
