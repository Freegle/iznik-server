<?php
namespace Freegle\Iznik;

function simulation() {
    global $dbhr, $dbhm;

    $ret = ['ret' => 100, 'status' => 'Unknown verb'];

    $me = Session::whoAmI($dbhr, $dbhm);

    if ($me) {
    // Check if user has mod/support/admin status
    if ($me->isModerator() || $me->isAdminOrSupport()) {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                // Initialize session
                $runid = intval(Utils::presdef('runid', $_REQUEST, 0));

                if ($runid) {
                    // Verify run exists and is completed
                    $runs = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_runs WHERE id = ?", [$runid]);

                    if (count($runs) == 0) {
                        $ret = ['ret' => 2, 'status' => 'Run not found'];
                    } else if ($runs[0]['status'] !== 'completed') {
                        $ret = ['ret' => 3, 'status' => 'Run not completed', 'run_status' => $runs[0]['status']];
                    } else {
                        // Create session
                        $sessionId = bin2hex(random_bytes(16));
                        $expires = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hour expiry

                        $dbhm->preExec("INSERT INTO simulation_message_isochrones_sessions
                            (id, runid, userid, current_index, expires) VALUES (?, ?, ?, 0, ?)", [
                            $sessionId,
                            $runid,
                            $me->getId(),
                            $expires
                        ]);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'session' => $sessionId,
                            'run' => [
                                'id' => $runs[0]['id'],
                                'name' => $runs[0]['name'],
                                'description' => $runs[0]['description'],
                                'created' => Utils::ISODate($runs[0]['created']),
                                'completed' => Utils::ISODate($runs[0]['completed']),
                                'parameters' => json_decode($runs[0]['parameters'], TRUE),
                                'filters' => json_decode($runs[0]['filters'], TRUE),
                                'message_count' => $runs[0]['message_count'],
                                'metrics' => json_decode($runs[0]['metrics'], TRUE)
                            ]
                        ];
                    }
                } else {
                    $ret = ['ret' => 1, 'status' => 'Missing runid parameter'];
                }
                break;

            case 'GET':
                // Navigate through messages
                $sessionId = Utils::presdef('session', $_REQUEST, NULL);
                $action = Utils::presdef('action', $_REQUEST, 'current');
                $index = intval(Utils::presdef('index', $_REQUEST, 0));

                if (!$sessionId) {
                    $ret = ['ret' => 1, 'status' => 'Missing session parameter'];
                } else {
                    // Get session
                    $sessions = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_sessions WHERE id = ? AND userid = ?", [
                        $sessionId,
                        $me->getId()
                    ]);

                    if (count($sessions) == 0) {
                        $ret = ['ret' => 2, 'status' => 'Session not found or expired'];
                    } else {
                        $session = $sessions[0];
                        $currentIndex = $session['current_index'];

                        // Check expiry
                        if (strtotime($session['expires']) < time()) {
                            $ret = ['ret' => 3, 'status' => 'Session expired'];
                        } else {
                            // Determine new index based on action
                            $newIndex = $currentIndex;

                            switch ($action) {
                                case 'next':
                                    $newIndex = $currentIndex + 1;
                                    break;
                                case 'prev':
                                    $newIndex = max(0, $currentIndex - 1);
                                    break;
                                case 'index':
                                    $newIndex = $index;
                                    break;
                                case 'current':
                                default:
                                    $newIndex = $currentIndex;
                                    break;
                            }

                            // Get message at index
                            $messages = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_messages
                                WHERE runid = ? AND sequence = ?", [
                                $session['runid'],
                                $newIndex
                            ]);

                            if (count($messages) == 0) {
                                $ret = ['ret' => 4, 'status' => 'No message at index ' . $newIndex];
                            } else {
                                $msg = $messages[0];

                                // Update session index
                                $dbhm->preExec("UPDATE simulation_message_isochrones_sessions SET current_index = ? WHERE id = ?", [
                                    $newIndex,
                                    $sessionId
                                ]);

                                // Get expansions
                                $expansions = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_expansions
                                    WHERE sim_msgid = ? ORDER BY sequence ASC", [$msg['id']]);

                                // Get users
                                $users = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_users
                                    WHERE sim_msgid = ?", [$msg['id']]);

                                // Get total count
                                $counts = $dbhr->preQuery("SELECT COUNT(*) AS count FROM simulation_message_isochrones_messages WHERE runid = ?", [
                                    $session['runid']
                                ]);

                                $totalMessages = $counts[0]['count'];

                                // Build response
                                $ret = [
                                    'ret' => 0,
                                    'status' => 'Success',
                                    'navigation' => [
                                        'current_index' => $newIndex,
                                        'total_messages' => $totalMessages,
                                        'has_next' => $newIndex < ($totalMessages - 1),
                                        'has_prev' => $newIndex > 0
                                    ],
                                    'message' => [
                                        'id' => $msg['msgid'],
                                        'subject' => $msg['subject'],
                                        'arrival' => Utils::ISODate($msg['arrival']),
                                        'location' => [
                                            'id' => $msg['locationid'],
                                            'lat' => floatval($msg['lat']),
                                            'lng' => floatval($msg['lng'])
                                        ],
                                        'group' => [
                                            'id' => $msg['groupid'],
                                            'name' => $msg['groupname']
                                        ],
                                        'total_group_users' => $msg['total_group_users'],
                                        'total_replies_actual' => $msg['total_replies_actual'],
                                        'metrics' => json_decode($msg['metrics'], TRUE)
                                    ],
                                    'group_cga' => [
                                        'type' => 'Feature',
                                        'geometry' => json_decode($msg['group_cga_polygon'], TRUE),
                                        'properties' => [
                                            'type' => 'group_cga',
                                            'name' => $msg['groupname']
                                        ]
                                    ],
                                    'expansions' => array_map(function($exp) {
                                        return [
                                            'sequence' => $exp['sequence'],
                                            'timestamp' => Utils::ISODate($exp['timestamp']),
                                            'minutes_after_arrival' => $exp['minutes_after_arrival'],
                                            'minutes' => $exp['minutes'],
                                            'transport' => $exp['transport'],
                                            'users_in_isochrone' => $exp['users_in_isochrone'],
                                            'new_users_reached' => $exp['new_users_reached'],
                                            'replies_at_time' => $exp['replies_at_time'],
                                            'replies_in_isochrone' => $exp['replies_in_isochrone'],
                                            'geometry' => [
                                                'type' => 'Feature',
                                                'geometry' => json_decode($exp['isochrone_polygon'], TRUE),
                                                'properties' => [
                                                    'type' => 'isochrone',
                                                    'sequence' => $exp['sequence'],
                                                    'minutes' => $exp['minutes']
                                                ]
                                            ]
                                        ];
                                    }, $expansions),
                                    'users' => [
                                        'type' => 'FeatureCollection',
                                        'features' => array_map(function($user) {
                                            return [
                                                'type' => 'Feature',
                                                'geometry' => [
                                                    'type' => 'Point',
                                                    'coordinates' => [floatval($user['lng']), floatval($user['lat'])]
                                                ],
                                                'properties' => [
                                                    'user_hash' => $user['user_hash'],
                                                    'in_group' => (bool)$user['in_group'],
                                                    'replied' => (bool)$user['replied'],
                                                    'reply_time' => $user['reply_time'] ? Utils::ISODate($user['reply_time']) : NULL,
                                                    'reply_minutes' => $user['reply_minutes'],
                                                    'distance_km' => $user['distance_km'] ? floatval($user['distance_km']) : NULL
                                                ]
                                            ];
                                        }, $users)
                                    ]
                                ];
                            }
                        }
                    }
                }
                break;

            default:
                $ret = ['ret' => 100, 'status' => 'Unknown verb'];
                break;
        }
    } else {
        $ret = ['ret' => 99, 'status' => 'Permission denied - requires moderator, support, or admin status'];
    }
    } else {
        $ret = ['ret' => 98, 'status' => 'Not logged in'];
    }

    return $ret;
}
