<?php
$scriptstart = microtime(true);

$entityBody =  file_get_contents('php://input');

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

# The MODTOOLS constant is used in a log of places in the code.  We are moving away from this towards it being an
# API parameter, but this is a slow migration.  If the parameter has been passed, then set it here, which will
# then take priority over any later setting in /etc/iznik.conf based on the domain.
if (array_key_exists('modtools', $_REQUEST)) {
    define('MODTOOLS', filter_var($_REQUEST['modtools'], FILTER_VALIDATE_BOOLEAN));
}

require_once('../../include/misc/apiheaders.php');
require_once('../../include/config.php');

# We might profile - only the occasional call as it generates a lot of data.
$xhprof = XHPROF && (mt_rand(0, 1000000) < 1000);

// @codeCoverageIgnoreStart
if ($xhprof) {
    # We are profiling.
    xhprof_enable(XHPROF_FLAGS_CPU);
}

if (file_exists(IZNIK_BASE . '/http/maintenance_on.html')) {
    echo json_encode(array('ret' => 111, 'status' => 'Down for maintenance'));
    exit(0);
}
// @codeCoverageIgnoreEnd

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;
require_once(IZNIK_BASE . '/include/session/Session.php');
require_once(IZNIK_BASE . '/include/session/Yahoo.php');
require_once(IZNIK_BASE . '/include/session/Facebook.php');
require_once(IZNIK_BASE . '/include/session/Google.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/dashboard/Dashboard.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');
require_once(IZNIK_BASE . '/include/message/Item.php');
require_once(IZNIK_BASE . '/include/message/Visualise.php');
require_once(IZNIK_BASE . '/include/user/Search.php');
require_once(IZNIK_BASE . '/include/user/Request.php');
require_once(IZNIK_BASE . '/include/user/Story.php');
require_once(IZNIK_BASE . '/include/user/Address.php');
require_once(IZNIK_BASE . '/include/misc/PAF.php');
require_once(IZNIK_BASE . '/include/misc/Donations.php');
require_once(IZNIK_BASE . '/include/misc/Authority.php');
require_once(IZNIK_BASE . '/include/user/MembershipCollection.php');
require_once(IZNIK_BASE . '/include/noticeboard/Noticeboard.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');
require_once(IZNIK_BASE . '/include/user/PushNotifications.php');
require_once(IZNIK_BASE . '/include/user/Schedule.php');
require_once(IZNIK_BASE . '/include/group/Alerts.php');
require_once(IZNIK_BASE . '/include/group/Admin.php');
require_once(IZNIK_BASE . '/include/group/CommunityEvent.php');
require_once(IZNIK_BASE . '/include/group/Volunteering.php');
require_once(IZNIK_BASE . '/include/group/Twitter.php');
require_once(IZNIK_BASE . '/include/group/Facebook.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');
require_once(IZNIK_BASE . '/include/misc/AdView.php');
require_once(IZNIK_BASE . '/include/misc/Supporters.php');
require_once(IZNIK_BASE . '/include/misc/Polls.php');
require_once(IZNIK_BASE . '/include/mail/MailRouter.php');
require_once(IZNIK_BASE . '/include/misc/plugin.php');
require_once(IZNIK_BASE . '/include/misc/Image.php');
require_once(IZNIK_BASE . '/include/misc/Search.php');
require_once(IZNIK_BASE . '/include/misc/Shortlink.php');
require_once(IZNIK_BASE . '/include/config/ModConfig.php');
require_once(IZNIK_BASE . '/include/config/StdMessage.php');
require_once(IZNIK_BASE . '/include/config/BulkOp.php');
require_once(IZNIK_BASE . '/include/newsfeed/Newsfeed.php');
require_once(IZNIK_BASE . '/include/user/Team.php');

