<?php
namespace Freegle\Iznik;



class BulkOp extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'configid', 'title', 'set', 'criterion', 'runevery', 'action', 'bouncingfor');

    var $settableatts = array('configid', 'title', 'set', 'criterion', 'runevery', 'action', 'bouncingfor');

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'mod_bulkops', 'bulkop', $this->publicatts, $fetched);
        $this->log = new Log($dbhr, $dbhm);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function create($title, $cid) {
        try {
            $rc = $this->dbhm->preExec("INSERT INTO mod_bulkops (title, configid) VALUES (?,?)", [$title,$cid]);
            $id = $this->dbhm->lastInsertId();
        } catch (\Exception $e) {
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'mod_bulkops', 'bulkop', $this->publicatts);
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $createdby = $me ? $me->getId() : NULL;
            $this->log->log([
                'type' => Log::TYPE_CONFIG,
                'subtype' => Log::SUBTYPE_CREATED,
                'byuser' => $createdby,
                'configid' => $cid,
                'bulkopid' => $id,
                'text' => $title
            ]);

            return($id);
        } else {
            return(NULL);
        }
    }

    public function setAttributes($settings) {
        parent::setAttributes($settings);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $this->log->log([
            'type' => Log::TYPE_STDMSG,
            'subtype' => Log::SUBTYPE_EDIT,
            'configid' => $this->bulkop['configid'],
            'bulkopid' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'text' => $this->getEditLog($settings)
        ]);
    }

    public function setRunAtt($groupid, $att, $val) {
        $rc = $this->dbhm->preExec("UPDATE mod_bulkops_run SET `$att` = ? WHERE bulkopid = ? AND groupid = ?;", [
            $val,
            $this->id,
            $groupid
        ]);

        return($rc);
    }

    public function canModify() {
        $c = new ModConfig($this->dbhr, $this->dbhm, $this->bulkop['configid']);
        return($c->canModify());
    }

    public function canSee() {
        $c = new ModConfig($this->dbhr, $this->dbhm, $this->bulkop['configid']);
        return($c->canSee());
    }

    public function checkDue($id = NULL) {
        # See which (if any) bulk ops are due to start.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        $idq = $id ? " WHERE mod_bulkops.id = $id " : "";

        # Look for groups we moderate, find configs used on those groups, find bulk ops in those configs.
        $sql = "SELECT mod_bulkops.*, memberships.groupid, mod_bulkops_run.runstarted, mod_bulkops_run.runfinished FROM mod_bulkops INNER JOIN mod_configs ON mod_bulkops.configid = mod_configs.id INNER JOIN memberships ON memberships.configid = mod_configs.id AND memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') LEFT JOIN mod_bulkops_run ON mod_bulkops.id = mod_bulkops_run.bulkopid AND memberships.groupid = mod_bulkops_run.groupid INNER JOIN groups ON groups.id = memberships.groupid $idq;";
        $bulkops = $this->dbhr->preQuery($sql, [
            $myid
        ]);

        $due = [];

        foreach ($bulkops as $bulkop) {
            if (!$bulkop['runstarted']) {
                # Make sure there's an entry for this group.
                $sql = "INSERT IGNORE INTO mod_bulkops_run (bulkopid, groupid) VALUES (?,?);";
                $this->dbhm->preExec($sql, [ $bulkop['id'], $bulkop['groupid']]);
            }

            $hoursago = floor((time() - strtotime($bulkop['runstarted'])) / 3600);
            #error_log("Bulk op {$bulkop['id']} started $hoursago hours ago from {$bulkop['runstarted']}, " . max(1, $bulkop['runevery']));

            if (!$bulkop['runstarted'] || ($bulkop['runevery'] > 0 && $hoursago >= $bulkop['runevery'])) {
                # This one is due.
                $due[] = $bulkop;
            }
        }

        return($due);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM mod_bulkops WHERE id = ?;", [$this->id]);
        if ($rc) {
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $this->log->log([
                'type' => Log::TYPE_STDMSG,
                'subtype' => Log::SUBTYPE_DELETED,
                'byuser' => $me ? $me->getId() : NULL,
                'configid' => $this->bulkop['configid'],
                'bulkopid' => $this->id
            ]);
        }

        return($rc);
    }
}