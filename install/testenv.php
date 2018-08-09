<?php

# Set up gridids for locations already in the locations table; you might do this after importing a bunch of locations
# from a source such as OpenStreetMap (OSM).
require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/Item.php');

$g = new Group($dbhr, $dbhm);
$gid = $g->findByShortName('FreeglePlayground');

if (!$gid) {
    # Not set up yet.
    error_log("Set up test environment");
    $gid = $g->create('FreeglePlayground', Group::GROUP_FREEGLE);
    $g->setPrivate('onyahoo', 0);
    $g->setPrivate('onhere', 1);
    $g->setPrivate('polyofficial', 'POLYGON((-3.1902622 55.9910847, -3.2472542 55.98263430000001, -3.2863922 55.9761038, -3.3159182 55.9522754, -3.3234712 55.9265089, -3.304932200000001 55.911888, -3.3742832 55.8880206, -3.361237200000001 55.8718436, -3.3282782 55.8729997, -3.2520602 55.8964911, -3.2177282 55.895336, -3.2060552 55.8903307, -3.1538702 55.88648049999999, -3.1305242 55.893411, -3.0989382 55.8972611, -3.0680392 55.9091938, -3.0584262 55.9215076, -3.0982522 55.928048, -3.1037452 55.9418938, -3.1236572 55.9649602, -3.168289199999999 55.9849393, -3.1902622 55.9910847))');
    $g->setPrivate('lat', 55.9533);
    $g->setPrivate('lng', 3.1883);

    $l = new Location($dbhr, $dbhm);
    $areaid = $l->create(NULL, 'Central', 'Polygon', 'POLYGON((-3.217620849609375 55.9565040997114,-3.151702880859375 55.9565040997114,-3.151702880859375 55.93304863776238,-3.217620849609375 55.93304863776238,-3.217620849609375 55.9565040997114))', 0);
    $pcid = $l->create(NULL, 'EH3 6SS', 'Postcode', 'POINT(55.957571 -3.205333)', 0);

    $u = new User($dbhr, $dbhm);
    $u->create('Test', 'User', 'Test User');
    $u->addEmail('test@test.com');
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

    $i = new Item($dbhr, $dbhm);
    $i->create('chair');

    $dbhm->preExec("INSERT INTO `spam_keywords` (`id`, `word`, `exclude`, `action`, `type`) VALUES (8, 'viagra', NULL, 'Spam', 'Literal'), (76, 'weight loss', NULL, 'Spam', 'Literal'), (77, 'spamspamspam', NULL, 'Review', 'Literal'), (272, '(?<!\bwater\W)\bbutt\b(?!\s+rd)', NULL, 'Review', 'Regex');");
    $dbhm->preExec("INSERT INTO `locations` (`id`, `osm_id`, `name`, `type`, `osm_place`, `geometry`, `ourgeometry`, `gridid`, `postcodeid`, `areaid`, `canon`, `popularity`, `osm_amenity`, `osm_shop`, `maxdimension`, `lat`, `lng`, `timestamp`) VALUES
      (1687412, '189543628', 'SA65 9ET', 'Line', 0, GeomFromText('POINT(-4.939858 52.006292)'), NULL, NULL, NULL, NULL, 'sa659et', 0, 0, 0, '0.002916', '52.006292', '-4.939858', '2016-08-23 06:01:25');
      INSERT INTO `paf_addresses` (`id`, `postcodeid`) VALUES   (102367696, 1687412);
    ");
    $dbhm->preExec("INSERT INTO weights (name, simplename, weight, source) VALUES ('2 seater sofa', 'sofa', 37, 'FRN 2009');");
    $dbhm->preExec("INSERT INTO spam_countries (country) VALUES ('Cameroon');");
    $dbhm->preExec("INSERT INTO spam_whitelist_links (domain, count) VALUES ('users.ilovefreegle.org', 3);");
    $dbhm->preExec("INSERT INTO spam_whitelist_links (domain, count) VALUES ('freegle.in', 3);");
} else {
    error_log("Test environment already set up.");
}

