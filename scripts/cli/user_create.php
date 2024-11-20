<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:n:p:g:c:');

function createUser($dbhr, $dbhm, $email, $password, $name, $group, $emailFrequency) {
    $u = User::get($dbhr, $dbhm);

    if (!$password) {
        $password = $u->inventPassword();
        error_log("Use password $password");
        sleep(5);
    }

    $u = User::get($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if ($uid) {
        error_log("User already exists for $email");
    } else {
        $uid = $u->create(NULL, NULL, $name);
        $u->addEmail($email);
        $u->addLogin(User::LOGIN_NATIVE, NULL, $password);
        $u->welcome($email, $password);
        error_log("Created ". $uid);

        if ($group) {
            $g = new Group($dbhr, $dbhm);
            $gid = $g->findByShortName($group);

            if ($gid) {
                $u->addMembership($gid);
                $u->setMembershipAtt($gid, 'emailfrequency', $emailFrequency);
                error_log("Added $email to $group");
            } else {
                error_log("Group $group not found");
            }
        }
    }
}

if (count($opts) < 2) {
    echo "Usage: php user_create.php -c <CSV file> -e <email> -n <full name> (-p <password>) (-g <shortname group to add>) (-f email frequency)\n";
} else {
    $csv = Utils::presdef('c', $opts, NULL);
    $group = Utils::presdef('g', $opts, NULL);
    $emailFrequency = Utils::presdef('f', $opts, '24');

    if ($csv) {
        $fh = fopen($csv, 'r');

        while ($row = fgetcsv($fh)) {
            $forename = $row[0];
            $surname = $row[1];
            $email = $row[2];
            $name = "$forename $surname";

            if ($email) {
                createUser($dbhr, $dbhm, $email, NULL, $name, $group, $emailFrequency);
            }
        }
    } else {
        $email = Utils::presdef('e', $opts, NULL);
        $name = Utils::presdef('n', $opts, NULL);
        $password = Utils::presdef('p', $opts, NULL);

        createUser($dbhr, $dbhm, $email, $password, $name, $group, $emailFrequency);
    }
}
