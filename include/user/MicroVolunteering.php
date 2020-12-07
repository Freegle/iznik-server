<?php
namespace Freegle\Iznik;

class MicroVolunteering
{
    private $dbhr, $dbhm;

    # Sometimes we make changes which affect the validity of previous data for the same tasks.  If so, note this here.
    # 1 - SearchTerm asking for 10 out of the top 1000 search terms.
    # 2 - SearchTerm asking for 10 out of the top 50 search terms.
    # 3 - Use item names instead of search terms.
    # 4 - Use top 300 items rather than top 100
    const VERSION = 4;

    const CHALLENGE_CHECK_MESSAGE = 'CheckMessage';
    const CHALLENGE_SEARCH_TERM = 'SearchTerm';  // No longer used.
    const CHALLENGE_ITEMS = 'Items';
    const CHALLENGE_FACEBOOK_SHARE = 'Facebook';
    const CHALLENGE_PHOTO_ROTATE = 'PhotoRotate';

    const RESULT_APPROVE = 'Approve';
    const RESULT_REJECT = 'Reject';

    # The number of people required to assume this is a good thing.  Note that the original poster did, too.
    const APPROVAL_QUORUM = 2;

    # The number of people we'll ask if there are differences of views.
    const DISSENTING_QUORUM = 3;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function challenge($userid, $groupid = NULL, $types) {
        $ret = NULL;
        $today = date('Y-m-d');

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        $groupids = [ $groupid ];

        if (!$groupid) {
            # Get all their groups.
            $groupids = $u->getMembershipGroupIds(FALSE, Group::GROUP_FREEGLE, $userid);
        }

        # Find the earliest message:
        # - on approved (in the spatial index)
        # - not explicitly moderated
        # - from the current day (use messages because we're interested in the first post).
        # - on one of these groups
        # - micro-volunteering is enabled on the group
        # - not from us
        # - not had a quorum of opinions
        # - not one we've seen
        # - still open
        # - on a group with this kind of microvolunteering enabled.
        if (in_array(self::CHALLENGE_CHECK_MESSAGE, $types) &&
            count($groupids)) {
            $msgs = $this->dbhr->preQuery(
                "SELECT messages_spatial.msgid,
       (SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid) AS reviewcount,
       (SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid AND result = ?) AS approvalcount
    FROM messages_spatial 
    INNER JOIN messages_groups ON messages_spatial.msgid = messages_groups.msgid
    INNER JOIN messages ON messages.id = messages_spatial.msgid
    INNER JOIN groups ON groups.id = messages_groups.groupid
    LEFT JOIN microactions ON microactions.msgid = messages_spatial.msgid AND microactions.userid = ?    
    LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_spatial.msgid
    WHERE groupid IN (" . implode(',', $groupids) . " ) 
        AND DATE(messages.arrival) = CURDATE()
        AND approvedby IS NULL  
        AND fromuser != ?
        AND microvolunteering = 1
        AND messages_outcomes.id IS NULL
        AND messages.deleted IS NULL
        AND microactions.id IS NULL
        AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.approvedmessages') = 1)
    HAVING approvalcount < ? AND reviewcount < ?
    ORDER BY messages_groups.arrival ASC LIMIT 1",
                [
                    self::RESULT_APPROVE,
                    $userid,
                    $userid,
                    self::APPROVAL_QUORUM,
                    self::DISSENTING_QUORUM
                ]
            );

            foreach ($msgs as $msg) {
                $ret = [
                    'type' => self::CHALLENGE_CHECK_MESSAGE,
                    'msgid' => $msg['msgid']
                ];
            }
        }

        if (!$ret && $u->hasFacebookLogin() && in_array(self::CHALLENGE_FACEBOOK_SHARE, $types)) {
            # Try sharing of Facebook post.
            $posts = $this->dbhr->preQuery("SELECT groups_facebook_toshare.* FROM groups_facebook_toshare LEFT JOIN microactions ON microactions.facebook_post = groups_facebook_toshare.id AND microactions.userid = ? WHERE DATE(groups_facebook_toshare.date) = CURDATE() AND microactions.id IS NULL ORDER BY date DESC LIMIT 1;", [
                $userid
            ]);

            foreach ($posts as $post) {
                $ret = [
                    'type' => self::CHALLENGE_FACEBOOK_SHARE,
                    'facebook' => $post
                ];
            }
        }

        if (!$ret && in_array(self::CHALLENGE_PHOTO_ROTATE, $types)) {
            # Select 9 distinct random recent photos that we've not reviewed.

            $atts = $this->dbhr->preQuery("SELECT messages_attachments.id, 
       (SELECT COUNT(*) AS count FROM microactions WHERE rotatedimage = messages_attachments.id) AS reviewcount
    FROM messages_groups 
    INNER JOIN messages_attachments ON messages_attachments.msgid = messages_groups.msgid
    LEFT JOIN microactions ON microactions.rotatedimage = messages_attachments.id AND userid = ?
    INNER JOIN groups ON groups.id = messages_groups.groupid AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.photorotate') = 1)
    WHERE arrival >= ? AND groupid IN (" . implode(',', $groupids) . ") AND microactions.id IS NULL
    HAVING reviewcount < ?
    ORDER BY RAND() LIMIT 9;", [
                $userid,
                $today,
                self::DISSENTING_QUORUM
            ]);

            if (count($atts)) {
                $photos = [];
                $a = new Attachment($this->dbhr, $this->dbhm);

                foreach ($atts as $att) {
                    $photos[] = [
                        'id' => $att['id'],
                        'path' => $a->getPath(TRUE, $att['id'])
                    ];
                }

                $ret = [
                    'type' => self::CHALLENGE_PHOTO_ROTATE,
                    'photos' => $photos
                ];
            }
        }

