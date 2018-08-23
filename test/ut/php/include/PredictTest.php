<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/Predict.php';
require_once IZNIK_BASE . '/include/chat/ChatMessage.php';
require_once IZNIK_BASE . '/include/chat/ChatRoom.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class PredictTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        error_log(__METHOD__);

        $u = new User($this->dbhr, $this->dbhm);
        $raterid = $u->create("Test", "User", "Test User");
        $rater = new User($this->dbhr, $this->dbhm, $raterid);

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $r = new ChatRoom($this->dbhr, $this->dbhm);

        $minratingid = NULL;

        # Set up a few users with good data.
        error_log("Set up good users");
        $goodusers = [];
        for ($i = 0; $i < 100; $i++) {
            $uid = $u->create("Test", "User", "Test User");;
            $goodusers[] = $uid;
            $ratingid = $rater->rate($raterid, $uid, User::RATING_UP);
            $minratingid = $minratingid == NULL ? $ratingid : $minratingid;
            $chatid = $r->createConversation($raterid, $uid);
            $m->create($chatid, $uid, "Hello, I'm lovely and polite and helpful and I will make your freegling experience pleasant.", ChatMessage::TYPE_INTERESTED);
        }

        # Set up a few users with good data.
        error_log("Set up bad users");
        $badusers = [];
        for ($i = 0; $i < 100; $i++) {
            $uid = $u->create("Test", "User", "Test User");;
            $badusers[] = $uid;
            $rater->rate($raterid, $uid, User::RATING_DOWN);
            $chatid = $r->createConversation($raterid, $uid);
            $m->create($chatid, $uid, "I'm rude and grumpy and won't show up", ChatMessage::TYPE_INTERESTED);
        }

        # Now train on these.  This means we'll work on both live and Travis; we might include a few live ratings
        # but that's ok.
        error_log("Train on test");
        $p = new Predict($this->dbhr, $this->dbhm);
        $count = $p->train($minratingid);
        self::assertGreaterThanOrEqual(200, $count);

        list ($up, $down, $right, $wrong, $badlywrong) = $p->checkAccuracy();
        error_log("\n\nTest data accuracy predicted Up $up Down $down, right $right (" . (100 * $right / ($wrong + $right)) . "%) wrong $wrong badly wrong $badlywrong (" . (100 * $badlywrong / ($wrong + $right)) . "%)");

        # We don't want to be badly wrong more than 5% of the time.
        self::assertLessThan(5, 100 * $badlywrong / ($up + $down));

        # Check the actual predict user call.
        error_log("Predict good user {$goodusers[0]}");
        assertEquals(User::RATING_UP, $p->predict($goodusers[0]));
        error_log("Predict bad user {$badusers[0]}");
        assertEquals(User::RATING_DOWN, $p->predict($badusers[0]));

        # First one will train and save
        $p->ensureModel($minratingid, '/tmp/iznik.predictions.ut');

        # Second will retrieve and use
        $p->ensureModel($minratingid, '/tmp/iznik.predictions.ut');

        assertEquals(User::RATING_UP, $p->predict($goodusers[0]));
        assertEquals(User::RATING_DOWN, $p->predict($badusers[0]));

        # Check the predict call with similar text.
        $uid = $u->create("Test", "User", "Test User");;
        $m->create($chatid, $uid, "I'm splendid and polite I will make your freegling experience wonderful.", ChatMessage::TYPE_INTERESTED);
        assertEquals(User::RATING_UP, $p->predict($uid));
        $uid = $u->create("Test", "User", "Test User");;
        $m->create($chatid, $uid, "I'm rude and it will be awful.", ChatMessage::TYPE_INTERESTED);
        assertEquals(User::RATING_DOWN, $p->predict($uid));

        # Now repeat the train and check on whatever is in the DB.  On Travis this will be the same, but if we
        # are running on a real DB it will act as a check against this going rogue in production.
        error_log("Train on rest");
        $p = new Predict($this->dbhr, $this->dbhm);
        $count = $p->train();
        self::assertGreaterThanOrEqual(200, $count);
        list ($up, $down, $right, $wrong, $badlywrong) = $p->checkAccuracy();
        error_log("\n\nRest data predicted Up $up Down $down, right $right (" . (100 * $right / ($wrong + $right)) . "%) wrong $wrong badly wrong $badlywrong (" . (100 * $badlywrong / ($wrong + $right)) . "%)");
        self::assertLessThan(6, 100 * $badlywrong / ($up + $down));

        error_log(__METHOD__ . " end");
    }
}

