<?php

namespace Freegle\Iznik;

# Parameterized test environment factory for Playwright test isolation.
# Usage: php create-test-env.php <prefix>
# Creates an isolated group, moderator, users, messages, and chats.
# Each prefix gets a unique UK city location so groups don't overlap.
# Idempotent: reuses existing data if prefix already exists.
# Returns JSON on stdout with all created IDs.

require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$prefix = $argv[1] ?? NULL;
if (!$prefix) {
    fwrite(STDERR, "Usage: create-test-env.php <prefix>\n");
    exit(1);
}

# --- UK city locations for test isolation (one per test file) ---
# Each test prefix gets a unique city so group polygons never overlap.
$locations = [
    ['postcode' => 'LS1 4AP',   'lat' => 53.7997, 'lng' => -1.5492],   // Leeds
    ['postcode' => 'LN1 3AE',   'lat' => 53.2327, 'lng' => -0.5400],   // Lincoln
    ['postcode' => 'NE1 4LP',   'lat' => 54.9783, 'lng' => -1.6178],   // Newcastle
    ['postcode' => 'NG1 5FS',   'lat' => 52.9548, 'lng' => -1.1581],   // Nottingham
    ['postcode' => 'OX1 3BG',   'lat' => 51.7520, 'lng' => -1.2577],   // Oxford
    ['postcode' => 'CB2 1TN',   'lat' => 52.2053, 'lng' => 0.1218],    // Cambridge
    ['postcode' => 'SO14 7DW',  'lat' => 50.9097, 'lng' => -1.4044],   // Southampton
    ['postcode' => 'PL1 2EX',   'lat' => 50.3755, 'lng' => -4.1427],   // Plymouth
    ['postcode' => 'EX1 1SR',   'lat' => 50.7236, 'lng' => -3.5275],   // Exeter
    ['postcode' => 'BA1 5BG',   'lat' => 51.3811, 'lng' => -2.3590],   // Bath
    ['postcode' => 'YO1 7HH',   'lat' => 53.9591, 'lng' => -1.0815],   // York
    ['postcode' => 'PE1 1HF',   'lat' => 52.5695, 'lng' => -0.2405],   // Peterborough
    ['postcode' => 'IP1 3QH',   'lat' => 52.0567, 'lng' => 1.1482],    // Ipswich
    ['postcode' => 'NR1 3JD',   'lat' => 52.6309, 'lng' => 1.2974],    // Norwich
    ['postcode' => 'AB10 1SH',  'lat' => 57.1497, 'lng' => -2.0943],   // Aberdeen
    ['postcode' => 'SA1 3SS',   'lat' => 51.6214, 'lng' => -3.9436],   // Swansea
    ['postcode' => 'HU1 3RA',   'lat' => 53.7676, 'lng' => -0.3274],   // Hull
    ['postcode' => 'CA1 1JB',   'lat' => 54.8925, 'lng' => -2.9360],   // Carlisle
    ['postcode' => 'BN1 1JE',   'lat' => 50.8225, 'lng' => -0.1372],   // Brighton
    ['postcode' => 'GL1 1SS',   'lat' => 51.8642, 'lng' => -2.2382],   // Gloucester
    ['postcode' => 'WR1 2ET',   'lat' => 52.1936, 'lng' => -2.2216],   // Worcester
    ['postcode' => 'ST1 1LZ',   'lat' => 53.0027, 'lng' => -2.1794],   // Stoke
    ['postcode' => 'SN1 1BD',   'lat' => 51.5558, 'lng' => -1.7797],   // Swindon
    ['postcode' => 'CT1 2HT',   'lat' => 51.2802, 'lng' => 1.0789],    // Canterbury
    ['postcode' => 'TR1 2LE',   'lat' => 50.2632, 'lng' => -5.0510],   // Truro
    ['postcode' => 'CF10 1EP',  'lat' => 51.4816, 'lng' => -3.1791],   // Cardiff
    ['postcode' => 'M1 1AD',    'lat' => 53.4808, 'lng' => -2.2426],   // Manchester
    ['postcode' => 'B1 1BB',    'lat' => 52.4862, 'lng' => -1.8904],   // Birmingham
    ['postcode' => 'G1 1DU',    'lat' => 55.8617, 'lng' => -4.2583],   // Glasgow
    ['postcode' => 'TA1 1JR',   'lat' => 51.0215, 'lng' => -3.1003],   // Taunton
];

