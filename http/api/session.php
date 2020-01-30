<?php
require_once(IZNIK_BASE . '/mailtemplates/verifymail.php');

function session() {
    global $dbhr, $dbhm;
    $me = NULL;

    $sessionLogout = function($dbhr, $dbhm) {
        $id = pres('id', $_SESSION);
        if ($id) {
            $s = new Session($dbhr, $dbhm);
            $s->destroy($id, NULL);
        }

        # Destroy the PHP session
        try {
            session_destroy();
            session_unset();
            session_start();
            session_regenerate_id(true);
        } catch (Exception $e) {
        }
    };

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            # Check if we're logged in.  This will handle persistent sessions, pushcreds, as well as login via
            # the PHP session.
            $me = whoAmI($dbhm, $dbhm);

            # Mobile app can send its version number, which we can use to determine if it is out of date.
            $appversion = presdef('appversion', $_REQUEST, NULL);

            if ($appversion == '2') {
                $ret = array('ret' => 123, 'status' => 'App is out of date');
            } else {
                if (pres('id', $_SESSION)) {
                    # We're logged in.
                    if (!MODTOOLS) {
                        # ...but we are running an old version of the code, probably the app, because we have
                        # not indicated which version we have.
                        $webversion = presdef('webversion', $_REQUEST, NULL);
                        $appversion = presdef('appversion', $_REQUEST, NULL);
                        $dbhm->background("INSERT INTO users_builddates (userid, webversion, appversion) VALUES ({$_SESSION['id']}, '$webversion', '$appversion') ON DUPLICATE KEY UPDATE timestamp = NOW(), webversion = '$webversion', appversion = '$appversion';");
                    }

                    $components = presdef('components', $_REQUEST, ['all']);
                    if ($components === ['all']) {
                        // Get all
                        $components = NULL;
                    }

                    $ret = [ 'ret' => 0, 'status' => 'Success', 'myid' => presdef('id', $_SESSION, NULL) ];

                    if (!$components || (gettype($components) == 'array' && in_array('me', $components))) {
                        # Don't want to use cached information when looking at our own session.
                        $ret['me'] = $me->getPublic();
                        $ret['me']['city'] = $me->getCity();

                        # Don't need to return this, and it might be large.
                        $ret['me']['messagehistory'] = NULL;
                    }

                    $ret['persistent'] = presdef('persistent', $_SESSION, NULL);

                    if (!$components || in_array('notifications', $components)) {
                        $settings = $me->getPrivate('settings');
                        $settings = $settings ? json_decode($settings, TRUE) : [];
                        $ret['me']['settings']['notifications'] = array_merge([
                            'email' => TRUE,
                            'emailmine' => FALSE,
                            'push' => TRUE,
                            'facebook' => TRUE,
                            'app' => TRUE
                        ], presdef('notifications', $settings, []));

                        $n = new PushNotifications($dbhr, $dbhm);
                        $ret['me']['notifications']['push'] = $n->get($ret['me']['id']);
                    }

                    if (MODTOOLS) {
                        if (!$components || in_array('allconfigs', $components)) {
                            $me = $me ? $me : whoAmI($dbhm, $dbhm);
                            $ret['configs'] = $me->getConfigs(TRUE);
                        } else if (in_array('configs', $components)) {
                            $me = $me ? $me : whoAmI($dbhm, $dbhm);
                            $ret['configs'] = $me->getConfigs(FALSE);
                        }
                    }

                    if (!$components || in_array('emails', $components)) {
                        $me = $me ? $me : whoAmI($dbhm, $dbhm);
                        $ret['emails'] = $me->getEmails();
                    }

                    if (!$components || in_array('phone', $components)) {
                        $me = $me ? $me : whoAmI($dbhm, $dbhm);
                        $ret['me']['phone'] = $me->getPhone();
                    }

                    if (!$components || in_array('aboutme', $components)) {
                        $me = $me ? $me : whoAmI($dbhm, $dbhm);
                        $ret['me']['aboutme'] = $me->getAboutMe();
                    }

                    if (!$components || in_array('newsfeed', $components)) {
                        # Newsfeed count.  We return this in the session to avoid getting it on each page transition
                        # in the client.
                        $n = new Newsfeed($dbhr, $dbhm);
                        $me = $me ? $me : whoAmI($dbhm, $dbhm);
                        $ret['newsfeedcount'] = $n->getUnseen($me->getId());
                    }

                    if (!$components || in_array('logins', $components)) {
                        $me = $me ? $me : whoAmI($dbhm, $dbhm);
                        $ret['logins'] = $me->getLogins(FALSE);
                    }

                    if (!$components || in_array('groups', $components) || in_array('work', $components)) {
                        # Get groups including work when we're on ModTools; don't need that on the user site.
                        $u = new User($dbhr, $dbhm);
                        $ret['groups'] = $u->getMemberships(FALSE, NULL, MODTOOLS, TRUE, $_SESSION['id']);

                        $gids = [];

                        foreach ($ret['groups'] as &$group) {
                            $gids[] = $group['id'];

                            # Remove large attributes we don't need in session.
                            unset($group['welcomemail']);
                            unset($group['description']);
                            unset($group['settings']['chaseups']['idle']);
                            unset($group['settings']['branding']);
                        }

                        # We should always return complete groups objects because they are stored in the client session.
                        #
                        # If we have many groups this can generate many DB calls, so quicker to prefetch for Twitter and
                        # Facebook, even though that makes the code hackier.
                        $facebooks = GroupFacebook::listForGroups($dbhr, $dbhm, $gids);
                        $twitters = [];

                        if (count($gids) > 0) {
                            # We don't want to show any ones which aren't properly linked (yet), i.e. name is null.
                            $tws = $dbhr->preQuery("SELECT * FROM groups_twitter WHERE groupid IN (" . implode(',', $gids) . ") AND name IS NOT NULL;");
                            foreach ($tws as $tw) {
                                $twitters[$tw['groupid']] = $tw;
                            }
                        }

                        foreach ($ret['groups'] as &$group) {
                            if ($group['role'] == User::ROLE_MODERATOR || $group['role'] == User::ROLE_OWNER) {
                                # Return info on Twitter status.  This isn't secret info - we don't put anything confidential
                                # in here - but it's of no interest to members so there's no point delaying them by
                                # fetching it.
                                #
                                # Similar code in group.php.
                                if (array_key_exists($group['id'], $twitters)) {
                                    $t = new Twitter($dbhr, $dbhm, $group['id'], $twitters[$group['id']]);
                                    $atts = $t->getPublic();
                                    unset($atts['token']);
                                    unset($atts['secret']);
                                    $atts['authdate'] = ISODate($atts['authdate']);
                                    $group['twitter'] = $atts;
                                }

                                # Ditto Facebook.
                                if (array_key_exists($group['id'], $facebooks)) {
                                    $group['facebook'] = [];

                                    foreach ($facebooks[$group['id']] as $atts) {
                                        $group['facebook'][] = $atts;
                                    }
                                }
                            }
                        }

                        if (MODTOOLS) {
                            if (!$components || in_array('work', $components)) {
                                # Tell them what mod work there is.  Similar code in Notifications.
                                $ret['work'] = [];
                                $national = FALSE;

                                if (!$me) {
                                    # When getting work we want to avoid instantiating the full User object.  But
                                    # we need the memberships.  So work around that.  Bit hacky but saves ops in a
                                    # perf critical path.
                                    $me = new User($dbhr, $dbhm);
                                    $me->cacheMemberships($_SESSION['id']);
                                    $perms = $dbhr->preQuery("SELECT permissions FROM users WHERE id = ?;", [
                                        $_SESSION['id']
                                    ]);

                                    foreach ($perms as $perm) {
                                        $national = stripos($perm['permissions'], User::PERM_NATIONAL_VOLUNTEERS) !== FALSE;
                                    }
                                } else {
                                    $national = $me->hasPermission(User::PERM_NATIONAL_VOLUNTEERS);
                                }

                                if ($national) {
                                    $v = new Volunteering($dbhr, $dbhm);
                                    $ret['work']['pendingvolunteering'] = $v->systemWideCount();
                                }

                                $s = new Spam($dbhr, $dbhm);
                                $spamcounts = $s->collectionCounts();
                                $ret['work']['spammerpendingadd'] = $spamcounts[Spam::TYPE_PENDING_ADD];
                                $ret['work']['spammerpendingremove'] = $spamcounts[Spam::TYPE_PENDING_REMOVE];

                                # Show social actions from last 4 days.
                                $ctx = NULL;
                                $starttime = date("Y-m-d H:i:s", strtotime("midnight 4 days ago"));
                                $f = new GroupFacebook($dbhr, $dbhm);
                                $ret['work']['socialactions'] = count($f->listSocialActions($ctx, $starttime));

                                $c = new ChatMessage($dbhr, $dbhm);

                                $ret['work'] = array_merge($ret['work'], $c->getReviewCount($me));

                                $s = new Story($dbhr, $dbhm);
                                $ret['work']['stories'] = $s->getReviewCount(FALSE);
                                $ret['work']['newsletterstories'] = $me->hasPermission(User::PERM_NEWSLETTER) ? $s->getReviewCount(TRUE) : 0;
                            }

                            foreach ($ret['groups'] as &$group) {
                                if (pres('work', $group)) {
                                    foreach ($group['work'] as $key => $work) {
                                        if (pres('work', $ret) && pres($key, $ret['work'])) {
                                            $ret['work'][$key] += $work;
                                        } else {
                                            $ret['work'][$key] = $work;
                                        }
                                    }
                                }
                            }

                            # Get Discourse notifications and unread topics, to drive mods through to that site.
                            if ($me->isFreegleMod()) {
                                $unreadcount = 0;
                                $notifcount = 0;
                                $newcount = 0;

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

                                if ($username) {
                                    $users = json_decode($username, TRUE);

                                    if (pres('users', $users) && count($users['users'])) {
                                        $name = $users['users'][0]['username'];

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

                                            if (pres('topic_list', $topics)) {
                                                $newcount = count($topics['topic_list']['topics']);
                                            }

                                            $topics = json_decode($unreads, TRUE);

                                            if (pres('topic_list', $topics)) {
                                                $unreadcount = count($topics['topic_list']['topics']);
                                            }

                                            $notifs = json_decode($notifs, TRUE);
                                            if (pres('unread_notifications', $notifs)) {
                                                $notifcount = intval($notifs['unread_notifications']);
                                            }

                                            $_SESSION['discourse'] = [
                                                'notifications' => $notifcount,
                                                'unreadtopics' => $unreadcount,
                                                'newtopics' => $newcount
                                            ];
                                        }
                                        #error_log("$name notifs $notifcount new topics $newcount unread topics $unreadcount");
                                    }
                                }
                            }

                            # Using the value from session means we fall back to an old value if we can't get it, e.g.
                            # for rate-limiting.
                            $ret['discourse'] = presdef('discourse', $_SESSION, NULL);
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
            $me = whoAmI($dbhm, $dbhm);

            # Login
            $fblogin = array_key_exists('fblogin', $_REQUEST) ? filter_var($_REQUEST['fblogin'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $fbaccesstoken = presdef('fbaccesstoken', $_REQUEST, NULL);
            $googlelogin = array_key_exists('googlelogin', $_REQUEST) ? filter_var($_REQUEST['googlelogin'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $yahoologin = array_key_exists('yahoologin', $_REQUEST) ? filter_var($_REQUEST['yahoologin'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $yahoocodelogin = presdef('yahoocodelogin', $_REQUEST, NULL);
            $googleauthcode = array_key_exists('googleauthcode', $_REQUEST) ? $_REQUEST['googleauthcode'] : NULL;
            $mobile = array_key_exists('mobile', $_REQUEST) ? filter_var($_REQUEST['mobile'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $email = array_key_exists('email', $_REQUEST) ? $_REQUEST['email'] : NULL;
            $password = array_key_exists('password', $_REQUEST) ? $_REQUEST['password'] : NULL;
            $returnto = array_key_exists('returnto', $_REQUEST) ? $_REQUEST['returnto'] : NULL;
            $action = presdef('action', $_REQUEST, NULL);
            $host = presdef('host', $_REQUEST, NULL);
            $keyu = intval(presdef('u', $_REQUEST, NULL));
            $keyk = presdef('k', $_REQUEST, NULL);

            $id = NULL;
            $user = User::get($dbhr, $dbhm);
            $f = NULL;
            $ret = array('ret' => 1, 'status' => 'Invalid login details', 'req' => $_REQUEST);

            if ($keyu && $keyk) {
                # uid and key login, used in email links and impersonation.
                $u = new User($dbhr, $dbhm, $keyu);

                if (presdef('id', $_SESSION, NULL) === $keyu || $u->linkLogin($keyk)) {
                    $id = $keyu;

                    $ret = [ 'ret' => 0, 'status' => 'Success' ];
                }
            } else if ($fblogin) {
                # We've been asked to log in via Facebook.
                $f = new Facebook($dbhr, $dbhm);
                list ($session, $ret) = $f->login($fbaccesstoken);
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
            } else if ($yahoocodelogin) {
                $y = Yahoo::getInstance($dbhr, $dbhm, $host);
                list ($session, $ret) = $y->loginWithCode($yahoocodelogin);
                error_log("Yahoo code login returned " . var_export($ret, TRUE));
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
                list ($session, $ret) = $g->login($googleauthcode);
                /** @var Session $session */
                $id = $session ? $session->getUserId() : NULL;
            } else if ($action) {
                switch ($action) {
                    case 'LostPassword': {
                        $id = $user->findByEmail($email);
                        $ret = [ 'ret' => 2, "We don't know that email address" ];
                        
                        if ($id) {
                            $u = User::get($dbhr, $dbhm, $id);
                            $u->forgotPassword($email);
                            $ret = [ 'ret' => 0, 'status' => "Success" ];
                        }    
                        
                        break;
                    }

                    case 'Forget': {
                        $ret = array('ret' => 1, 'status' => 'Not logged in');

                        if ($me) {
                            # We don't allow mods/owners to do this, as they might do it by accident.
                            $ret = array('ret' => 2, 'status' => 'Please demote yourself to a member first');

                            if (!$me->isModerator()) {
                                # We don't allow spammers to do this.
                                $ret = array('ret' => 3, 'status' => 'We can\'t do this.');

                                $s = new Spam($dbhr, $dbhm);

                                if (!$s->isSpammer($me->getEmailPreferred())) {
                                    $me->forget('Request');

                                    # Log out.
                                    $sessionLogout($dbhr, $dbhm);
                                    $ret = [ 'ret' => 0, 'status' => "Success" ];
                                }
                            }
                        }
                        break;
                    }
                }
            }
            else if ($password && $email) {
                # Native login via username and password
                $ret = array('ret' => 2, 'status' => "We don't know that email address.  If you're new, please Sign Up.");
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

                        # We have publish permissions for users who login via our platform.
                        $u->setPrivate('publishconsent', 1);
                    }
                }
            }

            if ($id) {
                # Return some more useful info.
                $u = User::get($dbhr, $dbhm, $id);
                $ret['user'] = $u->getPublic();
                $ret['persistent'] = presdef('persistent', $_SESSION, NULL);
            }

            break;
        }

        case 'PATCH': {
            # Don't want to use cached information when looking at our own session.
            $me = whoAmI($dbhm, $dbhm);

            if (!$me) {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];
            } else {
                $fullname = presdef('displayname', $_REQUEST, NULL);
                $firstname = presdef('firstname', $_REQUEST, NULL);
                $lastname = presdef('lastname', $_REQUEST, NULL);
                $password = presdef('password', $_REQUEST, NULL);
                $key = presdef('key', $_REQUEST, NULL);

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

                $settings = presdef('settings', $_REQUEST, NULL);
                if ($settings) {
                    $me->setPrivate('settings', json_encode($settings));

                    if (pres('mylocation', $settings)) {
                        # Save this off as the last known location.
                        $me->setPrivate('lastlocation', $settings['mylocation']['id']);
                    }
                }

                $notifs = presdef('notifications', $_REQUEST, NULL);
                if ($notifs) {
                    $n = new PushNotifications($dbhr, $dbhm);
                    $push = presdef('push', $notifs, NULL);
                    if ($push) {
                        switch ($push['type']) {
                            case PushNotifications::PUSH_GOOGLE:
                            case PushNotifications::PUSH_FIREFOX:
                            case PushNotifications::PUSH_FCM_ANDROID:
                            case PushNotifications::PUSH_FCM_IOS:
                                $n->add($me->getId(), $push['type'], $push['subscription']);
                                break;
                        }
                    }
                }

                $ret = ['ret' => 0, 'status' => 'Success'];

                $email = presdef('email', $_REQUEST, NULL);
                $force = array_key_exists('force', $_REQUEST) ? filter_var($_REQUEST['force'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                if ($email) {
                    if (!$me->verifyEmail($email, $force)) {
                        $ret = ['ret' => 10, 'status' => "We've sent a verification mail; please check your mailbox." ];
                    }
                }

                if (array_key_exists('phone', $_REQUEST)) {
                    $phone = $_REQUEST['phone'];
                    if ($phone) {
                        $me->addPhone($phone);
                    } else {
                        $me->removePhone();
                    }
                }

                if ($key) {
                    if (!$me->confirmEmail($key)) {
                        $ret = ['ret' => 11, 'status' => 'Confirmation failed'];
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
                        } else {
                            # No recent ones - add a new item
                            $n->create(Newsfeed::TYPE_ABOUT_ME, $me->getId(), $_REQUEST['aboutme']);
                        }
                    }
                }

                Session::clearSessionCache();
            }
            break;
        }

        case 'DELETE': {
            # Logout.  Kill all sessions for this user.
            $ret = array('ret' => 0, 'status' => 'Success');
            $sessionLogout($dbhr, $dbhm);
            break;
        }
    }

    return($ret);
}
