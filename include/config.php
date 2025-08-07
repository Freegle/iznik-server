<?php
// Some packages we use generate warning/deprecated messages which we want to suppress, especially for Sentry.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);

if (!defined('REDIS_CONNECT')) {
    if (file_exists('/etc/iznikredis')) {
        $val = trim(file_get_contents('/etc/iznikredis'));
        define('REDIS_CONNECT', $val);
    } else if (file_exists('/var/run/redis/redis.sock')) {
        define('REDIS_CONNECT', '/var/run/redis/redis.sock');
    } else {
        define('REDIS_CONNECT', '127.0.0.1');
    }
}

if (!defined('IZNIK_BASE')) {
    define('IZNIK_BASE', dirname(__FILE__) . '/..');
    require_once(IZNIK_BASE . '/composer/vendor/autoload.php');

    # Autoload for our code.
    spl_autoload_register(function ($class_name) {
        $p = strrpos($class_name, '\\');
        $class = $class_name;

        if ($p !== FALSE) {
            $q = strpos($class_name, 'Freegle\Iznik');

            if ($q === 0) {
                $class = substr($class_name, $p + 1);
            }
        }

        foreach ([ '/include/', '/include/user/', '/include/session/', '/include/group/', '/include/message/', '/include/misc/', '/include/chat/', '/include/newsfeed/', '/include/spam/', '/include/config/', '/include/dashboard/', '/include/mail/', '/include/noticeboard/', '/include/integrations/', '/include/ai/', '/test/ut/' ] as $dir) {
            $fn = IZNIK_BASE . $dir . $class . '.php';
            #error_log("Check $class_name $fn");
            if (file_exists($fn)) {
                #error_log("Exists");
                require_once $fn;
                break;
            }
        }
    });

    define('DUPLICATE_POST_PROTECTION', 10); # Set to 0 to disable
    define('API_RETRIES', 5);
    define('REDIS_TTL', 30);

    define('BROWSERTRACKING', TRUE);
    define('INCLUDE_TEMPLATE_NAME', TRUE);

    # SQL caching is disabled at the moment.  Caching operations in redis is fairly expensive, and we've had
    # more benefit in reducing the number of ops in the first place.  Not quite ready to remove the code yet.
    define('SQLCACHE', FALSE);

    define('EVENTLOG', TRUE);
    define('TWIG_CACHE', '/tmp/twig_cache');

    if (!defined('XHPROF')) {
        define('XHPROF', FALSE);
    }

    define('COOKIE_NAME', 'session');

    # Our servers run on UTC
    date_default_timezone_set('UTC');

    # Per-machine config or overrides
    require_once('/etc/iznik.conf');

    if (!defined('SQLLOG')) {
        define('SQLLOG', FALSE);
    }

    # There are some historical domains.
    define('OURDOMAINS', USER_DOMAIN . "," . GROUP_DOMAIN . ",direct.ilovefreegle.org,republisher.freegle.in");

    if(defined('SENTRY_DSN') && !defined('SENTRY_INITIALISED')) {
        define('SENTRY_INITIALISED', TRUE);
        \Sentry\init([
            'dsn' => SENTRY_DSN,
            'attach_stacktrace' => TRUE,
            'before_send' => function (\Sentry\Event $event): ?\Sentry\Event {
                if ($event) {
                    $msg = $event->getMessage();

                    if ($msg) {
                        if (strpos($msg, 'Warning: fwrite(): supplied resource is not a valid stream resource') !== FALSE) {
                            // This happens within Pheanstalk.  It's a benign reconnection case.
                            return FALSE;
                        } else if (strpos($msg, 'Notice: unserialize(): Error at offset') !== FALSE) {
                            // This happens in SwiftMailer, when spooling.  It's an issue, but there is no way of
                            // recovering from it.
                            return FALSE;
                        }
                    }
                }

                return $event;
            },
        ]);
    }
}

if (!defined('MMDB')) {
    if (file_exists('/usr/share/GeoIP/GeoLite2-Country.mmdb')) {
        define('MMDB', '/usr/share/GeoIP/GeoLite2-Country.mmdb');
    } else if (file_exists('/usr/local/share/GeoIP/GeoLite2-Country.mmdb')) {
        define('MMDB', '/usr/local/share/GeoIP/GeoLite2-Country.mmdb');
    } else {
        define('MMDB', '/var/lib/GeoIP/GeoLite2-Country.mmdb');
    }
}
