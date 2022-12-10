<?php
namespace Freegle\Iznik;



class ModConfig extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'name', 'createdby', 'fromname', 'ccrejectto', 'ccrejectaddr', 'ccfollowupto',
        'ccfollowupaddr', 'ccrejmembto', 'ccrejmembaddr', 'ccfollmembto', 'ccfollmembaddr', 'protected',
        'messageorder', 'network', 'coloursubj', 'subjreg', 'subjlen', 'default', 'chatread');

    var $settableatts = array('name', 'fromname', 'ccrejectto', 'ccrejectaddr', 'ccfollowupto',
        'ccfollowupaddr', 'ccrejmembto', 'ccrejmembaddr', 'ccfollmembto', 'ccfollmembaddr', 'protected',
        'messageorder', 'network', 'coloursubj', 'subjreg', 'subjlen', 'chatread');

    /** @var  $log Log */
    private $log;

    const CANSEE_CREATED = 'Created';
    const CANSEE_DEFAULT = 'Default';
    const CANSEE_SHARED = 'Shared';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL, $stdmsgs = NULL, $bulkops = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'mod_configs', 'modconfig', $this->publicatts, $fetched);
        $this->bulkops = $bulkops;
        $this->stdmsgs = $stdmsgs;

        $this->log = new Log($dbhr, $dbhm);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function create($name, $createdby = NULL, $copyid = NULL) {
        try {
            if (!$createdby) {
                # Create as current user
                $me = Session::whoAmI($this->dbhr, $this->dbhm);
                $createdby = $me ? $me->getId() : NULL;
            }

            if (!$copyid) {
                # Simple create of an empty config.
                $rc = $this->dbhm->preExec("INSERT INTO mod_configs (name, createdby) VALUES (?, ?)", [$name, $createdby]);
                $id = $this->dbhm->lastInsertId();
            } else {
                # We need to copy an existing config.  No need for a transaction as worst case we leave a bad config,
                # which a mod is likely to spot and not use.
                #
                # First copy the basic settings.
                $cfrom = new ModConfig($this->dbhr, $this->dbhm, $copyid);
                $rc = $this->dbhm->preExec("INSERT INTO mod_configs (ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, network, coloursubj, subjlen) SELECT ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, network, coloursubj, subjlen FROM mod_configs WHERE id = ?;", [ $copyid ]);
                $toid = $this->dbhm->lastInsertId();
                $cto = new ModConfig($this->dbhr, $this->dbhm, $toid);

                # Now set up the new name and the fact that we created it.
                $cto->setPrivate('name', $name);
                $cto->setPrivate('createdby', $createdby);

                # Now copy the existing standard messages.  Doing it this way will preserve any custom order.
                $stdmsgs = $cfrom->getPublic()['stdmsgs'];
                $order = [];
                foreach ($stdmsgs as $stdmsg) {
                    $sfrom = new StdMessage($this->dbhr, $this->dbhm, $stdmsg['id']);
                    $atts = $sfrom->getPublic();
                    $sid = $sfrom->create($atts['title'], $toid);
                    $sto = new StdMessage($this->dbhr, $this->dbhm, $sid);
                    unset($atts['id']);
                    unset($atts['title']);
                    unset($atts['configid']);

                    foreach ($atts as $att => $val) {
                        $sto->setPrivate($att, $val);
                    }

                    $order[] = $sid;
                }

                $cto->setPrivate('messageorder', json_encode($order, true));

                # Now copy the existing bulk ops
                $bulkops = $cfrom->getPublic()['bulkops'];
                foreach ($bulkops as $bulkop) {
                    $bfrom = new BulkOp($this->dbhr, $this->dbhm, $bulkop['id']);
                    $atts = $bfrom->getPublic();
                    $bid = $bfrom->create($atts['title'], $toid);
                    $bto = new BulkOp($this->dbhr, $this->dbhm, $bid);
                    unset($atts['id']);
                    unset($atts['title']);
                    unset($atts['configid']);

                    foreach ($atts as $att => $val) {
                        $bto->setPrivate($att, $val);
                    }
                }

                $id = $toid;
            }
        } catch (\Exception $e) {
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'mod_configs', 'modconfig', $this->publicatts);
            $this->log->log([
                'type' => Log::TYPE_CONFIG,
                'subtype' => Log::SUBTYPE_CREATED,
                'byuser' => $createdby,
                'configid' => $id,
                'text' => $name
            ]);

            return($id);
        } else {
            return(NULL);
        }
    }

    public function getPublic($stdmsgbody = TRUE, $sharedby = FALSE) {
        $ret = parent::getPublic();
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        # If the creating mod has been deleted, then we need to ensure that the config is no longer protected.
        $ret['protected'] = is_null($ret['createdby']) ? 0 : $ret['protected'];

        $ret['stdmsgs'] = [];

        # Get the standard messages.
        if ($this->stdmsgs) {
            # We were passed in the standard messages on the construct.  Saves DB ops.
            $stdmsgs = [];
            foreach ($this->stdmsgs as $stdmsg) {
                if ($stdmsg['configid'] == $this->id) {
                    $stdmsgs[] = $stdmsg;
                }
            }
        } else {
            # It saves a lot of queries to get all the standard messages at once.
            $sql = "SELECT * FROM mod_stdmsgs WHERE configid = ?;";
            $stdmsgs = $this->dbhr->preQuery($sql, [
                $this->id
            ]);
        }

        foreach ($stdmsgs as $stdmsg) {
            $s = new StdMessage($this->dbhr, $this->dbhm, $stdmsg['id'], $stdmsg);
            $ret['stdmsgs'][] = $s->getPublic($stdmsgbody);
        }

        # Get the bulk ops.
        if ($this->bulkops) {
            # We were passed in the bulk ops on the construct.  Saves DB ops.
            $bulkops = [];
            foreach ($this->bulkops as $bulkop) {
                if ($bulkop['configid'] == $this->id) {
                    $bulkops[] = $bulkop;
                }
            }
        } else {
            # It saves a lot of queries to get all the bulk ops at once.
            $sql = "SELECT * FROM mod_bulkops WHERE configid = {$this->id};";
            $bulkops = $this->dbhr->query($sql);
        }

        foreach ($bulkops as $bulkop) {
            $s = new BulkOp($this->dbhr, $this->dbhm, $bulkop['id'], $bulkop);
            $ret['bulkops'][] = $s->getPublic();
        }

        if ($sharedby) {
            if ($ret['createdby'] == $me->getId()) {
                $ret['cansee'] = ModConfig::CANSEE_CREATED;
            } else if ($ret['default']) {
                $ret['cansee'] = ModConfig::CANSEE_DEFAULT;
            } else {
                # Need to find out who shared it.  Pluck data directly because this is performance-significant
                # for people on many groups.
                $modships = $me ? $me->getModeratorships() : [0];
                $sql = "SELECT userid, firstname, lastname, fullname, groupid, nameshort, namefull FROM memberships INNER JOIN users ON users.id = memberships.userid INNER JOIN `groups` ON groups.id = memberships.groupid WHERE groupid IN (" . implode(',', $modships) . ") AND userid != {$this->id} AND role IN ('Moderator', 'Owner') AND configid = {$this->id};";
                $shareds = $this->dbhr->preQuery($sql);

                foreach ($shareds as $shared) {
                    $ret['cansee'] = ModConfig::CANSEE_SHARED;
                    $ret['sharedon'] = [
                        'namedisplay' => $shared['namefull'] ? $shared['namefull'] : $shared['nameshort']
                    ];

                    $name = 'Unknown';
                    if ($shared['fullname']) {
                        $name = $shared['fullname'];
                    } else if ($shared['firstname'] || $shared['lastname']) {
                        $name = $shared['firstname'] . ' ' . $shared['lastname'];
                    }

                    $ret['sharedby'] = [
                        'displayname' => $name
                    ];

                    $ctx = NULL;
                }
            }
        }

        return($ret);
    }

    public function useOnGroup($modid, $groupid) {
        $sql = "UPDATE memberships SET configid = {$this->id} WHERE userid = ? AND groupid = ?;";
        $this->dbhm->preExec($sql, [
            $modid,
            $groupid
        ]);
    }

    public function getForGroup($modid, $groupid) {
        $sql = "SELECT configid FROM memberships WHERE userid = ? AND groupid = ?;";
        $confs = $this->dbhr->preQuery($sql, [
            $modid,
            $groupid
        ]);

        $configid = NULL;
        foreach ($confs as $conf) {
            $configid = $conf['configid'];
        }

        $save = FALSE;
        if (is_null($configid)) {
            # This user has no config.  If there is another mod with one, then we use that.  This handles the case
            # of a new floundering mod who doesn't quite understand what's going on.  Well, partially.
            $sql = "SELECT configid FROM memberships WHERE groupid = ? AND role IN ('Moderator', 'Owner') AND configid IS NOT NULL;";
            $others = $this->dbhr->preQuery($sql, [ $groupid ]);
            foreach ($others as $other) {
                $configid = $other['configid'];
                $save = TRUE;
            }
        }

        if (is_null($configid)) {
            # Still nothing.  Choose the first one created by us - at least that's something.
            $sql = "SELECT id FROM mod_configs WHERE createdby = ? LIMIT 1;";
            $mines = $this->dbhr->preQuery($sql, [ $modid ]);
            foreach ($mines as $mine) {
                $configid = $mine['id'];
                $save = TRUE;
            }
        }

        if (is_null($configid)) {
            # Still nothing.  Choose a default
            $sql = "SELECT id FROM mod_configs WHERE `default` = 1 LIMIT 1;";
            $defs = $this->dbhr->preQuery($sql);
            foreach ($defs as $def) {
                $configid = $def['id'];
                $save = TRUE;
            }
        }

        if ($save) {
            # Record that for next time.
            $sql = "UPDATE memberships SET configid = ? WHERE groupid = ? AND userid = ?;";
            $this->dbhm->preExec($sql, [ $configid, $groupid, $modid ]);
        }

        return $configid;
    }

    public function setAttributes($settings) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        foreach ($this->settableatts as $att) {
            if (array_key_exists($att, $settings) && $att == 'protected') {
                # If we go ahead and set the protected attribute, and the person who is down as creating it is
                # not us, then we won't be able to unset it.
                $this->setPrivate('createdby', $me->getId());
            }
        }

        parent::setAttributes($settings);

        $this->log->log([
            'type' => Log::TYPE_CONFIG,
            'subtype' => Log::SUBTYPE_EDIT,
            'configid' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'text' => $this->getEditLog($settings)
        ]);
    }

    public function inUse() {
        $uses = $this->dbhr->preQuery("SELECT * FROM memberships WHERE configid = ? AND role IN (?, ?);", [
            $this->id,
            User::ROLE_MODERATOR,
            User::ROLE_OWNER
        ]);

        return(count($uses) > 0);
    }

    public function canModify() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);
        $systemrole = $me ? $me->getPrivate('systemrole') : User::SYSTEMROLE_USER;

