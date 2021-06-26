<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/config/ModConfig.php');
require_once(IZNIK_BASE . '/include/config/StdMessage.php');
require_once(IZNIK_BASE . '/include/config/BulkOp.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$dsn = "mysql:host={$dbconfig['host']};dbname=modtools;charset=utf8";

# Zap any existing configs.  The old DB is the master until we migrate.
$dbhm->preExec("DELETE FROM mod_configs;");

$dbhold = new \PDO($dsn, $dbconfig['user'], $dbconfig['pass']);

$c = new ModConfig($dbhr, $dbhm);
$u = User::get($dbhr, $dbhm);
$g = Group::get($dbhr, $dbhm);

$oldconfs = $dbhold->query("SELECT * FROM configs;");
foreach ($oldconfs as $config) {
    # See if we can find the user who created it.
    $sql = "SELECT * FROM moderators WHERE uniqueid = {$config['createdby']};";
    $mods = $dbhold->query($sql);
    foreach ($mods as $mod) {
        $modid = $u->findByEmail($mod['email']);
        error_log("Found modid $modid for {$mod['email']}");

        if (!$modid) {
            error_log("New mod, create user for them");
            try {
                $modid = $u->create(NULL, NULL, $mod['name'], "Migrated from ModTools Configs");
                $u2 = User::get($dbhr, $dbhm, $modid);
                $u2->addEmail($mod['email'], 1);
            } catch (\Exception $e) {
                error_log("Mod create failed " . $e->getMessage());
                $modid = NULL;
            }
        }

        $cid = $c->create(
            $config['name'],
            $modid
        );

        $c = new ModConfig($dbhr, $dbhm, $cid);
        error_log("...{$config['name']}");

        $atts = array('fromname', 'ccrejectto', 'ccrejectaddr', 'ccfollowupto',
            'ccfollowupaddr', 'ccrejmembto', 'ccrejmembaddr', 'ccfollmembto', 'ccfollmembaddr', 'protected',
            'network', 'coloursubj', 'subjreg', 'subjlen');
        foreach ($atts as $att) {
            $c->setPrivate($att, $config[$att]);
        }

        # Migrate messages.
        $dbhm->exec("DELETE FROM mod_stdmsgs WHERE configid = $cid;");

        $sql = "SELECT stdmsg.* FROM stdmsgmap INNER JOIN stdmsg ON stdmsgmap.stdmsgid = stdmsg.uniqueid WHERE stdmsgmap.configid = {$config['uniqueid']};";
        $stdmsgs = $dbhold->query($sql);
        $msgidmap = [];

        foreach ($stdmsgs as $stdmsg) {
            $s = new StdMessage($dbhr, $dbhm);
            $sid = $s->create($stdmsg['title'], $cid);
            $msgidmap[$stdmsg['uniqueid']] = $sid;
            $s = new StdMessage($dbhr, $dbhm, $sid);
            $atts = array('action', 'subjpref', 'subjsuff', 'body',
                'rarelyused', 'autosend', 'newmodstatus', 'newdelstatus', 'edittext');

            foreach ($atts as $att) {
                $s->setPrivate($att, $stdmsg[$att]);
            }
        }

        # Migrate the bulk ops
        $bulkops = $dbhold->query("SELECT bulkops.* FROM bulkops WHERE bulkops.configid = {$config['uniqueid']};");
        foreach ($bulkops as $bulkop) {
            $b = new BulkOp($dbhr, $dbhm);
            $bid = $b->create($bulkop['title'], $cid);
            $b = new BulkOp($dbhr, $dbhm, $bid);
            $atts = array('set', 'criterion', 'runevery', 'action', 'bouncingfor');

            foreach ($atts as $att) {
                $b->setPrivate($att, $bulkop[$att]);
            }
        }

        # Map the order
        $neworder = [];
        if ($config['messageorder']) {
            $order = json_decode($config['messageorder']);
            foreach ($order as $id) {
                if (Utils::pres($id, $msgidmap)) {
                    $neworder[] = $msgidmap[$id];
                    unset($msgidmap[$id]);
                }
            }

            foreach ($msgidmap as $key => $val) {
                $neworder[] = $val;
            }
        }

        $c->setPrivate('messageorder', json_encode($neworder));
    }

    # Migrate which configs are used to moderate.
    $sql = "SELECT groupid, email, name FROM groupsmoderated INNER JOIN moderators ON moderators.uniqueid = groupsmoderated.moderatorid WHERE configid = {$config['uniqueid']};";
    $mods = $dbhold->query($sql);

    foreach ($mods as $mod) {
        $sql = "SELECT * FROM `groups` WHERE groupid = {$mod['groupid']};";
        $groups = $dbhold->query($sql);

        foreach ($groups as $group) {
            try {
                $gid = $g->findByShortName($group['groupname']);
                error_log("Found group id $gid for {$group['groupname']}");
                if ($gid) {
                    $modid = $u->findByEmail($mod['email']);

                    if (!$modid) {
                        error_log("Don't know {$mod['email']}");
                        $u2 = User::get($dbhr, $dbhm);
                        $modid = $u2->create(NULL, NULL, $mod['name'], "Migrated from ModTools Configs");

                        # Create a membership for this mod
                        $emailid = $u2->addEmail($mod['email'], 1);
                        $u2->addMembership($gid, User::ROLE_MODERATOR, $emailid);
                    } else {
                        error_log("Already know {$mod['email']} as $modid");
                        $u2 = User::get($dbhr, $dbhm, $modid);
                        if (!$u2->isModOrOwner($gid)) {
                            error_log("But not mod");
                            $u2->addMembership($gid, User::ROLE_MODERATOR, $u2->getIdForEmail($mod['email'])['id']);
                        } else {
                            error_log("Already mod or owner");
                        }
                    }

                    $c->useOnGroup($modid, $gid);
                }
            } catch (\Exception $e) {
                error_log("Skip groupsmoderated " . $e->getMessage());
            }
        }
    }
}

