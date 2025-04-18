<?php
namespace Freegle\Iznik;

class UserSearch extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'userid', 'date', 'term', 'maxmsg', 'deleted');
    var $settableatts = array('deleted', 'date');

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'users_searches', 'search', $this->publicatts);
    }

    public function create($userid, $maxmsg, $term, $locationid = NULL) {
        $id = NULL;
        
        $rc = $this->dbhm->preExec("INSERT INTO users_searches (userid, maxmsg, term, locationid) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE maxmsg = GREATEST(maxmsg, ?), deleted = 0, locationid = ?;", [
            $userid,
            $maxmsg,
            $term,
            $locationid,
            $maxmsg,
            $locationid
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'users_searches', 'search', $this->publicatts);
            }
        }

        return($id);
    }
    
    public function listSearches($userid) {
        # Show the last few.  Slightly hacky search to make sure we show the most recent searches.
        $searches = $this->dbhr->preQuery("SELECT * FROM
          (SELECT *
            FROM users_searches 
            WHERE 
            userid = ? AND deleted = 0
             ORDER BY id desc LIMIT 100) t GROUP BY t.term ORDER BY t.id DESC limit 10;", [ $userid ]);
        $ret = [];
        foreach ($searches as $search) {
            $s = new UserSearch($this->dbhr, $this->dbhm, $search['id']);
            $ret[] = $s->getPublic();
        }
        return($ret);
    }

    public function markDeleted() {
        $rc = $this->dbhm->preExec("UPDATE users_searches SET deleted = 1 WHERE userid = ? AND term LIKE ?;", [ $this->search['userid'], $this->search['term'] ]);
        return($rc);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM users_searches WHERE id = ?;", [ $this->id ]);
        return($rc);
    }
}