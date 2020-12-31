<?php
# We use a static site, but we want link previews to work for individual messages.  This is a simple script
# that fetches the current static site, then splices in sufficient meta tags for the previews to work.
#
# The code here has to match message/_id:buildHead() on the client for consistency.
namespace Freegle\Iznik;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$id = Utils::presint('id', $_REQUEST,   NULL);

if ($id) {
    $m = new Message($dbhr, $dbhm, $id);

    error_log("Got message" . $m->getID());
    if ($m->getID() == $id) {
        $userlist = [];
        $locationlist = [];
        $atts = $m->getPublic(FALSE, FALSE, FALSE, $userlist, $locationlist, TRUE);

        $subj = addslashes($atts['subject']);
        $snippet = addslashes(Utils::presdef('snippet', $atts, 'Click for more details') . '...');
        $image = Utils::pres('attachments', $atts) && count($atts['attachments']) > 0 ? $atts['attachments'][0]['path'] : ('https://' . USER_SITE . '/icon.png');

        $page = file_get_contents('https://www.ilovefreegle.org');

        if ($page) {
            $page = preg_replace('/<title>.*?<\/title>/', "<title>$subj</title>", $page);
            $page = preg_replace('/name="description" content=".*?">/', "name=\"description\" content=\"$snippet\">", $page);
            $page = preg_replace('/property="og\:title.*?>/', "property=\"og:title\" content=\"$subj\">", $page);
            $page = preg_replace('/property="og\:description.*?>/', "property=\"og:description\" content=\"$snippet\">", $page);
            $page = preg_replace('/property="twitter\:title.*?>/', "property=\"twitter:title\" content=\"$subj\">", $page);
            $page = preg_replace('/property="twitter\:description.*?>/', "property=\"twitter:description\" content=\"$snippet\">", $page);
            $page = preg_replace('/property="og\:url.*?>/', "property=\"og:url\" content=\"https://" . USER_SITE . "/message/$id\">", $page);
            $page = preg_replace('/property="og\:image.*?>/', "property=\"og:image\" content=\"$image\">", $page);
            $page = preg_replace('/property="twitter\:image.*?>/', "property=\"twitter:image\" content=\"$image\">", $page);

            echo $page;
        }
    }
}