//        error_log("Canmod {$this->id} systemrole $systemrole");

        if ($systemrole == User::SYSTEMROLE_SUPPORT ||
            $systemrole == User::SYSTEMROLE_ADMIN) {
            # These can modify any config
            return (TRUE);
        }

//        error_log("Created {$this->modconfig['createdby']} vs $myid");
//        error_log("Protected {$this->modconfig['protected']}");
//        error_log("Cansee " . $this->canSee());

        # We can modify if:
        # - we can see the config
        # - we created it, or it's not owned any more (if the user has gone), or it's not protected.
        return($this->canSee() && ($myid == $this->modconfig['createdby'] || !$this->modconfig['createdby'] || !$this->modconfig['protected']));
    }

    public function canSee() {
        # Not quite see, exactly, as anyone can look at them.  But to be close enough to be able to run bulk ops.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);

        $systemrole = $me ? $me->getPrivate('systemrole') : User::SYSTEMROLE_USER;

        #error_log("Cansee {$this->id} systemrole $systemrole");

        if ($systemrole == User::SYSTEMROLE_SUPPORT ||
            $systemrole == User::SYSTEMROLE_ADMIN) {
            # These can see any config.
            return(TRUE);
        }

        if ($systemrole == User::SYSTEMROLE_MODERATOR) {
            # Mods can see configs which
            # - we created
            # - are used by mods on groups on which we are a mod
            # - defaults
            $modships = $me ? $me->getModeratorships() : [];
            $modships = count($modships) == 0 ? [0] : $modships;

            $sql = "SELECT DISTINCT * FROM ((SELECT configid AS id FROM memberships WHERE groupid IN (" . implode(',', $modships) . ") AND configid IS NOT NULL) UNION (SELECT id FROM mod_configs WHERE createdby = $myid OR `default` = 1)) t WHERE id = {$this->id};";
            $ids = $this->dbhr->preQuery($sql);

            foreach ($ids as $id) {
                return (TRUE);
            }
        }

        return(FALSE);
    }

    public function export() {
        return(json_encode($this->getPublic(TRUE)));
    }

    public function import($str) {
        $conf = json_decode($str, TRUE);
        $c = $this->create($conf['name'], $conf['createdby']);
        $s = new StdMessage($this->dbhr, $this->dbhm);
        $order = [];
        foreach ($conf['stdmsgs'] as $stdmsg) {
            $m = $s->create($stdmsg['title'], $c);
            $order[] = $m;
            foreach ($stdmsg as $key => $val) {
                if ($key != 'id' && $key != 'configid' && $key != 'messageorder') {
                    $s->setPrivate($key, $val);
                }
            }

            $this->setPrivate('messageorder', json_encode($order, true));
        }

        return($c);
    }

    private function evalIt($to, $addr) {
        $ret = NULL;
        $to = $this->getPrivate($to);
        $addr = $this->getPrivate($addr);

        if ($to == 'Me') {
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $ret = $me->getEmailPreferred();
        } else if ($to == 'Specific') {
            $ret = $addr;
        }

        return($ret);
    }

    public function getBcc($action)
    {
        # Work out whether we have a BCC address to use for this config
        switch ($action) {
            case 'Approve':
            case 'Reject':
            case 'Leave':
                $ret = $this->evalIt('ccrejectto', 'ccrejectaddr');
                break;
            case'Leave Member':
                $ret = $this->evalIt('ccrejmembto', 'ccrejmembaddr');
                break;
            case 'Leave Approved Message':
            case 'Delete Approved Message':
                $ret = $this->evalIt('ccfollowupto', 'ccfollowupaddr');
                break;
            case 'Leave Approved Member':
            case 'Delete Approved Member':
                $ret = $this->evalIt('ccfollmembto', 'ccfollmembaddr');
                break;
            default:
                $ret = NULL;
        }

        #error_log("Get BCC for {$this->modconfig['name']} $action = $ret");
        return($ret);
    }

    public function delete() {
        $name = $this->modconfig['name'];
        $rc = $this->dbhm->preExec("DELETE FROM mod_configs WHERE id = ?;", [$this->id]);
        if ($rc) {
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $this->log->log([
                'type' => Log::TYPE_CONFIG,
                'subtype' => Log::SUBTYPE_DELETED,
                'byuser' => $me ? $me->getId() : NULL,
                'configid' => $this->id,
                'text' => $name
            ]);
        }

        return($rc);
    }

    public function getUsing() {
        $usings = $this->dbhr->preQuery("SELECT DISTINCT userid, firstname, lastname, fullname FROM users INNER JOIN memberships m on users.id = m.userid WHERE m.configid = ? AND m.role IN (?, ?) LIMIT 10;", [
            $this->id,
            User::ROLE_OWNER,
            User::ROLE_MODERATOR
        ]);

        return($usings);
    }
}