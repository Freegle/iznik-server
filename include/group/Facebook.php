<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/group/CommunityEvent.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');

use Facebook\FacebookSession;
use Facebook\FacebookJavaScriptLoginHelper;
use Facebook\FacebookCanvasLoginHelper;
use Facebook\FacebookRequest;
use Facebook\FacebookRequestException;

class GroupFacebook {
    static public $publicatts = ['name', 'token', 'type', 'authdate', 'valid', 'msgid', 'msgarrival', 'eventid', 'sharefrom', 'token', 'groupid', 'id', 'lastupdated', 'uid' ];

    const TYPE_PAGE = 'Page';

    const ACTION_DO = 'Do';
    const ACTION_HIDE = 'Hide';
    const ACTION_SHARE_MESSAGE_TO_GROUP = 'ShareMessageToGroup';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        foreach (GroupFacebook::$publicatts as $att) {
            $this->$att = NULL;
        }

        $this->uid = $id;

        if ($id) {
            $groups = $fetched ? [ $fetched ] : $this->dbhr->preQuery("SELECT * FROM groups_facebook WHERE uid = ?;", [ $id ]);
            foreach ($groups as $group) {
                foreach (GroupFacebook::$publicatts as $att) {
                    $this->$att = $group[$att];
                }
            }
        }
    }

    public function getPublic() {
        $ret = [];
        foreach (GroupFacebook::$publicatts as $att) {
            $ret[$att] = $this->$att;
        }

        $ret['authdate'] = ISODate($ret['authdate']);
        $ret['msgarrival'] = ISODate($ret['msgarrival']);

        return($ret);
    }

    public function getFB($graffiti, $apptoken = FALSE) {
        #error_log("Get FB $graffiti");
        $fb = new Facebook\Facebook([
            'app_id' => $graffiti ? FBGRAFFITIAPP_ID : FBAPP_ID,
            'app_secret' => $graffiti ? FBGRAFFITIAPP_SECRET : FBAPP_SECRET
        ]);

        if ($apptoken) {
            # Use an app access token
            $this->token = $fb->getApp()->getAccessToken();
        }

        return($fb);
    }

    public function add($groupid, $token, $name, $id, $type = GroupFacebook::TYPE_PAGE) {
        $this->dbhm->preExec("INSERT INTO groups_facebook (groupid, name, id, token, authdate, type) VALUES (?,?,?,?,NOW(), ?) ON DUPLICATE KEY UPDATE name = ?, id = ?, token = ?, authdate = NOW(), valid = 1, type = ?;",
            [
                $groupid,
                $name,
                $id,
                $token,
                $type,
                $name,
                $id,
                $token,
                $type
            ]);

        $this->token = $token;
        return($this->dbhm->lastInsertId());
    }

    public function remove($uid) {
        $this->dbhm->preExec("DELETE FROM groups_facebook WHERE uid = ?;", [ $uid ]);
    }

    public function getPostsToShare($sharefrom, $since = "last week") {
        $fb = $this->getFB(TRUE, TRUE);
        $count = 0;

        # Get posts we might want to share.  This returns only posts by the page itself.
        try {
            $url = $sharefrom . "/feed?limit=100&&fields=id,link,message,type,caption,icon,name,full_picture";
            #error_log("Get from feed $url, {$this->token}");
            $ret = $fb->get($url, $this->token);
            #error_log("Got ok");

            $posts = $ret->getDecodedBody();

            foreach ($posts['data'] as $wallpost) {
                $rc = $this->dbhm->preExec("INSERT IGNORE INTO groups_facebook_toshare (sharefrom, postid, data) VALUES (?,?,?);", [
                    $sharefrom,
                    $wallpost['id'],
                    json_encode($wallpost)
                ]);

                if ($rc) {
                    $id = $this->dbhm->lastInsertId();
                    $count++;

                    if ($id) {
                        # We only want one copy of this in our newsfeed because it's shown to everyone.
                        $n = new Newsfeed($this->dbhr, $this->dbhm);
                        $fid = $n->create(Newsfeed::TYPE_CENTRAL_PUBLICITY, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, $id);
                    }
                }
            }
        } catch (Exception $e) {
            $code = $e->getCode();
            error_log("Failed code $code message " . $e->getMessage() . " token " . $this->token);
        }

        return($count);
    }

    public function listSocialActions(&$ctx, $mindate = NULL) {
        # We want posts which have been collected from the sharefrom page which have not already been shared, for
        # groups where we are a moderator.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $ret = [];
        $dateq = $mindate ? " groups_facebook_toshare.date >= '$mindate' AND " : '';


        if ($me) {
            $minid = $ctx ? $ctx['id'] : 0;

            $modships = [];

            # Remove groups which aren't linked.
            $groups = $this->dbhr->preQuery("SELECT memberships.groupid FROM memberships INNER JOIN groups_facebook ON groups_facebook.groupid = memberships.groupid WHERE userid = ? AND role IN ('Owner', 'Moderator') AND valid = 1;",
                [
                    $me->getId()
                ]);

            foreach ($groups as $group) {
                # Only show social actions where we're an active mod.
                if ($me->activeModForGroup($group['groupid'])) {
                    $modships[] = $group['groupid'];
                }
            }

            if (count($modships) > 0) {
                # We want to find all Facebook pages where we haven't shared this post.  To make this scale better with
                # many groups we do a more complex query and then munge the data.
                #
                # We don't want groups = that's for posts, not social actions / publicity.
                $groupids = implode(',', $modships);
                $sql = "SELECT DISTINCT groups_facebook_toshare.*, groups_facebook.uid, 'Facebook' AS actiontype FROM groups_facebook_toshare 
INNER JOIN groups_facebook ON groups_facebook.sharefrom = groups_facebook_toshare.sharefrom AND valid = 1 
LEFT JOIN groups_facebook_shares ON groups_facebook_shares.postid = groups_facebook_toshare.postid AND groups_facebook_shares.uid = groups_facebook.uid 
WHERE 
$dateq 
groups_facebook_toshare.id > ?
AND groups_facebook.groupid IN ($groupids) 
AND groups_facebook_shares.postid IS NULL 
AND groups_facebook.type = 'Page' 
ORDER BY groups_facebook_toshare.id DESC;";
                $posts = $this->dbhr->preQuery($sql, [ $minid ]);

                $remaining = [];

                foreach ($posts as &$post) {
                    $ctx['id'] = $post['id'];

                    if (!array_key_exists($post['id'], $remaining)) {
                        # This is a new post which we've not considered so far.
                        $remaining[$post['id']] = $post;
                        unset($remaining[$post['id']]['uid']);
                        $remaining[$post['id']]['uids'] = [];
                        $data = json_decode($post['data'], TRUE);
                        $remaining[$post['id']]['full_picture'] = presdef('full_picture', $data, NULL);
                        $remaining[$post['id']]['message'] = presdef('message', $data, NULL);
                        $remaining[$post['id']]['type'] = presdef('type', $data, NULL);

                        if (preg_match('/(.*)_(.*)/', $post['postid'], $matches)) {
                            # Create the iframe version of the Facebook plugin.
                            $pageid = $matches[1];
                            $postid = $matches[2];
                            $remaining[$post['id']]['iframe'] = '<iframe src="https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2F' . $pageid . '%2Fposts%2F' . $postid . '%2F&width=auto&show_text=true&appId=' . FBGRAFFITIAPP_ID . '&height=500" width="500" height="500" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowTransparency="true"></iframe>';
                        }
                    }

                    # Add this Facebook page/group in for this post.
                    $remaining[$post['id']]['uids'][] = $post['uid'];
                }

                foreach ($remaining as $groupid => $post) {
                    $ret[] = $post;
                }
            }
        }

        return($ret);
    }

    public function performSocialAction($id) {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $ret = [];
        if ($me) {
            # We need to be a mod on the relevant group.
            $modships = $me->getModeratorships();

            if (count($modships) > 0) {
                $groupids = implode(',', $modships);
                $sql = "SELECT DISTINCT groups_facebook_toshare.*, groups_facebook.type AS facebooktype, groups_facebook.uid, groups_facebook.groupid FROM groups_facebook_toshare INNER JOIN groups_facebook ON groups_facebook.sharefrom = groups_facebook_toshare.sharefrom AND groupid IN ($groupids) AND uid = ? AND groups_facebook_toshare.id = ?;";
                $actions = $this->dbhr->preQuery($sql, [ $this->uid, $id ]);

                foreach ($actions as $action) {
                    # Whether or not this worked, remember that we've tried, so that we don't try again.
                    #error_log("Record INSERT IGNORE INTO groups_facebook_shares (uid, groupid, postid) VALUES ({$action['uid']},{$action['groupid']},{$action['postid']});");
                    $this->dbhm->preExec("INSERT IGNORE INTO groups_facebook_shares (uid, groupid, postid) VALUES (?,?,?);", [
                        $action['uid'],
                        $action['groupid'],
                        $action['postid']
                    ]);

                    $page = $action['facebooktype'] == GroupFacebook::TYPE_PAGE;
                    $fb = $this->getFB($page);

                    if ($page) {
                        # Like the original post.
                        try {
                            $res = $fb->post($action['postid'] . '/likes', [], $this->token);
                        } catch (Exception $e) {
                            # Some likes can fail when using the user access token because some posts are
                            # strangely not visible.  Unclear why.  But don't mark the token as invalid just for
                            # these.
                            error_log("Like failed with " . $e->getMessage());
                        }
                        #error_log("Like returned " . var_export($res, true));
                    }

                    try {
                        # We want to share the post out with the existing details - but we need to remove the id, otherwise
                        # it's an invalid op.
                        $params = json_decode($action['data'], TRUE);
                        unset($params['id']);

                        error_log("Post to {$this->name} {$this->id} with {$this->token} action " . var_export($params, TRUE));
                        $result = $fb->post($this->id . '/feed', $params, $this->token);
                        error_log("Post returned " . var_export($result, true));
                    } catch (Exception $e) {
                        # Sometimes we get problems sharing, but we would be able to post ourselves.
                        $code = $e->getCode();

                        # Try a straight post.
                        error_log("Share failed, try post");
                        unset($params['link']);
                        try {
                            if (pres('full_picture', $params)) {
                                # There is a photo, so the process is more complex.
                                error_log("Get picture");

                                # Now post it to this page.
                                $result = $fb->post($this->id . '/photos', [
                                    'url' => $params['full_picture'],
                                    'message' => $params['message']
                                ], $this->token);
                                error_log("Photo post returned " . var_export($result, TRUE));
                            } else {
                                $result = $fb->post($this->id . '/feed', $params, $this->token);
                                error_log("Simple post returned " . var_export($result, TRUE));
                            }
                        } catch (Exception $e) {
                            $code = $e->getCode();
                            error_log("Simple post failed with " . $e->getMessage());
                            # These numbers come from FacebookResponseException.
                            #
                            # Code 100 can be returned for some posts which are not visible.
                            if ($code == 100 || $code == 102 || $code == 190) {
                                $this->dbhm->preExec("UPDATE groups_facebook SET valid = 0, lasterrortime = NOW(), lasterror = ? WHERE uid = ?;", [
                                    $e->getMessage(),
                                    $this->uid
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function hideSocialAction($id) {
        $me = whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            # We need to be a mod on the relevant group.
            $modships = $me->getModeratorships();

            if (count($modships) > 0) {
                $groupids = implode(',', $modships);

                $sql = "SELECT DISTINCT groups_facebook_toshare.*, groups_facebook.type AS facebooktype, groups_facebook.uid, groups_facebook.groupid FROM groups_facebook_toshare INNER JOIN groups_facebook ON groups_facebook.sharefrom = groups_facebook_toshare.sharefrom AND groupid IN ($groupids) AND uid = ? AND groups_facebook_toshare.id = ?;";
                $actions = $this->dbhr->preQuery($sql, [ $this->uid, $id ]);

                foreach ($actions as $action) {
                    $this->dbhm->preExec("INSERT IGNORE INTO groups_facebook_shares (uid, groupid, postid, status) VALUES (?,?,?, 'Hidden');", [
                        $this->uid,
                        $action['groupid'],
                        $action['postid']
                    ]);
                }
            }
        }
    }

    public static function listForGroup($dbhr, $dbhm, $groupid) {
        $ids = [];
        $groups = $dbhr->preQuery("SELECT uid FROM groups_facebook WHERE groupid = ?;", [ $groupid ]);
        foreach ($groups as $group) {
            $ids[] = $group['uid'];
        }

        return($ids);
    }

    public static function listForGroups($dbhr, $dbhm, $gids, $token = FALSE) {
        $ret = [];

        if (count($gids)) {
            $groups = $dbhr->preQuery("SELECT " . implode(',', GroupFacebook::$publicatts) . " FROM groups_facebook WHERE groupid IN (" . implode(',', $gids) . ");");
            foreach ($groups as &$group) {
                if (!$token) {
                    unset($group['token']);
                }

                $ret[$group['groupid']][] = $group;
            }
        }

        return($ret);
    }

    public function pollForChanges() {
        $fb = $this->getFB(FALSE);
        $count = 0;
        $since = $this->lastupdated ? ("since=" . ISODate($this->lastupdated) . "&") : '';
        $lastupdated = $this->lastupdated ? strtotime($this->lastupdated) : NULL;
        $fields = "&fields=id,from,updated_time,place,link,message,type,caption,icon,name";
        $next = $this->id . "/feed?$since$fields";
        $token = $this->token;
        $u = new User($this->dbhr, $this->dbhm);
        $now = time();

        do {
            try {
                $ret = $fb->get($next, $token);

                $posts = $ret->getDecodedBody();
                $next = pres('paging', $posts) ? presdef('next', $posts['paging'], NULL) : NULL;
                if ($next) {
                    $p = strpos($next, "/{$this->id}");
                    $next = $p !== FALSE ? substr($next, $p) : $next;
                }
                $token = NULL;

                foreach ($posts['data'] as $post) {
                    if ($post['type'] == 'link') {
                        if (preg_match('/https:\/\/.*\/message\/(.*)\?src=fbgroup/', $post['link'], $matches)) {
                            # This is a post we published to the group.
                            error_log("Our post of msg #{$matches[1]}");
                            $msgid = $matches[1];
                            $updated = strtotime($post['updated_time']);
                            $m = new Message($this->dbhr, $this->dbhm, $msgid);

                            if ($m->getId() && ($m->getPrivate('deleted') || $m->hasOutcome())) {
                                # We want to remove the post from Facebook.
                                error_log("...delete {$post['id']}");
                                $ret = $fb->delete($post['id'], [], $this->token);
                            } else if (!$lastupdated || $updated >= $lastupdated) {
                                # This post has changed on Facebook since we last checked.
                                error_log("...changed");

                                if ($m->getId() && !$m->getPrivate('deleted') && !$m->hasOutcome()) {
                                    # The message still exists and is active.  Get the comments from Facebook.
                                    $ret = $fb->get($post['id'] . '/comments', $this->token);

                                    do {
                                        $comments = $ret->getDecodedBody();
                                        if (pres('data', $comments) && count($comments['data']) > 0) {
                                            foreach ($comments['data'] as $comment) {
                                                error_log("Got comment " . var_export($comment, TRUE));
                                                if (pres('from', $comment)) {
                                                    $fbid = presdef('id', $comment['from'], NULL);
                                                    error_log("FBID $fbid");

                                                    if ($fbid) {
                                                        # We have a Facebook id.  If they have ever used the site before,
                                                        # we will have a Facebook login for that id.
                                                        $cid = $u->findByLogin(User::LOGIN_FACEBOOK, $fbid);
                                                        error_log("Already know user? $cid");

                                                        if ($cid) {
                                                            # We know this user.  Set up a chatroom between them
                                                            $r = new ChatRoom($this->dbhr, $this->dbhm);
                                                            $rid = $r->createConversation($m->getFromuser(), $cid);
                                                            error_log("Created conversation $rid to " . $m->getFromuser());
                                                            error_log("Already processed? " . $r->containsFBComment($comment['id']));

                                                            if ($rid && !$r->containsFBComment($comment['id'])) {
                                                                # Add this comment as a message.  If it's the first time
                                                                # we've referred to this message in this chat then flag it
                                                                # as interested so that the platform user knows which
                                                                # message they're talking about.
                                                                $already = $r->referencesMessage($msgid);
                                                                error_log("Already referencing? $already");
                                                                $type = $already ? ChatMessage::TYPE_DEFAULT : ChatMessage::TYPE_INTERESTED;
                                                                $cm = new ChatMessage($this->dbhr, $this->dbhm);
                                                                list ($mid, $banned) = $cm->create($rid, $cid, $comment['message'], $type, $already ? NULL : $msgid, FALSE, NULL, NULL, NULL, NULL, $comment['id']);
                                                                error_log("Created chat $mid");

                                                                # Flag this conversation as being sync'd to Facebook.
                                                                # This helps us find which to sync.  When we get the
                                                                # first message after this, it'll set linkedonfacebook,
                                                                # which will trigger us posting a link to Facebook
                                                                # to this conversation.
                                                                error_log("Flag conversation? " . $r->getId() . " " . $r->getPrivate('synctofacebook') . var_export($r->getPublic(), TRUE));
                                                                if ($r->getPrivate('synctofacebook') == ChatRoom::FACEBOOK_SYNC_DONT) {
                                                                    $r->setPrivate('synctofacebook', ChatRoom::FACEBOOK_SYNC_REPLIED_ON_FACEBOOK);
                                                                    $r->setPrivate('synctofacebookgroupid', $this->uid);
                                                                }

                                                                $count++;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        $ret = $fb->next($ret->getGraphEdge());
                                    } while ($ret);
                                }
                            }
                        }
                    } else {
                        error_log(var_export($post, TRUE));
                    }
                }
            } catch (Exception $e) {
                $code = $e->getCode();
                error_log("Failed code $code message " . $e->getMessage() . " token " . $this->token);
            }
        } while ($next && count($posts) > 0);

        $this->dbhm->preExec("UPDATE groups_facebook SET lastupdated = ? WHERE uid = ?;", [
            date("Y-m-d H:i:s", $now),
            $this->uid
        ]);

        return($count);
    }
}
