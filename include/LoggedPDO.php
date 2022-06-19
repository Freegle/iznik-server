<?php
namespace Freegle\Iznik;

use Pheanstalk\Pheanstalk;
use \PDO;

# Everyone has a custom DB class.  We have ours primarily for Percona clustering.  That can cause operations
# to fail due to conflict with other servers. In that case we retry a few times here, and then if that doesn't
# work - which it may not if we are inside a transaction - then we throw an exception which will cause us to
# retry the whole API call from scratch.
class LoggedPDO {

    public $_db = NULL;
    private $connected = FALSE;
    private $hosts = [];
    private $database = NULL;
    private $inTransaction = FALSE;
    private $tries = 10;
    public  $errorLog = FALSE;
    private $lastInsert = NULL;
    private $rowsAffected = NULL;
    private $transactionStart = NULL;
    private $dbwaittime = 0;
    private $pheanstalk = NULL;
    private $readonly;
    private $readconn;
    private $username = NULL;
    private $password = NULL;
    private $variant = NULL;
    private $sqllog = SQLLOG;
    private $preparedStatements = [];
    private $version;
    public $suppressSentry = FALSE;

    const MAX_LOG_SIZE = 100000;
    const MAX_BACKGROUND_SIZE = 100000;  # Max size of sql that we can pass to beanstalk directly; larger goes in file
    const SIMPLIFY = 0.001;

    public function getVersion() {
        return $this->version;
    }

    public function isV8() {
        return strpos($this->version, '8') === 0;
    }

    public function SRID() {
        // MySQL 5.7 uses 0 as the default.  We need to stick with this otherwise we start constructing and comparing
        // geometries of different SRIDs.
        //
        // Once we migrate to MySQL 8, we will be using 3857, which is lng/lat order and what is used by
        // OpenStreetMap.
        //
        // We only get the server version on connect, so if we haven't done that yet, we need to do it now.
        $this->doConnect();
        return $this->isV8() ? '3857' : '0';
    }

    /**
     * @param int $tries
     */
    public function setTries($tries)
    {
        $this->tries = $tries;
    }

    /**
     * @param boolean $errorLog
     */
    public function setErrorLog($errorLog)
    {
        $this->errorLog = $errorLog;
    }

    public function setLog($log) {
        // This allows us to turn the logging on in UT, even if it's turned off generally.
        $this->sqllog = $log;
    }

    /**
     * @param null $pheanstalk
     */
    public function setPheanstalk($pheanstalk)
    {
        $this->pheanstalk = $pheanstalk;
    }

    public function __construct($hosts, $database, $username, $password, $readonly = FALSE, \Freegle\Iznik\LoggedPDO $readconn = NULL, $variant = 'mysql')
    {
        $this->hosts = explode(',', $hosts);
        $this->variant = $variant;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
        $this->readonly = $readonly;
        $this->readconn = $readconn;

        return $this;
    }

    private function close() {
        $this->_db = NULL;
        $this->connected = FALSE;
        $this->preparedStatements = [];
    }

