<?php
namespace Freegle\Iznik;



class CommunityEvent extends Entity
{
    /** @var  $dbhm LoggedPDO */
    public $publicatts = [ 'id', 'userid', 'pending', 'title', 'location', 'contactname', 'contactphone', 'contactemail', 'contacturl', 'description', 'added', 'heldby', 'externalid'];
    public $settableatts = [ 'pending', 'title', 'location', 'contactname', 'contactphone', 'contactemail', 'contacturl', 'description' ];
    var $event;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'communityevents', 'event', $this->publicatts, $fetched);
    }

    public function create($userid, $title, $location, $contactname, $contactphone, $contactemail, $contacturl, $description, $photo = NULL, $externalid = NULL) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO communityevents (`userid`, `pending`, `title`, `location`, `contactname`, `contactphone`, `contactemail`, `contacturl`, `description`, `externalid`) VALUES (?,1,?,?,?,?,?,?,?,?);", [
            $userid, $title, $location, $contactname, $contactphone, $contactemail, $contacturl, $description, $externalid
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
            $this->fetch($this->dbhm, $this->dbhm, $id, 'communityevents', 'event', $this->publicatts);

            if ($photo) {
                $this->setPhoto($photo);
            }
        }

        return($id);
    }

    public function setPhoto($photoid) {
        $this->dbhm->preExec("UPDATE communityevents_images SET eventid = ? WHERE id = ?;", [ $this->id, $photoid ]);
    }

    public function addDate($start, $end) {
        # If the client passes us an end date before the start date, that's a bug, but we don't want to lose the event
        # so correct it to an hour.
        $end = (strtotime($end) < strtotime($start)) ? date("Y-m-d H:i:s", strtotime($start) + 60 * 60) : $end;

        $this->dbhm->preExec("INSERT INTO communityevents_dates (eventid, start, end) VALUES (?, ?, ?);" , [
            $this->id,
            $start,
            $end
        ]);
    }

    public function removeDate($id) {
        $this->dbhm->preExec("DELETE FROM communityevents_dates WHERE id = ?;" , [
            $id
        ]);
    }

    public function removeDates() {
        $this->dbhm->preExec("DELETE FROM communityevents_dates WHERE eventid = ?;" , [
            $this->id
        ]);
    }

    public function addGroup($groupid) {
        # IGNORE as we have a unique key on event/group.
        $this->dbhm->preExec("INSERT IGNORE INTO communityevents_groups (eventid, groupid) VALUES (?, ?);" , [
            $this->id,
            $groupid
        ]);

        # Create now so that we can pass the groupid.
        $n = new Newsfeed($this->dbhr, $this->dbhm);
        $fid = $n->create(Newsfeed::TYPE_COMMUNITY_EVENT, $this->event['userid'], NULL, NULL, NULL, NULL, $groupid, $this->id, NULL, NULL);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        error_log("Add group notify $groupid");
        $n->notifyGroupMods($groupid);
    }

    public function removeGroup($id) {
        $this->dbhm->preExec("DELETE FROM communityevents_groups WHERE eventid = ? AND groupid = ?;" , [
            $this->id,
            $id
        ]);
    }

    public function listForUser($userid, $pending, &$ctx) {
        $ret = [];
        $pendingq = $pending ? " AND pending = 1 " : " AND pending = 0 ";
        $roleq = $pending ? " AND role IN ('Owner', 'Moderator') " : '';
        $ctxq = $ctx ? (" AND end > '" . Utils::safedate($ctx['end']) . "' ") : '';

        $mysqltime = date("Y-m-d H:i:s", time());
        $sql = "SELECT communityevents.*, communityevents.pending, communityevents_dates.end, communityevents_groups.groupid FROM communityevents INNER JOIN communityevents_groups ON communityevents_groups.eventid = communityevents.id AND groupid IN (SELECT groupid FROM memberships WHERE userid = ? $roleq) AND deleted = 0 INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id AND end >= ? $pendingq $ctxq ORDER BY end ASC LIMIT 20;";
        #error_log("$sql, $userid, $mysqltime");
        $events = $this->dbhr->preQuery($sql, [
            $userid,
            $mysqltime
        ]);

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        foreach ($events as $event) {
            if (Group::get($this->dbhr, $this->dbhm, $event['groupid'])->getSetting('communityevents', 1) &&
                (!$event['pending'] || $u->activeModForGroup($event['groupid']))) {
                $ctx['end'] = $event['end'];
                $e = new CommunityEvent($this->dbhr, $this->dbhm, $event['id'], $event);
                $atts = $e->getPublic();
                $atts['canmodify'] = $e->canModify($userid);

                $ret[] = $atts;
            }
        }

        return($ret);
    }

    public function listForGroup($pending, $groupid = NULL, &$ctx) {
        $ret = [];
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        if ($g->getSetting('communityevent', 1)) {
            # We can only see pending events if we're an owner/mod.
            # We might be called for a specific groupid; if not then use logged in user's groups.
            $pendingq = $pending ? " AND pending = 1 " : " AND pending = 0 ";
            $roleq = $pending ? (" AND groupid IN (SELECT groupid FROM memberships WHERE userid = " . intval(
                    $myid
                ) . " AND role IN ('Owner', 'Moderator')) ") : '';
            $groupq = $groupid ? (" AND groupid = " . intval(
                    $groupid
                )) : (" AND groupid IN (SELECT groupid FROM memberships WHERE userid = " . intval($myid) . ") ");
            $ctxq = $ctx ? (" AND end > '" . Utils::safedate($ctx['end']) . "' ") : '';

            $mysqltime = date("Y-m-d H:i:s", time());
            $sql = "SELECT communityevents.*, communityevents_dates.end FROM communityevents INNER JOIN communityevents_groups ON communityevents_groups.eventid = communityevents.id $groupq $roleq AND deleted = 0 INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id AND end >= ? $pendingq $ctxq ORDER BY end ASC LIMIT 20;";
            # error_log("$sql, $mysqltime");
            $events = $this->dbhr->preQuery($sql, [
                $mysqltime
            ]);

            $myid = Session::whoAmId($this->dbhr, $this->dbhm);

            foreach ($events as $event) {
                $ctx['end'] = $event['end'];
                $e = new CommunityEvent($this->dbhr, $this->dbhm, $event['id'], $event);
                $atts = $e->getPublic();

                $atts['canmodify'] = $e->canModify($myid);

                $ret[] = $atts;
            }
        }

        return($ret);
    }

    public function getPublic() {
        $atts = parent::getPublic();
        $atts['groups'] = [];
        
        $groups = $this->dbhr->preQuery("SELECT * FROM communityevents_groups WHERE eventid = ?", [ $this->id ]);

        $atts['dates'] = $this->dbhr->preQuery("SELECT * FROM communityevents_dates WHERE eventid = ? ORDER BY end ASC", [ $this->id ]);
        
        foreach ($atts['dates'] as &$date) {
            $date['start'] = Utils::ISODate($date['start']);
            $date['end'] = Utils::ISODate($date['end']);
        }

        $photos = $this->dbhr->preQuery("SELECT id FROM communityevents_images WHERE eventid = ?;", [ $this->id ]);
        foreach ($photos as $photo) {
            $a = new Attachment($this->dbhr, $this->dbhm, $photo['id'], Attachment::TYPE_COMMUNITY_EVENT);

            $atts['photo'] = [
                'id' => $photo['id'],
                'path' => $a->getPath(FALSE),
                'paththumb' => $a->getPath(TRUE)
            ];
        }

        if ($atts['userid']) {
            $u = User::get($this->dbhr, $this->dbhm, $atts['userid']);
            $atts['user'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
        } else {
            $atts['user'] = [
                'id' => 0,
                'displayname' => 'System'
            ];
        }

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $atts['groups'][] = $g->getPublic(TRUE);

            if (Session::modtools() && $atts['userid']) {
                $atts['groups'][count($atts['groups']) - 1]['ourPostingStatus'] = $u->getMembershipAtt($group['groupid'], 'ourPostingStatus');
            }
        }

        unset($atts['userid']);

        if ($atts['heldby']) {
            $u = User::get($this->dbhr, $this->dbhm, $atts['heldby']);
            $atts['heldby'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
        }

        # Ensure leading 0 not stripped.
        $atts['contactphone'] = Utils::pres('contactphone', $atts) ? "{$atts['contactphone']} " : NULL;
        $atts['url'] = 'https://' . USER_SITE . '/communityevent/' . $atts['id'];

        if (strlen($atts['contacturl']) && strpos($atts['contacturl'], 'http') === FALSE) {
            $atts['contacturl'] = 'https://' . $atts['contacturl'];
        }

        return($atts);
    }

    public function canModify($userid) {
        # We can modify events which we created, or where we are a mod on any of the groups on which this event
        # appears, or if we're support/admin.
        $canmodify = FALSE;

        if ($userid) {
            $u = User::get($this->dbhr, $this->dbhm, $userid);
            #error_log("Check user {$this->event['userid']}, $userid");
            $canmodify = Utils::presdef('userid', $this->event, NULL) == $userid || ($u && $u->isAdminOrSupport());
            #error_log("Modify $canmodify for $userid admin" . ($u && $u->isAdminOrSupport()));

            if (!$canmodify) {
                $groups = $this->dbhr->preQuery("SELECT * FROM communityevents_groups WHERE eventid = ?;", [ $this->id ]);
                #error_log("\"SELECT * FROM communityevents_groups WHERE eventid = {$this->id};");
                foreach ($groups as $group) {
                    #error_log("Check for group {$group['groupid']} " . $u->isAdminOrSupport() . ", " . $u->isModOrOwner($group['groupid']) . " user $userid");
                    if ($u->isAdminOrSupport() || $u->isModOrOwner($group['groupid'])) {
                        #error_log("Mod admin " . $u->isAdminOrSupport(). " group " . $u->isModOrOwner($group['groupid']));
                        $canmodify = TRUE;
                    }
                }
            }
        }

        return($canmodify);
    }

    public function delete() {
        $this->dbhm->preExec("UPDATE communityevents SET deleted = 1 WHERE id = ?;", [ $this->id ]);
    }
}

