<?php
namespace Freegle\Iznik;



# Logging.  This is not guaranteed against loss in the event of serious failure.
class Log
{
    /** @var  $dbhr LoggedPDO */
    var $dbhr;
    /** @var  $dbhm LoggedPDO */
    var $dbhm;

    # Log types must match the enumeration in the logs table.
    const TYPE_GROUP = 'Group';
    const TYPE_USER = 'User';
    const TYPE_MESSAGE = 'Message';
    const TYPE_CONFIG = 'Config';
    const TYPE_STDMSG = 'StdMsg';
    const TYPE_BULKOP = 'BulkOp';
    const TYPE_LOCATION = 'Location';

    const SUBTYPE_CREATED = 'Created';
    const SUBTYPE_DELETED = 'Deleted';
    const SUBTYPE_EDIT = 'Edit';
    const SUBTYPE_APPROVED = 'Approved';
    const SUBTYPE_REJECTED = 'Rejected';
    const SUBTYPE_RECEIVED = 'Received';
    const SUBTYPE_NOTSPAM = 'NotSpam';
    const SUBTYPE_HOLD = 'Hold';
    const SUBTYPE_RELEASE = 'Release';
    const SUBTYPE_FAILURE = 'Failure';
    const SUBTYPE_JOINED = 'Joined';
    const SUBTYPE_APPLIED = 'Applied';
    const SUBTYPE_LEFT = 'Left';
    const SUBTYPE_REPLIED = 'Replied';
    const SUBTYPE_MAILED = 'Mailed';
    const SUBTYPE_LOGIN = 'Login';
    const SUBTYPE_LOGOUT = 'Logout';
    const SUBTYPE_CLASSIFIED_SPAM = 'ClassifiedSpam';
    const SUBTYPE_SUSPECT = 'Suspect';
    const SUBTYPE_SENT = 'Sent';
    const SUBTYPE_OUR_POSTING_STATUS = 'OurPostingStatus';
    const SUBTYPE_OUR_EMAIL_FREQUENCY = 'OurEmailFrequency';
    const SUBTYPE_ROLE_CHANGE = 'RoleChange';
    const SUBTYPE_MERGED = 'Merged';
    const SUBTYPE_SPLIT = 'Split';
    const SUBTYPE_MAILOFF = 'MailOff';
    const SUBTYPE_EVENTSOFF = 'EventsOff';
    const SUBTYPE_NEWSLETTERSOFF = 'NewslettersOff';
    const SUBTYPE_RELEVANTOFF = 'RelevantOff';
    const SUBTYPE_VOLUNTEERSOFF = 'VolunteersOff';
    const SUBTYPE_BOUNCE = 'Bounce';
    const SUBTYPE_SUSPEND_MAIL = 'SuspendMail';
    const SUBTYPE_AUTO_REPOSTED = 'Autoreposted';
    const SUBTYPE_OUTCOME = 'Outcome';
    const SUBTYPE_NOTIFICATIONOFF = 'NotificationOff';
    const SUBTYPE_AUTO_APPROVED = 'Autoapproved';
    const SUBTYPE_UNBOUNCE = 'Unbounce';
    const SUBTYPE_WORRYWORDS = 'WorryWords';
    const SUBTYPE_POSTCODECHANGE = 'PostcodeChange';
    
    const LOG_USER_CACHE_SIZE = 1000;