    public function doConnect() {
        if (!$this->connected) {
            # We haven't connected yet.  Do so now.  We defer the connect because all API calls have both a read
            # and a write connection, and many won't use the write one, so it's a waste of time opening it until we
            # need it.
            #
            # Try a few times to get a connection to make us resilient to errors.
            $start = microtime(true);
            $gotit = FALSE;
            $count = 0;
            $hostindex = 0;
            $hostname = NULL;

            do {
                try {
                    $host = $this->hosts[$hostindex];
                    $hostname = substr($host, 0, strpos($host, ':'));
                    $port = substr($host, strpos($host, ':') + 1);
                    $dsn = "{$this->variant}:host=$hostname;port=$port;dbname={$this->database}";

                    if ($this->variant == 'mysql') {
                        $dsn .= ";charset=utf8";
                    }

                    # Check if we know that the server is down.  This avoids problems where the server is not
                    # responding to network connections, which would otherwise cause our connect attempt to hang
                    # for upto ATTR_TIMEOUT.
                    $downfile = "/tmp/iznik.dbstatus.$hostname:$port.down";
                    $wasdown = FALSE;
                    $checkit = TRUE;

                    if (file_exists($downfile)) {
                        # May be down.  Check the timestamp to see if it's time to check again.
                        $wasdown = TRUE;

                        if (time() - filemtime($downfile) < 60) {
                            # Too soon to check again.
                            $checkit = FALSE;
                        }
                    }

                    if ($checkit) {
                        $connectStart = microtime(TRUE);

                        $this->_db = new \PDO($dsn, $this->username, $this->password, [
                            \PDO::ATTR_TIMEOUT => 30,
                            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                            \PDO::MYSQL_ATTR_LOCAL_INFILE => TRUE
                        ]);

                        $connectEnd = microtime(TRUE);
                        $duration = $connectEnd - $connectStart;

                        if ($duration > 1) {
                            error_log("DB to $host connect took $duration");
                        }

                        # TODO Remove this once we have upgraded to MySQL 8.
                        $this->version = $this->_db->getAttribute(PDO::ATTR_SERVER_VERSION);

                        if ($wasdown) {
                            # This server is now up.  Note that some other process might have just spotted that
                            # and removed the file, so suppress any error.
                            @unlink($downfile);
                        }

                        $gotit = TRUE;
                    }
                } catch (\Exception $e) {
                    # Record the failure so that we don't try this server again.
                    error_log("DB connect exception on $host error " . $e->getMessage());
                    touch($downfile);
                }

                if (!$gotit) {
                    # Try the next host.
                    $hostindex++;

                    if ($hostindex >= count($this->hosts)) {
                        # We've checked all hosts.  We'll go round again. Sleep for a second so that if
                        # one comes back in a few seconds we'll spot it.
                        error_log("Sleep for all DB hosts down");
                        if (array_key_exists('REQUEST_METHOD', $_SERVER)) {
                            set_time_limit(30);
                        }
                        sleep(1);
                        $count++;
                        $hostindex = 0;
                    }
                }
            } while (!$gotit && $count < 30);

            $this->dbwaittime += microtime(true) - $start;

            if ($gotit) {
                $this->connected = TRUE;
            } else {
                throw new DBException("Failed to connect to DB after $count retries");
            }
        }
    }

    public function getWaitTime() {
        return $this->dbwaittime;
    }

    # Our most commonly used method is a combine prepare and execute, wrapped in
    # a retry.  This is SQL injection safe and handles Percona failures.
    public function preExec($sql, $params = NULL, $log = TRUE) {
        return($this->prex($sql, $params, FALSE, $log));
    }

    public function preQuery($sql, $params = NULL, $log = FALSE) {
        return($this->prex($sql, $params, TRUE, $log));
    }

    public function parentPrepare($sql) {
        $this->doConnect();
        return($this->_db->prepare($sql));
    }

    public function getErrorInfo($sth) {
        # Split into function for UT
        return($sth->errorInfo());
    }

    public function executeStatement($sth, $params) {
        $this->doConnect();

        # Split into function for UT
        return($sth->execute($params));
    }

    private function retryable($msg) {
        $ret = stripos($msg, 'has gone away') !== FALSE ||
            stripos($msg, 'Lost connection to MySQL server') !== FALSE ||
            stripos($msg, 'Call to a member function prepare() on a non-object (null)') !== FALSE ||
            stripos($msg, 'WSREP has not yet prepared node for application use') !== FALSE ||
            stripos($msg, 'Faked deadlock exception') !== FALSE;

        if ($ret) {
            # Temporary errors (hopefully).  Try re-opening the connection, delaying and retrying.
            error_log("Retryable error $msg, sleep and reconnect");
            if (array_key_exists('REQUEST_METHOD', $_SERVER)) {
                set_time_limit(30);
            }
            sleep(1);
            $this->close();
            $this->doConnect();
        }

        return $ret;
    }

