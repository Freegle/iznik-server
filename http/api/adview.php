<?php
function adview() {
    # This proxies a request on to adview to avoid CORS issues.
    global $dbhr, $dbhm;

    $ip = presdef('REMOTE_ADDR', $_SERVER, NULL);
    $hdrs = getallheaders();
    if (pres('X-Real-Ip', $hdrs)) {
        // Passed using proxy protocol
        $ip = $hdrs['X-Real-Ip'];
    }

    $location = presdef('location', $_REQUEST, NULL);
    $link = presdef('link', $_REQUEST, NULL);
    $me = whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $data = NULL;
            $loc = NULL;

            if ($ip && $location) {
                # We might have a postcode search.  The AdView postcode search is unreliable, so we need to find
                # the nearest city.

                if ($me) {
                    list ($lat, $lng, $loc) = $me->getLatLng();

                    if ($loc == $location) {
                        # We are searching on our own location.
                        $location = $me->getCity();
                    }
                }

                $url = "https://adview.online/api/v1/jobs.json?publisher=2053&channel=web&limit=50&radius=5&user_ip=$ip&location=" . urlencode($location);

                $ctx = stream_context_create(array('http'=> [
                    'timeout' => 10,
                    "method" => "GET"
                ]));

                $data = @file_get_contents($url, FALSE, $ctx);

                if ($data) {
                    $d = json_decode($data, TRUE);
                    if (array_key_exists('data', $d)) {
                        // Score the jobs based on which jobs generate the most clicks.  Anything we have ourselves
                        // clicked on earlier jumps to the top of of the rankings.
                        $jobs = $d['data'];
                        $keywords = [];

                        # Get the keywords in all the urls.
                        foreach ($jobs as &$job) {
                            if (preg_match('/.*\/(.*)\?/', $job['url'], $matches)) {
                                $words = explode('-', $matches[1]);
                                $keywords = array_merge($keywords, $words);
                            }
                        }

                        $keywords = array_unique($keywords);

                        $commonwords = ['of', 'up', 'to', 'be'];
                        $keywords = array_diff($commonwords, $keywords);

                        # Get the number of clicks on each.
                        $sql = "SELECT keyword, count FROM jobs_keywords WHERE keyword IN (";
                        foreach ($keywords as $keyword) {
                            $sql .= $dbhr->quote($keyword) . ',';
                        }

                        $sql .= '0)';

                        $rows = $dbhr->preQuery($sql, NULL, FALSE, FALSE);
                        $scores = [];

                        foreach ($rows as $row) {
                            $scores[$row['keyword']] = $row['count'];
                        }

                        $max = max($scores);

                        # Normalise to less than 100.  This hides the click numbers and also allows us to score
                        # our own clicks higher.
                        foreach ($scores as $keyword => $score) {
                            $scores[$keyword] = 100 * $scores[$keyword] / $max;
                        }

                        $mykeywords = [];

                        if ($me) {
                            # Find keywords I've clicked on.
                            $logs = $dbhr->preQuery("SELECT * FROM logs_jobs WHERE userid = ?;", [
                                $me->getId()
                            ], FALSE, FALSE);

                            foreach ($logs as $log) {
                                if (preg_match('/.*\/(.*)\?/', $log['link'], $matches)) {
                                    $words = explode('-', $matches[1]);

                                    foreach ($words as $word) {
                                        if (!is_numeric($word) && !in_array($word, $commonwords)) {
                                            if (array_key_exists($word, $mykeywords)) {
                                                $mykeywords[$word]++;
                                            } else {
                                                $mykeywords[$word] = 1;
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        # Score the jobs.
                        foreach ($jobs as &$job) {
                            if (preg_match('/.*\/(.*)\?/', $job['url'], $matches)) {
                                $words = explode('-', $matches[1]);
                                $score = 0;

                                foreach ($words as $word) {
                                    if (!is_numeric($word)) {
                                        $score += presdef($word, $scores, 0);

                                        if (pres($word, $mykeywords)) {
                                            $score += 100 * $mykeywords[$word];
                                        }
                                    }
                                }

                                $job['score'] = 100 * $score / $max;
                            }
                        }

                        usort($jobs, function($a, $b) {
                            return presdef('score', $b, 0) - presdef('score', $a, 0);
                        });

                        $d['data'] = $jobs;

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'adview' => $d,
                            'url' => $url,
                            'ip' => $ip,
                            'user_agent' => presdef('User-Agent', $hdrs, NULL),
                            'searchedloc' => $location,
                            'ownlocation' => $loc,
                        ];
                    } else {
                        error_log("AdView data unexpected format for $location");
                        $ret = [
                            'ret' => 4,
                            'status' => 'Data returned has unexpected format.'
                        ];
                    }
                } else {
                    error_log("AdView no data for $location");
                    $ret = [
                        'ret' => 3,
                        'status' => 'No data returned'
                    ];
                }
            } else {
                $ret = [
                    'ret' => 2,
                    'status' => 'Invalid parameters'
                ];
            }
            break;
        }

        case 'POST': {
            $dbhm->preExec("INSERT INTO logs_jobs (userid, link) VALUES (?, ?);", [
                $me ? $me->getId() : NULL,
                $link
            ]);

            $ret = [ 'ret' => 0, 'status' => 'Success' ];
            break;
        }
    }

    return($ret);
}
