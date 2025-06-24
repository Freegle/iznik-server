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
class ReachVolunteeringTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp(): void {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $g = Group::get($dbhr, $dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        
        // Enable volunteering on the test group
        $g = Group::get($this->dbhr, $this->dbhm, $this->groupid);
        $g->setSettings(['volunteering' => 1]);

        // Clean up any existing test data
        $this->dbhm->preExec("DELETE FROM volunteering WHERE externalid LIKE 'reach-test-%';");
    }

    protected function tearDown(): void {
        parent::tearDown();
        $this->dbhm->preExec("DELETE FROM volunteering WHERE externalid LIKE 'reach-test-%';");
    }

    // Mock class that extends ReachVolunteering to override fetchFeedData for testing
    private function getMockReachVolunteering($useNewFieldNames, $mockData) {
        return new class($this->dbhr, $this->dbhm, $useNewFieldNames, $mockData) extends ReachVolunteering {
            private $mockData;
            
            public function __construct($dbhr, $dbhm, $useNewFieldNames, $mockData) {
                parent::__construct($dbhr, $dbhm, $useNewFieldNames);
                $this->mockData = $mockData;
            }
            
            protected function fetchFeedData($feedUrl) {
                return $this->mockData;
            }
        };
    }

    public function testOldFieldNameFormat() {
        $oldFormatData = json_encode([
            'Opportunities' => [
                [
                    'Opportunity' => [
                        'title' => 'Test Volunteer Role',
                        'Posting date' => date('Y-m-d H:i:s'),
                        'Job id' => 'test-123',
                        'summary' => 'Test summary',
                        'Job description' => 'Test description for volunteer role',
                        'Person specification' => 'Test person spec',
                        'What impact the opportunity will have' => 'Test impact',
                        'Other details' => 'Test other details',
                        'Location' => 'Test Location EH3 6SS',
                        'Required skills' => 'Test skills',
                        'Organisation' => 'Test Organisation',
                        'Charity sector' => 'Test charity sector',
                        'Organisation activities' => 'Test activities',
                        'Organisation objective' => 'Test objectives',
                        'Apply url' => 'https://test.example.com/apply'
                    ]
                ]
            ]
        ]);

        $reach = $this->getMockReachVolunteering(false, $oldFormatData);
        $reach->processFeed('http://test.example.com/feed');

        // Check that the volunteering opportunity was created
        $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ['reach-test-123']);
        $this->assertEquals(1, count($result));
        
        $opportunity = $result[0];
        $this->assertEquals('Test Volunteer Role', $opportunity['title']);
        $this->assertEquals('Test description for volunteer role', $opportunity['description']);
        $this->assertEquals('https://test.example.com/apply', $opportunity['contacturl']);
        $this->assertEquals('Test Location EH3 6SS', $opportunity['location']);
        $this->assertEquals('Test other details', $opportunity['timecommitment']);
    }

    public function testNewFieldNameFormat() {
        $newFormatData = json_encode([
            [
                'title' => 'Test New Format Role',
                'date_posted' => date('Y-m-d H:i:s'),
                'job_id' => 'test-456',
                'summary' => 'Test new summary',
                'description' => 'Test new description for volunteer role',
                'person_description' => 'Test new person spec',
                'person_impact' => 'Test new impact',
                'other_details' => 'Test new other details',
                'town' => 'Test Town',
                'postcode' => 'EH3 6SS',
                'skills' => 'Test new skills',
                'organisation' => 'Test New Organisation',
                'causes' => 'Test new charity sector',
                'activities' => 'Test new activities',
                'objectives' => 'Test new objectives',
                'url' => 'https://test-new.example.com/apply'
            ]
        ]);

        $reach = $this->getMockReachVolunteering(true, $newFormatData);
        $reach->processFeed('http://test.example.com/feed');

        // Check that the volunteering opportunity was created
        $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ['reach-test-456']);
        $this->assertEquals(1, count($result));
        
        $opportunity = $result[0];
        $this->assertEquals('Test New Format Role', $opportunity['title']);
        $this->assertEquals('Test new description for volunteer role', $opportunity['description']);
        $this->assertEquals('https://test-new.example.com/apply', $opportunity['contacturl']);
        $this->assertEquals('Test Town EH3 6SS', $opportunity['location']);
        $this->assertEquals('Test new other details', $opportunity['timecommitment']);
    }

    public function testOldFormatLocationExtraction() {
        // Test that postcode extraction works with old format
        $oldFormatData = json_encode([
            'Opportunities' => [
                [
                    'Opportunity' => [
                        'title' => 'Test Location Extraction',
                        'Posting date' => date('Y-m-d H:i:s'),
                        'Job id' => 'test-location-789',
                        'summary' => 'Test summary',
                        'Job description' => 'Test description',
                        'Apply url' => 'https://test.example.com/apply',
                        'Location' => 'Some address in Edinburgh EH3 6SS with extra text',
                    ]
                ]
            ]
        ]);

        $reach = $this->getMockReachVolunteering(false, $oldFormatData);
        $reach->processFeed('http://test.example.com/feed');

        // Check that the opportunity was created (postcode was extracted correctly)
        $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ['reach-test-location-789']);
        $this->assertEquals(1, count($result));
        
        $opportunity = $result[0];
        $this->assertEquals('Test Location Extraction', $opportunity['title']);
        $this->assertEquals('Some address in Edinburgh EH3 6SS with extra text', $opportunity['location']);
    }

    public function testExpiredOpportunitySkipped() {
        // Create an opportunity with old posting date
        $oldDate = date('Y-m-d H:i:s', time() - (Volunteering::EXPIRE_AGE + 1) * 24 * 60 * 60);
        
        $oldFormatData = json_encode([
            'Opportunities' => [
                [
                    'Opportunity' => [
                        'title' => 'Test Expired Role',
                        'Posting date' => $oldDate,
                        'Job id' => 'test-expired-999',
                        'summary' => 'Test summary',
                        'Job description' => 'Test description',
                        'Apply url' => 'https://test.example.com/apply',
                        'Location' => 'Test Location EH3 6SS',
                    ]
                ]
            ]
        ]);

        $reach = $this->getMockReachVolunteering(false, $oldFormatData);
        $reach->processFeed('http://test.example.com/feed');

        // Check that the opportunity was NOT created (too old)
        $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ['reach-test-expired-999']);
        $this->assertEquals(0, count($result));
    }

    public function testFieldMapping() {
        $reach = new ReachVolunteering($this->dbhr, $this->dbhm, false);
        $reflection = new \ReflectionClass($reach);
        $method = $reflection->getMethod('getFieldMapping');
        $method->setAccessible(true);

        // Test old field mapping
        $oldMapping = $method->invoke($reach);
        $this->assertEquals('Job id', $oldMapping['job_id']);
        $this->assertEquals('Job description', $oldMapping['description']);
        $this->assertEquals('Apply url', $oldMapping['url']);

        // Test new field mapping
        $reachNew = new ReachVolunteering($this->dbhr, $this->dbhm, true);
        $newMapping = $method->invoke($reachNew);
        $this->assertEquals('job_id', $newMapping['job_id']);
        $this->assertEquals('description', $newMapping['description']);
        $this->assertEquals('url', $newMapping['url']);
    }
}