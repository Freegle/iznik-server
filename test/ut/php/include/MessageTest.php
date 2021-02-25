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
class messageTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com');");
        $dbhm->preExec("DELETE users, users_logins FROM users INNER JOIN users_logins ON users.id = users_logins.userid WHERE uid IN ('testid', '1234');");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE firstname = 'Test' AND lastname = 'User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup1';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup2';");

        # We test around Tuvalu.  If you're setting up Tuvalu Freegle you may need to change that.
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 179.1 AND swlat <= 179.3;");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        for ($swlat = 8.3; $swlat <= 8.6; $swlat += 0.1) {
            for ($swlng = 179.1; $swlng <= 179.3; $swlng += 0.1) {
                $nelat = $swlat + 0.1;
                $nelng = $swlng + 0.1;

                # Use lng, lat order for geometry because the OSM data uses that.
                $dbhm->preExec("INSERT IGNORE INTO locations_grids (swlat, swlng, nelat, nelng, box) VALUES (?, ?, ?, ?, GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))'));",
                    [
                        $swlat,
                        $swlng,
                        $nelat,
                        $nelng
                    ]);
            }
        }

        $grids = $dbhr->preQuery("SELECT * FROM locations_grids WHERE swlng >= 179.1 AND swlng <= 179.3;");
        foreach ($grids as $grid) {
            $sql = "SELECT id FROM locations_grids WHERE MBRTouches (GeomFromText('POLYGON(({$grid['swlng']} {$grid['swlat']}, {$grid['swlng']} {$grid['nelat']}, {$grid['nelng']} {$grid['nelat']}, {$grid['nelng']} {$grid['swlat']}, {$grid['swlng']} {$grid['swlat']}))'), box);";
            $touches = $dbhr->preQuery($sql);
            foreach ($touches as $touch) {
                $dbhm->preExec("INSERT IGNORE INTO locations_grids_touches (gridid, touches) VALUES (?, ?);", [ $grid['id'], $touch['id'] ]);
            }
        }

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
        $this->group->setPrivate('onhere', 1);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertEquals(1, $u->addMembership($this->gid));
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();
        $this->user = $u;
    }

    public function testSetFromIP() {
        $m = new Message($this->dbhr, $this->dbhm);
        $m->setFromIP('8.8.8.8');
        assertTrue($m->getFromhost() === 'google-public-dns-a.google.com' || $m->getFromhost() === 'dns.google');
    }

    public function testRelated() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id1 = $m->save();

        # TAKEN after OFFER - should match
        $msg = str_replace('OFFER: Test item', 'TAKEN: Test item', $msg);
        $msg = str_replace('22 Aug 2015', '22 Aug 2016', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals(1, $m->recordRelated());
        $atts = $m->getPublic();
        assertEquals($id1, $atts['related'][0]['id']);

        # We don't match on messages with outcomes so hack this out out again.
        $this->dbhm->preExec("DELETE FROM messages_outcomes WHERE msgid = $id1;");

        # TAKEN before OFFER - shouldn't match
        $msg = str_replace('22 Aug 2016', '22 Aug 2014', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals(0, $m->recordRelated());

        # TAKEN after OFFER but for other item - shouldn't match
        $msg = str_replace('22 Aug 2014', '22 Aug 2016', $msg);
        $msg = str_replace('TAKEN: Test item', 'TAKEN: Something completely different', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals(0, $m->recordRelated());

        # TAKEN with similar wording - should match
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'TAKEN: Test items (location)', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals(1, $m->recordRelated());
     }

    public function testRelated2() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', '[hertford_freegle] Offered - Grey Driveway Blocks - Hoddesdon', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id1 = $m->save();

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', '[hertford_freegle] Offer - Pedestal Fan - Hoddesdon', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id2 = $m->save();

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', '[hertford_freegle] TAKEN: Grey Driveway Blocks (Hoddesdon)', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals(1, $m->recordRelated());
        $atts = $m->getPublic();
        assertEquals($id1, $atts['related'][0]['id']);

        # We don't match on messages with outcomes so hack this out out again.
        $this->dbhm->preExec("DELETE FROM messages_outcomes WHERE msgid = $id1;");

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'TAKEN: Grey Driveway Blocks (Hoddesdon)', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals(1, $m->recordRelated());
        $atts = $m->getPublic();
        assertEquals($id1, $atts['related'][0]['id']);
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $atts1 = $m1->getPublic();
        self::assertEquals('Taken', $atts1['outcomes'][0]['outcome']);

        }

    public function testRelated3() {
        # Post a message to two groups, mark it as taken on both, make sure that is handled correctly.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid2 = $g->create('testgroup2', Group::GROUP_REUSE);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test (Location)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id1 = $m->save();

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test (Location)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup2', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id2 = $m->save();

        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        assertEquals($gid1, $m1->getGroups(FALSE, TRUE)[0]);
        assertEquals($gid2, $m2->getGroups(FALSE, TRUE)[0]);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'TAKEN: Test (Location)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id3 = $m->save();

        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        assertEquals(Message::OUTCOME_TAKEN, $m1->hasOutcome());
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        assertEquals(FALSE, $m2->hasOutcome());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'TAKEN: Test (Location)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup2', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id4 = $m->save();

        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        assertEquals(Message::OUTCOME_TAKEN, $m1->hasOutcome());
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        assertEquals(Message::OUTCOME_TAKEN, $m2->hasOutcome());

        }

    public function testNoSender() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nosender');
        $msg = str_replace('Basic test', 'OFFER: Test item', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $rc = $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertFalse($rc);

        }

    public function testSuggest() {
        $l = new Location($this->dbhr, $this->dbhm);
        $id = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.4);

        $m = new Message($this->dbhr, $this->dbhm);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'testgroup1@yahoogroups.com', $msg);
        $mid = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $atts = $m->getPublic();
        $this->log("Public " . var_export($atts, true));

        # Shouldn't be able to see actual location
        assertFalse(array_key_exists('locationid', $atts));
        assertFalse(array_key_exists('location', $atts));
        assertEquals($id, $m->getPrivate('locationid'));

        $goodsubj = "OFFER: Test (Tuvalu High Street)";

        # Test variants which should all get corrected to the same value
        assertEquals($goodsubj, $m->suggestSubject($gid, $goodsubj));
        assertEquals($goodsubj, $m->suggestSubject($gid, "OFFER:Test (High Street)"));
        assertEquals($goodsubj, $m->suggestSubject($gid, "OFFR Test (High Street)"));
        assertEquals($goodsubj, $m->suggestSubject($gid, "OFFR - Test  - (High Street)"));
        $this->log("--1");
        assertEquals($goodsubj, $m->suggestSubject($gid, "OFFR Test Tuvalu High Street"));
        $this->log("--2");
        assertEquals($goodsubj, $m->suggestSubject($gid, "OFFR Test Tuvalu HIGH STREET"));
        assertEquals("OFFER: test (Tuvalu High Street)", $m->suggestSubject($gid, "OFFR TEST Tuvalu HIGH STREET"));

        # Test per-group keywords
        $g->setSettings([
            'keywords' => [
                'offer' => 'Offered'
            ]
        ]);
        $keywords = $g->getSetting('keywords', []);
        $this->log("After set " . var_export($keywords, TRUE));

        assertEquals("Offered: Test (Tuvalu High Street)", $m->suggestSubject($gid,$goodsubj));

        assertEquals("OFFER: Thing need (Tuvalu High Street)", "OFFER: Thing need (Tuvalu High Street)");
    }

    public function testMerge() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Now from a different email but the same Yahoo UID.  This shouldn't trigger a merge as we should identify
        # them by the UID.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test2@test.com');
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $msg = str_ireplace('test@test.com', 'test2@test.com', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from2@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Check the merge happened.  Can't use findlog as we hide merge logs.
        $this->waitBackground();
        $fromuser = $m->getFromuser();
        $sql = "SELECT * FROM logs WHERE user = ? AND type = 'User' AND subtype = 'Merged';";
        $logs = $this->dbhr->preQuery($sql, [ $fromuser ]);
        assertEquals(0, count($logs));
    }

    public function testHebrew() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', '=?windows-1255?B?UkU6IE1hdGFub3MgTGFFdnlvbmltIFB1cmltIDIwMTYg7sf6yMzw5Q==?=