# Known prefix -> location index mapping (sorted alphabetically).
# Guarantees no collisions for existing test files.
# New test files fall back to hash-based assignment.
$knownPrefixes = [
    'browse'                  => 0,
    'explore'                 => 1,
    'mtholdrelease'           => 2,
    'mtchatlist'              => 3,
    'mtdashboard'             => 4,
    'mtedits'                 => 5,
    'mtmemberlogs'            => 6,
    'mtmovemessage'           => 7,
    'mtpageloads'             => 8,
    'mtpendingmessages'       => 9,
    'mtsupport'               => 10,
    'postflow'                => 11,
    'replyflowedgecases'      => 12,
    'replyflowexistinguser'   => 13,
    'replyflowloggedin'       => 14,
    'replyflowlogging'        => 15,
    'replyflownewuser'        => 16,
    'replyflowsocial'         => 17,
    'userratings'             => 18,
    'v2apipages'              => 19,
    'mteditsflow'             => 20,
];

if (isset($knownPrefixes[$prefix])) {
    $locationIndex = $knownPrefixes[$prefix];
} else {
    $locationIndex = abs(crc32($prefix)) % count($locations);
}

$location = $locations[$locationIndex];

# Generate a polygon (8-point circle) around a center point.
function generatePolygon($lat, $lng, $radiusDeg = 0.1) {
    $points = 8;
    $coords = [];
    for ($i = 0; $i <= $points; $i++) {
        $angle = 2 * M_PI * ($i % $points) / $points;
        $pLat = $lat + $radiusDeg * sin($angle);
        $pLng = $lng + ($radiusDeg / cos(deg2rad($lat))) * cos($angle);
        $coords[] = sprintf('%.6f %.6f', $pLng, $pLat);
    }
    return 'POLYGON((' . implode(',', $coords) . '))';
}

$groupName = "PW_$prefix";
$groupName2 = "PW_{$prefix}_2";
$modEmail = "pw_{$prefix}_mod@test.com";
$userEmail = "pw_{$prefix}_user@test.com";
$user2Email = "pw_{$prefix}_user2@test.com";

$result = [];

# Find or create postcode location for this city.
$pcRows = $dbhr->preQuery("SELECT id FROM locations WHERE name = ? LIMIT 1", [$location['postcode']]);
$pcid = ($pcRows && count($pcRows) > 0) ? $pcRows[0]['id'] : NULL;

if (!$pcid) {
    $l = new Location($dbhr, $dbhm);
    $pcid = $l->create(NULL, $location['postcode'], 'Postcode',
        "POINT({$location['lng']} {$location['lat']})");
    error_log("Created postcode location {$location['postcode']} (ID: $pcid)");
} else {
    error_log("Postcode {$location['postcode']} already exists (ID: $pcid)");
}

# Generate group polygon around the city center.
$polygon = generatePolygon($location['lat'], $location['lng'], 0.05);

# Find or create primary group.
$g = new Group($dbhr, $dbhm);
$gid = $g->findByShortName($groupName);

if (!$gid) {
    $gid = $g->create($groupName, Group::GROUP_FREEGLE);
    $g->setPrivate('onhere', 1);
    $g->setPrivate('polyofficial', $polygon);
    $g->setPrivate('lat', $location['lat']);
    $g->setPrivate('lng', $location['lng']);
    error_log("Created group $groupName (ID: $gid) at {$location['postcode']}");
} else {
    # Update polygon/location to match current code (in case locations changed).
    $g = new Group($dbhr, $dbhm, $gid);
    $g->setPrivate('polyofficial', $polygon);
    $g->setPrivate('lat', $location['lat']);
    $g->setPrivate('lng', $location['lng']);
    error_log("Group $groupName already exists (ID: $gid), updated location to {$location['postcode']}");
}

# Find or create second group (for move-message tests).
$g2 = new Group($dbhr, $dbhm);
$gid2 = $g2->findByShortName($groupName2);

if (!$gid2) {
    $gid2 = $g2->create($groupName2, Group::GROUP_FREEGLE);
    $g2->setPrivate('onhere', 1);
    $g2->setPrivate('contactmail', 'contact@test.com');
    $g2->setPrivate('namefull', "$groupName2 Full");
    error_log("Created group $groupName2 (ID: $gid2)");
} else {
    error_log("Group $groupName2 already exists (ID: $gid2)");
}

