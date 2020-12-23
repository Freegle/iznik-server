<?php
namespace Freegle\Iznik;


class Tryst extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = [ 'id', 'user1', 'user2', 'arrangedat', 'arrangedfor' ];
    var $settableatts = [ 'user1', 'user2', 'arrangedfor' ];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'trysts', 'tryst', $this->publicatts);
    }

    public function create($user1, $user2, $arrangedfor) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?,?,?);", [
            $user1,
            $user2,
            $arrangedfor
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'trysts', 'tryst', $this->publicatts);
            }
        }

        return($id);
    }

    public function getPublic($getaddress = TRUE)
    {
        $ret = parent::getPublic();
        $ret['arrangedat'] = Utils::ISODate($ret['arrangedat']);
        $ret['arrangedfor'] = Utils::ISODate($ret['arrangedfor']);
        return($ret);
    }

    public function listForUser($userid, $future = TRUE) {
        $ret = [];

        $mysqltime = $future ? date("Y-m-d H:i:s", time()) : '1970-01-01';

        $trysts = $this->dbhr->preQuery("SELECT id FROM trysts WHERE (user1 = ? OR user2 = ?) AND arrangedfor >= ?;", [
            $userid,
            $userid,
            $mysqltime
        ]);

        foreach ($trysts as $tryst) {
            $r = new Tryst($this->dbhr, $this->dbhm, $tryst['id']);
            $ret[] = $r->getPublic(FALSE);
        }

        return($ret);
    }

    public function canSee($userid) {
        return $this->id && ($this->tryst['user1'] == $userid || $this->tryst['user2'] == $userid);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM trysts WHERE id = ?;", [ $this->id ]);
        return($rc);
    }
}