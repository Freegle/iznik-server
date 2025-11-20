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
class freebieAlertsTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    public function testBasic() {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_FREEGLE);

        # Create member.
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        list($member, $memberid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MEMBER, 'Test','User', 'Test User', $email, 'testpw');
        $member->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => TRUE,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        $f = new FreebieAlerts($this->dbhr, $this->dbhm);
        $f->add($mid);

        # Check add/remove from spatial kicks background.  We can't check the parameters because some (like 'queued'
        # vary, but should result in two calls.
        $this->waitBackground();
        $this->dbhm->preExec("DELETE FROM messages_spatial WHERE msgid = ?;", [
            $mid
        ]);

        $m = new Message($this->dbhr, $this->dbhm);

        $mock = $this->getMockBuilder('Pheanstalk\Pheanstalk')
            ->disableOriginalConstructor()
            ->setMethods(array('put'))
            ->getMock();

        $mock->expects($this->exactly(2))->method('put');

        $m->setPheanstalk($mock);

        $m->updateSpatialIndex();
        $m->markSuccessfulInSpatial($mid);

        # Manually test calls.  Won't actually call CURL because key is NULL so will return 0.
        $f = new FreebieAlerts($this->dbhr, $this->dbhm);
        $this->assertEquals(0, $f->add($mid));
        $this->assertEquals(0, $f->remove($mid));
    }

    public function testSkipIneligibleMessages() {
        # Test that non-offer messages are skipped
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_FREEGLE);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        list($member, $memberid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MEMBER, 'Test','User', 'Test User', $email, 'testpw');

        # Create a WANTED message (not an offer)
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Wanted',
            'item' => 'a thing',
            'groupid' => $gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => TRUE,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        $f = new FreebieAlerts($this->dbhr, $this->dbhm);
        $result = $f->add($mid);
        # Should return NULL because it's not an offer
        $this->assertNull($result);
    }

    public function testSkipMessagesWithoutLocation() {
        # Test that messages without lat/lng are skipped by directly inserting without location
        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_FREEGLE);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        list($member, $memberid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MEMBER, 'Test','User', 'Test User', $email, 'testpw');

        # Insert a message directly without location
        $msgid = $this->dbhm->preExec(
            "INSERT INTO messages (fromaddr, envelopefrom, fromname, fromip, date, message, subject, type, textbody)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?);",
            [$email, $email, 'Test User', '1.2.3.4', 'test message', 'Test offer', Message::TYPE_OFFER, 'Test body']
        );
        $msgid = $this->dbhm->lastInsertId();

        # Add to group
        $this->dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, ?, NOW());", [
            $msgid, $gid, MessageCollection::APPROVED
        ]);

        # Try to add to freebie alerts - should skip because no lat/lng
        $f = new FreebieAlerts($this->dbhr, $this->dbhm);
        $result = $f->add($msgid);
        # Should return NULL because it has no location
        $this->assertNull($result);
    }

    public function testSkipMessagesWithOutcome() {
        # Test that messages with outcomes are skipped
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_FREEGLE);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        list($member, $memberid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MEMBER, 'Test','User', 'Test User', $email, 'testpw');

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => TRUE,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Mark as taken
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->mark(Message::OUTCOME_TAKEN, NULL, NULL, NULL);

        $f = new FreebieAlerts($this->dbhr, $this->dbhm);
        $result = $f->add($mid);
        # Should return NULL because it has an outcome
        $this->assertNull($result);
    }
}