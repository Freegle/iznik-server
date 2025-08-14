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
class statsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->exec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
    }

    public function testBasic() {
        # Create a group with one message and one member.
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);

        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->user->addMembership($gid);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('FreeglePlayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals($gid, $m->getGroups()[0]);
        $this->log("Created message $id on $gid");
        $m->approve($gid);

        # Need to be a mod to see all.
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test1@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($u->login('testpw'));

        # Now generate stats for today
        $s = new Stats($this->dbhr, $this->dbhm, $gid);
        $date = date ("Y-m-d", strtotime("today"));
        $this->log("Generate for $date");
        $s->generate($date);

        $stats = $s->get($date);
        $this->assertEquals(1, $stats['ApprovedMessageCount']);
        $this->assertEquals(1, $stats['ApprovedMemberCount']);

        $this->assertEquals([ 'FDv2' => 1 ], $stats['PostMethodBreakdown']);
        $this->assertEquals([ 'Other' => 1 ], $stats['MessageBreakdown']);

        $multistats = $s->getMulti($date, [ $gid ], "30 days ago", "tomorrow");
        $this->assertEquals([
            [
                'date' => $date,
                'count' => 1
            ]
        ], $multistats['ApprovedMessageCount']);
        $this->assertEquals([
            [
                'date' => $date,
                'count' => 1
            ]
        ], $multistats['ApprovedMemberCount']);
        $this->assertEquals([], $multistats['SpamMemberCount']);
        $this->assertEquals([], $multistats['SpamMessageCount']);

        # Now yesterday - shouldn't be any
        $s = new Stats($this->dbhr, $this->dbhm, $gid);
        $date = date ("Y-m-d", strtotime("yesterday"));
        $stats = $s->get($date);
        $this->assertEquals(0, $stats['ApprovedMessageCount']);
        $this->assertEquals(0, $stats['ApprovedMemberCount']);
        $this->assertEquals([], $stats['PostMethodBreakdown']);
        $this->assertEquals([], $stats['MessageBreakdown']);
     }

    public function testHeatmap() {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $this->assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $m = new Message($this->dbhr, $this->dbhm);
        $id = $m->createDraft();
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $m->setPrivate('locationid', $fullpcid);
        $m->setPrivate('type', Message::TYPE_OFFER);
        $m->setPrivate('textbody', 'Test');

        $i = new Item($this->dbhr, $this->dbhm);
        $iid = $i->create('test item');
        $m->addItem($iid);

        list($g, $gid) = $this->createTestGroup('testgroup1', Group::GROUP_REUSE);
        $g->setSettings([ 'includearea' => FALSE ]);

        $m->constructSubject($gid);
        self::assertEquals(strtolower('OFFER: test item (TV13)'), strtolower($m->getSubject()));

        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test2@test.com', 'testpw');
        $this->dbhm->preExec("UPDATE users SET lastaccess = NOW(), lastlocation = ? WHERE id = ?", [
            $fullpcid,
            $uid
        ]);

        $s = new Stats($this->dbhr, $this->dbhm);

        $this->waitBackground();

        $map = $s->getHeatmap(Stats::HEATMAP_FLOW, 'TV13 1HH');
        $this->log("Heatmap " . var_export($map, TRUE));

        $map = $s->getHeatmap(Stats::HEATMAP_MESSAGES, 'TV13 1HH');
        $this->log("Heatmap " . var_export($map, TRUE));
        $this->assertGreaterThan(0, count($map));

        $map = $s->getHeatmap(Stats::HEATMAP_USERS, 'TV13 1HH');
        $this->log("Heatmap " . var_export($map, TRUE));
        $this->assertGreaterThan(0, count($map));
    }
}

