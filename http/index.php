<?php

try {
    header('Access-Control-Allow-Origin: *');
    date_default_timezone_set('UTC');
    session_start();
    define( 'BASE_DIR', dirname(__FILE__) . '/..' );
    require_once(BASE_DIR . '/include/config.php');

    if (file_exists(IZNIK_BASE . '/http/maintenance_on.html')) {
        header('HTTP/1.1 503 Service Temporarily Unavailable');
        header('Status: 503 Service Temporarily Unavailable');
        header('Retry-After: 300');
        exit(0);
    }

    require_once(IZNIK_BASE . '/include/utils.php');
    require_once(IZNIK_BASE . '/include/db.php');
    require_once(IZNIK_BASE . '/include/session/Yahoo.php');
    require_once(IZNIK_BASE . '/include/session/Facebook.php');
    require_once(IZNIK_BASE . '/include/session/Google.php');
    require_once(IZNIK_BASE . '/include/session/Session.php');
    require_once(IZNIK_BASE . '/include/user/User.php');

    if (!defined('SITE_NAME')) { error_log("Bad config " . $_SERVER['HTTP_HOST']); }

    global $dbhr, $dbhm;

    if (pres('REQUEST_URI', $_SERVER) == 'yahoologin') {
        # We have been redirected here from Yahoo.  Time to try to log in while we still have the
        # OAUTH data in our parameters (which won't be the case on subsequent API calls).
        #error_log("Redirect from Yahoo");
        $y = new Yahoo($dbhr, $dbhm);

        # No need to pay attention to the result - whether it worked or not will be determined by the
        # client later.
        $y->login(get_current_url());
    } else if (pres('fblogin', $_REQUEST)) {
        # We are logging in using Facebook, but on the server because of a problem with Chrome on IOS - see
        # signinup.js
        $fbcode = presdef('code', $_REQUEST, NULL);
        $f = new Facebook($dbhr, $dbhm);
        $url = get_current_url();
        $url = substr($url, 0, strpos($url, '&code'));
        $f->login(NULL, $fbcode, $url);

        # Now redirect so that the code doesn't appear in the URL to the user, which looks messy.
        $url = substr($url, 0, strpos($url, '?'));
        header("Location: " . $url);
        exit(0);
    } else if (pres('googlelogin', $_REQUEST)) {
        # We are logging in using Google.  We always do server logins for google due to issues with multiple accounts -
        # see google.js for more details.
        $code = presdef('code', $_REQUEST, NULL);
        $g = new Google($dbhr, $dbhm, FALSE);
        $url = get_current_url();
        $url = substr($url, 0, strpos($url, '&code'));
        $client = $g->getClient();
        $client->setRedirectUri($url);

        $g->login($code);

        # Now redirect so that the code doesn't appear in the URL to the user, which looks messy.
        $url = substr($url, 0, strpos($url, '?'));
        header("Location: " . $url);
        exit(0);
    } else if (pres('fb_locale', $_REQUEST) && pres('signed_request', $_REQUEST)) {
        # Looks like a load of the Facebook app.
        $f = new Facebook($dbhr, $dbhm);
        $f->loadCanvas();
    }

    # Depending on rewrites we might not have set up $_REQUEST.
    if (strpos(presdef('REQUEST_URI', $_SERVER, ''), '?') !== FALSE) {
        list($path, $qs) = explode("?", $_SERVER["REQUEST_URI"], 2);
        parse_str($qs, $qss);
        $_REQUEST = array_merge($_REQUEST, $qss);
    }

    if (!pres('id', $_SESSION)) {
        # Not logged in.  Check if we are fetching this url with a key which allows us to auto-login a user.
        $uid = presdef('u', $_REQUEST, NULL);
        $key = presdef('k', $_REQUEST, NULL);
        if ($uid && $key) {
            $u = User::get($dbhr, $dbhm, $uid);
            $u->linkLogin($key);
        }
    }

    if (pres('src', $_REQUEST)) {
        $dbhm->background("INSERT INTO logs_src (src, userid, session) VALUES (" . $dbhm->quote($_REQUEST['src']) . ", " . $dbhm->quote(presdef('id', $_SESSION, NULL)) . ", " . $dbhm->quote(session_id()) . ");");

        # Record in the session, as we might later create a user.
        $_SESSION['src'] = $_REQUEST['src'];
    }

    # Server-side rendering.  The webpack build produces an index.html which will
    # run the app, but we need to be able to serve up real HTML for web crawlers (even Google is
    # not yet reliable to properly index single-page apps).  We have a cron prerender script which
    # does this.
    #
    # So here we look at the URL and see if we have a pre-rendered <body> in the DB; if so then we
    # use that.  Otherwise we just use what's in index.html.
    $prerender = NULL;

#error_log("Consider pre-render " . presdef('id', $_SESSION, 'no id'));

    $url = "https://" . $_SERVER['HTTP_HOST'] . presdef('REQUEST_URI', $_SERVER, '');

    if (!pres('id', $_SESSION) && !pres('nocache', $_REQUEST)) {
        #error_log("Check for pre-render $url");
        # We cache the prerender info on the local file system to save the DB call.
        $fn = "/tmp/iznik.prerender." . base64_encode($url);

        if (is_file($fn) && (time() - filemtime($fn) < 60)) {
            # File exists and is less than 60s old.
            $prerender = json_decode(file_get_contents($fn), TRUE);
        } else {
            $prerenders = $dbhr->preQuery("SELECT * FROM prerender WHERE url = ?;", [ $url ]);

            if (count($prerenders) > 0 && $prerenders[0]['html']) {
                $prerender = $prerenders[0];
                file_put_contents($fn, json_encode($prerender));
            }
        }
    }

    $inspectlet = '';

    if (!pres('nocache', $_REQUEST) && INSPECTLET) {
        $inspectlet = <<<EOF
<!-- Begin Inspectlet Asynchronous Code -->
<script type="text/javascript">
(function() {
window.__insp = window.__insp || [];
__insp.push(['wid', zzzzz]);
var ldinsp = function(){
if(typeof window.__inspld != "undefined") return; window.__inspld = 1; var insp = document.createElement('script'); insp.type = 'text/javascript'; insp.async = true; insp.id = "inspsync"; insp.src = ('https:' == document.location.protocol ? 'https' : 'http') + '://cdn.inspectlet.com/inspectlet.js?wid=1810954883&r=' + Math.floor(new Date().getTime()/3600000); var x = document.getElementsByTagName('script')[0]; x.parentNode.insertBefore(insp, x); };
setTimeout(ldinsp, 0);
})();
</script>
<!-- End Inspectlet Asynchronous Code -->
EOF;
        $inspectlet = str_replace('zzzzz', INSPECTLET, $inspectlet);
    }

    if ($prerender) {
        #error_log("Pre-render $url");
        $head = $prerender['head'];
        $body = $prerender['html'];
        $uri = presdef('REQUEST_URI', $_SERVER, '/');

        # Load the AdSense script.  The actual ads are inserted in the views.
        $adsense = '<script async src="//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>';

        $indexhtml = "<!DOCTYPE HTML><html><head>{$head}{$adsense}{$inspectlet}</head>$body</html>";

        if (!MODTOOLS) {
            # Google init map have put some stuff in which will cause JS errors if we execute as is.  Ditto Inspectlet.
            #$indexhtml = preg_replace('/\<script type="text\/javascript" charset="UTF-8" src="https:\/\/maps.googleapis.com.*?<\/script>/m', '', $indexhtml);
            $indexhtml = preg_replace('/\<script src="https:\/\/apis.google.com\/\_.*?<\/script>/m', '', $indexhtml);
            $indexhtml = preg_replace('/\<script type="text\/javascript" async="" id="inspsync".*?<\/script>/m', '', $indexhtml);
            $indexhtml = preg_replace('/\<script src="https:\/\/apis.google.com\/\_.*?<\/script>/m', '', $indexhtml);
            $indexhtml = preg_replace('/<link rel="preload" href="https:\/\/adservice.google.co.uk.*?<\/script><style/m', '<style ', $indexhtml);
            $indexhtml = str_replace(' gapi_processed="true"', '', $indexhtml);
        }

        echo $indexhtml;
    } else {
        #error_log("No pre-render");
        $indexhtml = file_get_contents('./index.html');

        # We need to put in og: tags.
        $title = SITE_NAME;
        $desc = SITE_DESC;
        $image = USERLOGO;;
        $type = "product";
        $url = 'https://' . USER_SITE . $_SERVER['REQUEST_URI'];
        $p = strpos($url, '?');
        $url = $p !== -1 ? substr($url, 0, $p) : $url;

        if (preg_match('/\/explore\/(.*)/', $_SERVER["REQUEST_URI"], $matches)) {
            # Individual group - preview with name, tagline, image.
            require_once(IZNIK_BASE . '/include/group/Group.php');
            $g = Group::get($dbhr, $dbhm);
            $gid = $g->findByShortName($matches[1]);
            if ($gid) {
                $g = Group::get($dbhr, $dbhm, $gid);
                $atts = $g->getPublic();
                $groupdescdef = "Give and Get Stuff for Free on {$atts['namedisplay']}";

                $title = $atts['namedisplay'];
                $desc = presdef('tagline', $atts, $groupdescdef);
                $image = presdef('profile', $atts, USERLOGO);
                $type = "product.group";
            }
        } else if (preg_match('/\/message\/(.*)/', $_SERVER["REQUEST_URI"], $matches)) {
            # Individual message - preview with subject and photo.
            require_once(IZNIK_BASE . '/include/message/Message.php');
            $m = new Message($dbhr, $dbhm, intval($matches[1]));
            if ($m->getID()) {
                $atts = $m->getPublic();

                if ($m->canSee($atts)) {
                    $rsptext = '';
                    if ($m->getType() == Message::TYPE_OFFER) {
                        $rsptext = "Interested?  Click here to reply.  Everything on Freegle is free.  ";
                    } else if ($m->getType() == Message::TYPE_WANTED) {
                        $rsptext = "Got one?  Click here to reply.  Everything on Freegle is free.  ";
                    }

                    $title = $atts['subject'];;
                    $desc = $rsptext;
                    $image = (count($atts['attachments']) > 0 && pres('path', $atts['attachments'][0])) ? $atts['attachments'][0]['path'] : USERLOGO;
                }
            }
        } else if (preg_match('/\/communityevent\/(.*)/', $_SERVER["REQUEST_URI"], $matches)) {
            # Community event - preview with title and description
            require_once(IZNIK_BASE . '/include/group/CommunityEvent.php');
            $e = new CommunityEvent($dbhr, $dbhm, intval($matches[1]));

            if ($e->getID()) {
                $atts = $e->getPublic();
                $photo = presdef('photo', $atts, NULL);

                $title = $atts['title'];
                $desc = $atts['title'];
                $image = $photo ? $photo['path'] : USERLOGO;
                $type = "article";
            }
        } else if (preg_match('/\/story\/(.*)/', $_SERVER["REQUEST_URI"], $matches)) {
            # Story - preview with headline and description
            require_once(IZNIK_BASE . '/include/user/Story.php');
            $s = new Story($dbhr, $dbhm, intval($matches[1]));

            if ($s->getID()) {
                $atts = $s->getPublic();
                $photo = presdef('photo', $atts, NULL);

                $title = $atts['headline'];
                $desc = "Click to read more";
                $image = $photo ? $photo['path'] : USERLOGO;
                $type = "article";
            }
        } else if (preg_match('/\/chat\/(.*)\/external/', $_SERVER["REQUEST_URI"], $matches)) {
            # External link to a chat reply.
            require_once(IZNIK_BASE . '/include/group/CommunityEvent.php');

            $title = "Click to read your reply";
            $desc = "We passed on your message and got a reply - click here to read it.";
        } else if (preg_match('/\/newsfeed\/(.*)/', $_SERVER["REQUEST_URI"], $matches)) {
            # External link to a newsfeed thread.
            require_once(IZNIK_BASE . '/include/newsfeed/Newsfeed.php');
            $n = new Newsfeed($dbhr, $dbhm, $matches[1]);

            $title = 'A discussion on ' . SITE_NAME;
            $desc = '';
            $image = "https://" . USER_SITE . "/images/favicon/" . FAVICON_HOME . "/largetile.png?a=1";

            if ($n->getId()) {
                $atts = $n->getPublic();
                $desc = preg_replace('/\\\\\\\\u.*\\\\\\\\u/', '', $atts['message']);

                if (pres('user', $atts)) {
                    $title = $atts['user']['displayname'] . "'s discussion on " . SITE_NAME;
                    $image = $atts['user']['profile']['url'];
                }
            }
            $type = "article";
        }

        # Splice them in.
        $title = htmlentities($title);
        $desc = htmlentities(preg_replace("/[\r\n]*/","",$desc));

        $indexhtml = preg_replace('/\<title\>.*?\<\/title\>/', "<title>" . htmlentities($title) . "</title>", $indexhtml);
        $prehead = '<meta itemprop="title" content="' . $title . '"/><meta name="description" content="' . $desc . '"/><meta property="og:description" content="' . $desc . '" /><meta property="og:title" content="' . $title . '"/><meta property="og:image" content="' . $image . '"/><meta property="og:type" content="' . $type . '"/><meta property="og:url" content="' . $url . '"/><meta property="fb:app_id" content="' . FBAPP_ID . '"/>';
        $indexhtml = str_replace('</head>', "$prehead$inspectlet</head>", $indexhtml);

        echo $indexhtml;
    }
} catch (Exception $e) {
    # Make whatever went wrong look like a maintenance issue.
    header('HTTP/1.1 503 Service Temporarily Unavailable');
    header('Status: 503 Service Temporarily Unavailable');
    header('Retry-After: 300');//300 seconds
}

?>