# Include each API call
require_once(IZNIK_BASE . '/http/api/abtest.php');
require_once(IZNIK_BASE . '/http/api/adview.php');
require_once(IZNIK_BASE . '/http/api/authority.php');
require_once(IZNIK_BASE . '/http/api/activity.php');
require_once(IZNIK_BASE . '/http/api/alert.php');
require_once(IZNIK_BASE . '/http/api/admin.php');
require_once(IZNIK_BASE . '/http/api/address.php');
require_once(IZNIK_BASE . '/http/api/changes.php');
require_once(IZNIK_BASE . '/http/api/session.php');
require_once(IZNIK_BASE . '/http/api/modconfig.php');
require_once(IZNIK_BASE . '/http/api/stdmsg.php');
require_once(IZNIK_BASE . '/http/api/bulkop.php');
require_once(IZNIK_BASE . '/http/api/comment.php');
require_once(IZNIK_BASE . '/http/api/dashboard.php');
require_once(IZNIK_BASE . '/http/api/donations.php');
require_once(IZNIK_BASE . '/http/api/error.php');
require_once(IZNIK_BASE . '/http/api/export.php');
require_once(IZNIK_BASE . '/http/api/messages.php');
require_once(IZNIK_BASE . '/http/api/message.php');
require_once(IZNIK_BASE . '/http/api/newsfeed.php');
require_once(IZNIK_BASE . '/http/api/noticeboard.php');
require_once(IZNIK_BASE . '/http/api/invitation.php');
require_once(IZNIK_BASE . '/http/api/item.php');
require_once(IZNIK_BASE . '/http/api/usersearch.php');
require_once(IZNIK_BASE . '/http/api/merge.php');
require_once(IZNIK_BASE . '/http/api/memberships.php');
require_once(IZNIK_BASE . '/http/api/spammers.php');
require_once(IZNIK_BASE . '/http/api/supporters.php');
require_once(IZNIK_BASE . '/http/api/group.php');
require_once(IZNIK_BASE . '/http/api/groups.php');
require_once(IZNIK_BASE . '/http/api/communityevent.php');
require_once(IZNIK_BASE . '/http/api/plugin.php');
require_once(IZNIK_BASE . '/http/api/user.php');
require_once(IZNIK_BASE . '/http/api/chatrooms.php');
require_once(IZNIK_BASE . '/http/api/chatmessages.php');
require_once(IZNIK_BASE . '/http/api/locations.php');
require_once(IZNIK_BASE . '/http/api/logo.php');
require_once(IZNIK_BASE . '/http/api/image.php');
require_once(IZNIK_BASE . '/http/api/profile.php');
require_once(IZNIK_BASE . '/http/api/socialactions.php');
require_once(IZNIK_BASE . '/http/api/src.php');
require_once(IZNIK_BASE . '/http/api/poll.php');
require_once(IZNIK_BASE . '/http/api/request.php');
require_once(IZNIK_BASE . '/http/api/schedule.php');
require_once(IZNIK_BASE . '/http/api/shortlink.php');
require_once(IZNIK_BASE . '/http/api/stories.php');
require_once(IZNIK_BASE . '/http/api/status.php');
require_once(IZNIK_BASE . '/http/api/volunteering.php');
require_once(IZNIK_BASE . '/http/api/notification.php');
require_once(IZNIK_BASE . '/http/api/mentions.php');
require_once(IZNIK_BASE . '/http/api/logs.php');
require_once(IZNIK_BASE . '/http/api/visualise.php');
require_once(IZNIK_BASE . '/http/api/domains.php');
require_once(IZNIK_BASE . '/http/api/team.php');
require_once(IZNIK_BASE . '/http/api/stroll.php');

use GeoIp2\Database\Reader;

$includetime = microtime(true) - $scriptstart;

# All API calls come through here.
#error_log("Request " . var_export($_REQUEST, TRUE));
#error_log("Server " . var_export($_SERVER, TRUE));

if (array_key_exists('model', $_REQUEST)) {
    # Used by Backbone's emulateJSON to work around servers which don't handle requests encoded as
    # application/json.
    $_REQUEST = array_merge($_REQUEST, json_decode($_REQUEST['model'], true));
    unset($_REQUEST['model']);
}

$call = pres('call', $_REQUEST);

