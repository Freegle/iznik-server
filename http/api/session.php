<?php
namespace Freegle\Iznik;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

function session() {
    global $dbhr, $dbhm;
    $me = NULL;

    $modtools = Session::modtools();

    $sessionLogout = function($dbhr, $dbhm) {
        $id = 'No session';
        @session_start();
        if (isset($_SESSION)) {
            $id = Utils::pres('id', $_SESSION);
            if ($id) {
                $s = new Session($dbhr, $dbhm);
                $s->destroy($id, null);
            }
        }

        # Destroy the PHP session
        try {
            @session_destroy();
            @session_unset();
            @session_start();
            @session_regenerate_id(TRUE);
        } catch (\Exception $e) {
        }

        return $id;
    };

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            # Check if we're logged in.  This will handle persistent sessions, pushcreds, as well as login via
            # the PHP session.
            $me = Session::whoAmI($dbhm, $dbhm);

            # Mobile app can send its version number, which we can use to determine if it is out of date.
            $appversion = Utils::presdef('appversion', $_REQUEST, NULL);

            if ($appversion && substr($appversion, 0, 1) == '2') {
                $ret = array('ret' => 123, 'status' => 'App is out of date');
            } else {
                if (Utils::pres('id', $_SESSION) && $me) {
                    # We're logged in.
                    if (!$modtools) {
                        # ...but we are running an old version of the code, probably the app, because we have
                        # not indicated which version we have.
                        $last = Utils::presdef('lastversiontime', $_REQUEST, NULL);

                        if (!$last || time() - $last > 24 * 60 * 60) {
                            $webversion = Utils::presdef('webversion', $_REQUEST, NULL);
                            $appversion = Utils::presdef('appversion', $_REQUEST, NULL);
                            $dbhm->background("INSERT INTO users_builddates (userid, webversion, appversion) VALUES ({$_SESSION['id']}, '$webversion', '$appversion') ON DUPLICATE KEY UPDATE timestamp = NOW(), webversion = '$webversion', appversion = '$appversion';");
                        }

                        # Ensure we have the cookie set up for Discourse forum login.
                        if (array_key_exists('persistent', $_SESSION)) {
                            #error_log("Set Discourse Cookie");
//                            @setcookie('Iznik-Forum-SSO', json_encode($_SESSION['persistent']), 0, '/', 'ilovefreegle.org');
                            @setcookie('Iznik-Forum-SSO', json_encode($_SESSION['persistent']), [
                               'expires' => 0,
                               'path' => '/',
                               'domain' => 'ilovefreegle.org',
                               'secure' => 'TRUE',
                               'httponly' => 'FALSE',
                               'samesite' => 'None'
                           ]);
                        }
                    } else {
                        # ModTools.  Ensure we have the cookie set up for Discourse login.
                        if (array_key_exists('persistent', $_SESSION)) {
                            #error_log("Set Discourse Cookie");
//                            @setcookie('Iznik-Discourse-SSO', json_encode($_SESSION['persistent']), 0, '/', 'modtools.org');
                            @setcookie('Iznik-Discourse-SSO', json_encode($_SESSION['persistent']), [
                                'expires' => 0,
                                'path' => '/',
                                'domain' => 'modtools.org',
                                'secure' => 'TRUE',
                                'httponly' => 'FALSE',
                                'samesite' => 'None'
                            ]);
                        }
                    }

                    $components = Utils::presdef('components', $_REQUEST, ['all']);
                    if ($components ==  ['all']) {
                        // Get all
                        $components = NULL;
                    }

                    if (gettype($components) == 'string') {
                        $components = [ $components ];
                    }

                    $ret = [ 'ret' => 0, 'status' => 'Success', 'myid' => Utils::presdef('id', $_SESSION, NULL) ];

                    if (!$components || (gettype($components) == 'array' && in_array('me', $components))) {
                        # Don't want to use cached information when looking at our own session.
                        $ret['me'] = $me->getPublic(NULL, FALSE, FALSE, FALSE, FALSE);
                        $loc = $me->getCity();
                        $ret['me']['city'] = $loc[0];
                        $ret['me']['lat'] = $loc[1];
                        $ret['me']['lng'] = $loc[2];

                        # If we've not logged in with a secure login mechanism then we can't access Support.
                        if (!$me->isAdminOrSupport() && ($ret['me']['systemrole'] == User::SYSTEMROLE_SUPPORT || $ret['me']['systemrole'] == User::SYSTEMROLE_ADMIN)) {
                            // TODO Disabled for now.
//                            $ret['me']['systemrole'] = User::ROLE_MODERATOR;
//                            $ret['me']['supportdisabled'] = TRUE;
                        }

                        if (Utils::pres('profile', $ret['me']) && Utils::pres('url', $ret['me']['profile']) && strpos($ret['me']['profile']['url'], IMAGE_DOMAIN) !== FALSE) {
                            $ret['me']['profile']['ours'] = TRUE;
                        }

                        # Don't need to return this, and it might be large.
                        $ret['me']['messagehistory'] = NULL;

                        # Whether this user is on a group where microvolunteering is enabled.
                        $ret['me']['microvolunteering'] = $me->microVolunteering();

                        # The trust level for this user, as used by microvolunteering.
                        $ret['me']['trustlevel'] = $me->getPrivate('trustlevel');

                        $ret['me']['source'] = $me->getPrivate('source');
                    }

                    $ret['persistent'] = Utils::presdef('persistent', $_SESSION, NULL);
                    if ($ret['persistent']) {
                        $ret['jwt'] = Session::JWT($dbhr, $dbhm);
                    }

                    if (!$components || in_array('notifications', $components)) {
                        $settings = $me->getPrivate('settings');
                        $settings = $settings ? json_decode($settings, TRUE) : [];
                        $ret['me']['settings']['notifications'] = array_merge([
                            'email' => TRUE,
                            'emailmine' => FALSE,
                            'push' => TRUE,
                            'facebook' => TRUE
                        ], Utils::presdef('notifications', $settings, []));

                        $n = new PushNotifications($dbhr, $dbhm);
                        $ret['me']['notifications']['push'] = $n->get($ret['me']['id']);
                    }

                    if ($modtools) {
                        if (!$components || in_array('allconfigs', $components)) {
                            $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);
                            $ret['configs'] = $me->getConfigs(TRUE);
                        } else if (in_array('configs', $components)) {
                            $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);
                            $ret['configs'] = $me->getConfigs(FALSE);
                        }
                    }

                    if (!$components || in_array('emails', $components)) {
                        $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);
                        $ret['emails'] = $me->getEmails();
                    }

                    if (!$components || in_array('aboutme', $components)) {
                        $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);
                        $ret['me']['aboutme'] = $me->getAboutMe();
                    }

                    if (!$components || in_array('newsfeed', $components)) {
                        # Newsfeed count.  We return this in the session to avoid getting it on each page transition
                        # in the client.
                        $n = new Newsfeed($dbhr, $dbhm);
                        $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);
                        $ret['newsfeedcount'] = $n->getUnseen($me->getId());
                    }

                    if (!$components || in_array('logins', $components)) {
                        $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);
                        $ret['logins'] = $me->getLogins(FALSE);
                    }

                    if (!$components || in_array('expectedreplies', $components)) {
                        $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);

                        if ($me) {
                            $expecteds = $me->listExpectedReplies($me->getId());
                            $ret['me']['expectedreplies'] = count($expecteds);
                            $ret['me']['expectedchats'] = $expecteds;
                        }
                    }

                    if ($components && in_array('openposts', $components)) {
                        $me = $me ? $me : Session::whoAmI($dbhm, $dbhm);

                        if ($me) {
                            $m = new MessageCollection($dbhr, $dbhm);
                            $ret['me']['openposts'] = $m->countMyPostsOpen();
                        }
                    }

                    if (!$components || in_array('groups', $components) || in_array('work', $components)) {
                        # Get groups including work when we're on ModTools; don't need that on the user site.
                        $u = new User($dbhr, $dbhm);
                        $ret['groups'] = $u->getMemberships(FALSE, NULL, $modtools, TRUE, $_SESSION['id']);

                        $gids = [];

                        foreach ($ret['groups'] as &$group) {
                            $gids[] = $group['id'];

                            # Remove large attributes we don't need in session.
                            unset($group['welcomemail']);
                            unset($group['description']);
                            unset($group['settings']['chaseups']['idle']);
                            unset($group['settings']['branding']);
                        }

                        if (count($gids)) {
                            $polys = $dbhr->preQuery("SELECT id,  ST_AsText(ST_Envelope(polyindex)) AS bbox,  ST_AsText(polyindex) AS poly FROM `groups` WHERE id IN (" . implode(',', $gids) . ")");

                            foreach ($ret['groups'] as &$group) {
                                foreach ($polys as $poly) {
                                    if ($poly['id'] == $group['id']) {
                                        try {
                                            $g = new \geoPHP();
                                            $p = $g->load($poly['bbox']);
                                            $bbox = $p->getBBox();
                                            $group['bbox'] = [
                                                'swlat' => $bbox['miny'],
                                                'swlng' => $bbox['minx'],
                                                'nelat' => $bbox['maxy'],
                                                'nelng' => $bbox['maxx'],
                                            ];

                                            $group['polygon'] = $poly['poly'];
                                        } catch (\Exception $e) {
                                            error_log("Bad polygon data for {$group['id']}");
                                        }
                                    }
                                }
                            }
                        }

                        if ($modtools) {
                            if (!$components || in_array('work', $components)) {
                                $ret['work'] = $me->getWorkCounts($ret['groups']);

                                # Get Discourse notifications and unread topics, to drive mods through to that site.
                                if ($me->isFreegleMod()) {
                                    $unreadcount = 0;
                                    $notifcount = 0;
                                    $newcount = 0;

                                    # We cache the discourse name in the session for speed.  It is very unlikely to change.
                                    $username = Utils::presdef('discoursename', $_SESSION, NULL);

                                    if (!$username) {
                                        # We need this quick or not at all.  Also need to pass authentication in headers rather
                                        # than URL parameters.
                                        $ctx = stream_context_create(array('http'=> [
                                            'timeout' => 1,
                                            "method" => "GET",
                                            "header" => "Accept-language: en\r\n" .
                                                "Api-Key: " . DISCOURSE_APIKEY . "\r\n" .
                                                "Api-Username: system\r\n"
                                        ]));

                                        # Have to look up the name we need for other API calls by user id.
                                        $username = @file_get_contents(DISCOURSE_API . '/users/by-external/' . $me->getId() . '.json', FALSE, $ctx);
                                    }

                                    if ($username) {
                                        $_SESSION['discoursename'] = $username;
                                        $discourse = Utils::presdef('discourse', $_SESSION, NULL);

                                        if (!$discourse || (time() - Utils::presdef('timestamp', $discourse, time()) > 300)) {
                                            $users = json_decode($username, TRUE);

                                            if (Utils::pres('users', $users) && count($users['users'])) {
                                                $name = $users['users'][0]['username'];

                                                # We don't want to fetch Discourse info too often, for speed.

                                                $ctx = stream_context_create(array('http'=> [
                                                    'timeout' => 1,
                                                    "method" => "GET",
                                                    "header" => "Accept-language: en\r\n" .
                                                        "Api-Key: " . DISCOURSE_APIKEY . "\r\n" .
                                                        "Api-Username: $name\r\n"
                                                ]));

                                                $news = @file_get_contents(DISCOURSE_API . '/new.json', FALSE, $ctx);
                                                $unreads  = @file_get_contents(DISCOURSE_API . '/unread.json', FALSE, $ctx);
                                                $notifs = @file_get_contents(DISCOURSE_API . '/session/current.json', FALSE, $ctx);

                                                if ($news && $unreads && $notifs) {
                                                    $topics = json_decode($news, TRUE);

                                                    if (Utils::pres('topic_list', $topics)) {
                                                        $newcount = count($topics['topic_list']['topics']);
                                                    }

                                                    $topics = json_decode($unreads, TRUE);

                                                    if (Utils::pres('topic_list', $topics)) {
                                                        $unreadcount = count($topics['topic_list']['topics']);
                                                    }

                                                    $notifs = json_decode($notifs, TRUE);
                                                    if (Utils::pres('unread_notifications', $notifs)) {
                                                        $notifcount = intval($notifs['unread_notifications']);
                                                    }

                                                    $_SESSION['discourse'] = [
                                                        'notifications' => $notifcount,
                                                        'unreadtopics' => $unreadcount,
                                                        'newtopics' => $newcount,
                                                        'timestamp' => time()
                                                    ];
                                                }
                                                #error_log("$name notifs $notifcount new topics $newcount unread topics $unreadcount");
                                            }
                                        }
                                    }
                                }

                                # Using the value from session means we fall back to an old value if we can't get it, e.g.
                                # for rate-limiting.
                                $ret['discourse'] = Utils::presdef('discourse', $_SESSION, NULL);
                            }
                        }
                    }
                } else {
                    $ret = array('ret' => 1, 'status' => 'Not logged in');
                }
            }

            break;
        }

        case 'POST': {
            # Don't want to use cached information when looking at our own session.
            $me = Session::whoAmI($dbhm, $dbhm);

            # Login
            $fblogin = array_key_exists('fblogin', $_REQUEST) ? filter_var($_REQUEST['fblogin'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $fbaccesstoken = Utils::presdef('fbaccesstoken', $_REQUEST, NULL);
            $fblimited = Utils::presbool('fblimited', $_REQUEST, FALSE);
            $googlelogin = array_key_exists('googlelogin', $_REQUEST) ? filter_var($_REQUEST['googlelogin'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $googleauthcode = array_key_exists('googleauthcode', $_REQUEST) ? $_REQUEST['googleauthcode'] : NULL;
            $googlejwt = array_key_exists('googlejwt', $_REQUEST) ? $_REQUEST['googlejwt'] : NULL;
            $yahoologin = array_key_exists('yahoologin', $_REQUEST) ? filter_var($_REQUEST['yahoologin'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $yahoocodelogin = Utils::presdef('yahoocodelogin', $_REQUEST, NULL);
            $applelogin = array_key_exists('applelogin', $_REQUEST) ? filter_var($_REQUEST['applelogin'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $applecredentials = array_key_exists('applecredentials', $_REQUEST) ? $_REQUEST['applecredentials'] : NULL;
            $mobile = array_key_exists('mobile', $_REQUEST) ? filter_var($_REQUEST['mobile'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $email = array_key_exists('email', $_REQUEST) ? $_REQUEST['email'] : NULL;
            $password = array_key_exists('password', $_REQUEST) ? $_REQUEST['password'] : NULL;
            $returnto = array_key_exists('returnto', $_REQUEST) ? $_REQUEST['returnto'] : NULL;
            $action = Utils::presdef('action', $_REQUEST, NULL);
            $host = Utils::presdef('host', $_REQUEST, NULL);
            $keyu = (Utils::presint('u', $_REQUEST, NULL));
            $keyk = Utils::presdef('k', $_REQUEST, NULL);

            $id = NULL;
            $user = User::get($dbhr, $dbhm);
            $f = NULL;
            $ret = array('ret' => 1, 'status' => 'Invalid login details');

            if ($keyu && $keyk) {
                # uid and key login, used in email links and impersonation.
                $u = new User($dbhr, $dbhm, $keyu);

                # Check if this is a TN user BEFORE logging in
                $tnuserid = $u->getPrivate('tnuserid');
                if ($tnuserid) {
                    # Don't log in TN users via link login - block IP and send warning email
                    $email = $u->getEmailPreferred();
                    $username = $u->getName();
                    $ip = Utils::getClientIp();
                    $timestamp = date('Y-m-d H:i:s');

                    # Block the IP address
                    $ipBlocker = new IPBlocker($dbhr, $dbhm);
                    $ipBlocker->blockIP(
                        $ip,
                        "Attempted TN user login via u/k parameters (link login)",
                        $keyu,
                        $username,
                        $email
                    );

                    $ret = ['ret' => 5, 'status' => 'Now why would you do this for a TN user, hmmm?'];
                } else if (Utils::presdef('id', $_SESSION, null) == $keyu || $u->linkLogin($keyk)) {
                    # Not a TN user, proceed with normal login
                    $id = $keyu;
                    $ret = ['ret' => 0, 'status' => 'Success'];
                }
            } else if ($fblimited) {
                # We've been asked to log in using Facebook Limited Login
                $f = new Facebook($dbhr, $dbhm);
                list ($session, $ret) = $f->loginLimited($fbaccesstoken);
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
            } else if ($fblogin) {
                # We've been asked to log in via Facebook.
                $f = new Facebook($dbhr, $dbhm);
                list ($session, $ret) = $f->login($fbaccesstoken);
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
            } else if ($yahoocodelogin) {
                $y = Yahoo::getInstance($dbhr, $dbhm, $host);
                list ($session, $ret) = $y->loginWithCode($yahoocodelogin);
                error_log("Yahoo code login with $yahoocodelogin returned " . var_export($ret, TRUE));
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
                error_log("User id from session $id");
            } else if ($yahoologin) {
                # Yahoo old-style.  This is no longer used by FD, and as of Jan 2020 didn't work.
                $y = Yahoo::getInstance($dbhr, $dbhm, $host);
                list ($session, $ret) = $y->login($returnto);
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
            } else if ($googlelogin) {
                # Google
                $g = new Google($dbhr, $dbhm, $mobile);

                if ($googleauthcode) {
                    list ($session, $ret) = $g->login($googleauthcode);
                } else if ($googlejwt) {
                    list ($session, $ret) = $g->loginWithJWT($googlejwt);
                }
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
            } else if ($applelogin) {
                # Apple
                $a = new Apple($dbhr, $dbhm);
                list ($session, $ret) = $a->login($applecredentials);
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
            } else if ($action) {
                switch ($action) {
                    case 'LostPassword': {
                        $id = $user->findByEmail($email);
                        $ret = [ 'ret' => 2, 'status' => "We don't know that email address" ];
                        
                        if ($id) {
                            $u = User::get($dbhr, $dbhm, $id);
                            $u->forgotPassword($email);
                            $ret = [ 'ret' => 0, 'status' => "Success" ];
                        }    
                        
                        break;
                    }

                    case 'Unsubscribe': {
                        $id = $user->findByEmail($email);
                        $ret = [ 'ret' => 2, 'status' => "We don't know that email address" ];

                        if ($id) {
                            $u = User::get($dbhr, $dbhm, $id);

                            # Get an auto-login unsubscribe link
                            $u->confirmUnsubscribe();
                            $ret = [ 'ret' => 0, 'status' => "Success", 'emailsent' => TRUE ];
                        }

                        break;
                    }

                    case 'Forget': {
                        $ret = array('ret' => 1, 'status' => 'Not logged in');

                        $partner = Utils::presdef('partner', $_REQUEST, NULL);
                        $id = Utils::presdef('id', $_REQUEST, NULL);

                        if ($partner) {
                            // Might not be logged in but still have control over this user.
                            list ($partner, $domain) = Session::partner($dbhr, $partner);

                            if ($partner) {
                                $u = new User($dbhr, $dbhm, $id);

                                if ($u->getId() == $id && $u->getPrivate('ljuserid')) {
                                    $u->limbo();
                                    $ret = [ 'ret' => 0, 'status' => "Success" ];
                                }
                            }
                        } else if ($me) {
                            # We don't allow mods/owners to do this, as they might do it by accident.
                            $ret = array('ret' => 2, 'status' => 'Please demote yourself to a member first');

                            if (!$me->isModerator()) {
                                # We don't allow spammers to do this.
                                $ret = array('ret' => 3, 'status' => 'We can\'t do this.');

                                $s = new Spam($dbhr, $dbhm);

                                if (!$s->isSpammer($me->getEmailPreferred())) {
                                    $me->limbo();

                                    # Log out.
                                    $ret = array('ret' => 0, 'status' => 'Success', 'destroyed' => $sessionLogout($dbhr, $dbhm));
                                }
                            }
                        }
                        break;
                    }

                    case 'Related': {
                        $ret = array('ret' => 1, 'status' => 'Not logged in');

                        if ($me) {
                            $userlist = Utils::presdef('userlist', $_REQUEST, NULL);

                            $ret = [ 'ret' => 0, 'status' => "Success" ];

                            if (gettype($userlist) == 'array') {
                                # Check whether the userlist contains at least one userid that has recently been
                                # active using the same IP address.  We'd expect this to be the case, and we have
                                # seen examples where we get related reports from googlebot.com, which suggests
                                # there are extensions out there which are exfiltrating session data from user's
                                # browsers - perhaps during link preview.  If that happens to two users at the same
                                # time on the same crawler, then we would get a report of related users.  This IP
                                # check prevents that.
                                $foundip = FALSE;
                                $ip = Utils::getClientIp();
                                $loki = Loki::getInstance();

                                foreach ($userlist as $userid) {
                                    if ($loki->hasUserAccessedFromIP($userid, $ip)) {
                                        $foundip = TRUE;
                                    } else {
                                        $ret = array('ret' => 2, 'status' => 'Not from recently active IP');
                                    }
                                }

                                if ($foundip) {
                                    $me->related($userlist);
                                    $ret = [ 'ret' => 0, 'status' => "Success" ];
                                }
                            }
                        }
                    }
                }
            }
            else if ($password && $email) {
                # Native login via username and password
                $ret = array('ret' => 2, 'status' => "We don't know that email address.  If you're new, please Register.");
                $possid = $user->findByEmail($email);
                if ($possid) {
                    $ret = array('ret' => 3, 'status' => "The password is wrong.  Maybe you've forgotten it?");
                    $u = User::get($dbhr, $dbhm, $possid);

                    # If we are currently logged in as an admin, then we can force a log in as anyone else.  This is
                    # very useful for debugging.
                    $force = $me && $me->isAdmin();

                    if ($u->login($password, $force)) {
                        $ret = array('ret' => 0, 'status' => 'Success');
                        $id = $possid;
                    }
                }
            }

            if ($id) {
                # Return some more useful info.
                $u = User::get($dbhr, $dbhm, $id);
                $ret['user'] = $u->getPublic();
                $ret['persistent'] = Utils::presdef('persistent', $_SESSION, NULL);
                if ($ret['persistent']) {
                    $ret['jwt'] = Session::JWT($dbhr, $dbhm);
                }
            }

            break;
        }

        case 'PATCH': {
            # Don't want to use cached information when looking at our own session.
            $me = Session::whoAmI($dbhm, $dbhm);

            $notifs = Utils::presdef('notifications', $_REQUEST, NULL);
            $email = array_key_exists('email', $_REQUEST) ? $_REQUEST['email'] : NULL;

            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($notifs) {
                $uid = $me ? $me->getId() : NULL;

                if (!$me && Session::modtools()) {
                    # We allow setting up of a MT push subscription to a user without authentication for testing.
                    $u = new User($dbhr, $dbhm);
                    $uid = $u->findByEmail($email);
                }

                if ($uid) {
                    $n = new PushNotifications($dbhr, $dbhm);
                    $push = Utils::presdef('push', $notifs, NULL);
                    if ($push && Utils::pres('type', $push) && Utils::pres('subscription', $push)) {
                        switch ($push['type']) {
                            case PushNotifications::PUSH_GOOGLE:
                            case PushNotifications::PUSH_FIREFOX:
                            case PushNotifications::PUSH_FCM_ANDROID:
                            case PushNotifications::PUSH_FCM_IOS:
                            case PushNotifications::PUSH_BROWSER_PUSH:
                                $n->add($uid, $push['type'], $push['subscription']);
                                break;
                        }

                        $ret = ['ret' => 0, 'status' => 'Success'];
                    }
                }
            }

            if ($me) {
                if (array_key_exists('marketingconsent', $_REQUEST)) {
                    // Consent to marketing under PECR.
                    $consent = Utils::presbool('marketingconsent', $_REQUEST, FALSE);
                    $me->setPrivate('marketingconsent', $consent);
                }

                $fullname = Utils::presdef('displayname', $_REQUEST, NULL);
                $firstname = Utils::presdef('firstname', $_REQUEST, NULL);
                $lastname = Utils::presdef('lastname', $_REQUEST, NULL);
                $password = Utils::presdef('password', $_REQUEST, NULL);
                $key = Utils::presdef('key', $_REQUEST, NULL);
                $source = Utils::presdef('source', $_REQUEST, NULL);

                if ($source) {
                    $me->setPrivate('source', $source);
                }
                if ($firstname) {
                    $me->setPrivate('firstname', $firstname);
                }
                if ($lastname) {
                    $me->setPrivate('lastname', $lastname);
                }
                if ($fullname) {
                    # Fullname is what we set from the client.  Zap the first/last names so that people who change
                    # their name for privacy reasons are respected.
                    $me->setPrivate('fullname', $fullname);
                    $me->setPrivate('firstname', NULL);
                    $me->setPrivate('lastname', NULL);
                }

                $settings = Utils::presdef('settings', $_REQUEST, NULL);
                if ($settings) {
                    $me->setPrivate('settings', json_encode($settings));

                    if (Utils::pres('mylocation', $settings)) {
                        # Save this off as the last known location.
                        $me->setPrivate('lastlocation', $settings['mylocation']['id']);
                    }
                }

                $ret = ['ret' => 0, 'status' => 'Success'];

                $email = Utils::presdef('email', $_REQUEST, NULL);
                $force = array_key_exists('force', $_REQUEST) ? filter_var($_REQUEST['force'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                if ($email) {
                    if (!$me->verifyEmail($email, $force)) {
                        $ret = ['ret' => 10, 'status' => "We've sent a verification mail; please check your mailbox." ];
                    }
                }

                if ($key) {
                    $confirmid = $me->confirmEmail($key);

                    if (!$confirmid) {
                        $ret = ['ret' => 11, 'status' => 'Confirmation failed'];
                    } else {
                        # That might have merged.
                        $sessions = $dbhr->preQuery("SELECT * FROM sessions WHERE userid = ?;", [
                            $confirmid
                        ]);

                        foreach ($sessions as $session) {
                            # We need to return new session info for the merged user.  The client will pick
                            # this up and use it for future requests to the v2 API.  If we didn't do
                            # this they'd have a JWT/persistent for the old user/session which no longer exists.
                            $_SESSION['id'] = $confirmid;
                            $_SESSION['persistent'] = [
                                'id' => $session['id'],
                                'series' => $session['series'],
                                'token' => $session['token'],
                                'userid' => $confirmid
                            ];

                            $ret['persistent'] = Utils::presdef('persistent', $_SESSION, NULL);
                            if ($ret['persistent']) {
                                $ret['jwt'] = Session::JWT($dbhr, $dbhm);
                            }
                        }
                    }
                }

                if ($password) {
                    $me->addLogin(User::LOGIN_NATIVE, $me->getId(), $password);
                }

                if (array_key_exists('onholidaytill', $_REQUEST)) {
                    $me->setPrivate('onholidaytill', $_REQUEST['onholidaytill']);
                }

                if (array_key_exists('relevantallowed', $_REQUEST)) {
                    $me->setPrivate('relevantallowed', $_REQUEST['relevantallowed']);
                }

                if (array_key_exists('newslettersallowed', $_REQUEST)) {
                    $me->setPrivate('newslettersallowed', $_REQUEST['newslettersallowed']);
                }

                if (array_key_exists('aboutme', $_REQUEST)) {
                    $me->setAboutMe($_REQUEST['aboutme']);

                    if (strlen($_REQUEST['aboutme']) > 5) {
                        # Newsworthy.  But people might edit them a lot for typos, so look for a recent other
                        # one and update that before adding a new one.
                        $n = new Newsfeed($dbhr, $dbhm);
                        $nid = $n->findRecent($me->getId(), Newsfeed::TYPE_ABOUT_ME);

                        if ($nid) {
                            # Found a recent one - update it.
                            $n = new Newsfeed($dbhr, $dbhm, $nid);
                            $n->setPrivate('message', $_REQUEST['aboutme']);
                        } else if (strlen($_REQUEST['aboutme']) > 32) {
                            # No recent ones - add a new item.  But don't do this for very short ones, as they are
                            # of no interest on the newsfeed.
                            $n->create(Newsfeed::TYPE_ABOUT_ME, $me->getId(), $_REQUEST['aboutme']);
                        }
                    }
                }

                $simplemail = Utils::presdef('simplemail', $_REQUEST, NULL);

                if ($simplemail) {
                    # This is a way to set a bunch of email settings at once.
                    $me->setSimpleMail($simplemail);
                }

                if (array_key_exists('deleted', $_REQUEST)) {
                    # Users who leave have deleted set, but can restore their account.  If they don't, their data
                    # will be purged later in the background.
                    $me->setPrivate('deleted', NULL);
                }

                Session::clearSessionCache();
            }
            break;
        }

        case 'DELETE': {
            # Logout.  Kill all sessions for this user.
            $ret = array('ret' => 0, 'status' => 'Success', 'destroyed' => $sessionLogout($dbhr, $dbhm));
            break;
        }
    }

    return($ret);
}