    function __construct($dbhr, $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function log($params) {
        # We assume that the parameters passed match fields in the logs table.
        # If they don't, the caller is at fault and should be taken out and shot.
        $q = [];
        foreach ($params as $key => $val) {
            $q[] = $val !== NULL ? $this->dbhm->quote($val) : 'NULL';
        }

        $atts = implode('`,`', array_keys($params));
        $vals = implode(',', $q);

        $sql = "INSERT INTO logs (`$atts`) VALUES ($vals);";

        # No need to check return code - if it doesn't work, nobody dies.
        $this->dbhm->background($sql);
    }

    public function get($types, $subtypes, $groupid, $userid, $date, $search, $limit, &$ctx, $uid = NULL) {
        $limit = intval($limit);

        $groupq = $groupid ? " groupid = $groupid " : '1 = 1 ';
        $userq = $userid ? " groupid = $groupid " : '1 = 1 ';
        $typeq = $types ? (" AND logs.type IN ('" . implode("','", $types) . "') ") : '';
        $subtypeq = $subtypes ? (" AND `subtype` IN ('" . implode("','", $subtypes) . "') ") : '';
        $mysqltime = date("Y-m-d", strtotime("midnight $date days ago"));
        $dateq = $date ? " AND timestamp >= '$mysqltime' " : '';

        $searchq = $this->dbhr->quote("%$search%");

        $idq = Utils::pres('id', $ctx) ? (" AND logs.id < " . intval($ctx['id']) . " ") : '';
        
        # We might have consecutive logs for the same messages/users, so try to speed that up.
        $msgs = [];
        $users = [];
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        if ($uid) {
            $sql = "SELECT logs.* FROM logs 
                LEFT JOIN users ON users.id = logs.user 
                LEFT JOIN messages ON messages.id = logs.msgid
                WHERE $groupq $idq $typeq $subtypeq $dateq AND 
                (logs.user = $uid OR logs.byuser = $uid)
                ORDER BY logs.id DESC LIMIT $limit";
        } else if (!$search) {
            # This is simple.
            $sql = "SELECT * FROM logs WHERE $groupq $idq $typeq $subtypeq $dateq ORDER BY id DESC LIMIT $limit";
        } else  {
            # This is complex.  We want to search in the various user names, and the message
            # subject.  And the email - and people might search using an email belonging to a member but which
            # isn't the fromaddr of any messages.  So first expand the email.
            $sql = "SELECT users_emails.userid FROM users_emails INNER JOIN memberships ON groupid = $groupid AND memberships.userid = users_emails.userid AND email LIKE $searchq;";
            $emails = $this->dbhr->preQuery($sql);
            $uids = [];

            foreach ($emails as $email) {
                $uids[] = $email['userid'];
            }

            $uidq = count($uids) > 0 ? (" OR logs.user IN (" . implode(',', $uids) . ") OR logs.byuser IN (" . implode(',', $uids) . ")") : '';

            $sql = "SELECT logs.* FROM logs 
                LEFT JOIN users ON users.id = logs.user 
                LEFT JOIN messages ON messages.id = logs.msgid
                WHERE $groupq $idq $typeq $subtypeq $dateq AND 
                ((users.firstname LIKE $searchq OR users.lastname LIKE $searchq OR users.fullname LIKE $searchq OR CONCAT(users.firstname, ' ', users.lastname) LIKE $searchq) OR
                 (messages.subject LIKE $searchq $uidq))
                ORDER BY logs.id DESC LIMIT $limit";
        }

        $logs = $this->dbhr->preQuery($sql);
        $total = count($logs);
        #error_log("...total logs $total");
        $count = 0;

        $uids = array_filter(array_unique(array_merge(array_column($logs, 'user'), array_column($logs, 'byuser'))));
        $u = new User($this->dbhr, $this->dbhm);
        $users = [];
        if (count($uids)) {
            $users = $u->getPublicsById($uids, NULL, NULL, FALSE, TRUE, TRUE);
        }

        $mids = array_filter(array_column($logs, 'msgid'));
        $msgs = [];

        if (count($mids)) {
            $ms = $this->dbhr->preQuery("SELECT id, subject, sourceheader, envelopeto FROM messages WHERE id IN (" . implode(',', $mids) .");");
            foreach ($ms as $m) {
                $msgs[$m['id']] = $m;
            }
        }

        foreach ($logs as &$log) {
            $count++;

            if ($count % 1000 == 0) {
                #error_log("...$count / $total");
            }

            $log['timestamp'] = Utils::ISODate($log['timestamp']);

            if (Utils::pres('user', $log)) {
                $id = $log['user'];
                $log['user'] = Utils::presdef($log['user'], $users, NULL);

                if (!$log['user']) {
                    $log['user'] = User::purgedUser($id);
                }
            }

            if (Utils::pres('byuser', $log)) {
                $id = $log['byuser'];
                $log['byuser'] = Utils::presdef($log['byuser'], $users, NULL);

                if (!$log['byuser']) {
                    $log['byuser'] = User::purgedUser($id);
                }
            }

            if (Utils::pres('msgid', $log)) {
                $log['message'] = Utils::presdef($log['msgid'], $msgs, NULL);
            }

            if ($log['subtype'] == Log::SUBTYPE_OUTCOME && $log['text']) {
                # Trim the long text leaving just the outcome.
                $p = strpos($log['text'], ' ');

                if ($p) {
                    $log['text'] = substr($log['text'], 0, $p);
                    $log['text'] = str_replace(':', '', $log['text']);
                }
            }

            $ctx['id'] = $log['id'];
        }

        return($logs);
    }

    public function deleteLogsForMessage($msgid) {
        # Need to background as the original log request might be backgrounded.
        $this->dbhm->background("DELETE FROM logs WHERE msgid = $msgid");
    }
}