# Helper to find or create a user.
function findOrCreateUser($dbhr, $dbhm, $email, $firstname, $lastname, $systemrole, $gid, $role, $pcid, $lat, $lng) {
    $u = new User($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if (!$uid) {
        $uid = $u->create($firstname, $lastname, "$firstname $lastname");
        $u->addEmail($email);
        $ouremail = $u->inventEmail();
        $u->addEmail($ouremail, 0, FALSE);
        $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');

        if ($systemrole !== 'User') {
            $u->setPrivate('systemrole', $systemrole);
        }

        error_log("Created user $email (ID: $uid, role: $systemrole)");
    } else {
        $u = new User($dbhr, $dbhm, $uid);
        error_log("User $email already exists (ID: $uid)");
    }

    # Ensure membership (idempotent).
    $u->addMembership($gid, $role);
    $u->setMembershipAtt($gid, 'ourPostingStatus',
        $role == User::ROLE_MODERATOR ? Group::POSTING_DEFAULT : Group::POSTING_UNMODERATED);

    # Create isochrone for this user's location.
    if ($pcid) {
        $isoPoly = generatePolygon($lat, $lng, 0.08);
        $dbhm->preExec(
            "INSERT IGNORE INTO `isochrones` (`id`, `locationid`, `transport`, `minutes`, `source`, `timestamp`, `polygon`) " .
            "VALUES (NULL, ?, 'Drive', '15', 'Generated', CURRENT_TIMESTAMP, ST_GeomFromText(?, 3857))",
            [$pcid, $isoPoly]
        );
    }

    return $uid;
}

# Create moderator.
$modUid = findOrCreateUser($dbhr, $dbhm, $modEmail, 'PW', "Mod_$prefix", 'Admin', $gid, User::ROLE_MODERATOR, $pcid, $location['lat'], $location['lng']);

# Also add mod to second group.
$modUser = new User($dbhr, $dbhm, $modUid);
$modUser->addMembership($gid2, User::ROLE_MODERATOR);

# Create regular users.
$userUid = findOrCreateUser($dbhr, $dbhm, $userEmail, 'PW', "User_$prefix", 'User', $gid, User::ROLE_MEMBER, $pcid, $location['lat'], $location['lng']);
$user2Uid = findOrCreateUser($dbhr, $dbhm, $user2Email, 'PW', "User2_$prefix", 'User', $gid, User::ROLE_MEMBER, $pcid, $location['lat'], $location['lng']);

# Create approved messages (OFFER + WANTED) if they don't exist.
function findOrCreateMessage($dbhr, $dbhm, $subject, $gid, $groupName, $approver, $pcid, $lat, $lng, $senderEmail, $collection = 'Approved') {
    # Check if a message with this subject already exists on this group.
    $existing = $dbhr->preQuery(
        "SELECT m.id FROM messages m " .
        "INNER JOIN messages_groups mg ON m.id = mg.msgid " .
        "WHERE m.subject = ? AND mg.groupid = ? LIMIT 1",
        [$subject, $gid]
    );

    if ($existing && count($existing) > 0) {
        error_log("Message '$subject' already exists (ID: {$existing[0]['id']})");
        return $existing[0]['id'];
    }

    # Load template message.
    $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');

    # Generate unique message-id.
    $uniqueId = time() . rand(1, 1000000) . rand(1, 1000000);
    $msg = preg_replace('/Message-Id: <[^>]+>/i', 'Message-Id: <' . $uniqueId . '@pw-testenv>', $msg);
    $msg = str_replace('Test att', $subject, $msg);
    $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);

    # Replace From/To/Reply-To headers so MailRouter routes to the correct group.
    $groupEmail = strtolower($groupName) . '@yahoogroups.com';
    $msg = str_replace('test@test.com', $senderEmail, $msg);
    $msg = str_replace('freegleplayground@yahoogroups.com', $groupEmail, $msg);

    $r = new MailRouter($dbhr, $dbhm);
    list ($id, $failok) = $r->received(Message::EMAIL, $senderEmail, $senderEmail, $msg);
    $rc = $r->route();
    error_log("Created message '$subject' (ID: $id, route: $rc)");

    if ($id) {
        $m = new Message($dbhr, $dbhm, $id);
        $m->setPrivate('lat', $lat);
        $m->setPrivate('lng', $lng);
        if ($pcid) {
            $m->setPrivate('locationid', $pcid);
        }

        if ($collection === 'Approved') {
            $dbhm->preExec(
                "UPDATE messages_groups SET collection = 'Approved', approvedby = ?, approvedat = NOW() WHERE msgid = ?",
                [$approver, $id]
            );

            # Add to spatial index.
            $dbhm->preExec(
                "INSERT IGNORE INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival) " .
                "SELECT m.id, ST_GeomFromText(CONCAT('POINT(', m.lng, ' ', m.lat, ')'), {$dbhr->SRID()}), 0, 0, mg.groupid, m.type, mg.arrival " .
                "FROM messages m JOIN messages_groups mg ON m.id = mg.msgid WHERE m.id = ? AND m.lat IS NOT NULL AND m.lng IS NOT NULL",
                [$id]
            );

            # Index for search.
            $m->index();
        } else {
            $dbhm->preExec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", [$id]);
        }
    }

    return $id;
}

