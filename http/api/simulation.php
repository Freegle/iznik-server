<?php
namespace Freegle\Iznik;

function simulation() {
    global $dbhr, $dbhm;

    $ret = ['ret' => 100, 'status' => 'Unknown verb'];

    $me = Session::whoAmI($dbhr, $dbhm);

    if ($me) {
    // Check if user is a moderator
    if ($me->isModerator()) {
        $action = Utils::presdef('action', $_REQUEST, NULL);

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                // Check for special actions first
                if ($action === 'listruns') {
                    // List all completed simulation runs
                    $runs = $dbhr->preQuery("SELECT id, name, description, created, completed,
                                                    parameters, filters, message_count, metrics, status
                                             FROM simulation_message_isochrones_runs
                                             WHERE status = 'completed'
                                             ORDER BY created DESC
                                             LIMIT 100");

                    $runList = [];
                    foreach ($runs as $run) {
                        $runList[] = [
                            'id' => $run['id'],
                            'name' => $run['name'],
                            'description' => $run['description'],
                            'created' => Utils::ISODate($run['created']),
                            'completed' => Utils::ISODate($run['completed']),
                            'parameters' => json_decode($run['parameters'], TRUE),
                            'filters' => json_decode($run['filters'], TRUE),
                            'message_count' => $run['message_count'],
                            'metrics' => json_decode($run['metrics'], TRUE),
                            'status' => $run['status']
                        ];
                    }

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'runs' => $runList
                    ];
                } elseif ($action === 'getrun') {
                    // Get run information
                    $runid = intval(Utils::presdef('runid', $_REQUEST, 0));

                    if (!$runid) {
                        $ret = ['ret' => 1, 'status' => 'Missing runid parameter'];
                    } else {
                        // Check if run exists
                        $runs = $dbhr->preQuery("SELECT id, name, description, created, completed,
                                                        parameters, filters, message_count, metrics, status
                                                 FROM simulation_message_isochrones_runs
                                                 WHERE id = ?", [$runid]);

                        if (count($runs) == 0) {
                            $ret = ['ret' => 2, 'status' => 'Run not found'];
                        } else {
                            $run = $runs[0];

                            // Check if run is completed
                            if ($run['status'] !== 'completed') {
                                $ret = [
                                    'ret' => 3,
                                    'status' => 'Run is not completed',
                                    'run_status' => $run['status']
                                ];
                            } else {
                                $ret = [
                                    'ret' => 0,
                                    'status' => 'Success',
                                    'run' => [
                                        'id' => $run['id'],
                                        'name' => $run['name'],
                                        'description' => $run['description'],
                                        'created' => Utils::ISODate($run['created']),
                                        'completed' => Utils::ISODate($run['completed']),
                                        'parameters' => json_decode($run['parameters'], TRUE),
                                        'filters' => json_decode($run['filters'], TRUE),
                                        'message_count' => $run['message_count'],
                                        'metrics' => json_decode($run['metrics'], TRUE),
                                        'status' => $run['status']
                                    ]
                                ];
                            }
                        }
                    }
                } else {
                    // Stateless navigation - get message at index for a run
                    $runid = intval(Utils::presdef('runid', $_REQUEST, 0));
                    $index = intval(Utils::presdef('index', $_REQUEST, 0));

                    if (!$runid) {
                        $ret = ['ret' => 1, 'status' => 'Missing runid parameter'];
                    } else {
                        // Get message at index
                        $messages = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_messages
                            WHERE runid = ? AND sequence = ?", [
                            $runid,
                            $index
                        ]);

                        if (count($messages) == 0) {
                            $ret = ['ret' => 2, 'status' => 'No message at index ' . $index];
                        } else {
                            $msg = $messages[0];

                            // Get expansions
                            $expansions = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_expansions
                                WHERE sim_msgid = ? ORDER BY sequence ASC", [$msg['id']]);

                            // Get users
                            $users = $dbhr->preQuery("SELECT * FROM simulation_message_isochrones_users
                                WHERE sim_msgid = ?", [$msg['id']]);

                            // Get total count
                            $counts = $dbhr->preQuery("SELECT COUNT(*) AS count FROM simulation_message_isochrones_messages WHERE runid = ?", [
                                $runid
                            ]);

                            $totalMessages = $counts[0]['count'];

                            // Build response
                            $ret = [
                                'ret' => 0,
                                'status' => 'Success',
                                'navigation' => [
                                    'current_index' => $index,
                                    'total_messages' => $totalMessages,
                                    'has_next' => $index < ($totalMessages - 1),
                                    'has_prev' => $index > 0
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
