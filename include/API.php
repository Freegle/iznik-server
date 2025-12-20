<?php

namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/misc/Loki.php');
global $dbhr, $dbhm;

use GeoIp2\Database\Reader;

class API
{
    public static function headers() {
        $call = array_key_exists('call', $_REQUEST) ? $_REQUEST['call'] : NULL;
        $type = array_key_exists('type', $_REQUEST) ? $_REQUEST['type'] : 'GET';

        // We allow anyone to use our API.
        //
        // Suppress errors on the header command for UT
        if (!(($call == 'image' || $call == 'profile') && $type == 'GET')) {
            # For images we'll set the content type later.
            @header('Content-type: application/json');
        }

        // Access-Control-Allow-Origin not now added by nginx.
        @header('Access-Control-Allow-Origin: *');
        @header('Access-Control-Allow-Headers: ' . (array_key_exists('HTTP_ACCESS_CONTROL_REQUEST_HEADERS', $_SERVER) ? $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] : "Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Iznik-PHP-Session")); // X-HTTP-Method-Override not needed
        @header('Access-Control-Allow-Credentials: TRUE');
        @header('P3P:CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');
    }

    public static function call()
    {
        global $dbhr, $dbhm;

        $ip = Utils::presdef('REMOTE_ADDR', $_SERVER, '');

        if (file_exists("/tmp/noheaders-$ip")) {
            echo json_encode(array('ret' => 1, 'status' => 'Not  logged in'));
            exit(0);
        }

        $scriptstart = microtime(TRUE);

        $entityBody = file_get_contents('php://input');

        if ($entityBody) {
            $parms = json_decode($entityBody, TRUE);
            if (json_last_error() == JSON_ERROR_NONE) {
                # We have been passed parameters in JSON.
                foreach ($parms as $parm => $val) {
                    $_REQUEST[$parm] = $val;
                }
            } else {
                # In some environments (e.g. PHP-FPM 7.2) parameters passed as an encoded form for PUT aren't parsed correctly,
                # probably because we have a rewrite that adds a parameter to the URL, and PHP-FPM doesn't want to have both
                # URL and form parameters.
                #
                # This may be a bug or a feature, but it messes us up.  So decode anything we can find that has not already
                # been decoded by our interpreter (if it did it, it's likely to be better).
                #
                # We needed this code when the app didn't contain the use of HTTP_X_HTTP_METHOD_OVERRIDE, and it's useful
                # anyway in case the client forgets.
                parse_str($entityBody, $params);
                foreach ($params as $key => $val) {
                    if (!array_key_exists($key, $_REQUEST)) {
                        $_REQUEST[$key] = $val;
                    }
                }
            }
        }

        if (array_key_exists('REQUEST_METHOD', $_SERVER)) {
            $_SERVER['REQUEST_METHOD'] = strtoupper($_SERVER['REQUEST_METHOD']);
            $_REQUEST['type'] = $_SERVER['REQUEST_METHOD'];
        }

        if (array_key_exists('HTTP_X_HTTP_METHOD_OVERRIDE', $_SERVER)) {
            # Used by Backbone's emulateHTTP to work around servers which don't handle verbs like PATCH very well.
            #
            # We use this because when we issue a PATCH we don't seem to be able to get the body parameters.
            $_REQUEST['type'] = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
            #error_log("Request method override to {$_REQUEST['type']}");
        }

        // Record timing.
        list ($tusage, $rusage) = API::requestStart();

        API::headers();

        // @codeCoverageIgnoreStart
        if (file_exists(IZNIK_BASE . '/http/maintenance_on.html')) {
            echo json_encode(array('ret' => 111, 'status' => 'Down for maintenance'));
            exit(0);
        }
        // @codeCoverageIgnoreEnd

        # Check if IP is blocked
        $ip = Utils::presdef('REMOTE_ADDR', $_SERVER, '');
        $ipBlocker = new IPBlocker($dbhr, $dbhm);

        if ($ipBlocker->isBlocked($ip)) {
            echo json_encode(array(
                'ret' => 403,
                'status' => 'Access denied'
            ));
            exit(0);
        }

        $includetime = microtime(TRUE) - $scriptstart;

        # All API calls come through here.
        #error_log("Request " . var_export($_REQUEST, TRUE));
        #error_log("Server " . var_export($_SERVER, TRUE));

        if (Utils::pres('HTTP_X_REAL_IP', $_SERVER)) {
            # We jump through hoops to get the real IP address. This is one of them.
            $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_REAL_IP'];
        }

        if (array_key_exists('model', $_REQUEST)) {
            # Used by Backbone's emulateJSON to work around servers which don't handle requests encoded as
            # application/json.
            $_REQUEST = array_merge($_REQUEST, json_decode($_REQUEST['model'], TRUE));
            unset($_REQUEST['model']);
        }

        # Include the API call
        $call = Utils::pres('call', $_REQUEST);

        if ($call) {
            $fn = IZNIK_BASE . '/http/api/' . $call . '.php';
            if (file_exists($fn)) {
                require_once($fn);
            }
        }

        if (Utils::presdef('type', $_REQUEST, null) == 'OPTIONS') {
            # We don't bother returning different values for different calls.
            http_response_code(204);
            @header('Allow: POST, GET, DELETE, PUT');
            @header('Access-Control-Allow-Methods:  POST, GET, DELETE, PUT');
        } else {
            # Actual API calls
            $ret = array('ret' => 1000, 'status' => 'Invalid API call');
            $t = microtime(TRUE);

            # We wrap the whole request in a retry handler.  This is so that we can deal with errors caused by
            # conflicts within the Percona cluster.
            $apicallretries = 0;

            # This is an optimisation for User.php.
            if (session_status() !== PHP_SESSION_NONE) {
                $_SESSION['modorowner'] = Utils::presdef('modorowner', $_SESSION, []);

                # Add X-User-Id header for HAProxy per-user rate limiting.
                $userId = Utils::presdef('id', $_SESSION, NULL);
                if ($userId) {
                    @header('X-User-Id: ' . $userId);
                }
            }

            $encoded_ret = NULL;

            do {
                if (Utils::presdef('type', $_REQUEST, null) != 'GET') {
                    # Check that we're not posting from a blocked country.
                    try {
                        $reader = new Reader(MMDB);
                        $ip = Utils::presdef('REMOTE_ADDR', $_SERVER, null);

                        if ($ip) {
                            $record = $reader->country($ip);
                            $country = $record->country->name;
                            # Failed to look it up.
                            $countries = $dbhr->preQuery("SELECT * FROM spam_countries WHERE country = ?;", [$country]);
                            foreach ($countries as $country) {
                                error_log("Block post from {$country['country']} " . var_export($_REQUEST, TRUE));
                                $encoded_ret = json_encode(array('ret' => 0, 'status' => 'Success'));
                                echo $encoded_ret;
                                break 2;
                            }
                        }
                    } catch (\Exception $e) {
                        // Don't log to Sentry - this can happen validly for some IPs (e.g. on CircleCI).
                    }
                }

                # Duplicate protection.  We upload multiple images so don't protect against those.
                if ((DUPLICATE_POST_PROTECTION > 0) &&
                    array_key_exists('REQUEST_METHOD', $_SERVER) &&
                    ((Utils::presdef('type', $_REQUEST, null) == 'POST' || Utils::presdef('type', $_REQUEST, null) == 'PUT')) &&
                    $call != 'image') {
                    # We want to make sure that we don't get duplicate POST requests within the same session.  We can't do this
                    # using information stored in the session because when Redis is used as the session handler, there is
                    # no session locking, and therefore two requests in quick succession could be allowed.  So instead
                    # we use Redis directly with a roll-your-own mutex.  We use the IP address as a key - if there are
                    # multiple sessions from the same IP then we might get FALSE positives/negatives.
                    $reqData = $_REQUEST;
                    unset($reqData['requestid']);
                    $reqData['call'] = preg_replace('/(\?|&)requestid=[0-9]+?/', '', $reqData['call']);
                    $url = preg_replace('/(\?|&)requestid=[0-9]+?/', '', $_SERVER['REQUEST_URI']);
                    $req = $url . serialize($reqData);
                    $ip = Utils::presdef('REMOTE_ADDR', $_SERVER, NULL);
                    $lockkey = "POST_LOCK_$ip";
                    $datakey = "POST_DATA_$ip";
                    $uid = uniqid('', TRUE);
                    $predis = new \Redis();
                    $predis->pconnect(REDIS_CONNECT);

                    # Get a lock.
                    $start = time();
                    $count = 0;

                    do {
                        $rc = $predis->setNx($lockkey, $uid);

                        if ($rc) {
                            # We managed to set it.  Ideally we would set an expiry time to make sure that if we got
                            # killed right now, this session wouldn't hang.  But that's an extra round trip on each
                            # API call, and the worst case would be a single session hanging, which we can live with.

                            # Sound out the last POST.
                            $last = $predis->get($datakey);

                            # Some actions are ok, so we exclude those.
                            if (!in_array($call, ['session', 'correlate', 'chatrooms', 'upload']) &&
                                $last ==  $req) {
                                # The last POST request was the same.  So this is a duplicate.
                                $predis->del($lockkey);
                                $ret = array(
                                    'ret' => 999,
                                    'text' => 'Duplicate request - rejected.',
                                    'data' => $_REQUEST
                                );
                                $encoded_ret = json_encode($ret);
                                echo $encoded_ret;
                                break 2;
                            }

                            # The last request wasn't the same.  Save this one.
                            $predis->set($datakey, $req);
                            $predis->expire($datakey, DUPLICATE_POST_PROTECTION);

                            # We're good to go - release the lock.
                            $predis->del($lockkey);
                            break;
                            // @codeCoverageIgnoreStart
                        } else {
                            # We didn't get the lock - another request for this session must have it.
                            $count++;
                            #error_log("Sleep for duplicate POST lock key $lockkey attempt $count, waited " . (time() - $start));
                            usleep(100000);
                        }
                    } while (time() < $start + 45);
                    // @codeCoverageIgnoreEnd
                }

                try {
                    # Each call is inside a file with a suitable name.
                    #
                    # call_user_func doesn't scale well on multicores with some versions of PHP, so we need can't figure out the function from
                    # the call name - use a switch instead.
                    switch ($call) {
                        case 'abtest':
                            $ret = abtest();
                            break;
                        case 'activity':
                            $ret = activity();
                            break;
                        case 'authority':
                            $ret = authority();
                            break;
                        case 'address':
                            $ret = address();
                            break;
                        case 'alert':
                            $ret = alert();
                            break;
                        case 'adview':
                            # Remove after 2021-05-05.
                            $ret = [
                                'ret' => 0,
                                'status' => 'Success',
                                'data' => []
                            ];
                            break;
                        case 'admin':
                            $ret = admin();
                            break;
                        case 'config':
                            $ret = config();
                            break;
                        case 'changes':
                            $ret = changes();
                            break;
                        case 'dashboard':
                            $ret = dashboard();
                            break;
                        case 'error':
                            $ret = error();
                            break;
                        case 'export':
                            $ret = export();
                            break;
                        case 'exception':
                            # For UT
                            throw new \Exception();
                        case 'image':
                            $ret = image();
                            break;
                        case 'isochrone':
                            $ret = isochrone();
                            break;
                        case 'jobs':
                            $ret = jobs();
                            break;
                        case 'profile':
                            $ret = profile();
                            break;
                        case 'socialactions':
                            $ret = socialactions();
                            break;
                        case 'messages':
                            $ret = messages();
                            break;
                        case 'message':
                            $ret = message();
                            break;
                        case 'invitation':
                            $ret = invitation();
                            break;
                        case 'item':
                            $ret = item();
                            break;
                        case 'usersearch':
                            $ret = usersearch();
                            break;
                        case 'memberships':
                            $ret = memberships();
                            break;
                        case 'merge':
                            $ret = merge();
                            break;
                        case 'spammers':
                            $ret = spammers();
                            break;
                        case 'session':
                            $ret = session();
                            break;
                        case 'simulation':
                            $ret = simulation();
                            break;
                        case 'group':
                            $ret = group();
                            break;
                        case 'groups':
                            $ret = groups();
                            break;
                        case 'communityevent':
                            $ret = communityevent();
                            break;
                        case 'domains':
                            $ret = domains();
                            break;
                        case 'locations':
                            $ret = locations();
                            break;
                        case 'logo':
                            $ret = logo();
                            break;
                        case 'modconfig':
                            $ret = modconfig();
                            break;
                        case 'stdmsg':
                            $ret = stdmsg();
                            break;
                        case 'bulkop':
                            $ret = bulkop();
                            break;
                        case 'comment':
                            $ret = comment();
                            break;
                        case 'user':
                            $ret = user();
                            break;
                        case 'chatrooms':
                            $ret = chatrooms();
                            break;
                        case 'chatmessages':
                            $ret = chatmessages();
                            break;
                        case 'poll':
                            $ret = poll();
                            break;
                        case 'request':
                            $ret = request();
                            break;
                        case 'shortlink':
                            $ret = shortlink();
                            break;
                        case 'stories':
                            $ret = stories();
                            break;
                        case 'donations':
                            $ret = donations();
                            break;
                        case 'giftaid':
                            $ret = giftaid();
                            break;
                        case 'status':
                            $ret = status();
                            break;
                        case 'volunteering':
                            $ret = volunteering();
                            break;
                        case 'logs':
                            $ret = logs();
                            break;
                        case 'newsfeed':
                            $ret = newsfeed();
                            break;
                        case 'noticeboard':
                            $ret = noticeboard();
                            break;
                        case 'notification':
                            $ret = notification();
                            break;
                        case 'mentions':
                            $ret = mentions();
                            break;
                        case 'microvolunteering':
                            $ret = microvolunteering();
                            break;
                        case 'team':
                            $ret = team();
                            break;
                        case 'tryst':
                            $ret = tryst();
                            break;
                        case 'src':
                            $ret = src();
                            break;
                        case 'visualise':
                            $ret = visualise();
                            break;
                        case 'stripecreateintent':
                            $ret = stripecreateintent();
                            break;
                        case 'stripecreatesubscription':
                            $ret = stripecreatesubscription();
                            break;
                        case 'echo':
                            $ret = array_merge($_REQUEST, $_SERVER);
                            break;
                        case 'DBexceptionWork':
                            # For UT
                            if ($apicallretries < 2) {
                                error_log("Fail DBException $apicallretries");
                                throw new DBException();
                            }

                            break;
                        case 'DBexceptionFail':
                            # For UT
                            throw new DBException();
                        case 'DBleaveTrans':
                            # For UT
                            $dbhm->beginTransaction();

                            break;
                    }

                    # If we get here, everything worked.
                    if ($call == 'upload') {
                        # Output is handled within the lib.
                    } else {
                        if (Utils::pres('redirectto', $ret)) {
                            header("Location: {$ret['redirectto']}", TRUE, 302);
                        } else if (Utils::pres('img', $ret)) {
                            # This is an image we want to output.  Can cache forever - if an image changes it would get a new id
                            @header('Content-Type: image/jpeg');
                            @header('Content-Length: ' . strlen($ret['img']));
                            @header('Cache-Control: max-age=5360000');
                            print $ret['img'];
                        } else {
                            # This is a normal API call.  Add profiling info.
                            $duration = (microtime(TRUE) - $scriptstart);
                            $ret['call'] = $call;
                            $ret['type'] = Utils::presdef('type', $_REQUEST, null);
                            $ret['session'] = session_id();
                            $ret['duration'] = $duration;
                            $ret['cpucost'] = API::getCpuUsage($tusage, $rusage);
                            $ret['dbwaittime'] = $dbhr->getWaitTime() + $dbhm->getWaitTime();
                            $ret['includetime'] = $includetime;
                            //                $ret['remoteaddr'] = Utils::presdef('REMOTE_ADDR', $_SERVER, '-');
                            //                $ret['_server'] = $_SERVER;

                            Utils::filterResult($ret);

                            # Set X-User-Role header for HAProxy to exempt mods/support/admin from rate limiting.
                            # Use whoAmI which caches the user object.
                            $me = Session::whoAmI($dbhr, $dbhm);
                            if ($me) {
                                $systemrole = $me->getPrivate('systemrole');
                                if ($systemrole && $systemrole != User::SYSTEMROLE_USER) {
                                    @header('X-User-Role: ' . $systemrole);
                                }
                            }

                            $encoded_ret = json_encode($ret, JSON_PARTIAL_OUTPUT_ON_ERROR);
                            echo $encoded_ret;

                            if ($duration > 5000) {
                                # Slow call.
                                $stamp = microtime(TRUE);
                                error_log("Slow API call for user " . Utils::presdef('id', $_SESSION, NULL) . " $call stamp $stamp");
                                file_put_contents("/tmp/iznik.slowapi.$stamp", "User # " . Utils::presdef('id', $_SESSION, NULL) . " request " . var_export($_REQUEST, TRUE) . " response " . var_export($ret, TRUE));
                            }
                        }
                    }

                    if ($apicallretries > 0) {
                        error_log("API call $call worked after $apicallretries");
                    }

                    break;
                } catch (\Exception $e) {
                    # This is our retry handler.
                    if ($e instanceof DBException) {
                        # This is a DBException.  We want to retry, which means we just go round the loop
                        # again.
                        error_log(
                            "DB Exception try $apicallretries," . $e->getMessage() . ", " . $e->getTraceAsString()
                        );
                        $apicallretries++;

                        if ($apicallretries >= API_RETRIES) {
                            if ($call != 'DBexceptionFail') {
                                # Don't log deliberate exceptions in UT.
                                \Sentry\captureException($e);
                            }

                            $ret = [
                                'ret' => 997,
                                'status' => 'DB operation failed after retry',
                                'exception' => $e->getMessage()
                            ];
                            $encoded_ret = json_encode($ret);
                            echo $encoded_ret;
                        }
                    } else {
                        # Something else.
                        if ($call != 'exception') {
                            # Don't log deliberate exceptions in UT.
                            \Sentry\captureException($e);
                        }

                        error_log(
                            "Uncaught exception at " . $e->getFile() . " line " . $e->getLine() . " " . $e->getMessage()
                        );
                        $ret = ['ret' => 998, 'status' => 'Unexpected error', 'exception' => $e->getMessage()];
                        $encoded_ret = json_encode($ret);
                        echo $encoded_ret;
                        break;
                    }

                    # Make sure the duplicate POST detection doesn't throw us.
                    $_REQUEST['retry'] = uniqid('', TRUE);
                }
            } while ($apicallretries < API_RETRIES);

            # Log API request to Loki (fire-and-forget, doesn't block response).
            # This is done via shutdown function to ensure response is sent first.
            # Generate a unique request_id to correlate API logs with their headers.
            # Format: timestamp_ms (hex) + random bytes for uniqueness within same ms.
            $requestId = dechex((int)(microtime(TRUE) * 1000)) . bin2hex(random_bytes(4));

            $lokiLogData = [
                'call' => $call,
                'method' => Utils::presdef('type', $_REQUEST, 'GET'),
                'statusCode' => Utils::presdef('ret', $ret, 0),
                'duration' => (microtime(TRUE) - $scriptstart) * 1000, // ms
                'userId' => session_status() !== PHP_SESSION_NONE ? Utils::presdef('id', $_SESSION, NULL) : NULL,
                'ip' => Utils::presdef('REMOTE_ADDR', $_SERVER, ''),
                'queryParams' => $_GET,
                'requestBody' => Utils::presdef('type', $_REQUEST, 'GET') === 'POST' ? $_POST : [],
                'responseBody' => is_array($ret) ? $ret : [],
                'requestId' => $requestId,
            ];

            # Capture request headers from $_SERVER (HTTP_* format).
            $requestHeaders = [];
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    # Convert HTTP_USER_AGENT to User-Agent format.
                    $headerName = str_replace('_', '-', substr($key, 5));
                    $headerName = ucwords(strtolower($headerName), '-');
                    $requestHeaders[$headerName] = $value;
                } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                    $headerName = str_replace('_', '-', $key);
                    $headerName = ucwords(strtolower($headerName), '-');
                    $requestHeaders[$headerName] = $value;
                }
            }

            # Capture response headers (must be done before output).
            $responseHeaders = [];
            foreach (headers_list() as $header) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
            }

            $lokiHeaderData = [
                'requestHeaders' => $requestHeaders,
                'responseHeaders' => $responseHeaders,
            ];

            register_shutdown_function(function() use ($lokiLogData, $lokiHeaderData) {
                $loki = Loki::getInstance();
                if ($loki->isEnabled()) {
                    $loki->logApiRequestFull(
                        'v1',
                        $lokiLogData['method'],
                        $lokiLogData['call'],
                        $lokiLogData['statusCode'],
                        $lokiLogData['duration'],
                        $lokiLogData['userId'],
                        [
                            'ip' => $lokiLogData['ip'],
                            'request_id' => $lokiLogData['requestId'],
                        ],
                        $lokiLogData['queryParams'],
                        $lokiLogData['requestBody'],
                        $lokiLogData['responseBody']
                    );

                    # Log headers separately (7-day retention for debugging).
                    # Include request_id for correlation with main API log.
                    $loki->logApiHeaders(
                        'v1',
                        $lokiLogData['method'],
                        $lokiLogData['call'],
                        $lokiHeaderData['requestHeaders'],
                        $lokiHeaderData['responseHeaders'],
                        $lokiLogData['userId'],
                        $lokiLogData['requestId']
                    );

                    $loki->flush();
                }
            });

            # Any outstanding transaction is a bug; force a rollback to avoid locks lasting beyond this call.
            if ($dbhm->inTransaction()) {
                $dbhm->rollBack();
            }

            if (session_status() !== PHP_SESSION_NONE) {
                if (Utils::presdef('type', $_REQUEST, null) != 'GET') {
                    # This might have changed things.
                    $_SESSION['modorowner'] = [];
                }

                # Update our last access time for this user.  his is used to return our
                # roster status in ChatRoom.php, and also for spotting idle members.
                #
                # Do this here, as we might not be logged in at the start if we had a persistent token but no PHP session.
                $id = Utils::pres('id', $_SESSION);
                $last = intval(Utils::presdef('lastaccessupdate', $_SESSION, 0));
                if ($id && (abs(time() - $last) > 600)) {
                    $dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $id;");
                    $_SESSION['lastaccessupdate'] = time();
                }
            }
        }
    }

    public static function requestStart() {
        $dat = getrusage();
        return ([ microtime(TRUE), $dat["ru_utime.tv_sec"]*1e6+$dat["ru_utime.tv_usec"] ]);
    }

    public static function getCpuUsage($tusage, $rusage) {
        $dat = getrusage();
        $dat["ru_utime.tv_usec"] = ($dat["ru_utime.tv_sec"]*1e6 + $dat["ru_utime.tv_usec"]) - $rusage;
        $time = (microtime(TRUE) - $tusage) * 1000000;

        // cpu per request
        $cpu = $time > 0 ? $dat["ru_utime.tv_usec"] / $time / 1000 : 0;

        return $cpu;
    }
}
