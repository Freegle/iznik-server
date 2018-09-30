<?php

require_once(IZNIK_BASE . '/include/group/Group.php');

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
     * @var Redis
     */
    protected $redis;

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

        # We cache groups in Redis, so we might have some, all or none of them.
        $cachekeys = array_map(function ($id) {
            return ("group-$id");
        }, $ids);

        $cached = $this->getRedis()->mget($cachekeys);
        $cachedids = [];
        $cachedgroups = [];

        if ($cached) {
            foreach ($cached as &$group) {
                if ($group) {
                    # Convert to group object.
                    $group = unserialize($group);
                    $group->setDefaults();

                    # We didn't serialise the PDO objects.
                    $group->dbhr = $dbhr;
                    $group->dbhm = $dbhm;

                    $id1 = $group->getId();
                    $cachedids[] = $id1;
                    $cachedgroups[$id1] = $group;
                }
            }
        }

        # See which ones we have still to get.
        $missing = array_diff($ids, $cachedids);

        $fromdb = [];

        if ($missing) {
            # We have some to fetch.  We want to get them all in a single DB query for performance.
            $groups = $this->dbhr->preQuery("SELECT * FROM groups WHERE id IN (" . implode(',', $missing) . ");", [], FALSE, FALSE);

            foreach ($groups as $group) {
                # Create the group object, passing in the attributes so it won't do a DB op.
                $g = new Group($this->dbhr, $this->dbhm, $group['id'], $group);
                $fromdb[$group['id']] = $g;

                # Explicitly don't save these into redis for next time.  There is no way to set multiple items
                # with a TTL, so we would have to do a call per group, which is the kind of scalability fail we're
                # trying to avoid.
            }
        }

        # Now combine the redis and DB results.  This preserves the order of the the ids passed to us, which
        # is important.
        foreach ($ids as $id) {
            $this->groups[] = pres($id, $cachedgroups) ? $cachedgroups[$id] : pres($id, $fromdb);
        }

        # Now we have the combined groups.
    }

    /**
     * @return Group[]
     */
    public function get() {
        return($this->groups);
    }

    /**
     * @return Redis
     */
    public function getRedis()
    {
        if (!$this->redis) {
            $this->redis = new Redis();
            @$this->redis->pconnect(REDIS_CONNECT);
        }

        return ($this->redis);
    }
}