=?windows-1255?B?yfog7MjgxuHA6cnwxOnt?=', $msg);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->gid);
        $this->user = $u;

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
    }
    
    public function testPrune() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/prune'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertGreaterThan(0, strlen($m->getMessage()));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/prune2'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->log("Pruned to " . $m->getMessage());
        assertLessThan(20000, strlen($m->getMessage()));
    }

    public function testReverseSubject() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('TAKEN: Test item (location)', $m->reverseSubject());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $msg = str_replace('Basic test', '[StevenageFreegle] OFFER: Ninky nonk train and night garden characters St NIcks [1 Attachment]', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('TAKEN: Ninky nonk train and night garden characters St NIcks', $m->reverseSubject());

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        assertEquals('TAKEN: Test item (location)', $m->reverseSubject());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $m = new Message($this->dbhr, $this->dbhm);
        $msg = str_replace('Basic test', 'Bexley Freegle OFFER: compost bin (Bexley DA5)', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('TAKEN: compost bin (Bexley DA5)', $m->reverseSubject());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $m = new Message($this->dbhr, $this->dbhm);
        $msg = str_replace('Basic test', 'OFFER/CYNNIG: Windows 95 & 98 on DVD (Criccieth LL52)', $msg);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('TAKEN: Windows 95 & 98 on DVD (Criccieth LL52)', $m->reverseSubject());

        }

    public function testStripQuoted() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply.", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text2'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply.", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text3'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply.\r\n\r\nAnd something after it.", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text4'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals('Replying.', $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text5'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply.", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text6'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply.", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text7'));
        $msg = str_replace('USER_SITE', USER_SITE, $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply with https://" . USER_SITE ."?u=1234& an url and https://" . USER_SITE . "/sub?a=1&", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text8'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals('Ok, here\'s a reply.', $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text9'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply to:\r\n\r\nSomewhere.", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text10'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text11'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text12'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Great thank youFriday evening around 7?Maria", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text13'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Yes no problem, roughly how big is it. Will depends if it car or van. On 20 Jul 2018 10:39 am, gothe <notify-4703531-875040@users.ilovefreegle.org> wrote:", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text14'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Please may I be considered", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text15'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Please may I be considered", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text16'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Please may I be considered", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text16'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Please may I be considered", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text17'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Hello\r\n\r\nI would be interested in these as have a big slug problem and also the lawn feed and could collect today ?\r\n\r\nMany thanks\r\n\r\nAnn", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text18'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply.", $stripped);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text19'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $stripped = $m->stripQuoted();
        assertEquals("Ok, here's a reply.", $stripped);
    }
    
    public function testCensor() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/phonemail'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $atts = $m->getPublic();
        assertEquals('Hey. *** *** and ***@***.com.', $atts['textbody']);

        }

    public function testModmail() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/modmail'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertTrue($m->getPrivate('modmail'));

        }

    public function testAutoReply() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertFalse($m->isAutoreply());;

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: Out of the office', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertTrue($m->isAutoreply());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Hey.', 'I aim to respond within', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertTrue($m->isAutoreply());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/autosubmitted'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertTrue($m->isAutoreply());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/weirdreceipt'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertTrue($m->isAutoreply());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('fromaddr', 'notify@yahoogroups.com');
        assertTrue($m->isAutoreply());

        }

    public function testBounce() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertFalse($m->isBounce());;

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: Mail delivery failed', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertTrue($m->isBounce());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Hey.', '550 No such user', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertTrue($m->isBounce());

        }

    public function testAutoRepost() {
        $m = new Message($this->dbhr, $this->dbhm);

        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $this->user->addEmail($email);

        # Put two messages on the group - one eligible for autorepost, the other not yet.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('test@test.com', $email, $msg);
        $msg = str_replace('Basic test', 'OFFER: Test not due (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $this->user->addEmail($email);
        $id1 = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id1);
        $m->setPrivate('sourceheader', 'Platform');
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
        $msg = str_replace('test@test.com', $email, $msg);
        $msg = str_replace('Test att', 'OFFER: Test due (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id2 = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $this->log("Due message $id2");
        $m = new Message($this->dbhr, $this->dbhm, $id2);
        $m->setPrivate('sourceheader', 'Platform');
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $m = new Message($this->dbhr, $this->dbhm);

        # Should get nothing - first message is not due and too old to generate a warning.
        $this->log("Expect nothing");
        list ($count, $warncount) = $m->autoRepostGroup(Group::GROUP_FREEGLE, '2016-03-01', $this->gid);
        assertEquals(0, $count);
        assertEquals(0, $warncount);

        # Call when repost not due.  First one should cause a warning only.
        $this->log("Expect warning for $id2");
        $mysqltime = date("Y-m-d H:i:s", strtotime('59 hours ago'));
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = '$mysqltime' WHERE msgid = ?;", [ $id2 ]);

        list ($count, $warncount) = $m->autoRepostGroup(Group::GROUP_FREEGLE, '2016-01-01', $this->gid);
        assertEquals(0, $count);
        assertEquals(1, $warncount);

        # Again - no action.
        $this->log("Expect nothing");
        list ($count, $warncount) = $m->autoRepostGroup(Group::GROUP_FREEGLE, '2016-01-01', $this->gid);
        assertEquals(0, $count);
        assertEquals(0, $warncount);

        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $_SESSION['id'] = $m2->getFromuser();
        $atts = $m2->getPublic();
        self::assertEquals(FALSE, $atts['canrepost']);
        self::assertEquals(TRUE, $atts['willautorepost']);

        # Make the message and warning look longer ago.  Then call - should cause a repost.
        $this->log("Expect repost");
        $mysqltime = date("Y-m-d H:i:s", strtotime('77 hours ago'));
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = '$mysqltime' WHERE msgid = ?;", [ $id2 ]);
        $this->dbhm->preExec("UPDATE messages_groups SET lastautopostwarning = '2016-01-01' WHERE msgid = ?;", [ $id2 ]);

        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $atts = $m2->getPublic();
        $this->log("Can repost {$atts['canrepost']} {$atts['canrepostat']}");
        self::assertEquals(TRUE, $atts['canrepost']);

        list ($count, $warncount) = $m->autoRepostGroup(Group::GROUP_FREEGLE, '2016-01-01', $this->gid);
        assertEquals(1, $count);
        assertEquals(0, $warncount);

        $this->waitBackground();
        $uid = $m2->getFromuser();
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_AUTO_REPOSTED, $logs[$u->getId()]['logs']);
        self::assertNotNull($log);
    }

    public function testChaseup() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $this->user->addEmail($email);
        $msg = str_replace('test@test.com', $email, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($mid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        # Create a reply
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $r->createConversation($m->getFromuser(), $uid);

        $c = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cid, $banned) = $c->create($rid, $uid, "Test reply", ChatMessage::TYPE_DEFAULT, $mid);
        assertNotNull($cid);

        # Chaseup - expect none as too recent.
        $count = $m->chaseUp(Group::GROUP_FREEGLE, '2016-03-01', $this->gid);
        assertEquals(0, $count);

        # Make it older.
        $mysqltime = date("Y-m-d H:i:s", strtotime('121 hours ago'));
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = ? WHERE msgid = ?;", [
            $mysqltime,
            $mid
        ]);
        $c = new ChatMessage($this->dbhr, $this->dbhm, $cid);
        $c->setPrivate('date', $mysqltime);

        # Chaseup again - should get one.
        $count = $m->chaseUp(Group::GROUP_FREEGLE, '2016-03-01', $this->gid);
        assertEquals(1, $count);

        # And again - shouldn't, as the last chaseup was too recent.
        $count = $m->chaseUp(Group::GROUP_FREEGLE, '2016-03-01', $this->gid);
        assertEquals(0, $count);
    }

    public function testLanguishing() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $this->user->addEmail($email);
        $msg = str_replace('test@test.com', $email, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        assertNotNull($mid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        # Shouldn't notify, too new.
        assertEquals(0, $m->notifyLanguishing($mid));

        # Should have no notification.
        $n = new Notifications($this->dbhr, $this->dbhm);
        $ctx = NULL;
        $notifs = $n->get($m->getFromuser(), $ctx);
        assertEquals(1, count($notifs));
        assertEquals(Notifications::TYPE_ABOUT_ME, $notifs[0]['type']);

        # Make it older.
        $mysqltime = date("Y-m-d H:i:s", strtotime('121 hours ago'));
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = ? WHERE msgid = ?;", [
            $mysqltime,
            $mid
        ]);

        # Notify languish - nothing as not reposted enough.
        assertEquals(0, $m->notifyLanguishing($mid));

        # Fake reposts
        $this->dbhm->preExec("UPDATE messages_groups SET autoreposts = 100 WHERE msgid = ?;", [
            $mid
        ]);
        assertEquals(1, $m->notifyLanguishing($mid));

        # Should have a notification.
        $n = new Notifications($this->dbhr, $this->dbhm);
        $ctx = NULL;
        $notifs = $n->get($m->getFromuser(), $ctx);
        assertEquals(2, count($notifs));
        assertEquals(Notifications::TYPE_ABOUT_ME, $notifs[1]['type']);
        assertEquals(Notifications::TYPE_OPEN_POSTS, $notifs[0]['type']);
    }

    public function testTN() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tnatt2'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $m->save();
        $atts = $m->getAttachments();
        assertEquals(1, count($atts));
        $m->delete();

        }

    public function testIncludeArea() {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)', 0);
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)', 0);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $m = new Message($this->dbhr, $this->dbhm);
        $id = $m->createDraft();
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Check the can see code for our own messages.
        $atts = $m->getPublic();
        $atts['myrole'] = User::ROLE_NONMEMBER;
        assertTrue($m->canSee($atts));

        $m->setPrivate('locationid', $fullpcid);
        $m->setPrivate('type', Message::TYPE_OFFER);
        $m->setPrivate('textbody', 'Test');

        $i = new Item($this->dbhr, $this->dbhm);
        $iid = $i->create('test item');
        $m->addItem($iid);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $g->setSettings([ 'includearea' => FALSE ]);

        $m->constructSubject($gid);
        self::assertEquals(strtolower('OFFER: test item (TV13)'), strtolower($m->getSubject()));

        $g->setSettings([ 'includepc' => FALSE ]);
        $m->constructSubject($gid);
        self::assertEquals(strtolower('OFFER: test item (Tuvalu Central)'), strtolower($m->getSubject()));

        # Edit the message to make sure the subject stays in that format.
        $this->dbhm->preExec("INSERT INTO messages_groups (msgid, groupid) VALUES (?, ?)", [
            $id,
            $gid
        ]);

        $m->edit(NULL, NULL, Message::TYPE_WANTED, 'test item2', 'TV13 1HH', [], TRUE, NULL);
        self::assertEquals(strtolower('WANTED: test item2 (Tuvalu Central)'), strtolower($m->getSubject()));

        # Test subject twice for location caching coverage.
        $locationlist = [];
        assertEquals($areaid, $m->getLocation($areaid, $locationlist)->getId());
        assertEquals($areaid, $m->getLocation($areaid, $locationlist)->getId());
    }

    public function testTNShow() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('test@test.com', 'test@user.trashnothing.com', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test@user.trashnothing.com', 'to@test.com', $msg);
        $id1 = $m->save();
        $atts = $m->getPublic();
        assertTrue($m->canSee($atts));
        $m->delete();

        }

    public function testQuickDelete() {
        $dbconfig = array (
            'host' => SQLHOST,
            'user' => SQLUSER,
            'pass' => SQLPASSWORD,
            'database' => SQLDB
        );

        $dsn = "mysql:host={$dbconfig['host']};dbname=information_schema;charset=utf8";

        $dbhschema = new \PDO($dsn, $dbconfig['user'], $dbconfig['pass']);

        $sql = "SELECT * FROM KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'messages' AND table_schema = '" . SQLDB . "';";
        $schema = $dbhschema->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $m->quickDelete($schema, $id);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNull($m->getID());
    }

    public function testAutoApprove() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_UT);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        $this->user->addMembership($gid);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Too soon to autoapprove.
        assertEquals(0, $m->autoapprove($id));

        # Age it.
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = '2018-01-01' WHERE msgid = ?;", [
            $id
        ]);
        $this->dbhm->preExec("UPDATE memberships SET added = '2018-01-01' WHERE userid = ?;", [
            $m->getFromuser()
        ]);

        assertEquals(1, $m->autoapprove($id));

        # Check logs.
        $this->waitBackground();
        $groups = $g->listByType(Group::GROUP_UT, TRUE, FALSE);

        $found = FALSE;
        foreach ($groups as $group) {
            if ($group['id'] == $gid) {
                assertEquals(1, $group['recentautoapproves']);
                assertEquals(100, $group['recentautoapprovespercent']);
                assertEquals(0, $group['recentmanualapproves']);
                $found = TRUE;
            }
        }

        assertTrue($found);
    }

    // For manual testing
//    public function testSpecial() {
//        //
//        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/special');
//
//        $m = new Message($this->dbhr, $this->dbhm);
//        $rc = $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
//        assertTrue($rc);
//        $id = $m->save();
//        $m = new Message($this->dbhr, $this->dbhm, $id);
//        $this->log("IP " . $m->getFromIP());
//        $s = new Spam($this->dbhr, $this->dbhm);
//        $s->check($m);
        //
//
//        //    }

//    public function testType() {
//        $m = new Message($this->dbhr, $this->dbhm, 8153598);
//        $this->log(Message::determineType($m->getSubject()));
//    }

}

