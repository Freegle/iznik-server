<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) == 0) {
    echo "Usage: php prerender.php -i ID\n";
} else {
    $id = Utils::presdef('i', $opts, NULL);
    $pages = $dbhr->preQuery("SELECT id, url FROM prerender WHERE id = ?;", [$id]);
    foreach ($pages as $page) {
        $url = $page['url'] . "?nocache=1";
        $file_name = tempnam('/tmp', 'prerender_') . ".html";
        $job_file = tempnam('/tmp', 'prerender_') . ".js";
        error_log("Fetch $url using $job_file into $file_name");

        # Create phantomjs script which loads the page, and then waits until a time has passed during which there have
        # been no new network requests.  That tells us that the page has loaded.
        #
        # Have a tall viewport because for pages with infinite scrolling this will fetch a decent amount of data.
        $src = "
                var page = new WebPage();
                page.settings.userAgent = 'Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:16.0) Gecko/20120815 Firefox/16.0';
                page.viewportSize = {
                  width: 1366,
                  height: 4000
                };                
                var fs = require('fs');
                var requests = 0;
                
                page.settings.resourceTimeout = 12000; 
                page.onResourceTimeout = function(e) {
                  console.log(e.errorCode);   // it'll probably be 408 
                  console.log(e.errorString); // it'll probably be 'Network timeout on resource'
                  console.log(e.url);         // the url whose request timed out
                  phantom.exit(1);
                };
                                
                page.onResourceRequested = function(request) {
                    console.log('Requested', request.url);
                    requests++;
                };
                
                page.onConsoleMessage = function(msg, lineNum, sourceId) {
                    console.log('CONSOLE: ' + msg + ' (from line #' + lineNum + ' in \"' + sourceId + '\")');
                };
                
                page.onLoadFinished = function(status) {
                    interval = setInterval(function() {
                        console.log('Check finished', requests);
                        if (requests == 0) {
                            var bodyhtml = page.evaluate(function() {
                                return document.body.outerHTML;
                            });
                            fs.write('{$file_name}', bodyhtml, 'w');
                            var headhtml = page.evaluate(function() {
                                return document.head.innerHTML;
                            });
                            fs.write('{$file_name}.head', headhtml, 'w');
                            var title = page.evaluate(function() {
                                var tits = document.getElementsByClassName('js-pagetitle');
                                if (tits.length > 0) {
                                    return tits[0].innerHTML.replace(/[\\r\\n]+/g, ' ').trim();
                                }
                                
                                return document.title;
                            });
                            
                            fs.write('{$file_name}.title', title, 'w');
                           
                            var description = page.evaluate(function() {
                                var descs = document.getElementsByClassName('js-pagedescription');
                                console.log('Descriptions ' + descs.length);
                                if (descs.length > 0) {
                                    return descs[0].innerHTML.replace(/<\/?[^>]+(>|$)/g, '').replace(/[\\r\\n]+/g, ' ').trim();
                                }
                                
                                var metas = document.getElementsByTagName('meta');
                                var desc = null; 
    
                                for (var i = 0; i < metas.length; i++) { 
                                    if (metas[i].getAttribute('property') == 'description') {
                                        desc = metas[i].getAttribute('content'); 
                                    }
                                } 
                                
                                return(desc);
                            });
                            
                            fs.write('{$file_name}.description', description, 'w');                                                         

                            phantom.exit();
                        }
                        
                        requests = 0;
                    }, 10000);
                }
                page.open('{$url}');
            ";

        file_put_contents($job_file, $src);
        $op = [];
        exec("/usr/local/bin/phantomjs --ssl-protocol=any $job_file 2>&1", $op);
        $body = trim(file_get_contents($file_name));
        $head = trim(file_get_contents($file_name . ".head"));
        $title = file_get_contents("$file_name.title");
        $desc = file_get_contents("$file_name.description");

        if ($body && strlen($body) > 100) {
            $rc = $dbhm->preExec("UPDATE prerender SET html = ?, head = ?, title = ?, description = ? WHERE id = ?;", [
                $body,
                $head,
                strlen($title) > 0 ? $title : NULL,
                strlen($desc) > 0 ? $desc : NULL,
                $page['id']]);
            if ($rc) {
                error_log("...saved");
            } else {
                error_log("...failed to save");
            }
            unlink($file_name);
            unlink("$file_name.head");
            unlink("$file_name.title");
            unlink("$file_name.description");
            unlink($job_file);
        } else {
            error_log("...failed to fetch " . implode("\n", $op));
        }
    }
}
