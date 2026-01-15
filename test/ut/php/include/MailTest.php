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
class MailTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM returnpath_seedlist WHERE email LIKE 'test@test.com';");
    }

    public function testBasic() {
        list($user, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->dbhm->preExec("INSERT INTO `returnpath_seedlist` (`id`, `timestamp`, `email`, `userid`, `type`, `active`, `oneshot`) VALUES (NULL, CURRENT_TIMESTAMP, 'test@test.com', $uid, 'ReturnPath', '1', '1')");
        $seeds = Mail::getSeeds($this->dbhr, $this->dbhm);
        $found = FALSE;

        foreach ($seeds as $seed) {
            if (strcmp($seed['email'], 'test@test.com') === 0) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);
    }

    public function testRealEmailTrue() {
        // Real email addresses should return TRUE.
        $this->assertTrue(Mail::realEmail('user@gmail.com'));
        $this->assertTrue(Mail::realEmail('test@yahoo.com'));
        $this->assertTrue(Mail::realEmail('someone@example.org'));
    }

    public function testRealEmailFalseUserDomain() {
        // Email addresses matching USER_DOMAIN should return FALSE.
        $this->assertFalse(Mail::realEmail('test@' . USER_DOMAIN));
    }

    public function testRealEmailFalseFbuser() {
        // Email addresses containing fbuser should return FALSE.
        $this->assertFalse(Mail::realEmail('fbuser123@example.com'));
    }

    public function testRealEmailFalseTrashnothing() {
        // Email addresses from trashnothing.com should return FALSE.
        $this->assertFalse(Mail::realEmail('user@trashnothing.com'));
    }

    public function testRealEmailFalseIlovefreegle() {
        // Email addresses from ilovefreegle.org should return FALSE.
        $this->assertFalse(Mail::realEmail('test@ilovefreegle.org'));
    }

    public function testRealEmailFalseModtools() {
        // Email addresses from modtools.org should return FALSE.
        $this->assertFalse(Mail::realEmail('mod@modtools.org'));
    }

    public function testOurDomainTrue() {
        // Email addresses from OURDOMAINS should return TRUE.
        $ourdomains = explode(',', OURDOMAINS);
        if (count($ourdomains) > 0) {
            $testEmail = 'test@' . $ourdomains[0];
            $this->assertTrue(Mail::ourDomain($testEmail));
        }
    }

    public function testOurDomainFalse() {
        // External email addresses should return FALSE.
        $this->assertFalse(Mail::ourDomain('user@gmail.com'));
        $this->assertFalse(Mail::ourDomain('test@yahoo.com'));
        $this->assertFalse(Mail::ourDomain('someone@external.org'));
    }

    public function testGetDescriptionReturnsValue() {
        // Test that getDescription returns a value for valid types.
        $types = [
            Mail::EVENTS,
            Mail::VOLUNTEERING,
            Mail::CHAT,
            Mail::NOTIFICATIONS
        ];

        foreach ($types as $type) {
            $desc = Mail::getDescription($type);
            $this->assertNotNull($desc, "Description for type $type should not be null");
            $this->assertIsString($desc, "Description for type $type should be a string");
        }
    }

    public function testMatchingIdFormat() {
        // Test that matchingId returns a properly formatted string.
        $id = Mail::matchingId(Mail::EVENTS, 123);

        // Should start with 'freegle'.
        $this->assertStringStartsWith('freegle', $id);

        // Should contain the type.
        $this->assertStringContainsString((string)Mail::EVENTS, $id);

        // Should be consistent for the same inputs (based on week).
        $id2 = Mail::matchingId(Mail::EVENTS, 123);
        $this->assertEquals($id, $id2);
    }

    public function testMatchingIdNegativeQualifier() {
        // Test matchingId with a negative qualifier.
        $id = Mail::matchingId(Mail::CHAT, -1);

        // Should start with 'freegle'.
        $this->assertStringStartsWith('freegle', $id);

        // Should handle negative qualifier (100 + qualifier = 99, then str_pad pads right to '990').
        $this->assertStringContainsString('990', $id);
    }

    public function testMatchingIdZeroQualifier() {
        // Test matchingId with zero qualifier.
        $id = Mail::matchingId(Mail::CHAT, 0);

        // Should start with 'freegle'.
        $this->assertStringStartsWith('freegle', $id);

        // Should pad with zeros.
        $this->assertStringContainsString('000', $id);
    }
}

