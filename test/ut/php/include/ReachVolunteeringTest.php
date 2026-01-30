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

        $reach = $this->getMockReachVolunteering(FALSE, $oldFormatData);
        $reach->processFeed('http://test.example.com/feed');

        // Check that the volunteering opportunity was created
        $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ['reach-test-123']);
        $this->assertEquals(1, count($result));
        
        $opportunity = $result[0];
        $this->assertEquals('Test Volunteer Role', $opportunity['title']);
        $this->assertEquals("Posted by Test Organisation.\n\nTest description for volunteer role", $opportunity['description']);
        $this->assertEquals('https://test.example.com/apply', $opportunity['contacturl']);
        $this->assertEquals('Test Location EH3 6SS', $opportunity['location']);
        $this->assertEquals('Test other details', $opportunity['timecommitment']);
    }

    public function testNewFieldNameFormat() {
        // Test with the actual JSON format that has combined location field
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
                'location' => 'Test Town, EH3 6SS, United Kingdom',  // Combined location field
                'skills' => 'Test new skills',
                'organisation' => 'Test New Organisation',
                'causes' => 'Test new charity sector',
                'activities' => 'Test new activities',
                'objectives' => 'Test new objectives',
                'url' => 'https://test-new.example.com/apply'
            ]
        ]);

        $reach = $this->getMockReachVolunteering(TRUE, $newFormatData);
        $reach->processFeed('http://test.example.com/feed');

        // Check that the volunteering opportunity was created
        $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ['reach-test-456']);
        $this->assertEquals(1, count($result));
        
        $opportunity = $result[0];
        $this->assertEquals('Test New Format Role', $opportunity['title']);
        $this->assertEquals("Posted by Test New Organisation.\n\nTest new description for volunteer role", $opportunity['description']);
        $this->assertEquals('https://test-new.example.com/apply', $opportunity['contacturl']);
        $this->assertEquals('Test Town, EH3 6SS', $opportunity['location']);
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

        $reach = $this->getMockReachVolunteering(FALSE, $oldFormatData);
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

        $reach = $this->getMockReachVolunteering(FALSE, $oldFormatData);
        $reach->processFeed('http://test.example.com/feed');

        // Check that the opportunity was NOT created (too old)
        $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ['reach-test-expired-999']);
        $this->assertEquals(0, count($result));
    }

    public function testFieldMapping() {
        $reflection = new \ReflectionClass(ReachVolunteering::class);
        $method = $reflection->getMethod('getFieldMapping');
        $method->setAccessible(TRUE);

        // Test old field mapping
        $reachOld = new ReachVolunteering($this->dbhr, $this->dbhm, FALSE);
        $oldMapping = $method->invoke($reachOld);
        $this->assertEquals('Job id', $oldMapping['job_id']);
        $this->assertEquals('Job description', $oldMapping['description']);
        $this->assertEquals('Apply url', $oldMapping['url']);

        // Test new field mapping
        $reachNew = new ReachVolunteering($this->dbhr, $this->dbhm, TRUE);
        $newMapping = $method->invoke($reachNew);
        $this->assertEquals('job_id', $newMapping['job_id']);
        $this->assertEquals('description', $newMapping['description']);
        $this->assertEquals('url', $newMapping['url']);
        // Test that location is mapped correctly for town (postcode field not needed in new format)
        $this->assertEquals('location', $newMapping['town']);
    }

    public function testActualFeedFormatParsing() {
        // Test with data structure matching the actual feed format
        $actualFeedData = json_encode([
            [
                "title" => "Community Garden Volunteer",
                "date_posted" => date('Y-m-d'),
                "job_id" => "test-garden-123",
                "summary" => "Help maintain our community garden and grow fresh produce for local families.",
                "description" => "We are looking for enthusiastic volunteers to help with our community garden project. Tasks include planting, weeding, watering, and harvesting vegetables that are distributed to local food banks.",
                "person_description" => "Someone who enjoys working outdoors and has an interest in sustainable living.",
                "person_impact" => "Your work will help provide fresh, healthy food to families in need in our local community.",
                "other_details" => "Flexible hours, weekends preferred. Training provided.",
                "location" => "Edinburgh EH3 6SS, United Kingdom",
                "skills" => "No experience necessary, enthusiasm for gardening helpful",
                "organisation" => "Green Spaces Community Project",
                "causes" => "Environment, Community",
                "activities" => "Gardening, food production",
                "objectives" => "Reduce food waste and provide fresh produce to those in need",
                "url" => "https://example.com/volunteer/garden-123"
            ],
            [
                "title" => "Digital Skills Trainer",
                "date_posted" => date('Y-m-d'),
                "job_id" => "test-digital-456", 
                "summary" => "Teach basic computer and internet skills to older adults",
                "description" => "Help bridge the digital divide by teaching older adults how to use computers, tablets, and smartphones. Sessions cover email, video calls, online shopping, and staying safe online.",
                "person_description" => "Patient, friendly person with good communication skills and computer knowledge",
                "person_impact" => "Help older adults stay connected with family and access important services online",
                "other_details" => "2-3 hours per week, weekday mornings",
                "location" => "Edinburgh EH3 6SS, United Kingdom",
                "skills" => "Computer literacy, teaching or training experience helpful but not essential",
                "organisation" => "Age Friendly Digital Hub",
                "causes" => "Education, Digital Inclusion",
                "activities" => "Teaching, mentoring, technology support",
                "objectives" => "Improve digital skills and reduce social isolation among older adults",
                "url" => "https://example.com/volunteer/digital-456"
            ]
        ]);

        $reach = $this->getMockReachVolunteering(TRUE, $actualFeedData);
        $reach->processFeed('http://test.example.com/feed');

        // Check both opportunities were created
        $results = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid IN (?, ?) ORDER BY externalid", 
                                       ['reach-test-garden-123', 'reach-test-digital-456']);
        $this->assertEquals(2, count($results));
        
        // Check first opportunity (garden)
        $garden = $results[1]; // reach-test-garden-123 comes second alphabetically
        $this->assertEquals('Community Garden Volunteer', $garden['title']);
        $this->assertEquals('Edinburgh EH3 6SS', $garden['location']);
        $this->assertEquals('https://example.com/volunteer/garden-123', $garden['contacturl']);

        // Check second opportunity (digital)
        $digital = $results[0]; // reach-test-digital-456 comes first alphabetically
        $this->assertEquals('Digital Skills Trainer', $digital['title']);
        $this->assertEquals('Edinburgh EH3 6SS', $digital['location']);
        $this->assertEquals('https://example.com/volunteer/digital-456', $digital['contacturl']);
    }

    public function testLocationFieldHandling() {
        // Test various location formats that might appear in the feed
        $testCases = [
            'Edinburgh, EH3 6SS, United Kingdom',
            'Edinburh, EH3 6SS, UK',
            'Edinburgh EH3 6SS',
            'Edinburgh, EH3 6SS, Scotland',
        ];
        
        foreach ($testCases as $index => $location) {
            $testData = json_encode([
                [
                    "title" => "Test Location $index",
                    "date_posted" => date('Y-m-d'),
                    "job_id" => "test-location-$index",
                    "summary" => "Test summary",
                    "description" => "Test description", 
                    "location" => $location,
                    "url" => "https://example.com/test-$index"
                ]
            ]);

            $reach = $this->getMockReachVolunteering(TRUE, $testData);
            $reach->processFeed('http://test.example.com/feed');

            // Check that opportunity was created with correct location
            $result = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", ["reach-test-location-$index"]);
            
            if (preg_match(Utils::POSTCODE_PATTERN, $location)) {
                // Should be created if valid postcode found
                $this->assertEquals(1, count($result), "Location '$location' should have been processed");
                // Country suffix is stripped by processFeed
                $expectedLocation = preg_replace('/,\s*(United Kingdom|UK|England|Scotland|Wales|Northern Ireland)\s*$/i', '', $location);
                $this->assertEquals($expectedLocation, $result[0]['location'], "Location should match with country stripped");
            } else {
                // Should be skipped if no valid postcode
                $this->assertEquals(0, count($result), "Location '$location' should have been skipped (no valid postcode)");
            }
            
            // Clean up for next iteration
            $this->dbhm->preExec("DELETE FROM volunteering WHERE externalid = ?", ["reach-test-location-$index"]);
        }
    }
}