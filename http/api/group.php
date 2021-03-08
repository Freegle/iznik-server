<?php
namespace Freegle\Iznik;

function group() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $me = Session::whoAmI($dbhr, $dbhm);

    # The id parameter can be an ID or a nameshort.
    $id = Utils::presdef('id', $_REQUEST, NULL);
    $nameshort = NULL;

    if (is_numeric($id)) {
        $id = intval($id);
    } else {
        $nameshort = $id;
    }

    $action = Utils::presdef('action', $_REQUEST, NULL);

    if ($nameshort) {
        $g = Group::get($dbhr, $dbhm);
        $id = $g->findByShortName($nameshort);
    }

    if ($id || ($action == 'Create') || ($action == 'Contact') || ($action == 'RecordFacebookShare' || ($action == 'RemoveFacebook'))) {
        $g = new Group($dbhr, $dbhm, $id);

        switch ($_REQUEST['type']) {
            case 'GET': {
                $ret = [
                    'ret' => 10,
                    'status' => 'Invalid group id'
                ];

                if ($id) {
                    $members = array_key_exists('members', $_REQUEST) ? filter_var($_REQUEST['members'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                    $showmods = array_key_exists('showmods', $_REQUEST) ? filter_var($_REQUEST['showmods'], FILTER_VALIDATE_BOOLEAN) : FALSE;

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'group' => $g->getPublic()
                    ];

                    $ret['group']['myrole'] = $me ? $me->getRoleForGroup($id) : User::ROLE_NONMEMBER;
                    $ret['group']['mysettings'] = $me ? $me->getGroupSettings($id) : NULL;
                    $ctx = Utils::presdef('context', $_REQUEST, NULL);
                    $limit = Utils::presint('limit', $_REQUEST, 5);
                    $search = Utils::presdef('search', $_REQUEST, NULL);

                    if ($members && $me && $me->isModOrOwner($id)) {
                        $ret['group']['members'] = $g->getMembers($limit, $search, $ctx);
                        $ret['context'] = $ctx;
                    }

                    $partner = Utils::pres('partner', $_SESSION);

                    if ($me && $me->isModerator() || $partner) {
                        # Return info on Twitter status.  This isn't secret info - we don't put anything confidential
                        # in here - but it's of no interest to members so there's no point delaying them by
                        # fetching it.
                        #
                        # Similar code in session.php
                        $t = new Twitter($dbhr, $dbhm, $id);
                        $atts = $t->getPublic();
                        unset($atts['token']);
                        unset($atts['secret']);
                        $atts['authdate'] = Utils::ISODate($atts['authdate']);
                        $ret['group']['twitter'] =  $atts;

                        # Ditto Facebook.
                        $uids = GroupFacebook::listForGroup($dbhr, $dbhm, $id);
                        $ret['group']['facebook'] = [];

                        foreach ($uids as $uid) {
                            $f = new GroupFacebook($dbhr, $dbhm, $uid);
                            $atts = $f->getPublic();
                            unset($atts['token']);
                            $atts['authdate'] = Utils::ISODate($atts['authdate']);
                            $ret['group']['facebook'][] =  $atts;
                        }
                    }

                    if (Utils::presdef('polygon', $_REQUEST, FALSE)) {
                        $ret['group']['cga'] = $g->getPrivate('polyofficial');
                        $ret['group']['dpa'] = $g->getPrivate('poly');
                        $ret['group']['polygon'] = $ret['group']['dpa'] ? $ret['group']['dpa'] : $ret['group']['cga'];
                    }

                    if (Utils::presdef('tnkey', $_REQUEST, FALSE) && $me && $me->isModerator()) {
                        # Get the link that we could use to access TN settings.
                        $ret['group']['tnkey'] = json_decode(file_get_contents('https://trashnothing.com/modtools/api/group-settings-url?key=' . TNKEY . '&moderator_email=' . urlencode($me->getEmailPreferred()) . '&group_id=' . urlencode($ret['group']['nameshort'])), TRUE);
                    }

                    if (Utils::presdef('sponsors', $_REQUEST, FALSE)) {
                        $ret['group']['sponsors'] = $g->getSponsorships();
                    }

                    if (Utils::presdef('affiliationconfirmedby', $_REQUEST, FALSE)) {
                        $by = $g->getPrivate('affiliationconfirmedby');

                        if ($by) {
                            $byu = User::get($dbhr, $dbhm, $by);
                            $ret['group']['affiliationconfirmedby'] = [
                                'id' => $by,
                                'displayname' => $byu->getName()
                            ];
                        }
                    }

                    if ($showmods) {
                        # We want the list of visible mods.
                        $ctx = NULL;
                        $mods = $g->getMembers(100, NULL, $ctx, NULL, MembershipCollection::APPROVED, NULL, NULL, NULL, NULL, Group::FILTER_MODERATORS);
                        $toshow = [];

                        foreach ($mods as $mod) {
                            $u = User::get($dbhr, $dbhm, $mod['userid']);
                            $settings = $u->getPrivate('settings');
                            $settings = $settings ? json_decode($settings, TRUE) : [];
                            if (Utils::pres('showmod', $settings)) {
                                # We can show this mod.  Return basic info about them.
                                $atts = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
                                $toshow[] = [
                                    'id' => $mod['userid'],
                                    'firstname' => $atts['firstname'],
                                    'displayname' => $atts['displayname'],
                                    'profile' => $atts['profile']
                                ];
                            }
                        }

                        $ret['group']['showmods'] = $toshow;
                    }
                }
                break;
            }

            case 'PATCH': {
                $settings = Utils::presdef('settings', $_REQUEST, NULL);
                $profile = (Utils::presint('profile', $_REQUEST, NULL));

                $ret = [
                    'ret' => 1,
                    'status' => 'Not logged in',
                ];

                if ($me) {
                    $ret = [
                        'ret' => 1,
                        'status' => 'Failed or permission denied'
                    ];

                    if ($me->isModOrOwner($id) || $me->isAdminOrSupport()) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];

                        if ($settings) {
                            $g->setSettings($settings);
                        }

                        if ($profile) {
                            # Set the profile picture.  Rescale if need be to 200x200 to save space in the DB and,
                            # more importantly, download time.
                            $g->setPrivate('profile', $profile);
                            $a = new Attachment($dbhr, $dbhm, $profile, Attachment::TYPE_GROUP);
                            $data = $a->getData();
                            $i = new Image($data);
                            
                            if ($i->width() > 200 || $i->height() > 200) {
                                $i->scale(200, 200);
                                $data = $i->getData(100);
                                $a->setPrivate('data', $data);
                            }

                            $a->setPrivate('groupid', $id);
                        }

                        # Other settable attributes
                        foreach (['onhere', 'publish', 'microvolunteering'] as $att) {
                            $val = Utils::presdef($att, $_REQUEST, NULL);
                            if (array_key_exists($att, $_REQUEST)) {
                                $g->setPrivate($att, $val);

                                if ($att === 'affiliationconfirmed') {
                                    $g->setPrivate('affiliationconfirmedby', $me->getId());
                                }
                            }
                        }
                        foreach (['microvolunteeringoptions'] as $att) {
                            $val = Utils::presdef($att, $_REQUEST, NULL);
                            if (array_key_exists($att, $_REQUEST)) {
                                $g->setPrivate($att, json_encode($val));
                            }
                        }
                        foreach (['tagline', 'namefull', 'welcomemail', 'description', 'region', 'affiliationconfirmed'] as $att) {
                            $val = Utils::presdef($att, $_REQUEST, NULL);
                            if (array_key_exists($att, $_REQUEST) && $val != "1") {
                                $g->setPrivate($att, $val);

                                if ($att === 'affiliationconfirmed') {
                                    $g->setPrivate('affiliationconfirmedby', $me->getId());
                                }
                            }
                        }

                        # Other support-settable attributes
                        if ($me->isAdminOrSupport()) {
                            foreach (['publish', 'licenserequired', 'lat', 'lng', 'mentored'] as $att) {
                                $val = Utils::presdef($att, $_REQUEST, NULL);
                                if (array_key_exists($att, $_REQUEST)) {
                                    $g->setPrivate($att, $val);
                                }
                            }

                            # For polygon attributes, check that they are valid before putting them into the DB.
                            # Otherwise, we can break the whole site.
                            foreach (['poly', 'polyofficial'] as $att) {
                                $val = Utils::presdef($att, $_REQUEST, NULL);
                                if (array_key_exists($att, $_REQUEST)) {
                                    if (!$g->setPrivate($att, $val)) {
                                        $ret = [
                                            'ret' => 3,
                                            'status' => 'Invalid polygon data'
                                        ];
                                    }
                                }
                            }

                        }
                    }
                }
            }

            case 'POST': {
                switch ($action) {
                    case 'Create': {
                        $ret = [
                            'ret' => 1,
                            'status' => 'Not logged in'
                        ];

                        # Only mods can create.
                        if ($me && $me->isModerator()) {
                            $name = Utils::presdef('name', $_REQUEST, NULL);
                            $type = Utils::presdef('grouptype', $_REQUEST, NULL);
                            $lat = Utils::presfloat('lat', $_REQUEST, NULL);
                            $lng = Utils::presfloat('lng', $_REQUEST, NULL);
                            $core = Utils::presdef('corearea', $_REQUEST, NULL);
                            $catchment = Utils::presdef('atchmentarea', $_REQUEST, NULL);

                            $id = $g->create($name, $type);

                            $ret = ['ret' => 2, 'status' => 'Create failed'];

                            if ($id) {
                                $me->addMembership($id, User::ROLE_OWNER);

                                $ret = [
                                    'ret' => 0,
                                    'status' => 'Success',
                                    'id' => $id
                                ];

                                if ($me && $me->isAdminOrSupport()) {
                                    # Admin or support can say where a group is. Not normal mods otherwise people might
                                    # trample on each other's toes.
                                    $g->setPrivate('lat', $lat);
                                    $g->setPrivate('lng', $lng);
                                    $g->setPrivate('polyofficial', $core);
                                    $g->setPrivate('poly', $catchment);
                                }
                            }
                        }

                        break;
                    }

                    case 'ConfirmKey': {
                        if ($me && $me->isAdminOrSupport()) {
                            # If we already have Admin or Support rights, we trust ourselves enough to add the
                            # membership immediately.  This helps with people who are on many groups, because
                            # it avoids having to wait for Yahoo invitation processing.
                            #
                            # If this is incorrect, and we're not actually a mod on Yahoo, then it will get
                            # downgraded on the next sync.
                            $me->addMembership($id, User::ROLE_MODERATOR);
                            $ret = [
                                'ret' => 100,
                                'status' => 'Added status on server.'
                            ];
                        } else {
                            $ret = [
                                'ret' => 0,
                                'status' => 'Success',
                                'key' => $g->getConfirmKey()
                            ];
                        }

                        break;
                    }

                    case 'RemoveFacebook': {
                        $uid = (Utils::presint('uid', $_REQUEST, NULL));
                        $ret = ['ret' => 2, 'status' => 'Invalid parameters'];

                        if ($uid) {
                            $f = new GroupFacebook($dbhr, $dbhm);
                            $f->remove($uid);
                            $ret = ['ret' => 0, 'status' => 'Success'];
                        }

                        break;
                    }                }

                break;
            }
        }
    } else {
        $ret = [
            'ret' => 2,
            'status' => 'We don\'t host that group'
        ];
    }

    return($ret);
}
