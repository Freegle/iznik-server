<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');

try {
    $sessions = $dbhr->preQuery("SELECT sessions.* FROM sessions INNER JOIN users ON sessions.userid = users.id WHERE users.systemrole IN ('Admin', 'Support', 'Moderator');", NULL, FALSE, FALSE);
    $total = count($sessions);
    $count = 0;
    $tosave = [];

    foreach ($sessions as $session) {
        $u = new User($dbhr, $dbhm, $session['userid']);
        
        if ($u->isModerator()) {
            $ctx = NULL;
            $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE, MessageCollection::APPROVED, FALSE);

            $memberships = $u->getMemberships(TRUE, Group::GROUP_FREEGLE);
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
                $session['name'] = $u->getName(); // str_replace(' ', '', $u->getName());
                $session['avatar_url'] = $atts['profile']['url'];
                $session['admin'] = $u->isAdmin();
                $session['email'] = $u->getEmailPreferred();
                $session['grouplist'] = implode(',', $grouplist);

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
} catch (Exception $e) {
    error_log("Get sessions failed with " . $e->getMessage());
}
