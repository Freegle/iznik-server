<?php

namespace Freegle\Iznik;

# Set up test environment with all required data for Go tests
require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

error_log("Setting up test environment");

# Helper function to create a user with all required relationships
function createTestUser($dbhr, $dbhm, $firstname, $lastname, $email, $systemrole, $gid, $pcid, $volid, $eventid, $role = User::ROLE_MEMBER) {
    $u = new User($dbhr, $dbhm);
    $uid = $u->create($firstname, $lastname, "$firstname $lastname");
    error_log("Created $systemrole user '$firstname $lastname' (ID: $uid)");

    # Set system role
    if ($systemrole != 'User') {
        $u->setPrivate('systemrole', $systemrole);
        error_log("Set systemrole to $systemrole for user $uid");
    }

    # Add email and login
    $u->addEmail($email);
    $ouremail = $u->inventEmail();
    $u->addEmail($ouremail, 0, FALSE);
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');

    # Add membership
    $u->addMembership($gid, $role);
    $u->setMembershipAtt($gid, 'ourPostingStatus', $role == User::ROLE_MODERATOR ? Group::POSTING_DEFAULT : Group::POSTING_UNMODERATED);

    # Create isochrone
    $dbhm->preExec("INSERT IGNORE INTO `isochrones` (`id`, `locationid`, `transport`, `minutes`, `source`, `timestamp`, `polygon`) VALUES (NULL, $pcid, 'Drive', '20', 'Mapbox', CURRENT_TIMESTAMP, ST_GeomFromText('POLYGON((-3.393639 55.979571,-3.334983 55.971921,-3.359333 55.967691,-3.315029 55.955875,-3.336093 55.937571,-3.3004 55.936504,-3.307505 55.917571,-3.287333 55.901114,-3.207333 55.901722,-3.197333 55.92384,-3.149311 55.903549,-3.127308 55.913571,-3.131561 55.939799,-3.111333 55.926988,-3.081996 55.947571,-3.187333 55.989277,-3.317333 55.969117,-3.393639 55.979571))',3857));");
    $isochroneid = $dbhm->lastInsertId();
    if (!$isochroneid) {
        # Get existing isochrone
        $rows = $dbhm->preQuery("SELECT id FROM isochrones WHERE locationid = $pcid AND transport = 'Drive' AND minutes = 20 LIMIT 1");
        if ($rows && count($rows) > 0) {
            $isochroneid = $rows[0]['id'];
        }
    }
    if ($isochroneid) {
        $dbhm->preExec("INSERT IGNORE INTO `isochrones_users` (`id`, `userid`, `isochroneid`, `nickname`) VALUES (NULL, $uid, $isochroneid, NULL)");
        error_log("Linked isochrone $isochroneid to user $uid");
    }

    # Create address
    $test_udprn = 50464670 + $uid % 10000;
    $dbhm->preExec("INSERT IGNORE INTO paf_addresses (postcodeid, udprn) VALUES ($pcid, $test_udprn)");
    $pafid = $dbhm->lastInsertId();
    if (!$pafid) {
        $rows = $dbhm->preQuery("SELECT id FROM paf_addresses WHERE postcodeid = $pcid AND udprn = $test_udprn LIMIT 1");
        if ($rows && count($rows) > 0) {
            $pafid = $rows[0]['id'];
        }
    }
    if ($pafid) {
        $dbhm->preExec("INSERT IGNORE INTO users_addresses (userid, pafid) VALUES ($uid, $pafid)");
        error_log("Created user address for user $uid (PAF ID: $pafid)");
    }

    # Link to volunteering and community events
    if ($volid) {
        $dbhm->preExec("INSERT IGNORE INTO volunteering_groups (volunteeringid, groupid) VALUES ($volid, $gid)");
    }
    if ($eventid) {
        $dbhm->preExec("INSERT IGNORE INTO communityevents_groups (eventid, groupid) VALUES ($eventid, $gid)");
    }

    return $uid;
}

$g = new Group($dbhr, $dbhm);
$gid = $g->findByShortName('FreeglePlayground');

