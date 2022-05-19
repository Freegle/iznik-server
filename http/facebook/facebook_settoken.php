<?php
namespace Freegle\Iznik;

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$id = intval(Utils::presdef('id', $_REQUEST, NULL));
$token = Utils::presdef('token', $_REQUEST, NULL);
$gid = Utils::presint('groupid', $_REQUEST, NULL);

$fb = new \Facebook\Facebook([
    'app_id' => FBGRAFFITIAPP_ID,
    'app_secret' => FBGRAFFITIAPP_SECRET,
    'default_graph_version' =>  'v13.0'
]);

if ($id && $token) {
    # We have to ensure that we are an admin for the page we've chosen, so check the list again.
    try {
        $accessToken = $_SESSION['fbaccesstoken'];
        #error_log("Got token from session $accessToken");

        $totalPages = array();

        $url = '/me/accounts';

        do {
            $getPages = $fb->get($url, $accessToken);
            $body = $getPages->getDecodedBody();
            $pages = Utils::presdef('data', $body, []);
            #echo("Body " . json_encode($body));

            foreach ($pages as $page) {
                #echo("Page {$page['name']}");
                $totalPages[] = $page;
            }

            $url = Utils::pres('paging', $body) ? ('/me/accounts?after=' . Utils::presdef('after', $body['paging']['cursors'], NULL)) : NULL;
            #echo("Next url $url");
        } while ($url);

        $found = FALSE;

        foreach ($totalPages as $page) {
            #echo("Compare {$page['id']} vs $id");
            if ($id && (intval($page['id']) == intval($id))) {
                $f = new GroupFacebook($dbhr, $dbhm);

                if ($gid) {
                    #echo "Found group.  You can close this tab now.";
                    $f = new GroupFacebook($dbhr, $dbhm, $gid);
                    $f->add($gid, $page['access_token'], $page['name'], $page['id'], GroupFacebook::TYPE_PAGE);
                    $found = TRUE;
                } else {
                    echo "Group not found";
                }
            }
        }

        if (!$found) {
            echo "Hmmm...couldn't find that page in your list.";
        } else {
            echo "Page linked.  You can close this now.";
        }
    } catch (\Exception $e) {
        echo "Something went wrong " . $e->getMessage();
    }
}
