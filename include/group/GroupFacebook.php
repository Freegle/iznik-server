<?php
namespace Freegle\Iznik;

use JanuSoftware\Facebook\FacebookSession;
use JanuSoftware\Facebook\FacebookJavaScriptLoginHelper;
use JanuSoftware\Facebook\FacebookCanvasLoginHelper;
use JanuSoftware\Facebook\FacebookRequest;
use JanuSoftware\Facebook\FacebookRequestException;

class GroupFacebook {
    static public $publicatts = ['name', 'token', 'type', 'authdate', 'valid', 'msgid', 'msgarrival', 'eventid', 'sharefrom', 'token', 'groupid', 'id', 'lastupdated', 'uid' ];

    const TYPE_PAGE = 'Page';

    const ACTION_DO = 'Do';
    const ACTION_HIDE = 'Hide';
    const ACTION_DO_POPULAR  = 'DoPopular';
    const ACTION_HIDE_POPULAR = 'HidePopular';
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

        $ret['authdate'] = Utils::ISODate($ret['authdate']);
        $ret['msgarrival'] = Utils::ISODate($ret['msgarrival']);

        return($ret);
    }

    public function getFB($graffiti, $apptoken = FALSE) {
        #error_log("Get FB $graffiti");
        $fb = new \JanuSoftware\Facebook\Facebook([
            'app_id' => $graffiti ? FBGRAFFITIAPP_ID : FBAPP_ID,
            'app_secret' => $graffiti ? FBGRAFFITIAPP_SECRET : FBAPP_SECRET,
            'default_graph_version' =>  'v13.0'
        ]);

        if ($apptoken) {
            # Use an app access token
            $this->setToken($fb->getApplication()->getAccessToken());
        }

        return($fb);
    }

    public function setToken($token) {
        $this->token = $token;
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

        $created = $this->dbhm->lastInsertId();
        $this->token = $token;

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->notifyGroupMods($groupid);

        return($created);
    }

    public function remove($uid) {
        $this->dbhm->preExec("DELETE FROM groups_facebook WHERE uid = ?;", [ $uid ]);
    }

    public function getPostsToShare($sharefrom, $since = "last week", $token = NULL) {
        $fb = $this->getFB(TRUE, FALSE);
        $count = 0;
        #error_log("Scrape posts from $sharefrom");

        # Get posts we might want to share.  This returns only posts by the page itself.
        try {
            $url = $sharefrom . "/posts?limit=100&&fields=id,message,icon,full_picture,created_time";
            #error_log("Get from feed $url, $token");
            $ret = $fb->get($url, $token);
            #error_log("Got ok");

            $posts = $ret->getDecodedBody();

            foreach ($posts['data'] as $wallpost) {
                error_log("Got " . json_encode($wallpost));
                if (!Utils::pres('created_time', $wallpost) || (strtotime($wallpost['created_time']) >= strtotime($since))) {
                    error_log("Include");
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
            }
        } catch (\Exception $e) {
            $code = $e->getCode();
            error_log("Failed to scrape code $code message " . $e->getMessage() . " token " . $this->token);
        }

        # Reset any rate-limited pages.
        $this->dbhm->preExec("UPDATE `groups_facebook` SET valid = 1, lasterror = 'Reset after rate limit' WHERE valid = 0 AND lasterror LIKE '%We limit how often you can post%' AND TIMESTAMPDIFF(MINUTE, lasterrortime, NOW()) > 120;");

        return($count);
    }

    public function listSocialActions(&$ctx, $me = NULL, $mindate = '7 days ago') {
        # We want posts which have been collected from the sharefrom page which have not already been shared, for
        # groups where we are a moderator.
        $mindate = date("Y-m-d H:i:s", strtotime($mindate));
        $me = $me ? $me : Session::whoAmI($this->dbhr, $this->dbhm);
        $ret = [];
        $dateq = $mindate ? " groups_facebook_toshare.date >= '$mindate' AND " : '';

        if ($me) {
            $minid = $ctx ? intval($ctx['id']) : 0;

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
                        $remaining[$post['id']]['full_picture'] = Utils::presdef('full_picture', $data, NULL);
                        $remaining[$post['id']]['message'] = Utils::presdef('message', $data, NULL);
                        $remaining[$post['id']]['type'] = Utils::presdef('type', $data, NULL);

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
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $ret = FALSE;

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
                        } catch (\Exception $e) {
                            # Some likes can fail when using the user access token because some posts are
                            # strangely not visible.  Unclear why.  But don't mark the token as invalid just for
                            # these.
                            error_log("Like failed with " . $e->getMessage());
                        }
                        #error_log("Like returned " . var_export($res, true));
                    }

                    try {
                        $a = explode('_', $action['postid']);
                        $params['link'] = 'https://www.facebook.com/' . $a[0] . '/posts/' . $a[1];
                        $result = $fb->post($this->id . '/feed', $params, $this->token);
                        #error_log("Share via " . json_encode($params) . " returned " . var_export($result, TRUE));
                        $ret = TRUE;
                    } catch (\Exception $e) {
                        error_log("Share failed with " . $e->getMessage() . " params " . json_encode($params));
                        $msg = $e->getMessage();

                        if (strpos($msg, 'The url you supplied is invalid') === FALSE &&
                            strpos($msg, 'Please reduce the amount of data you\'re asking for') === FALSE) {
                            # These errors seems to happen occasionally at random, and doesn't mean the link is
                            # invalid.
                            $this->dbhm->preExec("UPDATE groups_facebook SET valid = 0, lasterror = ?, lasterrortime = NOW() WHERE uid = ?", [
                                $msg,
                                $action['uid']
                            ]);
                        }
                    }

                    $n = new PushNotifications($this->dbhr, $this->dbhm);
                    $n->notifyGroupMods($action['groupid']);
                }
            }
        }

        return $ret;
    }

    public function hideSocialAction($id) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
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

    public function sharePopularMessage($groupid, $msgid = NULL, $batch = FALSE) {
        $ret = FALSE;

        if (!$msgid) {
            $pops = $this->dbhr->preQuery("SELECT * FROM messages_popular WHERE groupid = ? AND shared = 0 AND declined = 0 AND expired = 0;", [
                $groupid
            ]);

            foreach ($pops as $pop) {
                $msgid = $pop['msgid'];
            }
        }

        if ($msgid) {
            $me = Session::whoAmI($this->dbhr, $this->dbhm);

            if ($batch || $me && $me->isModOrOwner($groupid)) {
                $pages = $this->dbhr->preQuery("SELECT * FROM groups_facebook WHERE groupid = ?;", [ $groupid ]);

                foreach ($pages as $page)
                {
                    # Whether or not this works, remember that we've tried, so that we don't try again.
                    $this->dbhm->preExec(
                        "UPDATE messages_popular SET shared = 1, declined = 0 WHERE msgid = ? AND groupid = ?;",
                        [
                            $msgid,
                            $groupid
                        ]
                    );

                    $fb = $this->getFB(TRUE);
                    $token = $page['token'];

                    $g = Group::get($this->dbhr, $this->dbhm, $groupid);
                    $s = new Shortlink($this->dbhr, $this->dbhm);
                    $shortlinks = $s->listAll($groupid);

                    try
                    {
                        # Find the location.  We're assuming that this will encourage Facebook to show it to
                        # people nearby.
                        $m = new Message($this->dbhr, $this->dbhm, $msgid);
                        $atts = $m->getPublic();

                        $message = 'FREE!  Trending yesterday on ' . $g->getName() . ".";
                        $link = 'https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort') . '/' . $msgid;
                        $message .= "\n\n" . $m->getSubject() . "\n\nHop over to $link to see what else is being given away - or to ask for stuff you'd like.";

                        $picture = NULL;

                        if (Utils::pres('attachments', $atts) && count($atts['attachments'])) {
                            $picture = $atts['attachments'][0]['path'];
                        }

                        $fb->post($page['id'] . '/photos', [
                            'message' => $message,
                            'url' => $picture
                        ], $token);

                        $ret = TRUE;
                    } catch (\Exception $e) {
                        error_log("Share failed with " . $e->getMessage());
                    }
                }
            }
        }

        return $ret;
    }
}
