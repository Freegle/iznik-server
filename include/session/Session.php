<?php
namespace Freegle\Iznik;

use Firebase\JWT\JWT;

$sessionPrepared = FALSE;

class Session {
    # Based on http://stackoverflow.com/questions/244882/what-is-the-best-way-to-implement-remember-me-for-a-website
    private $dbhr;
    private $dbhm;
    private $id;

    public static function modtools() {
        # Whether we are in ModTools or Freegle Direct.  For API calls this is passed as a request parameter.
        if (isset($_REQUEST) && array_key_exists('modtools', $_REQUEST)) {
            return filter_var($_REQUEST['modtools'], FILTER_VALIDATE_BOOLEAN);
        } else {
            # Default TRUE for scripts.
            return TRUE;
        }
    }

    public static function prepareSession($dbhr, $dbhm) {
        # We only want to do the prepare once, otherwise we will generate many headers.

        # Backbone may send requests wrapped insode a model; extract them.
        if (array_key_exists ( 'model', $_REQUEST )) {
            $_REQUEST = array_merge ( $_REQUEST, json_decode ( $_REQUEST ['model'], true ) );
        }

        if (!Utils::pres('sessionPrepared', $GLOBALS)) {
            $GLOBALS['sessionPrepared'] = TRUE;

            if (Utils::pres('api_key', $_REQUEST)) {
                # We have been passed a session id.
                #
                # One example of this is when we are called from Swagger.
                session_id($_REQUEST['api_key']);
            }

            # The IOS app needs to be able to set the session id.  This is as secure as the Cookie header would be.
            $headers = Session::getallheaders();
            $sessid = Utils::presdef('X-Iznik-Php-Session', $headers, NULL);
            if ($sessid && strlen($sessid) >= 26 && ctype_alnum($sessid)) {
                @session_destroy();
                session_id($sessid);
            }

            # We need to also be prepared to do a session_start here, because if we're running in the UT then the session_start
            # above will happen once at the start of the test, when the script is first included, and we will later on destroy
            # it.
            #error_log("prepare " . isset($_SESSION) . " id " . session_id());
            if (session_status() == PHP_SESSION_NONE) {
                # Don't return PHP session cookie.  There is a risk of conflict between different servers, and our
                # persistent/JWT code means we don't need it.
                ini_set('session.use_cookies', '0');
                @setcookie('PHPSESSID', '', -1, '/');
                @session_start();
            }

            # We might have a partner key which allows us access to the API when not logged in as a user.
            $_SESSION['partner'] = FALSE;

            if (Utils::pres('partner', $_REQUEST)) {
                list ($partner, $domain) = Session::partner($dbhr, $_REQUEST['partner']);
                $_SESSION['partner'] = $partner;
                $_SESSION['partnerdomain'] = $domain;
            }

            # Always verify the persistent session if passed.  This guards against
            # session id collisions, which can happen (albeit quite rarely).
            $cookie = Utils::presdef('persistent', $_REQUEST, NULL);

            if (!$cookie) {
                # Check headers too.
                $headers = Session::getallheaders();
                if (Utils::pres('Authorization', $headers)) {
                    $auth = $headers['Authorization'];

                    if (strpos($auth, 'Iznik ') === 0) {
                        $cookie = json_decode(substr($auth, 6), TRUE);
                    }
                }
            }

            if ($cookie) {
                # Check our cookie to see if it's a valid session
                #error_log("Cookie " . var_export($cookie, TRUE));

                if ((array_key_exists('id', $cookie)) &&
                    (array_key_exists('series', $cookie)) &&
                    (array_key_exists('token', $cookie))
                ) {
                    $sesscook = Utils::presdef('persistent', $_SESSION, NULL);
                    #error_log("Check vs " . var_export($sesscook, TRUE));

                    if (Utils::pres('id', $_SESSION) && Utils::presdef('userid', $cookie, NULL) != $_SESSION['id']) {
                        # The cookie we have been passed doesn't match the one we are logged in as.  That's a bit
                        # worrying.  Log it, and don't switch.
                        $msg = "Persistent session cookie doesn't match logged in user; logged in as {$_SESSION['id']} passed " . json_encode($cookie);
                        \Sentry\captureMessage($msg);
                        error_log($msg);
                    } else if (!Utils::presdef('id', $_SESSION, NULL) || $sesscook != $cookie) {
                        # We are not logged in as the correct user (or at all).  Try to switch to the persistent one.
                        #error_log("Logged in wrongly as " . var_export($sesscook, TRUE) . " when should be " . var_export($cookie, TRUE));
                        $_SESSION['id'] = NULL;
                        $s = new Session($dbhr, $dbhm);
                        $s->verify($cookie['id'], $cookie['series'], $cookie['token']);
                    }
                }
            }

            if (!Utils::pres('id', $_SESSION)) {
                # We might not have a cookie, but we might have push credentials.  This happens when we are logged out
                # on the client but get a notification.  That is sufficient to log us in.
                $pushcreds = Utils::presdef('pushcreds', $_REQUEST, NULL);
                #error_log("No session, pushcreds $pushcreds " . var_exporT($_REQUEST, TRUE));
                if ($pushcreds) {
                    $sql = "SELECT * FROM users_push_notifications WHERE subscription = ?;";
                    $pushes = $dbhr->preQuery($sql, [$pushcreds]);
                    foreach ($pushes as $push) {
                        $s = new Session($dbhr, $dbhm);
                        #error_log("Log in as {$push['userid']}");
                        $s->create($push['userid']);
                    }
                }
            }
        }
    }

