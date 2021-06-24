<?php
namespace Freegle\Iznik;

class GroupCollection
{
    /**
     * @var LoggedPDO
     */
    protected $dbhr;

    /**
     * @var LoggedPDO
     */
    protected $dbhm;

    /**
     * @var integer[]
     */
    protected $ids;

    /**
     * @var Group[]
     */
    protected $groups = [];

    /**
     * GroupCollection constructor.
     * @param LoggedPDO $dbhr
     * @param LoggedPDO $dbhm
     * @param $ids
     */
    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $ids)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->ids = $ids;

        # We want to get them all in a single DB query for performance.
        $groups = $this->dbhr->preQuery("SELECT * FROM groups WHERE id IN (" . implode(',', $ids) . ");", []);

        foreach ($groups as $group) {
            # Create the group object, passing in the attributes so it won't do a DB op.
            $g = new Group($this->dbhr, $this->dbhm, $group['id'], $group);
            $fromdb[$group['id']] = $g;
        }

        # This preserves the order of the the ids passed to us, which is important.
        foreach ($ids as $id) {
            $this->groups[] = Utils::pres($id, $fromdb);
        }
    }

    /**
     * @return Group[]
     */
    public function get() {
        return($this->groups);
    }
}