$locName = $location['postcode'];
$offerMsgId = findOrCreateMessage($dbhr, $dbhm, "OFFER: PW_$prefix Test Item ($locName)", $gid, $groupName, $modUid, $pcid, $location['lat'], $location['lng'], $modEmail, 'Approved');
$wantedMsgId = findOrCreateMessage($dbhr, $dbhm, "WANTED: PW_$prefix Wanted Item ($locName)", $gid, $groupName, $modUid, $pcid, $location['lat'], $location['lng'], $modEmail, 'Approved');
$pendingOfferId = findOrCreateMessage($dbhr, $dbhm, "OFFER: PW_$prefix Pending Sofa ($locName)", $gid, $groupName, $modUid, $pcid, $location['lat'], $location['lng'], $modEmail, 'Pending');
$pendingWantedId = findOrCreateMessage($dbhr, $dbhm, "OFFER: PW_$prefix Pending Bookshelf ($locName)", $gid, $groupName, $modUid, $pcid, $location['lat'], $location['lng'], $modEmail, 'Pending');

# Ensure pending messages start unheld (clean state for hold/release tests).
$dbhm->preExec("UPDATE messages SET heldby = NULL WHERE id IN (?, ?)", [$pendingOfferId, $pendingWantedId]);

# Clean up stale chats for test users to prevent 404 errors when chat store loads old references.
# Tests accumulate chat rooms across runs; old chats may reference deleted messages/users.
$testUserIds = [$modUid, $userUid, $user2Uid];
$placeholders = implode(',', array_fill(0, count($testUserIds), '?'));
$dbhm->preExec(
    "DELETE FROM chat_messages WHERE chatid IN (SELECT id FROM chat_rooms WHERE user1 IN ($placeholders) OR user2 IN ($placeholders))",
    array_merge($testUserIds, $testUserIds)
);
$dbhm->preExec(
    "DELETE FROM chat_rooms WHERE user1 IN ($placeholders) OR user2 IN ($placeholders)",
    array_merge($testUserIds, $testUserIds)
);
error_log("Cleaned up stale chats for test users");

# Create chat rooms.
$r = new ChatRoom($dbhr, $dbhm);
$cm = new ChatMessage($dbhr, $dbhm);

# User2User chat.
list ($u2uRid, $banned) = $r->createConversation($userUid, $modUid);
$cm->create($u2uRid, $userUid, "PW test message from user to mod in $prefix");
error_log("User2User chat (ID: $u2uRid)");

# User2Mod chat.
$u2mRid = $r->createUser2Mod($userUid, $gid);
$cm->create($u2mRid, $userUid, "PW test message from user to group in $prefix");
error_log("User2Mod chat (ID: $u2mRid)");

# Output result JSON on stdout.
$result = [
    'group' => ['id' => (int)$gid, 'name' => $groupName],
    'group2' => ['id' => (int)$gid2, 'name' => $groupName2],
    'mod' => ['id' => (int)$modUid, 'email' => $modEmail],
    'user' => ['id' => (int)$userUid, 'email' => $userEmail],
    'user2' => ['id' => (int)$user2Uid, 'email' => $user2Email],
    'messages' => ['offer' => (int)$offerMsgId, 'wanted' => (int)$wantedMsgId],
    'pending' => ['offer' => (int)$pendingOfferId, 'wanted' => (int)$pendingWantedId],
    'chats' => ['user2user' => (int)$u2uRid, 'user2mod' => (int)$u2mRid],
    'postcode' => $location['postcode'],
];

echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