if (presdef('type', $_REQUEST, NULL) == 'OPTIONS') {
    # We don't bother returning different values for different calls.
    http_response_code(204);
    @header('Allow: POST, GET, DELETE, PUT');
    @header('Access-Control-Allow-Methods:  POST, GET, DELETE, PUT');
} else {
    # Actual API calls
    $ret = array('ret' => 1000, 'status' => 'Invalid API call');
    $t = microtime(true);

    # We wrap the whole request in a retry handler.  This is so that we can deal with errors caused by
    # conflicts within the Percona cluster.
    $apicallretries = 0;

    # This is an optimisation for User.php.
    $_SESSION['modorowner'] = presdef('modorowner', $_SESSION, []);

    # Update our last access time for this user.  We do this every 60 seconds.  This is used to return our
    # roster status in ChatRoom.php, and also for spotting idle members.
    $id = pres('id', $_SESSION);
    $last = presdef('lastaccessupdate', $_SESSION, 0);
    if ($id && (time() - $last > 60)) {
        $dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $id;");
        $_SESSION['lastaccessupdate'] = time();
    }

    do {
        if (presdef('type', $_REQUEST, NULL) != 'GET') {
            # Check that we're not posting from a blocked country.
            try {
                $reader = new Reader(MMDB);
                $ip = presdef('REMOTE_ADDR', $_SERVER, NULL);
                $record = $reader->country($ip);
                $country = $record->country->name;
                # Failed to look it up.
                $countries = $dbhr->preQuery("SELECT * FROM spam_countries WHERE country = ?;", [$country]);
                foreach ($countries as $country) {
                    error_log("Block post from {$country['country']} " . var_export($_REQUEST, TRUE));
                    echo json_encode(array('ret' => 0, 'status' => 'Success'));
                    break 2;
                }
            } catch (Exception $e) {
            }
        }

        # Duplicate POST protection.  We upload multiple images so don't protect against those.
        if ((DUPLICATE_POST_PROTECTION > 0) &&
            array_key_exists('REQUEST_METHOD', $_SERVER) && (presdef('type', $_REQUEST, NULL) == 'POST') &&
            $call != 'image') {
            # We want to make sure that we don't get duplicate POST requests within the same session.  We can't do this
            # using information stored in the session because when Redis is used as the session handler, there is
            # no session locking, and therefore two requests in quick succession could be allowed.  So instead
            # we use Redis directly with a roll-your-own mutex.
            #
            # TODO uniqid() is not actually unique.  Nor is md5.
            $req = $_SERVER['REQUEST_URI'] . serialize($_REQUEST);
            $lockkey = 'POST_LOCK_' . session_id();
            $datakey = 'POST_DATA_' . session_id();
            $uid = uniqid('', TRUE);
            $predis = new Redis();
            $predis->pconnect(REDIS_CONNECT);

            # Get a lock.
            $start = time();
            do {
                $rc = $predis->setNx($lockkey, $uid);

                if ($rc) {
                    # We managed to set it.  Ideally we would set an expiry time to make sure that if we got
                    # killed right now, this session wouldn't hang.  But that's an extra round trip on each
                    # API call, and the worst case would be a single session hanging, which we can live with.

                    # Sound out the last POST.
                    $last = $predis->get($datakey);

                    # Some actions are ok, so we exclude those.
                    if (!in_array($call, [ 'session', 'correlate', 'chatrooms', 'upload']) &&
                        $last === $req) {
                        # The last POST request was the same.  So this is a duplicate.
                        $predis->del($lockkey);
                        $ret = array('ret' => 999, 'text' => 'Duplicate request - rejected.', 'data' => $_REQUEST);
                        echo json_encode($ret);
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
                    usleep(100000);
                }
            } while (time() < $start + 45);
            // @codeCoverageIgnoreEnd
        }

        try {
            # Each call is inside a file with a suitable name.
            #
            # call_user_func doesn't scale well on multicores with HHVM, so we need can't figure out the function from
            # the call name - use a switch instead.
            switch ($call) {
                case 'abtest':
                    $ret = abtest();
                    break;
                case 'adview':
                    $ret = adview();
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
                case 'admin':
                    $ret = admin();
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
                    throw new Exception();
                case 'image':
                    $ret = image();
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
                case 'plugin':
                    $ret = plugin();
                    break;
                case 'session':
                    $ret = session();
                    break;
                case 'supporters':
                    $ret = supporters();
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
                case 'schedule':
                    $ret = schedule();
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
                case 'team':
                    $ret = team();
                    break;
                case 'src':
                    $ret = src();
                    break;
                case 'stroll':
                    $ret = stroll();
                    break;
                case 'visualise':
                    $ret = visualise();
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
            } else if (pres('img', $ret)) {
                # This is an image we want to output.  Can cache forever - if an image changes it would get a new id
                @header('Content-Type: image/jpeg');
                @header('Content-Length: ' . strlen($ret['img']));
                @header('Cache-Control: max-age=5360000');
                print $ret['img'];
            } else {
                # This is a normal API call.  Add profiling info.
                $duration = (microtime(true) - $scriptstart);
                $ret['call'] = $call;
                $ret['type'] = presdef('type', $_REQUEST, NULL);
                $ret['session'] = session_id();
                $ret['duration'] = $duration;
                $ret['cpucost'] = getCpuUsage();
                $ret['dbwaittime'] = $dbhr->getWaitTime() + $dbhm->getWaitTime();
                $ret['includetime'] = $includetime;
                $ret['cachetime'] = $dbhr->getCacheTime();
                $ret['cachequeries'] = $dbhr->getCacheQueries();
                $ret['cachehits'] = $dbhr->getCacheHits();

                filterResult($ret);

                # We use a streaming encoder rather than json_encode because we can run out of memory encoding
                # large results such as exports
                # Don't - this seems to break the heatmap by returning truncated data.
//                $encoder = new \Violet\StreamingJsonEncoder\StreamJsonEncoder($ret);
//                $encoder->encode();
                $str = json_encode($ret);
                echo $str;

                if ($duration > 1000) {
                    # Slow call.
                    $stamp = microtime(true);
                    error_log("Slow API call $call stamp $stamp");
                    file_put_contents("/tmp/iznik.slowapi.$stamp", var_export($_REQUEST, TRUE));
                }
            }

            if ($apicallretries > 0) {
                error_log("API call $call worked after $apicallretries");
            }

            $ip = presdef('REMOTE_ADDR', $_SERVER, '');

            if (BROWSERTRACKING && (presdef('type', $_REQUEST, NULL) != 'GET') &&
                (gettype($ret) == 'array' && !array_key_exists('nolog', $ret))) {
                # Save off the API call and result, except for the (very frequent) event tracking calls.  Don't
                # save GET calls as they don't change the DB and there are a lot of them.
                #
                # Beanstalk has a limit on the size of job that it accepts; no point trying to log absurdly large
                # API requests.
                $req = json_encode($_REQUEST);
                $rsp = json_encode($ret);

                if (strlen($req) + strlen($rsp) > 180000) {
                    $req = substr($req, 0, 1000);
                    $rsp = substr($rsp, 0, 1000);
                }

                $sql = "INSERT INTO logs_api (`userid`, `ip`, `session`, `request`, `response`) VALUES (" . presdef('id', $_SESSION, 'NULL') . ", '" . presdef('REMOTE_ADDR', $_SERVER, '') . "', " . $dbhr->quote(session_id()) .
                    ", " . $dbhr->quote($req) . ", " . $dbhr->quote($rsp) . ");";
                $dbhm->background($sql);
            }

            break;
        } catch (Exception $e) {
            # This is our retry handler - see apiheaders.
            if ($e instanceof DBException) {
                # This is a DBException.  We want to retry, which means we just go round the loop
                # again.
                error_log("DB Exception try $apicallretries," . $e->getMessage() . ", " . $e->getTraceAsString());
                $apicallretries++;

                if ($apicallretries >= API_RETRIES) {
                    if (strpos($e->getMessage(), 'WSREP has not yet prepared node for application') !== FALSE) {
                        # Our cluster is sick.  Make it look like maintenance.
                        echo json_encode(array('ret' => 111, 'status' => 'Cluster not operational'));
                    } else {
                        echo json_encode(array('ret' => 997, 'status' => 'DB operation failed after retry', 'exception' => $e->getMessage()));
                    }
                }
            } else {
                # Something else.
                error_log("Uncaught exception at " . $e->getFile() . " line " . $e->getLine() . " " . $e->getMessage());
                echo json_encode(array('ret' => 998, 'status' => 'Unexpected error', 'exception' => $e->getMessage()));
                break;
            }

            # Make sure the duplicate POST detection doesn't throw us.
            $_REQUEST['retry'] = uniqid('', TRUE);
        }
    } while ($apicallretries < API_RETRIES);

    # Any outstanding transaction is a bug; force a rollback to avoid locks lasting beyond this call.
    if ($dbhm->inTransaction()) {
        $dbhm->rollBack();
    }

    if (presdef('type', $_REQUEST, NULL) != 'GET') {
        # This might have changed things.
        $_SESSION['modorowner'] = [];
    }
}

// @codeCoverageIgnoreStart
if ($xhprof) {
    # We collect the stats and aggregate the data into the DB
    $stats = xhprof_disable();

    foreach ($stats as $edge => $data) {
        $p = strpos($edge, '==>');
        if ($p !== FALSE) {
            $caller = substr($edge, 0, $p);
            $callee = substr($edge, $p + 3);
            $data['caller'] = $caller;
            $data['callee'] = $callee;

            $atts = [ 'ct', 'wt', 'cpu', 'mu', 'pmu', 'alloc', 'free'];
            $sql = "INSERT INTO logs_profile (caller, callee";

            foreach ($atts as $att) {
                if (pres($att, $data)) {
                    $sql .= ", $att";
                }
            };

            $sql .= ") VALUES (" . $dbhr->quote($caller) . ", " . $dbhr->quote($callee);

            foreach ($atts as $att) {
                if (pres($att, $data)) {
                    $sql .= ", {$data[$att]}";
                }
            }

            $sql .= ") ON DUPLICATE KEY UPDATE ";

            foreach ($atts as $att) {
                if (pres($att, $data)) {
                    $sql .= "$att = $att + {$data[$att]}, ";
                }
            }

            $sql = substr($sql, 0, strlen($sql) - 2) . ";";
            $dbhm->background($sql);
        }
    }
}
// @codeCoverageIgnoreEnd

