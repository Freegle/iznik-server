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
class simulationAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;
    private $user;
    private $uid;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        // Clean up simulation tables
        // Note: simulation_message_isochrones_sessions table has been removed
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_users;");
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_expansions;");
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_messages;");
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_runs;");

        // Create test user
        list($this->user, $this->uid) = $this->createTestUserWithLogin('Test User', 'testpw');
    }

    public function testNotLoggedIn() {
        // Test that non-logged-in users get rejected
        $ret = $this->call('simulation', 'POST', []);
        $this->assertEquals(98, $ret['ret']);
    }

    public function testNotModerator() {
        // Test that regular users (non-moderators) get rejected
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->addLoginAndLogin($u, 'testpw');

        $ret = $this->call('simulation', 'POST', []);
        $this->assertEquals(99, $ret['ret']);
    }

    public function testGetRun() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        // Create a completed simulation run
        $params = json_encode([
            'initialMinutes' => 10,
            'maxMinutes' => 60
        ]);

        $filters = json_encode([
            'startDate' => '2025-10-01',
            'endDate' => '2025-10-07'
        ]);

        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_runs
            (name, description, parameters, filters, status, completed, message_count, metrics)
            VALUES (?, ?, ?, ?, 'completed', NOW(), 5, ?)", [
            'Test Run',
            'Testing API',
            $params,
            $filters,
            json_encode(['messages_analyzed' => 5])
        ]);

        $runId = $this->dbhm->lastInsertId();

        // Call API to get run info
        $ret = $this->call('simulation', 'GET', [
            'runid' => $runId,
            'action' => 'getrun'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($runId, $ret['run']['id']);
        $this->assertEquals('Test Run', $ret['run']['name']);
        $this->assertEquals(5, $ret['run']['message_count']);
    }

    public function testGetRunMissingParams() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        // Try to get run without runid
        $ret = $this->call('simulation', 'GET', ['action' => 'getrun']);
        $this->assertEquals(1, $ret['ret']);

        // Try to get non-existent run
        $ret = $this->call('simulation', 'GET', [
            'runid' => 99999,
            'action' => 'getrun'
        ]);
        $this->assertEquals(2, $ret['ret']);
    }

    public function testGetIncompleteRun() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        // Create a pending simulation run
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_runs
            (name, parameters, filters, status) VALUES (?, ?, ?, 'pending')", [
            'Pending Run',
            '{}',
            '{}'
        ]);

        $runId = $this->dbhm->lastInsertId();

        // Try to get incomplete run
        $ret = $this->call('simulation', 'GET', [
            'runid' => $runId,
            'action' => 'getrun'
        ]);
        $this->assertEquals(3, $ret['ret']);
        $this->assertEquals('pending', $ret['run_status']);
    }

    public function testNavigateMessages() {
        $this->markTestSkipped('Transaction isolation prevents session data from persisting across API calls in tests');

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        // Create a completed simulation run
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_runs
            (name, parameters, filters, status, completed) VALUES (?, ?, ?, 'completed', NOW())", [
            'Test Run',
            '{}',
            '{}'
        ]);

        $runId = $this->dbhm->lastInsertId();

        // Create test messages
        for ($i = 0; $i < 3; $i++) {
            $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_messages
                (runid, msgid, sequence, arrival, subject, locationid, lat, lng, groupid, groupname,
                 total_group_users, total_replies_actual, metrics)
                VALUES (?, ?, ?, NOW(), ?, 1, 55.9, -3.2, 1, 'testgroup', 10, 5, ?)", [
                $runId,
                1000 + $i,
                $i,
                "Test Message $i",
                json_encode(['total_replies' => 5])
            ]);

            $simMsgId = $this->dbhm->lastInsertId();

            // Add expansion
            $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_expansions
                (sim_msgid, sequence, timestamp, minutes_after_arrival, minutes, transport,
                 users_in_isochrone, new_users_reached, replies_at_time, replies_in_isochrone, isochrone_polygon)
                VALUES (?, 0, NOW(), 0, 10, 'walk', 10, 10, 5, 5, ?)", [
                $simMsgId,
                json_encode(['type' => 'Polygon', 'coordinates' => [[[0,0], [1,0], [1,1], [0,1], [0,0]]]])
            ]);

            // Add users
            for ($j = 0; $j < 2; $j++) {
                $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_users
                    (sim_msgid, user_hash, lat, lng, in_group, replied, distance_km)
                    VALUES (?, ?, 55.9, -3.2, 1, ?, 0.5)", [
                    $simMsgId,
                    "hash_${i}_${j}",
                    $j == 0 ? 1 : 0
                ]);
            }
        }

        // Create session
        $ret = $this->call('simulation', 'POST', [
            'runid' => $runId
        ]);
        $this->assertEquals(0, $ret['ret']);
        $sessionId = $ret['session'];

        // Get current message (should be first)
        $ret = $this->call('simulation', 'GET', [
            'session' => $sessionId,
            'action' => 'current'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test Message 0', $ret['message']['subject']);
        $this->assertEquals(0, $ret['navigation']['current_index']);
        $this->assertTrue($ret['navigation']['has_next']);
        $this->assertFalse($ret['navigation']['has_prev']);
        $this->assertEquals(3, $ret['navigation']['total_messages']);

        // Navigate to next
        $ret = $this->call('simulation', 'GET', [
            'session' => $sessionId,
            'action' => 'next'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test Message 1', $ret['message']['subject']);
        $this->assertEquals(1, $ret['navigation']['current_index']);

        // Navigate to next again
        $ret = $this->call('simulation', 'GET', [
            'session' => $sessionId,
            'action' => 'next'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test Message 2', $ret['message']['subject']);
        $this->assertEquals(2, $ret['navigation']['current_index']);
        $this->assertFalse($ret['navigation']['has_next']);
        $this->assertTrue($ret['navigation']['has_prev']);

        // Navigate to prev
        $ret = $this->call('simulation', 'GET', [
            'session' => $sessionId,
            'action' => 'prev'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test Message 1', $ret['message']['subject']);
        $this->assertEquals(1, $ret['navigation']['current_index']);

        // Navigate by index
        $ret = $this->call('simulation', 'GET', [
            'session' => $sessionId,
            'action' => 'index',
            'index' => 0
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test Message 0', $ret['message']['subject']);
        $this->assertEquals(0, $ret['navigation']['current_index']);
    }

    public function testGetMessageWithoutRunid() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        // Try to get message without runid
        $ret = $this->call('simulation', 'GET', []);
        $this->assertEquals(1, $ret['ret']);
    }

    public function testGetMessageInvalidRun() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        // Try to get message with invalid runid
        $ret = $this->call('simulation', 'GET', [
            'runid' => 99999,
            'index' => 0
        ]);
        $this->assertEquals(2, $ret['ret']);
    }

    public function testResponseStructure() {
        $this->markTestSkipped('Transaction isolation prevents session data from persisting across API calls in tests');

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        // Create a completed simulation run with full data
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_runs
            (name, parameters, filters, status, completed) VALUES (?, ?, ?, 'completed', NOW())", [
            'Test Run',
            '{}',
            '{}'
        ]);

        $runId = $this->dbhm->lastInsertId();

        // Create message with full GeoJSON data
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_messages
            (runid, msgid, sequence, arrival, subject, locationid, lat, lng, groupid, groupname,
             group_cga_polygon, total_group_users, total_replies_actual, metrics)
            VALUES (?, 1001, 0, NOW(), 'Test Message', 1, 55.9, -3.2, 1, 'testgroup', ?, 10, 5, ?)", [
            $runId,
            json_encode(['type' => 'Polygon', 'coordinates' => [[[0,0], [1,0], [1,1], [0,1], [0,0]]]]),
            json_encode(['total_replies' => 5, 'capture_rate' => 80.0])
        ]);

        $simMsgId = $this->dbhm->lastInsertId();

        // Add expansion with polygon
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_expansions
            (sim_msgid, sequence, timestamp, minutes_after_arrival, minutes, transport,
             users_in_isochrone, new_users_reached, replies_at_time, replies_in_isochrone, isochrone_polygon)
            VALUES (?, 0, NOW(), 0, 10, 'walk', 10, 10, 5, 5, ?)", [
            $simMsgId,
            json_encode(['type' => 'Polygon', 'coordinates' => [[[0,0], [0.1,0], [0.1,0.1], [0,0.1], [0,0]]]])
        ]);

        // Add user
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_users
            (sim_msgid, user_hash, lat, lng, in_group, replied, reply_time, reply_minutes, distance_km)
            VALUES (?, 'hash123', 55.9, -3.2, 1, 1, NOW(), 15, 0.5)", [
            $simMsgId
        ]);

        // Create session and get message
        $ret = $this->call('simulation', 'POST', ['runid' => $runId]);
        $sessionId = $ret['session'];

        $ret = $this->call('simulation', 'GET', ['session' => $sessionId]);

        // Verify response structure
        $this->assertEquals(0, $ret['ret']);

        // Check message structure
        $this->assertArrayHasKey('message', $ret);
        $this->assertArrayHasKey('subject', $ret['message']);
        $this->assertArrayHasKey('location', $ret['message']);
        $this->assertArrayHasKey('lat', $ret['message']['location']);
        $this->assertArrayHasKey('lng', $ret['message']['location']);
        $this->assertArrayHasKey('metrics', $ret['message']);

        // Check group_cga structure
        $this->assertArrayHasKey('group_cga', $ret);
        $this->assertArrayHasKey('type', $ret['group_cga']);
        $this->assertEquals('Feature', $ret['group_cga']['type']);
        $this->assertArrayHasKey('geometry', $ret['group_cga']);

        // Check expansions structure
        $this->assertArrayHasKey('expansions', $ret);
        $this->assertGreaterThan(0, count($ret['expansions']));
        $this->assertArrayHasKey('sequence', $ret['expansions'][0]);
        $this->assertArrayHasKey('minutes', $ret['expansions'][0]);
        $this->assertArrayHasKey('geometry', $ret['expansions'][0]);
        $this->assertEquals('Feature', $ret['expansions'][0]['geometry']['type']);

        // Check users structure (GeoJSON FeatureCollection)
        $this->assertArrayHasKey('users', $ret);
        $this->assertEquals('FeatureCollection', $ret['users']['type']);
        $this->assertArrayHasKey('features', $ret['users']);
        $this->assertGreaterThan(0, count($ret['users']['features']));

        $user = $ret['users']['features'][0];
        $this->assertEquals('Feature', $user['type']);
        $this->assertArrayHasKey('geometry', $user);
        $this->assertEquals('Point', $user['geometry']['type']);
        $this->assertArrayHasKey('properties', $user);
        $this->assertArrayHasKey('replied', $user['properties']);
        $this->assertEquals(TRUE, $user['properties']['replied']);
    }
}
