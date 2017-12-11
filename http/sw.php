<?php
define('IZNIK_BASE', dirname(__FILE__) . '/..');
require_once('/etc/iznik.conf');
require_once(IZNIK_BASE . '/include/config.php');

header("Cache-Control: max-age=0, no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");
header('Content-type: text/javascript'); 

?>
// We hold the push subscription.  Unfortunately localStorage isn't allowed in service workers and
// so we need to use indexedDB.  This allows us to receive push notifications from the server, and display them to
// the client (most useful on mobile).
const request = indexedDB.open( 'iznikDB', 1 );
var db;
var pushsub = null;
var cacheConfig = {
    staticCacheItems: [],
    offlineImage: '<svg role="img" aria-labelledby="offline-title"' + ' viewBox="0 0 400 300" xmlns="http://www.w3.org/2000/svg">' + '<title id="offline-title">Offline</title>' + '<g fill="none" fill-rule="evenodd"><path fill="#D8D8D8" d="M0 0h400v300H0z"/>' + '<text fill="#9B9B9B" font-family="Times New Roman,Times,serif" font-size="72" font-weight="bold">' + '<tspan x="93" y="172">offline</tspan></text></g></svg>',
    offlinePage: '/offline/'
};

request.onsuccess = function() {
    db = this.result;

    // Now get our push subscription, if present.
    // Get our subscription from indexDB
    var transaction = db.transaction(['swdata']);
    var objectStore = transaction.objectStore('swdata');
    var request1 =  objectStore.get('pushsubscription');
    request1.onsuccess = function(event) {
        if (request1.result) {
            pushsub = request1.result.value;
            console.log("Retrieved pushsub", pushsub);
        }
    }
};

request.onupgradeneeded = function(event) {
    var db = event.target.result;
    db.createObjectStore("swdata", {keyPath: "id"});
}

request.onerror = function(event) {
    console.log("SW IndexedDB error", event);
}

self.addEventListener('activate', function(event) {
    console.log("SW activated");
    return self.clients.claim();
});

self.addEventListener('message', function(event) {
    console.log("SW got message", event.data, event.data.type);
    
    switch(event.data.type) {
        case 'subscription': {
            // We have been passed our push notification subscription, which we may use to authenticate ourselves
            // to the server when processing notifications.
            console.log("SW Save subscription ", event.data.subscription);
            var request = db.transaction(['swdata'], 'readwrite')
                .objectStore('swdata')
                .put({id: 'pushsubscription', value: event.data.subscription});

            request.onsuccess = function (e) {
                console.log("SW Saved subscription");
            };

            request.onerror = function (e) {
                console.error("SW Failed to save subscription", e);
                e.preventDefault();
            };
            break;
        }
    }
});

