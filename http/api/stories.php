<?php
namespace Freegle\Iznik;

function stories() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $id = Utils::presint('id', $_REQUEST, NULL);
    $groupid = Utils::presint('groupid', $_REQUEST, NULL);
    $authorityid = Utils::presint('authorityid', $_REQUEST, NULL);
    $reviewed = Utils::presint('reviewed', $_REQUEST, 1);
    $story = array_key_exists('story', $_REQUEST) ? filter_var($_REQUEST['story'], FILTER_VALIDATE_BOOLEAN) : TRUE;
    $newsletter = array_key_exists('newsletter', $_REQUEST) ? filter_var($_REQUEST['newsletter'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $reviewnewsletter = array_key_exists('reviewnewsletter', $_REQUEST) ? filter_var($_REQUEST['reviewnewsletter'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $newsletterreviewed = array_key_exists('newsletterreviewed', $_REQUEST) ? filter_var($_REQUEST['newsletterreviewed'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $limit = (Utils::presint('limit', $_REQUEST, 20));
    $s = new Story($dbhr, $dbhm, $id);
    $me = Session::whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 3, 'status' => 'Invalid id' ];
            if ($id) {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                if ($s->canSee() && $s->getId()) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'story' => $s->getPublic()
                    ];
                }
            } else if ($reviewnewsletter) {
                # This is for mods reviewing stories for inclusion in the newsletter.
                $stories = $s->getStories($groupid, $authorityid, $story, $limit, true);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'stories' => $stories
                ];
            } else if ($me && $newsletter) {
                $stories = [];

                if ($me->hasPermission(User::PERM_NEWSLETTER)) {
                    $stories = $s->getForReview([], $newsletter);
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'stories' => $stories
                ];

            } else if ($me && $reviewed === 0) {
                $groupids = [ $groupid ];

                if (!$groupid) {
                    # We want to see the ones on groups we mod.
                    $mygroups = $me->getMemberships(TRUE, Group::GROUP_FREEGLE, FALSE, FALSE, NULL, FALSE);
                    $groupids = [];
                    foreach ($mygroups as $mygroup) {
                        # This group might have turned stories off.
                        $g = new Group($dbhr, $dbhm, $mygroup['id']);
                        if ($me->activeModForGroup($mygroup['id'])) {
                            if ($g->getSetting('stories', 1))
                            {
                                $groupids[] = $mygroup['id'];
                            }
                        }
                    }
                }

                $stories = [];

                if (count($groupids) > 0) {
                    $stories = $s->getForReview($groupids, FALSE);
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'stories' => $stories
                ];
            } else {
                # We want to see the most recent few.
                $stories = $s->getStories($groupid, $authorityid, $story, $limit, FALSE);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'stories' => $stories
                ];
            }

            break;
        }

        case 'PUT':
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];
            if ($me) {
                $id = $s->create($me->getId(),
                    array_key_exists('public', $_REQUEST) ? filter_var($_REQUEST['public'], FILTER_VALIDATE_BOOLEAN) : FALSE,
                    Utils::presdef('headline', $_REQUEST, NULL),
                    Utils::presdef('story', $_REQUEST, NULL),
                    (Utils::presint('photo', $_REQUEST, NULL)));
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id
                ];
            }
            break;

        case 'PATCH': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];
            if ($s->canMod()) {
                $newsfeedbefore = $s->getPrivate('reviewed') && $s->getPrivate('public');
                $s->setAttributes($_REQUEST);
                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];

                $newsfeedafter = $s->getPrivate('reviewed') && $s->getPrivate('public');

                if (!$newsfeedbefore && $newsfeedafter) {
                    # We have reviewed a public story.  We can push it to the newsfeed.
                    $n = new Newsfeed($dbhr, $dbhm);
                    $n->create(Newsfeed::TYPE_STORY, $s->getPrivate('userid'), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, $s->getPrivate('id'));
                }

                if ($newsletter && $newsletterreviewed) {
                    # We have reviewed a story for inclusion in the newsletter.  Mail it to the local publicity address.
                    list ($transport, $mailer) = Mail::getMailer();

                    try {
                        $m = \Swift_Message::newInstance()
                            ->setSubject('New story for possible local publicity')
                            ->setFrom([NOREPLY_ADDR => SITE_NAME])
                            ->setReplyTo(NOREPLY_ADDR)
                            ->setBody(
                                $s->getPrivate('headline') . "\n\n\n\n" .
                                $s->getPrivate('story') . "\n\n\n\n" .
                                'https://' . USER_SITE . '/story/' . $s->getPrivate('id')
                            )
                            ->setTo(STORIES_ADDR);

                        $mailer->send($m);
                    } catch (\Exception $e) { error_log("Failed with " . $e->getMessage()); }
                }
            }
            break;
        }

        case 'POST': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];
            $action = Utils::presdef('action', $_REQUEST, Story::LIKE);

            if ($me) {
                switch ($action) {
                    case Story::LIKE: $s->like(); break;
                    case Story::UNLIKE: $s->unlike(); break;
                }
                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            }
            break;
        }

        case 'DELETE': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];
            if ($s->canMod()) {
                $s->delete();

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            }
            break;
        }
    }

    return($ret);
}