if (!$gid) {
    # Create group
    $gid = $g->create('FreeglePlayground', Group::GROUP_FREEGLE);
    error_log("Created group FreeglePlayground (ID: $gid)");

    $g->setPrivate('onhere', 1);
    $g->setPrivate('polyofficial', 'POLYGON((-3.1902622 55.9910847, -3.2472542 55.98263430000001, -3.2863922 55.9761038, -3.3159182 55.9522754, -3.3234712 55.9265089, -3.304932200000001 55.911888, -3.3742832 55.8880206, -3.361237200000001 55.8718436, -3.3282782 55.8729997, -3.2520602 55.8964911, -3.2177282 55.895336, -3.2060552 55.8903307, -3.1538702 55.88648049999999, -3.1305242 55.893411, -3.0989382 55.8972611, -3.0680392 55.9091938, -3.0584262 55.9215076, -3.0982522 55.928048, -3.1037452 55.9418938, -3.1236572 55.9649602, -3.168289199999999 55.9849393, -3.1902622 55.9910847))');
    $g->setPrivate('lat', 55.9533);
    $g->setPrivate('lng', -3.1883);

    # Create second group
    $gid2 = $g->create('FreeglePlayground2', Group::GROUP_FREEGLE);
    error_log("Created group FreeglePlayground2 (ID: $gid2)");
    $g->setPrivate('onhere', 1);
    $g->setPrivate('contactmail', 'contact@test.com');
    $g->setPrivate('namefull', 'Freegle Playground2');

    # Create location
    $l = new Location($dbhr, $dbhm);
    $l->copyLocationsToPostgresql();
    $areaid = $l->create(NULL, 'Central', 'Polygon', 'POLYGON((-3.217620849609375 55.9565040997114,-3.151702880859375 55.9565040997114,-3.151702880859375 55.93304863776238,-3.217620849609375 55.93304863776238,-3.217620849609375 55.9565040997114))');
    error_log("Created location 'Central' (ID: $areaid)");
    $pcid = $l->create(NULL, 'EH3 6SS', 'Postcode', 'POINT(-3.205333 55.957571)');
    error_log("Created postcode 'EH3 6SS' (ID: $pcid)");
    $l->copyLocationsToPostgresql(FALSE);

    # Add volunteering opportunity
    $dbhm->preExec("INSERT IGNORE INTO volunteering (title, location, contactname, contactemail, contacturl, description, added) VALUES ('Test Volunteering', 'Edinburgh', 'Test Contact', 'volunteer@test.com', 'http://test.com', 'Test volunteering opportunity', NOW())");
    $volid = $dbhm->lastInsertId();
    if (!$volid) {
        $rows = $dbhm->preQuery("SELECT id FROM volunteering WHERE title = 'Test Volunteering' AND contactemail = 'volunteer@test.com' LIMIT 1");
        if ($rows && count($rows) > 0) {
            $volid = $rows[0]['id'];
        }
    }

    if ($volid) {
        $dbhm->preExec("INSERT IGNORE INTO volunteering_groups (volunteeringid, groupid) VALUES ($volid, $gid)");
        error_log("Linked volunteering 'Test Volunteering' to group $gid (ID: $volid)");

        # Add date for volunteering
        $start = date('Y-m-d', strtotime('+1 week'));
        $end = date('Y-m-d', strtotime('+1 week'));
        $dbhm->preExec("INSERT IGNORE INTO volunteering_dates (volunteeringid, start, end) VALUES ($volid, '$start', '$end')");
        error_log("Added date for volunteering $volid (start: $start, end: $end)");
    }

    # Add community event
    $dbhm->preExec("INSERT IGNORE INTO communityevents (title, location, contactname, contactemail, contacturl, description, added) VALUES ('Test Event', 'Edinburgh', 'Test Contact', 'event@test.com', 'http://test.com', 'Test community event', NOW())");
    $eventid = $dbhm->lastInsertId();
    if (!$eventid) {
        $rows = $dbhm->preQuery("SELECT id FROM communityevents WHERE title = 'Test Event' AND contactemail = 'event@test.com' LIMIT 1");
        if ($rows && count($rows) > 0) {
            $eventid = $rows[0]['id'];
        }
    }

    if ($eventid) {
        $dbhm->preExec("INSERT IGNORE INTO communityevents_groups (eventid, groupid) VALUES ($eventid, $gid)");
        error_log("Linked community event 'Test Event' to group $gid (ID: $eventid)");

        # Add date for community event
        $start = date('Y-m-d', strtotime('+1 week'));
        $end = date('Y-m-d', strtotime('+1 week'));
        $dbhm->preExec("INSERT IGNORE INTO communityevents_dates (eventid, start, end) VALUES ($eventid, '$start', '$end')");
        error_log("Added date for community event $eventid (start: $start, end: $end)");
    }

    # Create test users with all required relationships
    $uid = createTestUser($dbhr, $dbhm, 'Test', 'User', 'test@test.com', 'User', $gid, $pcid, $volid, $eventid);
    $uid2 = createTestUser($dbhr, $dbhm, 'Test', 'Moderator', 'testmod@test.com', 'User', $gid, $pcid, $volid, $eventid, User::ROLE_MODERATOR);
    $uid3 = createTestUser($dbhr, $dbhm, 'Test', 'User3', 'test3@test.com', 'User', $gid, $pcid, $volid, $eventid);
    $adminUid = createTestUser($dbhr, $dbhm, 'Admin', 'User', 'admin@test.com', 'Admin', $gid, $pcid, $volid, $eventid);
    $supportUid = createTestUser($dbhr, $dbhm, 'Support', 'User', 'support@test.com', 'Support', $gid, $pcid, $volid, $eventid);

    # Create chat rooms
    $r = new ChatRoom($dbhr, $dbhm);
    $cm = new ChatMessage($dbhr, $dbhm);

    # User to User chats (with messages)
    list ($rid, $banned) = $r->createConversation($uid, $uid2);
    error_log("Created User2User chat room between $uid and $uid2 (ID: $rid)");
    $cm->create($rid, $uid, "The plane in Spayne falls mainly on the reign.");

    list ($rid4, $banned) = $r->createConversation($uid3, $uid);
    error_log("Created User2User chat room between $uid3 and $uid (ID: $rid4)");
    $cm->create($rid4, $uid3, "Hello from user3");

    # User2Mod chats - Regular users need to be user1 for GetUserWithToken test
    $rid2 = $r->createUser2Mod($uid, $gid);
    error_log("Created User2Mod chat room for user $uid and group $gid (ID: $rid2)");
    $cm->create($rid2, $uid, "Message from user to group");

    $rid3 = $r->createUser2Mod($uid3, $gid);
    error_log("Created User2Mod chat room for user3 $uid3 and group $gid (ID: $rid3)");
    $cm->create($rid3, $uid3, "Message from user3 to group");

    # Moderator User2Mod chat - moderator must be user1 for GetChatFromModToGroup test
    $rid5 = $r->createUser2Mod($uid2, $gid);
    error_log("Created User2Mod chat room for moderator $uid2 and group $gid (ID: $rid5)");
    $cm->create($rid5, $uid2, "Message from moderator to group");

    # Admin and Support users also need User2User and User2Mod chats
    list ($rid6, $banned) = $r->createConversation($adminUid, $uid);
    error_log("Created User2User chat room between admin $adminUid and $uid (ID: $rid6)");
    $cm->create($rid6, $adminUid, "Message from admin");

    $rid7 = $r->createUser2Mod($adminUid, $gid);
    error_log("Created User2Mod chat room for admin $adminUid and group $gid (ID: $rid7)");
    $cm->create($rid7, $adminUid, "Message from admin to group");

    list ($rid8, $banned) = $r->createConversation($supportUid, $uid);
    error_log("Created User2User chat room between support $supportUid and $uid (ID: $rid8)");
    $cm->create($rid8, $supportUid, "Message from support");

    $rid9 = $r->createUser2Mod($supportUid, $gid);
    error_log("Created User2Mod chat room for support $supportUid and group $gid (ID: $rid9)");
    $cm->create($rid9, $supportUid, "Message from support to group");

    # Static counter for unique message IDs
    static $messageCounter = 1;

    # Function to create a test message with unique message-id
    $createTestMessage = function($subject, $date, $approver, $pcid) use ($dbhr, $dbhm, $gid, &$messageCounter) {
        # Load the template message
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');

        # Generate unique message-id to avoid duplicates
        $uniqueId = time() . rand(1, 1000000) . $messageCounter++;
        $msg = preg_replace('/Message-Id: <[^>]+>/i', 'Message-Id: <' . $uniqueId . '@testenv>', $msg);

        # Replace subject and date
        $msg = str_replace('Test att', $subject, $msg);
        $msg = str_replace('22 Aug 2015', $date, $msg);

        # Create and route the message
        $r = new MailRouter($dbhr, $dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        error_log("Created message '$subject' (ID: $id, route result: $rc)");

        if ($id) {
            # Set location
            $m = new Message($dbhr, $dbhm, $id);
            $m->setPrivate('lat', 55.9533);
            $m->setPrivate('lng',  -3.1883);
            $m->setPrivate('locationid', $pcid);

            # Manually approve the message
            $dbhm->preExec("UPDATE messages_groups SET collection = 'Approved', approvedby = ?, approvedat = NOW() WHERE msgid = ?", [$approver, $id]);
            error_log("Approved message $id");

            # Add to messages_spatial for Go tests
            $dbhm->preExec("INSERT IGNORE INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
                            SELECT m.id, ST_Transform(ST_SRID(POINT(m.lng, m.lat), 4326), 3857), 0, 0, mg.groupid, m.type, mg.arrival
                            FROM messages m
                            JOIN messages_groups mg ON m.id = mg.msgid
                            WHERE m.id = ? AND m.lat IS NOT NULL AND m.lng IS NOT NULL", [$id]);
            error_log("Added message $id to messages_spatial");

            # Index the message for search (required for Go search tests)
            $m->index();
            error_log("Indexed message $id for search");
        }

        return $id;
    };

    # Create test messages (Go tests require at least 2 messages)
    $id = $createTestMessage('OFFER: Test due (Tuvalu High Street)', '22 Aug 2035', $uid2, $pcid);
    $id2 = $createTestMessage('WANTED: Test item (Tuvalu High Street)', '23 Aug 2035', $uid2, $pcid);

    # Create item
    $i = new Item($dbhr, $dbhm);
    $i->create('chair');

    # Add spam keywords
    $dbhm->preExec("INSERT ignore INTO `spam_keywords` (`id`, `word`, `exclude`, `action`, `type`) VALUES (8, 'viagra', NULL, 'Spam', 'Literal'), (76, 'weight loss', NULL, 'Spam', 'Literal'), (77, 'spamspamspam', NULL, 'Review', 'Literal');");
    $dbhm->preExec('REPLACE INTO `spam_keywords` (`id`, `word`, `exclude`, `action`, `type`) VALUES (272, \'(?<!\\\\bwater\\\\W)\\\\bbutt\\\\b(?!\\\\s+rd)\', NULL, \'Review\', \'Regex\');');

    # Add locations
    $dbhm->preExec("INSERT IGNORE INTO `locations` (`id`, `osm_id`, `name`, `type`, `osm_place`, `geometry`, `ourgeometry`, `gridid`, `postcodeid`, `areaid`, `canon`, `popularity`, `osm_amenity`, `osm_shop`, `maxdimension`, `lat`, `lng`, `timestamp`) VALUES
(303768, '1929174', 'Edinburgh', 'Line', NULL, ST_GeomFromText('POINT(-3.1883000 55.9533000)'), NULL, NULL, NULL, NULL, 'edinburgh', 100, NULL, NULL, NULL, 55.9533000, -3.1883000, '2024-10-18 17:51:48');");

    error_log("Test environment setup complete");
} else {
    error_log("Test environment already exists (group FreeglePlayground ID: $gid)");
}

# Add required test data for PHPUnit tests (hardcoded IDs used by tests)
# These always run since tests expect specific IDs
$dbhm->preExec("INSERT IGNORE INTO `locations` (`id`, `osm_id`, `name`, `type`, `osm_place`, `geometry`, `ourgeometry`, `gridid`, `postcodeid`, `areaid`, `canon`, `popularity`, `osm_amenity`, `osm_shop`, `maxdimension`, `lat`, `lng`, `timestamp`) VALUES
  (1687412, '189543628', 'SA65 9ET', 'Postcode', 0, ST_GeomFromText('POINT(-4.939858 52.006292)', {$dbhr->SRID()}), NULL, NULL, NULL, NULL, 'sa659et', 0, 0, 0, '0.002916', '52.006292', '-4.939858', '2016-08-23 06:01:25');");
$dbhm->preExec("INSERT IGNORE INTO `paf_addresses` (`id`, `postcodeid`, `udprn`) VALUES (102367696, 1687412, 50464672);");
$dbhm->preExec("INSERT IGNORE INTO weights (name, simplename, weight, source) VALUES ('2 seater sofa', 'sofa', 37, 'FRN 2009');");
$dbhm->preExec("INSERT IGNORE INTO spam_countries (country) VALUES ('Cameroon');");
$dbhm->preExec("INSERT IGNORE INTO spam_whitelist_links (domain, count) VALUES ('users.ilovefreegle.org', 3);");
$dbhm->preExec("INSERT IGNORE INTO spam_whitelist_links (domain, count) VALUES ('freegle.in', 3);");
$dbhm->preExec("INSERT IGNORE INTO towns (name, lat, lng, position) VALUES ('Edinburgh', 55.9500,-3.2000, ST_GeomFromText('POINT (-3.2000 55.9500)', {$dbhr->SRID()}));");
$dbhm->preExec("INSERT IGNORE INTO link_previews (`url`, `title`, `description`) VALUES ('https://www.ilovefreegle.org', 'Freegle', 'Freegle is a UK-wide umbrella organisation for local free reuse groups.');");
