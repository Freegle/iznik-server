<?php

// Keep in sync with http/discourse_sso.php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

try {
    $sessions = $dbhr->preQuery("SELECT sessions.* FROM sessions INNER JOIN users ON sessions.userid = users.id WHERE users.systemrole IN ('Admin', 'Support', 'Moderator');", NULL, FALSE, FALSE);
    $total = count($sessions);
    $count = 0;
    $tosave = [];

    foreach ($sessions as $session) {
        $u = new User($dbhr, $dbhm, $session['userid']);
        
        if ($u->isModerator()) {
            $atts = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, MessageCollection::APPROVED, FALSE);

            $memberships = $u->getModGroupsByActivity();

            if (count($memberships) === 0) {
              # Not a Freegle mod.
              continue;
            }

            $grouplist = [];

            foreach ($memberships as $membership) {
                $grouplist[] = $membership['namedisplay'];
            }

            if (count($memberships)) {
                # Save the info we need for login.
                $session['name'] = $u->getName();
                $session['avatar_url'] = $atts['profile']['url'];
                $session['admin'] = $u->isAdmin();
                $session['email'] = $u->getEmailPreferred();
                $session['grouplist'] = substr(implode(',', $grouplist),0,1000);  // Actual max is 3000 but 1000 is enough

                $tosave[] = $session;
            }
        }

        $count++;

        if ($count % 100 === 0) {
            error_log("...$count / $total");
        }
    }

    if (file_exists('/var/www/iznik/iznik_sessions.tmp')) {
        unlink('/var/www/iznik/iznik_sessions.tmp');
    }

    file_put_contents('/var/www/iznik/iznik_sessions.tmp', json_encode($tosave));

    # Now swap them.  rename is atomic, so if we crash partway through we will either have the .old file or the real
    # one; in either case we can serve login.
    if (file_exists('/var/www/iznik/iznik_sessions')) {
        rename('/var/www/iznik/iznik_sessions', '/var/www/iznik/iznik_sessions.old');
    }

    rename('/var/www/iznik/iznik_sessions.tmp', '/var/www/iznik/iznik_sessions');

    if (file_exists('/var/www/iznik/iznik_sessions.old')) {
        unlink('/var/www/iznik/iznik_sessions.old');
    }
} catch (\Exception $e) {
    error_log("Get sessions failed with " . $e->getMessage());
}

Utils::unlockScript($lockh);
