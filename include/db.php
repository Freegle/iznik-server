<?php
namespace Freegle\Iznik;

$dbconfig = array (
    'hosts_read' => SQLHOSTS_READ,
    'hosts_mod' => SQLHOSTS_MOD,
    'user' => SQLUSER,
    'pass' => SQLPASSWORD,
    'database' => SQLDB
);

$GLOBALS['dbconfig'] = $dbconfig;

# We have two handles; one for reads, and one for writes, which we need because we might have a complex
# DB architecture where the master is split out from a replicated copy, or we might have a cluster where
# we direct mods to one server (to avoid cluster lockups) and reads to others.
#
# Don't use persistent connections as they don't play nice - PDO can use a connection which was already
# closed.  It's possible that our retrying would handle this ok, but we should only rely on that if
# we've tested it better and we need the perf gain.
#
# We emulate prepared statements on the client.  This is because we use prepared statements primarily for
# SQL injection, and we don't reuse the statements much.  Prepared statements involve additional round trips to
# the SQL server, and therefore they are actually significantly slower.  Emulation is as secure as long as
# you use a suitable charset (e.g. utf8).  See https://phpdelusions.net/pdo#emulation, which refers to
# https://stackoverflow.com/questions/134099/are-pdo-prepared-statements-sufficient-to-prevent-sql-injection/12202218
$GLOBALS['dbhr'] = new LoggedPDO($dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);
$GLOBALS['dbhm'] = new LoggedPDO($dbconfig['hosts_mod'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], FALSE, $GLOBALS['dbhr']);

