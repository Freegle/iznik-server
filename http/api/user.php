<?php
namespace Freegle\Iznik;

function user() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $subject = Utils::presdef('subject', $_REQUEST, NULL);
    $body = Utils::presdef('body', $_REQUEST, NULL);
    $action = Utils::presdef('action', $_REQUEST, NULL);
    $suspectcount = Utils::presint('suspectcount', $_REQUEST, NULL);
    $suspectreason = Utils::presdef('suspectreason', $_REQUEST, NULL);
    $search = Utils::presdef('search', $_REQUEST, NULL);
    $password = array_key_exists('password', $_REQUEST) ? $_REQUEST['password'] : NULL;
    $engageid = (Utils::presint('engageid', $_REQUEST, NULL));
    $trustlevel = Utils::presdef('trustlevel', $_REQUEST, NULL);

    $email = Utils::presdef('email', $_REQUEST, NULL);
    if (!$id && $email) {
        # We still don't know our unique ID, but we do know an email.  Find it.
        $u = new User($dbhr, $dbhm);
        $id = $u->findByEmail($email);
    }

    $u = User::get($dbhr, $dbhm, $id);
    $sysrole = $u->getPrivate('systemrole');

    $ourPostingStatus = Utils::presdef('ourPostingStatus', $_REQUEST, NULL);
    $ourEmailFrequency = Utils::presdef('emailfrequency', $_REQUEST, NULL);
    $chatmodstatus = Utils::presdef('chatmodstatus', $_REQUEST, NULL);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $emailhistory = array_key_exists('emailhistory', $_REQUEST) ? filter_var($_REQUEST['emailhistory'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $modmailsonly = array_key_exists('modmailsonly', $_REQUEST) ? filter_var($_REQUEST['modmailsonly'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $info = array_key_exists('info', $_REQUEST) ? filter_var($_REQUEST['info'], FILTER_VALIDATE_BOOLEAN) : FALSE;
            $ctx = Utils::presdef('logcontext', $_REQUEST, NULL);

            $ret = ['ret' => 2, 'status' => 'Permission denied'];

            if ($u) {
                if ($me && $search) {
                    # Admin or support can search users.
                    if ($me->isAdminOrSupport()) {
                        $users = $u->search($search, $ctx);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'users' => $users,
                            'context' => $ctx
                        ];
                    }
                } else {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];

                    $ret['user'] = $u->getPublic(NULL, TRUE, TRUE, TRUE, TRUE, $modmailsonly, $emailhistory);

                    if ($info && $id && $u->getId() == $id) {
                        $u->ensureAvatar($ret['user']);
                        $ret['user']['info'] = $u->getInfo();
                    }

                    if ($me && $me->isModerator()) {
                        $ret['user']['trustlevel'] = $u->getPrivate('trustlevel');
                    }
                }
            }

            break;
        }

        case 'PUT': {
            $u = new User($dbhr, $dbhm);
            $email = Utils::presdef('email', $_REQUEST, NULL);
            $password = Utils::presdef('password', $_REQUEST, NULL);

            $pwtomail = NULL;

            if (!$password) {
                # If we invent a password we want to mail it.
                $pwtomail = $u->inventPassword();
                $password = $pwtomail;
            }

            $firstname = Utils::presdef('firstname', $_REQUEST, NULL);
            $lastname = Utils::presdef('lastname', $_REQUEST, NULL);

            $ret = ['ret' => 1, 'status' => 'Invalid parameters'];

            if ($email && $password) {
                $id = $u->findByEmail($email);

                if ($id) {
                    # This user already exists.  If we are trying to register again with the same password, then
                    # the user is probably just a bit confused, but it's the same person - so treat this as a success.
                    # So try to login.
                    $u = User::get($dbhr, $dbhm, $id);
                    $rc = $u->login($password);

                    if ($rc) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'id' => $id
                        ];
                    } else {
                        # Behaviour is different.  For mods we return success and the existing id - this is used
                        # in the Add User feature.  For users we return an error to get them to sign in.
                        $mod = $me && $me->isModerator();

                        $ret = [
                            'ret' => $mod ? 0 : 2,
                            'status' => "That user already exists, but with a different password.",
                            'id' => $mod ? $id : null
                        ];
                    }
                } else {
                    $id = $u->create($firstname, $lastname, NULL, "Registered");

                    $ret = [
                        'ret' => 3,
                        'status' => 'User create failed, please try later'
                    ];

                    if ($id) {
                        # We have publish permissions for users we created.
                        $u->setPrivate('publishconsent', 1);

                        # We created the user.  Add their email and log in.
                        $rc = $u->addEmail($email);

                        if ($rc) {
                            $u->welcome($email, $pwtomail);
                            $rc = $u->addLogin(User::LOGIN_NATIVE, $id, $password);

                            if ($rc) {
                                $rc = $u->login($password);

                                if ($rc) {
                                    $ret = [
                                        'ret' => 0,
                                        'status' => 'Success',
                                        'id' => $id,
                                        'password' => $pwtomail
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            break;
        }

        case 'PATCH': {
            $u = User::get($dbhr, $dbhm, $id);
            $l = new Log($dbhr, $dbhm);

            $ret = ['ret' => 2, 'status' => 'Permission denied'];

            #error_log("Owner of $groupid? " . $me->isModOrOwner($groupid) . " admin " . $me->isAdminOrSupport() . " $id vs ". $me->getId());
            if ($u && $me && $me->isFreegleMod() && ($id == $me->getId() || $sysrole == User::SYSTEMROLE_USER)) {
                # Freegle mods can set settings of members so that they can adjust email settings.
                foreach (['settings', 'newslettersallowed', 'relevantallowed'] as $att) {
                    if (array_key_exists($att, $_REQUEST)) {
                        $u->setPrivate($att, $att == 'settings' ? json_encode($_REQUEST['settings']) : $_REQUEST[$att]);
                    }
                }

                if ($id == $me->getId()) {
                    User::clearCache();
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            }

            if (array_key_exists('trustlevel', $_REQUEST) && $u && $me) {
                $ret = [
                    'ret' => 2,
                    'status' => 'Permission denied'
                ];

                if ($me->isModerator()) {
                    # Can set any trust level
                    $u->setPrivate('trustlevel', $trustlevel);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($u->getId() == $me->getId() && (!$trustlevel || $trustlevel == User::TRUST_BASIC || $trustlevel == User::TRUST_DECLINED)) {
                    # Can only turn this on/off.
                    if ($trustlevel) {
                        $setu = $u->getId() == $me->getId() ? $me : $u;
                        $setu->setPrivate('trustlevel', $trustlevel);
                    } else {
                        $u->setPrivate('trustlevel', NULL);
                    }

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
            }

            if ($u && $me && ($me->isModOrOwner($groupid) || $me->isAdminOrSupport())) {
                if ($suspectcount !== NULL && $groupid) {
                    $u->memberReview($groupid, $suspectcount, $suspectreason);
                }

                if ($ourPostingStatus) {
                    $g = new Group($dbhr, $dbhm);
                    $ourPostingStatus = $g->ourPS($ourPostingStatus);

                    $l->log([
                        'type' => Log::TYPE_USER,
                        'subtype' => Log::SUBTYPE_OUR_POSTING_STATUS,
                        'groupid' => $groupid,
                        'user' => $id,
                        'byuser' => $me->getId(),
                        'text' => $ourPostingStatus
                    ]);

                    $u->setMembershipAtt($groupid, 'ourPostingStatus', $ourPostingStatus);
                }

                if ($ourEmailFrequency) {
                    $l->log([
                        'type' => Log::TYPE_USER,
                        'subtype' => Log::SUBTYPE_OUR_EMAIL_FREQUENCY,
                        'groupid' => $groupid,
                        'user' => $id,
                        'byuser' => $me->getId(),
                        'text' => $ourEmailFrequency
                    ]);

                    $u->setMembershipAtt($groupid, 'emailfrequency', $ourEmailFrequency);
                }

                if ($password &&
                    ($sysrole == User::SYSTEMROLE_USER || $me->getPrivate('systemrole') == User::SYSTEMROLE_ADMIN)) {
                    # Can only set the password of users, to prevent us using that to gain access to
                    # accounts with admin rights.
                    $u->addLogin(User::LOGIN_NATIVE, $u->getId(), $password);
                }

                if (array_key_exists('onholidaytill', $_REQUEST)) {
                    $u->setPrivate('onholidaytill', Utils::presdef('onholidaytill', $_REQUEST, NULL));
                }

                if ($chatmodstatus !== NULL) {
                    $u->setPrivate('chatmodstatus', $chatmodstatus);
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            }

            break;
        }

        case 'POST': {
            $u = User::get($dbhr, $dbhm, $id);
            $ret = ['ret' => 2, 'status' => 'Permission denied'];

            if ($engageid) {
                $e = new Engage($dbhr, $dbhm);
                $e->recordSuccess($engageid);
                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            } else {
                if ($action == 'Mail') {
                    $role = $me ? $me->getRoleForGroup($groupid) : User::ROLE_NONMEMBER;
                } else {
                    $role = $me->moderatorForUser($id) ? User::ROLE_MODERATOR : User::ROLE_NONMEMBER;
                }

                if ($me && $me->isAdminOrSupport() && $action == 'AddEmail') {
                    $ret = [ 'ret' => 3, 'status' => 'Email already used' ];
                    $uid = $u->findByEmail($email);

                    if (!$uid) {
                        $id = $u->addEmail($email);
                        $ret = $id ? [ 'ret' => 0, 'status' => 'Success', 'emailid' => $id ] : [ 'ret' => 4, 'status' => 'Email add failed for some reason' ];
                    }
                }

                if ($me && ($me->isAdminOrSupport() || $id == $me->getId()) && $action == 'RemoveEmail') {
                    # People can remove their own emails.
                    $ret = [ 'ret' => 3, 'status' => 'Not on same user' ];
                    $uid = $u->findByEmail($email);

                    if ($uid && $uid == $id) {
                        # The email is on the same user.
                        $ret = [ 'ret' => 0, 'status' => 'Success' ];
                        $u->removeEmail($email);
                    }
                }

                if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                    $ret = [ 'ret' => 0, 'status' => 'Success' ];

                    switch ($action) {
                        case 'Mail':
                            $u->mail($groupid, $subject, $body, NULL);
                            break;
                        case 'Unbounce':
                            $email = $u->getEmailPreferred();
                            $eid = $u->getIdForEmail($email)['id'];
                            $u->unbounce($eid, TRUE);
                            break;
                    }
                }

                if ($me) {
                    if ($action == 'Merge') {
                        $email1 = Utils::presdef('email1', $_REQUEST, NULL);
                        $email2 = Utils::presdef('email2', $_REQUEST, NULL);
                        $reason = Utils::presdef('reason', $_REQUEST, NULL);
                        $ret = ['ret' => 5, 'status' => 'Invalid parameters'];

                        if (strlen($email1) && strlen($email2)) {
                            $u = new User($dbhr, $dbhm);
                            $uid1 = $u->findByEmail($email1);
                            $uid2 = $u->findByEmail($email2);

                            $ret = ['ret' => 3, 'status' => "Can't find those users."];

                            if ($uid1 && $uid2) {
                                $ret = ['ret' => 4, 'status' => "You cannot administer those users"];

                                if ($me->isAdminOrSupport() ||
                                    ($me->moderatorForUser($uid1) && $me->moderatorForUser($uid2))) {
                                    $ret = $u->merge($uid2, $uid1, $reason);

                                    if ($ret) {
                                        $u = new User($dbhr, $dbhm, $uid2);
                                        $u->addEmail($email2, 1, TRUE);
                                        $ret = [ 'ret' => 0, 'status' => 'Success' ];
                                    } else {
                                        $ret = [ 'ret' => 6, 'status' => 'Merged failed'];
                                    }
                                }
                            }
                        }
                    } else if ($action == 'Rate') {
                        $ret = ['ret' => 5, 'status' => 'Invalid parameters'];
                        $ratee = (Utils::presint('ratee', $_REQUEST, 0));
                        $rating = Utils::presdef('rating', $_REQUEST, NULL);

                        if ($ratee && ($rating == User::RATING_UP || $rating == User::RATING_DOWN || $rating === NULL)) {
                            $me->rate($me->getId(), $ratee, $rating);
                            $ret = [ 'ret' => 0, 'status' => 'Success' ];
                        }
                    }
                }
            }

            break;
        }

        case 'DELETE': {
            $u = User::get($dbhr, $dbhm, $id);
            $ret = ['ret' => 2, 'status' => 'Permission denied'];

            # We can only delete members, to be safe.
            if ($me && $me->isAdminOrSupport() && ($me->isAdmin() || !$u->isModerator())) {
                $ret = [ 'ret' => 0, 'status' => 'Success' ];
                $u->delete();
            }
        }
    }

    return($ret);
}
