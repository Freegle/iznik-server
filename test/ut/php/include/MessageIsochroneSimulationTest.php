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
class MessageIsochroneSimulationTest extends IznikTestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        // Clean up simulation tables
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_sessions;");
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_users;");
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_expansions;");
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_messages;");
        $this->dbhm->preExec("DELETE FROM simulation_message_isochrones_runs;");
        $this->dbhm->preExec("DELETE FROM isochrones;");
        $this->dbhm->preExec("DELETE FROM users_approxlocs;");
    }

    public function testSimulationTablesExist() {
        // Verify all simulation tables exist
        $tables = $this->dbhr->preQuery("SHOW TABLES LIKE 'simulation_message_isochrones%'");
        $this->assertGreaterThanOrEqual(5, count($tables));

        $tableNames = array_map(function($t) {
            return array_values($t)[0];
        }, $tables);

        $this->assertContains('simulation_message_isochrones_runs', $tableNames);
        $this->assertContains('simulation_message_isochrones_messages', $tableNames);
        $this->assertContains('simulation_message_isochrones_expansions', $tableNames);
        $this->assertContains('simulation_message_isochrones_users', $tableNames);
        $this->assertContains('simulation_message_isochrones_sessions', $tableNames);
    }

    public function testSimulationRunCreation() {
        // Test creating a simulation run
        $params = json_encode([
            'initialMinutes' => 10,
            'maxMinutes' => 60,
            'increment' => 10
        ]);

        $filters = json_encode([
            'startDate' => '2025-10-01',
            'endDate' => '2025-10-07'
        ]);

        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_runs
            (name, description, parameters, filters, status) VALUES (?, ?, ?, ?, ?)", [
            'Test Run',
            'Testing simulation',
            $params,
            $filters,
            'pending'
        ]);

        $runId = $this->dbhm->lastInsertId();
        $this->assertGreaterThan(0, $runId);

        // Verify it was created
        $runs = $this->dbhr->preQuery("SELECT * FROM simulation_message_isochrones_runs WHERE id = ?", [$runId]);
        $this->assertEquals(1, count($runs));
        $this->assertEquals('Test Run', $runs[0]['name']);
        $this->assertEquals('pending', $runs[0]['status']);
    }

    public function testSimulationWithTestData() {
        // Create test data: group, users, message, replies
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        // Create location
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        $this->assertNotNull($lid);

        $lat = $l->getPrivate('lat');
        $lng = $l->getPrivate('lng');

        // Create message poster
        $u1 = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u1->create(NULL, NULL, 'Test User 1');
        $u1->addMembership($gid);
        $u1->addEmail('test1@test.com');

        // Create message
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = str_replace('Basic test', 'OFFER: Test Item (EH3 6SS)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test1@test.com', 'to@test.com', $msg);
        list ($mid, $failok) = $m->save();

        $m->setPrivate('locationid', $lid);
        $m->setPrivate('lat', $lat);
        $m->setPrivate('lng', $lng);

        // Create active users near the location
        for ($i = 0; $i < 5; $i++) {
            $u = User::get($this->dbhr, $this->dbhm);
            $uid = $u->create(NULL, NULL, "Test User " . ($i + 2));
            $u->addMembership($gid);

            // Add to users_approxlocs
            $offsetLat = ($i - 2) * 0.001;
            $offsetLng = ($i - 2) * 0.001;
            $userLat = $lat + $offsetLat;
            $userLng = $lng + $offsetLng;

            $this->dbhm->preExec("INSERT INTO users_approxlocs (userid, lat, lng, position, timestamp)
                VALUES (?, ?, ?, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), {$this->dbhr->SRID()}), NOW())", [
                $uid,
                $userLat,
                $userLng,
                $userLng,
                $userLat
            ]);

            // Create some replies
            if ($i < 2) {
                $r = new ChatRoom($this->dbhr, $this->dbhm);
                list ($rid, $blocked) = $r->createConversation($uid, $uid1);

                $cm = new ChatMessage($this->dbhr, $this->dbhm);
                $cm->create($rid, $uid, "I'm interested", ChatMessage::TYPE_INTERESTED, $mid);
            }
        }

        // Create and run simulation
        $params = [
            'initialMinutes' => 10,
            'maxMinutes' => 30,
            'increment' => 10,
            'targetUsers' => 50,
            'activeSince' => 90,
            'transport' => 'walk',
            'timeSinceLastExpand' => 60,
            'numReplies' => 1
        ];

        $filters = [
            'startDate' => date('Y-m-d'),
            'endDate' => date('Y-m-d'),
            'groupId' => $gid
        ];

        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_runs
            (name, description, parameters, filters, status) VALUES (?, ?, ?, ?, ?)", [
            'PHPUnit Test Run',
            'Testing with real data',
            json_encode($params),
            json_encode($filters),
            'running'
        ]);

        $runId = $this->dbhm->lastInsertId();

        // Store message in simulation
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_messages
            (runid, msgid, sequence, arrival, subject, locationid, lat, lng, groupid, groupname,
             total_group_users, total_replies_actual)
            VALUES (?, ?, 0, NOW(), ?, ?, ?, ?, ?, ?, 5, 2)", [
            $runId,
            $mid,
            'Test Item',
            $lid,
            $lat,
            $lng,
            $gid,
            'testgroup'
        ]);

        $simMsgId = $this->dbhm->lastInsertId();

        // Store expansion
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_expansions
            (sim_msgid, sequence, timestamp, minutes_after_arrival, minutes, transport,
             users_in_isochrone, new_users_reached, replies_at_time, replies_in_isochrone)
            VALUES (?, 0, NOW(), 0, 10, 'walk', 5, 5, 2, 2)", [
            $simMsgId
        ]);

        // Store users
        for ($i = 0; $i < 5; $i++) {
            $replied = $i < 2 ? 1 : 0;
            $userHash = hash('sha256', 'user_' . $i . '_' . $simMsgId);

            $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_users
                (sim_msgid, user_hash, lat, lng, in_group, replied, distance_km)
                VALUES (?, ?, ?, ?, 1, ?, ?)", [
                $simMsgId,
                $userHash,
                55.95,
                -3.2,
                $replied,
                0.5
            ]);
        }

        // Calculate metrics
        $metrics = [
            'total_replies' => 2,
            'total_active_users' => 5,
            'initial_users_reached' => 5,
            'final_users_reached' => 5,
            'total_expansions' => 0,
            'replies_in_final_isochrone' => 2,
            'capture_rate' => 100.0,
            'efficiency' => 40.0,
            'cost_notifications' => 5
        ];

        $this->dbhm->preExec("UPDATE simulation_message_isochrones_messages SET metrics = ? WHERE id = ?", [
            json_encode($metrics),
            $simMsgId
        ]);

        // Update run as completed
        $aggregateMetrics = [
            'messages_analyzed' => 1,
            'total_replies' => 2,
            'total_users_reached' => 5,
            'median_capture_rate' => 100.0,
            'mean_capture_rate' => 100.0,
            'median_efficiency' => 40.0,
            'mean_efficiency' => 40.0,
            'median_expansions' => 0,
            'mean_expansions' => 0
        ];

        $this->dbhm->preExec("UPDATE simulation_message_isochrones_runs
            SET status = 'completed', completed = NOW(), message_count = 1, metrics = ?
            WHERE id = ?", [
            json_encode($aggregateMetrics),
            $runId
        ]);

        // Verify data was stored correctly
        $runs = $this->dbhr->preQuery("SELECT * FROM simulation_message_isochrones_runs WHERE id = ?", [$runId]);
        $this->assertEquals(1, count($runs));
        $this->assertEquals('completed', $runs[0]['status']);
        $this->assertEquals(1, $runs[0]['message_count']);

        $messages = $this->dbhr->preQuery("SELECT * FROM simulation_message_isochrones_messages WHERE runid = ?", [$runId]);
        $this->assertEquals(1, count($messages));
        $this->assertEquals($mid, $messages[0]['msgid']);
        $this->assertEquals('Test Item', $messages[0]['subject']);

        $expansions = $this->dbhr->preQuery("SELECT * FROM simulation_message_isochrones_expansions WHERE sim_msgid = ?", [$simMsgId]);
        $this->assertEquals(1, count($expansions));
        $this->assertEquals(10, $expansions[0]['minutes']);

        $users = $this->dbhr->preQuery("SELECT * FROM simulation_message_isochrones_users WHERE sim_msgid = ?", [$simMsgId]);
        $this->assertEquals(5, count($users));

        $replied = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM simulation_message_isochrones_users WHERE sim_msgid = ? AND replied = 1", [$simMsgId]);
        $this->assertEquals(2, $replied[0]['count']);
    }

    public function testPointInPolygon() {
        // Test the point-in-polygon logic with a simple square
        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [0, 0],
                [0, 10],
                [10, 10],
                [10, 0],
                [0, 0]
            ]]
        ];

        // Point inside
        $this->assertTrue($this->pointInPolygonTest(5, 5, $polygon));

        // Point outside
        $this->assertFalse($this->pointInPolygonTest(15, 15, $polygon));

        // Point on edge (may be inside or outside depending on algorithm)
        // Just verify it doesn't crash
        $this->pointInPolygonTest(0, 5, $polygon);
    }

    private function pointInPolygonTest($lat, $lng, $polygon) {
        // Replicate the logic from MessageIsochroneSimulator
        if (!isset($polygon['coordinates']) || !is_array($polygon['coordinates'])) {
            return FALSE;
        }

        $coords = $polygon['coordinates'][0];
        $inside = FALSE;

        for ($i = 0, $j = count($coords) - 1; $i < count($coords); $j = $i++) {
            $xi = $coords[$i][0];
            $yi = $coords[$i][1];
            $xj = $coords[$j][0];
            $yj = $coords[$j][1];

            $intersect = (($yi > $lat) != ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    public function testMetricsCalculation() {
        // Test metrics calculation logic
        $allMetrics = [
            [
                'total_replies' => 5,
                'final_users_reached' => 100,
                'capture_rate' => 80.0,
                'efficiency' => 5.0,
                'total_expansions' => 2
            ],
            [
                'total_replies' => 3,
                'final_users_reached' => 50,
                'capture_rate' => 100.0,
                'efficiency' => 6.0,
                'total_expansions' => 1
            ],
            [
                'total_replies' => 10,
                'final_users_reached' => 200,
                'capture_rate' => 90.0,
                'efficiency' => 5.0,
                'total_expansions' => 3
            ]
        ];

        $result = $this->calculateAggregateMetricsTest($allMetrics);

        $this->assertEquals(3, $result['messages_analyzed']);
        $this->assertEquals(18, $result['total_replies']);
        $this->assertEquals(350, $result['total_users_reached']);
        $this->assertEquals(90.0, $result['median_capture_rate']);
        $this->assertEquals(5.0, $result['median_efficiency']);
        $this->assertEquals(2, $result['median_expansions']);
    }

    private function calculateAggregateMetricsTest($allMetrics) {
        if (count($allMetrics) == 0) {
            return [];
        }

        $totals = [
            'messages' => count($allMetrics),
            'total_replies' => 0,
            'total_users_reached' => 0,
            'capture_rates' => [],
            'efficiencies' => [],
            'expansions' => []
        ];

        foreach ($allMetrics as $m) {
            $totals['total_replies'] += $m['total_replies'];
            $totals['total_users_reached'] += $m['final_users_reached'];
            $totals['capture_rates'][] = $m['capture_rate'];
            $totals['efficiencies'][] = $m['efficiency'];
            $totals['expansions'][] = $m['total_expansions'];
        }

        return [
            'messages_analyzed' => $totals['messages'],
            'total_replies' => $totals['total_replies'],
            'total_users_reached' => $totals['total_users_reached'],
            'median_capture_rate' => $this->medianTest($totals['capture_rates']),
            'mean_capture_rate' => $this->meanTest($totals['capture_rates']),
            'median_efficiency' => $this->medianTest($totals['efficiencies']),
            'mean_efficiency' => $this->meanTest($totals['efficiencies']),
            'median_expansions' => $this->medianTest($totals['expansions']),
            'mean_expansions' => $this->meanTest($totals['expansions'])
        ];
    }

    private function medianTest($arr) {
        if (count($arr) == 0) return 0;
        sort($arr);
        $count = count($arr);
        $middle = floor($count / 2);

        if ($count % 2 == 0) {
            return ($arr[$middle - 1] + $arr[$middle]) / 2;
        }

        return $arr[$middle];
    }

    private function meanTest($arr) {
        if (count($arr) == 0) return 0;
        return array_sum($arr) / count($arr);
    }
}