self.addEventListener('push', function(event) {
    // At present there is no payload in the notification, so we need to query the server to get the information
    // we need to display to the user.  This is why we need our pushsub stored, so that we can authenticate to
    // the server.
    console.log('SW Push message received', event, pushsub);
    var ammod = self.registration.scope.indexOf('modtools') != -1;
    var url;

    if (ammod) {
        url =  new URL(self.registration.scope + 'api/session');

        if (pushsub) {
            // We add our push subscription as a way of authenticating ourselves to the server, in case we're
            // not already logged in.  A by product of this will be that it will log us in - but for the user
            // this is nice behaviour, as it means that if they click on a notification they won't be prompted
            // to log in.
            if (url.searchParams) {
                url.searchParams.append('pushcreds', pushsub);
                console.log("SW Add pushcreds", pushsub);
            } else {
                // Chrome mobile doesn't seem to support searchParams
                url = url + '?pushcreds=' + encodeURIComponent(pushsub);
                console.log("SW Add pushcreds into url", url);
            }
        }
    } else {
        url = new URL(self.registration.scope + 'api/chat/rooms?chattypes%5B%5D=User2User&chattypes%5B%5D=User2Mod');

        if (pushsub) {
            if (url.searchParams) {
                url.searchParams.append('pushcreds', pushsub);
            } else {
                url = url + '&pushcreds=' + encodeURIComponent(pushsub);
            }
        }
    }

    function closeAll() {
        registration.getNotifications({tag: 'work'}).then(function (notifications) {
            for (var i = 0; i < notifications.length; i++) {
                notifications[i].close();
            }
        });
    }

    event.waitUntil(
        fetch(url, {
            credentials: 'include'
        }).then(function(response) {
            return response.json().then(function(ret) {
                console.log("SW got session during push", ret);
                var notifstr = '';
                var url = '/';

                if (ret.ret == 0) {
                    try {
                        if (ammod && ret.hasOwnProperty('work')) {
                            // We are a mod.
                            url = '/modtools';
                            // Now we can decide what notification to show.
                            var work = ret.work;

                            if (typeof(work) != 'undefined') {
                                // The order of these is intentional, because it controls what the value of url will be and therefore
                                // where we go when we click the notification.
                                var spam = work.spam + work.spammembers + ((ret.systemrole == 'Admin' || ret.systemrole == 'Support') ? (work.spammerpendingadd + work.spammerpendingremove) : 0);

                                if (spam > 0) {
                                    notifstr += spam + ' spam ' + " \n";
                                    url = '/modtools/messages/spam';
                                }

                                if (work.pendingmembers > 0) {
                                    notifstr += work.pendingmembers + ' pending member' + ((work.pendingmembers != 1) ? 's' : '') + " \n";
                                    url = '/modtools/members/pending';
                                }

                                if (work.pending > 0) {
                                    notifstr += work.pending + ' pending message' + ((work.pending != 1) ? 's' : '') + " \n";
                                    url = '/modtools/messages/pending';
                                }

                                // Clear any we have shown so far.
                                closeAll();

                                if (notifstr == '') {
                                    // We have to show a popup, otherwise we'll get the "updated in the background" message.  But
                                    // we can start a timer to clear the notifications later.
                                    setTimeout(closeAll, 2000);
                                }

                                notifstr = notifstr == '' ? "No tasks outstanding" : notifstr;
                            } else {
                                notifstr = "No tasks outstanding";
                                setTimeout(closeAll, 2000);
                            }

                            return self.registration.showNotification("ModTools", {
                                body: notifstr,
                                icon: '/images/favicon/modtools/favicon-96x96.png',
                                tag: 'work',
                                data: {
                                    'url': url
                                }
                            });
                        } else {
                            // We're a user.  Check if we have any chats to notify on.
                            console.log("SW got chats", ret);
                            var notifstr = 'No messages';

                            if (ret.ret == 0) {
                                var simple = null;
                                var aggregate = 0;
                                var myid = Iznik.Session.get('me').id;

                                for (var i = 0; i < ret.chatrooms.length; i++) {
                                    var chat = ret.chatrooms[i];

                                    // For the user interface we are interested in user chats or our own chats to
                                    // mods.
                                    if (chat.type == 'User2User' || (chat.type == 'User2Mod' && chat.user1.id == myid)) {
                                        aggregate += chat.unseen;

                                        if (chat.unseen > 0) {
                                            simple = chat.name + ' wrote: ' + chat.snippet;
                                        }
                                    }
                                }

                                if (aggregate > 0) {
                                    notifstr = (aggregate > 1) ? (aggregate + ' messages') : simple;
                                } else {
                                    setTimeout(closeAll, 2000);
                                }
                            } else {
                                setTimeout(closeAll, 2000);
                            }

                            return self.registration.showNotification("Freegle", {
                                body: notifstr,
                                icon: '/images/favicon/user/favicon-96x96.png',
                                tag: 'work',
                                data: {
                                    'url': url
                                }
                            });
                        }
                    } catch (e) {
                        console.error("SW Exception " + e.message);
                    }
                }
            }).catch(function(err) {
                setTimeout(closeAll, 2000);
            });
        })
    );
});

self.addEventListener('notificationclick', function(event) {
    // We've clicked on a notification.  We want to try to open the site in the appropriate place to show the work.
    var data = event.notification.data;
    var url = data.url ? data.url : '/';

    // Close the notification now we've clicked on it.
    event.notification.close();

    // This looks to see if the site is already open and focuses if it is
    event.waitUntil(clients.matchAll({
        type: "window"
    }).then(function(clientList) {
        // Attempt to focus on existing client.  This probably doesn't work, though; see
        // https://github.com/slightlyoff/ServiceWorker/issues/758
        // TODO
        for (var i = 0; i < clientList.length; i++) {
            var client = clientList[i];
            if ('focus' in client)
                return client.focus();
        }
        if (clients.openWindow)
            return clients.openWindow(url + '?src=pushnotif');
    }));
});