    private function prex($sql, $params = NULL, $select, $log) {
        if (stripos($sql, 'SLEEP(') !== FALSE) {
            throw new \Exception("Invalid SQL");
        }

        #error_log($sql);
        $try = 0;
        $ret = NULL;
        $msg = '';
        $worked = false;
        $start = microtime(true);

        do {
            try {
                # We try to reuse prepared statements for performance reasons.  Although PHP is short-lived this
                # still has some gains.
                if (!Utils::pres($sql, $this->preparedStatements)) {
                    if (count($this->preparedStatements) > 100) {
                        # Prevent memory leaks.
                        $this->preparedStatements = [];
                    }

                    $this->preparedStatements[$sql] = $this->parentPrepare($sql);
                }

                $sth = $this->preparedStatements[$sql];
                $rc = $this->executeStatement($sth, $params);

                if ($rc && !$select) {
                    $this->lastInsert = 0;
                    $this->rowsAffected = 0;

                    # lastInsertId might fail on Postgresql, eg for CREATE TABLE.
                    try {
                        $this->lastInsert = $this->_db->lastInsertId();
                        $this->rowsAffected = $sth->rowCount();
                    } catch (\Exception $e) {}
                }

                if ($rc) {
                    # For selects we return all the rows found; for updates we return the return value.
                    $ret = $select ? $sth->fetchAll() : $rc;
                    $worked = true;

                    # Close the statement so we can reuse it later.
                    $sth->closeCursor();
                } else {
                    $msg = var_export($this->getErrorInfo($sth), true);
                    if ($this->retryable($msg)) {
                        $try++;
                    } else {
                        $try = $this->tries;
                    }
                }

                $try++;
            } catch (\Exception $e) {
                if (stripos($e->getMessage(), 'deadlock') !== FALSE) {
                    # It's a Percona deadlock.
                    #
                    # If we're not in a transaction we can just retry and it's likely to work.
                    #
                    # If we're in a transaction, then there is a very nasty case we need to watch out for.
                    # We're in a cluster, and it uses optimistic locking, which means that we can commit a
                    # conflicting transaction on another node, and then get a deadlock error.  We can get this
                    # on any operation partway through the transaction, even on a SELECT.  The deadline error
                    # has aborted our transaction and everything up to this point, but if we retry then a SELECT
                    # will work, and we will continue merrily on our way, implicitly committing as we go,
                    # until the COMMIT; which may well work.  That means that we can do half of a transaction.
                    # So instead we will give up, which will ripple up an exception causing either a retry of
                    # the whole API request, or a failure of a script.  Either way we won't end up half doing stuff.
                    #
                    # We have observed this in practice due to user merges happening on different servers -
                    # a background sync of the approved membership and a foreground sync of a pending membership
                    # which both trigger a merge, but on different servers connected to different DB hosts in the
                    # cluster.
                    #
                    # This is similar to https://ghostaldev.com/2016/05/22/galera-gotcha-mysql-users/
                    $msg = $e->getMessage();

                    if (!$this->inTransaction) {
                        $try++;
                    } else {
                        $msg = "Deadlock in transaction " . $e->getMessage() . " $sql";
                        error_log($msg);
                        $try = $this->tries;
                    }
                } else if ($this->retryable($e->getMessage())) {
                    $try++;
                } else {
                    $msg = "Non-deadlock DB Exception " . $e->getMessage() . " $sql";
                    error_log($msg);
                    if (!$this->suppressSentry) {
                        \Sentry\captureMessage($msg);
                    }

                    $try = $this->tries;
                }
            }
        } while (!$worked && $try < $this->tries);

        if ($worked && $try > 1) {
            error_log("prex succeeded after $try for $sql");
        } else if (!$worked) {
            $this->giveUp($msg . " for $sql " . var_export($params, true) . " " . ($this->_db ? var_export($this->_db->errorInfo(), true) : ''));
        }

        $this->dbwaittime += microtime(true) - $start;

        if ($log && $this->sqllog) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $duration = microtime(true) - $start;
            $logret = $select ? count($ret) : ("$ret:" . $this->lastInsert);

            if (isset($_SESSION)) {
                $logparams = var_export($params, TRUE);
                $logparams = substr($logparams, 0, LoggedPDO::MAX_LOG_SIZE);
                $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES (" .
                    Utils::presdef('id', $_SESSION, 'NULL') .
                    ", '$mysqltime', $duration, " .
                    $this->quote(session_id()) . "," .
                    $this->quote($sql . ", " . $this->quote($logparams)) . "," .
                    $this->quote($logret) . ");";
                $this->background($logsql);
            }
        }

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $start) * 1000), 2) . "ms for "  . substr($sql, 0, 256) . " " . var_export($params, TRUE));
        }

        return($ret);
    }

    public function parentExec($sql) {
        $this->doConnect();
        return($this->_db->exec($sql));
    }

    function retryExec($sql) {
        $this->doConnect();
        $try = 0;
        $ret = NULL;
        $msg = '';
        $worked = false;
        $start = microtime(true);

        # Make sure we have a connection.
        $this->doConnect();

        do {
            try {
                $ret = $this->parentExec($sql);

                if ($ret !== FALSE) {
                    $worked = true;
                } else {
                    $msg = var_export($this->errorInfo(), true);
                    if ($this->retryable($msg)) {
                        $try++;
                    } else {
                        $try = $this->tries;
                    }
                }
            } catch (\Exception $e) {
                if ($this->retryable($e->getMessage())) {
                    $try++;
                    $msg = $e->getMessage();
                } else {
                    $msg = "Non-deadlock DB Exception $sql " . $e->getMessage();
                    $try = $this->tries;
                }
            }
        } while (!$worked && $try < $this->tries);

        if ($worked && $try > 0) {
            error_log("retryExec succeeded after $try for $sql");
        } else if (!$worked)
            $this->giveUp($msg);

        $this->dbwaittime += microtime(true) - $start;

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $start) * 1000), 2) . "ms for " . substr($sql, 0, 256));
        }

        return($ret);
    }

    public function parentQuery($sql) {
        $this->doConnect();
        return($this->_db->query($sql));
    }

    public function retryQuery($sql) {
        $this->doConnect();
        $try = 0;
        $ret = NULL;
        $worked = false;
        $start = microtime(true);
        $msg = '';

        # Make sure we have a connection.
        $this->doConnect();

        do {
            try {
                $ret = $this->parentQuery($sql);

                if ($ret !== FALSE) {
                    $worked = true;
                } else {
                    $try++;
                    $msg = var_export($this->errorInfo(), true);
                }
            } catch (\Exception $e) {
                if ($this->retryable($e)) {
                    $try++;
                    $msg = $e->getMessage();
                } else {
                    $msg = "Non-deadlock DB Exception $sql " . $e->getMessage();
                    $try = $this->tries;
                }
            }
        } while (!$worked && $try < $this->tries);

        if ($worked && $try > 0) {
            error_log("retryQuery succeeded after $try");
        } else if (!$worked)
            $this->giveUp($msg); // No brace because of coverage oddity

        #error_log("Query took " . (microtime(true) - $start) . " $sql" );
        $this->dbwaittime += microtime(true) - $start;

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $start) * 1000), 2) . "ms for " . substr($sql, 0, 256) . " ");
        }

        return($ret);
    }

    public function inTransaction() {
        return($this->inTransaction) ;
    }

    public function setAttribute($attr, $val) {
        $this->doConnect();
        return($this->_db->setAttribute($attr, $val));
    }

    public function quote($str) {
        $this->doConnect();
        return($this->_db->quote($str));
    }

    public function errorInfo() {
        $this->doConnect();
        return($this->_db ? $this->_db->errorInfo() : 'No DB handle');
    }

    public function rollBack() {
        $this->doConnect();
        $this->inTransaction = FALSE;

        $time = microtime(true);
        $rc = $this->_db->rollBack();
        $duration = microtime(true) - $time;
        $mysqltime = date("Y-m-d H:i:s", time());

        if ($this->sqllog) {
            $myid = defined('_SESSION') ? Utils::presdef('id', $_SESSION, 'NULL') : 'NULL';
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES ($myid, '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote('ROLLBACK;') . "," . $this->quote($rc) . ");";
            $this->background($logsql);
        }

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $time) * 1000), 2) . "ms for rollback");
        }

        return($rc);
    }

    public function beginTransaction() {
        $this->doConnect();
        $this->inTransaction = TRUE;
        $this->transactionStart = microtime(true);
        $ret = $this->_db->beginTransaction();
        $duration = microtime(true) - $this->transactionStart;
        $this->dbwaittime += $duration;

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $this->transactionStart) * 1000), 2) . "ms for beginTransaction");
        }

        if ($this->sqllog) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $myid = defined('_SESSION') ? Utils::presdef('id', $_SESSION, 'NULL') : 'NULL';
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES ($myid, '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote('BEGIN TRANSACTION;') . "," . $this->quote($ret . ":" . $this->lastInsert) . ");";
            $this->background($logsql);
        }

        return($ret);
    }

    function commit() {
        $this->doConnect();
        $time = microtime(true);
        # PDO's commit() isn't reliable - it can return true
        $this->_db->query('COMMIT;');
        $rc = $this->_db->errorCode() == '0000';

        # ...but issue it anyway to get the states in sync
        $this->_db->commit();
        $duration = microtime(true) - $time;

        $this->dbwaittime += $duration;

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $time) * 1000), 2) . "ms for commit");
        }

        if ($this->sqllog) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $myid = defined('_SESSION') ? Utils::presdef('id', $_SESSION, 'NULL') : 'NULL';
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES ($myid, '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote('COMMIT;') . "," . $this->quote($rc) . ");";
            $this->background($logsql);
        }

        $this->inTransaction = FALSE;

        return($rc);
    }

    public function exec ($sql, $log = true)    {
        $this->doConnect();
        $time = microtime(true);
        $ret = $this->retryExec($sql);
        $this->lastInsert = $this->_db->lastInsertId();

        $duration = microtime(true) - $time;

        if ($log && $this->sqllog) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $myid = defined('_SESSION') ? Utils::presdef('id', $_SESSION, 'NULL') : 'NULL';
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES ($myid, '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote($sql) . "," . $this->quote($ret . ":" . $this->lastInsert) . ");";
            $this->background($logsql);
        }

        $this->dbwaittime += $duration;

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $time) * 1000), 2) . "ms for exec $sql");
        }

        return($ret);
    }

    public function query($sql) {
        $this->doConnect();
        $ret = $this->retryQuery($sql);
        return($ret);
    }

    public function lastInsertId() {
        $this->doConnect();
        return($this->lastInsert);
    }

    public function rowsAffected() {
        $this->doConnect();
        return($this->rowsAffected);
    }

    public function background($sql) {
        $count = 0;
        $fn = NULL;
        $time = microtime(true);

        do {
            $done = FALSE;
            try {
                # This SQL needs executing, but not in the foreground, and it's not the end of the
                # world if we drop it, or duplicate it.
                if (!$this->pheanstalk) {
                    $this->pheanstalk = Pheanstalk::create(PHEANSTALK_SERVER);
                }

                if (strlen($sql) > LoggedPDO::MAX_BACKGROUND_SIZE) {
                    # This is too large to pass to Beanstalk - we can blow its limit.   Save to a temp
                    # file and pass a reference to that.
                    $fn = tempnam('/tmp', 'iznik.background.');
                    file_put_contents($fn, $sql);

                    $id = $this->pheanstalk->put(json_encode(array(
                                                                 'type' => 'sqlfile',
                                                                 'queued' => microtime(TRUE),
                                                                 'file' => $fn,
                                                                 'ttr' => Utils::PHEANSTALK_TTR
                                                             )));
                } else {
                    # Can pass inline and save the disk write.
                    $id = $this->pheanstalk->put(json_encode(array(
                                                                 'type' => 'sql',
                                                                 'queued' => microtime(TRUE),
                                                                 'sql' => $sql,
                                                                 'ttr' => Utils::PHEANSTALK_TTR
                                                             )));
                }
                #error_log("Backgroupd $id for $sql");
                $done = TRUE;
            } catch (\Exception $e) {
                # Try again in case it's a temporary error.
                error_log("Beanstalk exception " . $e->getMessage() . " on sql of len " . strlen($sql));
                $this->pheanstalk = NULL;
                $count++;
            }
        } while (!$done && $count < 10);

        if ($this->errorLog) {
            error_log(Utils::presdef('call',$_REQUEST, ''). " " . round(((microtime(true) - $time) * 1000), 2) . "ms for background " . substr($sql, 0, 256));
        }

        return($fn);
    }

    private function giveUp($msg) {
        throw new DBException("Unexpected database error $msg", 999);
    }

    public function isConnected() {
        return $this->connected;
    }
}