    public static function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }

    public static function partner($dbhr, $key) {
        $ret = FALSE;
        $domain = NULL;

        $partners = $dbhr->preQuery("SELECT * FROM partners_keys WHERE `key` = ?;", [ $key ]);
        foreach ($partners as $partner) {
            $ret = $partner;
            $domain = $partner['domain'];
        }

        return([$ret, $domain]);
    }

    public static function whoAmId(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        Session::prepareSession($dbhr, $dbhm);

        $id = Utils::presdef('id', $_SESSION, NULL);

        if ($id) {
            # Add context for Sentry exceptions.
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($id): void {
                $scope->setUser(['id' => $id]);
            });
        }
        return $id;
    }

    public static function whoAmI(LoggedPDO $dbhr, $dbhm)
    {
        $id = self::whoAmId($dbhr, $dbhm);
        $ret = NULL;
        #error_log("Session::whoAmI $id in " . session_id());

        if ($id) {
            # We are logged in.  Get our details
            $r = User::get($dbhr, $dbhm, $id);

            if ($r->getId() == $id) {
                #error_log("Return cached user $id");
                $ret = $r;
            }
            #error_log("Found " . $ret->getId() . " role " . $ret->isModerator());
        }

        return($ret);
    }

    public static function clearSessionCache() {
        # We cache some information.
        $_SESSION['notification'] = [];
        $_SESSION['modorowner'] = [];
    }

    public function getUserId() {
        $sql = "SELECT userid FROM sessions WHERE id = ?;";
        $sessions = $this->dbhr->preQuery($sql, [ $this->id ]);
        $ret = NULL;
        foreach ($sessions as $session) {
            $ret = $session['userid'];
        }

        return($ret);
    }

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function create($userid) {
        # If we wanted to only allow login from a single device/browser, we'd destroy cookies at this point.  But
        # we want to allow login on as many devices as the user wants.  We want to leave any existing sessions around
        # so that if they are used later on by other clients, they'll still work.  This can happen if they're stored
        # in local storage - we can get different sessions on different clients, and unless we allow them all, one
        # device can effectively log another one out.  They get tidied up via a cron script.
        # TODO SHA1 is no longer brilliantly secure.
        $series = Utils::devurandom_rand();
        $token  = Utils::devurandom_rand();
        $thash  = sha1($token);

        $sql = "INSERT INTO sessions (`userid`, `series`, `token`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);";

        $this->dbhm->preExec($sql, [
            $userid,
            $series,
            $thash
        ]);

        $id = $this->dbhm->lastInsertId();
        $this->id = $id;

        $_SESSION['id'] = $userid;
        #error_log("Created session for $userid in " . session_id());
        $_SESSION['logged_in'] = TRUE;

        $_SESSION['persistent'] = [
            'id' => $id,
            'series' => $series,
            'token' => $thash,
            'userid' => $userid
        ];
        
        return ($_SESSION['persistent']);
    }

    public function verify($id, $series, $token) {
        # Look for a session.
        $sql = "SELECT * FROM sessions WHERE id = ? AND series = ? AND token = ?;";
        $sessions = $this->dbhr->preQuery($sql, [
            $id,
            $series,
            $token
        ]);

        #error_log("SELECT * FROM sessions WHERE id = $id AND series = $series AND token = '$token';");

        /** @noinspection PhpUnusedLocalVariableInspection */
        foreach ($sessions as $session) {
            # Leave the cookie in existence, marked as active.  This is a bit less secure, but it does mean that we
            # can frequently use the cookie to recover a session when the PHP session is no longer there, including
            # from multiple devices.
            $userid = $session['userid'];
            $_SESSION['id'] = $userid;
            $_SESSION['logged_in'] = TRUE;

            # Store this away in our PHP session, so that it gets returned the client, and will then work again
            # next time.
            $_SESSION['persistent'] = [
                'id' => $id,
                'series' => $series,
                'token' => $token,
                'userid' => $userid
            ];

            # Update the last access time, unless we have done so recently.
            $age = time() - strtotime($session['lastactive']);
            if ($age > 600) {
                $this->dbhm->preExec("UPDATE sessions SET lastactive = NOW() WHERE  id = ? AND series = ? AND token = ?;", [
                    $id,
                    $series,
                    $token
                ]);
            }

            return($userid);
        }

        # We failed to verify.  Some systems would zap the existing sessions here in case there was a theft, but
        # this means that a bad cookie on one device will log out other devices, which can create a ping-pong
        # from which you don't recover.

        return(NULL);
    }

    public function destroy($userid, $series) {
        # Deleting the cookie will mean that we can no longer use this cookie to sign in on any device - which means
        # that if you log out on one device, the others will get logged out too (once the PHP session goes, anyway).
        #error_log(var_export($this->dbhr, true));
        if ($userid) {
            # If we're doing an explicit logout we're called with a null $series and want to zap all sessions for this
            # user.  Otherwise we only want to delete the session with this series, otherwise a failed login for this
            # user would log out other users.
            $sql = $series ? "DELETE FROM sessions WHERE userid = ? AND series = ?;" : "DELETE FROM sessions WHERE userid = ?;";
            $parms = $series ? [ $userid, $series ] : [ $userid ];
            $this->dbhm->preExec($sql, $parms);

            $l = new Log($this->dbhr, $this->dbhm);
            $l->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_LOGOUT,
                'byuser' => $userid,
                'text' => "Series $series"
            ]);
        }

        $_SESSION['id'] = NULL;
        $_SESSION['logged_in'] = FALSE;
    }

    public static function JWT($dbhr, $dbhm) {
        $ret = NULL;

        # Generate a JWT for this user.
        $id = Session::whoAmId($dbhr, $dbhm);

        $privateKey = trim(file_get_contents('/etc/iznik_jwt_secret'));

        if ($privateKey) {
            // Create a JWT.  This can be very long-lived because the Go API will check it against the database.  We
            // need to do that check in order to be able to force logout.
            $ret = JWT::encode([
                'id' => $id . "",
                'sessionid' => $_SESSION['persistent']['id'] . "",
                'exp' => (time() + 365 * 30 * 24 * 60 * 60)
           ],
           $privateKey,
           'HS256');
        }

        return $ret;
    }
}