        if (!$ret && in_array(self::CHALLENGE_SEARCH_TERM, $types)) {
            # Try pairing of popular item names.
            #
            # We choose 10 random distinct popular items, and ask which are related.
            $enabled = $this->dbhr->preQuery("SELECT memberships.id FROM memberships INNER JOIN groups ON memberships.groupid = groups.id WHERE memberships.userid = ? AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.wordmatch') = 1);", [
                $userid
            ]);

            if (count($enabled)) {
                $items = $this->dbhr->preQuery("SELECT DISTINCT id, term FROM (SELECT id, name AS term FROM items WHERE LENGTH(name) > 2 ORDER BY popularity DESC LIMIT 300) t ORDER BY RAND() LIMIT 10;");
                $ret = [
                    'type' => self::CHALLENGE_SEARCH_TERM,
                    'terms' => $items
                ];
            }
        }

        return $ret;
    }

    public function responseCheckMessage($userid, $msgid, $result, $comments) {
        if ($result == self::RESULT_APPROVE || $result == self::RESULT_REJECT) {
            # Insert might fail if message is deleted - timing window.
            $this->dbhm->preExec("INSERT INTO microactions (userid, msgid, result, comments, version) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE result = ?, comments = ?, version = ?;", [
                $userid,
                $msgid,
                $result,
                $comments,
                self::VERSION,
                $result,
                $comments,
                self::VERSION
            ]);

            if ($result == self::RESULT_REJECT) {
                # Check whether we have enough votes to flag this up to mods.
                $votes = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM microactions WHERE msgid = ? AND result = ?;", [
                    $msgid,
                    self::RESULT_REJECT
                ]);

                if ($votes[0]['count'] >= self::APPROVAL_QUORUM) {
                    $m = new Message($this->dbhr, $this->dbhm, $msgid);
                    $m->sendForReview("A member thinks this message is not OK.");
                }
            }
        }
    }

    public function responseItems($userid, $item1, $item2) {
        try {
            $this->dbhm->preExec("INSERT INTO microactions (userid, item1, item2, version) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE userid = userid, version = ?;", [
                $userid,
                $item1,
                $item2,
                self::VERSION,
                self::VERSION
            ]);
        } catch (Exception $e) {}
    }

    public function responseFacebook($userid, $postid, $result) {
        try {
            $this->dbhm->preExec("INSERT IGNORE INTO microactions (userid, facebook_post, result, version) VALUES (?, ?, ?, ?);", [
                $userid,
                $postid,
                $result,
                self::VERSION
            ]);
        } catch (Exception $e) {}
    }

    public function responsePhotoRotate($userid, $photoid, $result, $deg) {
        $ret = FALSE;

        try {
            $this->dbhm->preExec("INSERT IGNORE INTO microactions (userid, rotatedimage, result, version) VALUES (?, ?, ?, ?);", [
                $userid,
                $photoid,
                $result,
                self::VERSION
            ]);
        } catch (Exception $e) {}

        # Check whether we have enough votes to rotate this photo.
        $votes = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM microactions WHERE rotatedimage = ? AND result = ?;", [
            $photoid,
            self::RESULT_REJECT
        ]);

        if ($votes[0]['count'] >= self::APPROVAL_QUORUM) {
            # We do.
            $a = new Attachment($this->dbhr, $this->dbhm, $photoid, Attachment::TYPE_MESSAGE);
            $data = $a->getData();
            $i = new Image($data);
            $i->rotate($deg);
            $newdata = $i->getData(100);
            $a->setData($newdata);
            $a->recordRotate();
            $ret = TRUE;
        }

        return $ret;
    }
}