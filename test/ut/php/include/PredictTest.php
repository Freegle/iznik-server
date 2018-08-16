<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/Profile.php';
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
        list ($accuracy, $count) = $p->train($minratingid);

        error_log("Accuracy $accuracy on $count");

        self::assertGreaterThan(0.8, $accuracy);
        self::assertGreaterThanOrEqual(200, $count);

        list ($up, $down, $right, $wrong, $badlywrong) = $p->checkAccuracy();
        error_log("\n\nTest data accuracy $accuracy predicted Up $up Down $down, right $right (" . (100 * $right / ($wrong + $right)) . "%) wrong $wrong badly wrong $badlywrong (" . (100 * $badlywrong / ($wrong + $right)) . "%)");

        # We don't want to be badly wrong more than 5% of the time.
        self::assertLessThan(5, 100 * $badlywrong / ($up + $down));

        # Now repeat the train and check on whatever is in the DB.  On Travis this will be the same, but if we
        # are running on a real DB it will act as a check against this going rogue in production.
        error_log("Train on rest");
        $p = new Predict($this->dbhr, $this->dbhm);
        list ($accuracy, $count) = $p->train();
        self::assertGreaterThan(0.8, $accuracy);
        self::assertGreaterThanOrEqual(200, $count);
        list ($up, $down, $right, $wrong, $badlywrong) = $p->checkAccuracy();
        error_log("\n\nRest data accuracy $accuracy predicted Up $up Down $down, right $right (" . (100 * $right / ($wrong + $right)) . "%) wrong $wrong badly wrong $badlywrong (" . (100 * $badlywrong / ($wrong + $right)) . "%)");
        self::assertLessThan(5, 100 * $badlywrong / ($up + $down));

        error_log(__METHOD__ . " end");
    }
}

