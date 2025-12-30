#!/bin/bash
# Wrapper script to run PHPUnit tests with paratest for parallel execution

echo "Stopping chat_notify background job..."
touch /tmp/iznik.mail.abort
pkill -f chat_notifyemail_user2user.php 2>/dev/null

# Update iznik.conf FIRST to use per-worker databases (needed before testenv.php runs)
echo "Configuring per-worker MySQL database, PostgreSQL database, and Beanstalkd tube..."

# Update SQLDB to be per-worker
if ! grep -q "SQLDB based on TEST_TOKEN" /etc/iznik.conf; then
    # Backup original config
    cp /etc/iznik.conf /etc/iznik.conf.bak

    # Replace the static SQLDB line with dynamic version
    sed -i "/define('SQLDB'/c\\
# SQLDB based on TEST_TOKEN for parallel testing\\
\$sqldb = 'iznik_phpunit_test';\\
\$testToken = getenv('TEST_TOKEN');\\
if (\$testToken !== FALSE && \$testToken !== '' && is_numeric(\$testToken)) {\\
    \$sqldb = 'iznik_' . \$testToken;\\
}\\
define('SQLDB', \$sqldb);" /etc/iznik.conf

    echo "  iznik.conf updated for per-worker MySQL (SQLDB)."
fi

# Update PGSQLDB to be per-worker
if ! grep -q "PGSQLDB based on TEST_TOKEN" /etc/iznik.conf; then
    sed -i "/define('PGSQLDB'/c\\
# PGSQLDB based on TEST_TOKEN for parallel testing\\
\$pgsqldb = 'iznik';\\
\$testToken = getenv('TEST_TOKEN');\\
if (\$testToken !== FALSE && \$testToken !== '' && is_numeric(\$testToken)) {\\
    \$pgsqldb = 'iznik_' . \$testToken;\\
}\\
define('PGSQLDB', \$pgsqldb);" /etc/iznik.conf

    echo "  iznik.conf updated for per-worker PostgreSQL (PGSQLDB)."
fi

# Update Beanstalkd tube to be per-worker
if ! grep -q "PHEANSTALK_TUBE based on TEST_TOKEN" /etc/iznik.conf; then
    sed -i "/define('PHEANSTALK_TUBE'/c\\
# PHEANSTALK_TUBE based on TEST_TOKEN for parallel testing\\
\$tube = 'phpunit';\\
\$testToken = getenv('TEST_TOKEN');\\
if (\$testToken !== FALSE && \$testToken !== '' && is_numeric(\$testToken)) {\\
    \$tube = 'phpunit_' . \$testToken;\\
}\\
define('PHEANSTALK_TUBE', \$tube);" /etc/iznik.conf

    echo "  iznik.conf updated for per-worker Beanstalkd tube."
fi

# Sync schema from main database to worker databases
# This ensures worker databases have all tables including any new migrations
echo "Syncing schema from main database to worker databases..."
MAIN_DB="iznik_phpunit_test"
WORKER_DBS="iznik_1 iznik_2 iznik_3 iznik_4"
MYSQL_OPTS="-h percona -u root -piznik"

# Export schema (no data) from main database
echo "  Exporting schema from $MAIN_DB..."
mysqldump $MYSQL_OPTS --no-data --routines --triggers $MAIN_DB 2>/dev/null > /tmp/schema.sql

if [ -f /tmp/schema.sql ] && [ -s /tmp/schema.sql ]; then
    for db in $WORKER_DBS; do
        echo "  Syncing schema to $db..."
        # Create database if it doesn't exist
        mysql $MYSQL_OPTS -e "CREATE DATABASE IF NOT EXISTS $db;" 2>/dev/null
        # Apply schema (DROP TABLE IF EXISTS ensures clean sync)
        mysql $MYSQL_OPTS $db < /tmp/schema.sql 2>/dev/null
    done
    echo "  Schema sync complete."
    rm -f /tmp/schema.sql

    # Copy PAF reference data to worker databases (needed for address-related tests)
    echo "  Copying PAF reference data to worker databases..."
    PAF_TABLES="paf_addresses paf_buildingname paf_departmentname paf_dependentlocality paf_dependentthoroughfaredescriptor paf_doubledependentlocality paf_organisationname paf_pobox paf_posttown paf_subbuildingname paf_thoroughfaredescriptor locations_excluded postcodes"
    mysqldump $MYSQL_OPTS --no-create-info --skip-triggers $MAIN_DB $PAF_TABLES 2>/dev/null > /tmp/paf_data.sql
    if [ -f /tmp/paf_data.sql ] && [ -s /tmp/paf_data.sql ]; then
        for db in $WORKER_DBS; do
            mysql $MYSQL_OPTS $db < /tmp/paf_data.sql 2>/dev/null
        done
        echo "  PAF reference data copied."
        rm -f /tmp/paf_data.sql
    fi

    # Run testenv.php for each worker database to create fixture data (FreeglePlayground, etc.)
    echo "Setting up test environment in worker databases..."
    for i in 1 2 3 4; do
        echo "  Running testenv.php for iznik_$i..."
        (cd /var/www/iznik && TEST_TOKEN=$i php install/testenv.php 2>&1 | head -3)
    done
    echo "  Test environment setup complete."
else
    echo "  WARNING: Failed to export schema from $MAIN_DB - worker databases may be out of sync"
fi

# Set up PostgreSQL databases for each worker (to avoid cross-worker location contamination)
echo "Setting up PostgreSQL worker databases..."
PGPASSWORD=iznik
export PGPASSWORD

# Get schema from main PostgreSQL database
echo "  Getting PostgreSQL schema..."
pg_dump -h postgres -U root -s iznik > /tmp/pgsql_schema.sql 2>/dev/null

if [ -f /tmp/pgsql_schema.sql ] && [ -s /tmp/pgsql_schema.sql ]; then
    for i in 1 2 3 4; do
        PGDB="iznik_$i"
        echo "  Setting up PostgreSQL database $PGDB..."
        # Drop and recreate database to ensure clean state
        psql -h postgres -U root -d postgres -c "DROP DATABASE IF EXISTS $PGDB;" 2>/dev/null
        psql -h postgres -U root -d postgres -c "CREATE DATABASE $PGDB;" 2>/dev/null
        psql -h postgres -U root -d postgres -c "GRANT ALL PRIVILEGES ON DATABASE $PGDB TO root;" 2>/dev/null
        # Apply schema
        psql -h postgres -U root -d $PGDB < /tmp/pgsql_schema.sql 2>/dev/null
    done
    rm -f /tmp/pgsql_schema.sql
    echo "  PostgreSQL worker databases ready."
else
    echo "  WARNING: Failed to get PostgreSQL schema - location tests may fail"
fi

# Kill any existing background workers and clear stop files
echo "Stopping any existing background workers..."
rm -f /tmp/stop_background_workers
rm -f /tmp/iznik.mail.abort
pkill -f "background.php" 2>/dev/null
pkill -f "exports.php" 2>/dev/null
sleep 1

# Start background workers for each worker (these process jobs from the queue)
# NOTE: Use -n option to give each worker a unique instance name for separate lock files
# Run in a wrapper loop that restarts if they exit (unless test signals stop via iznik.mail.abort)
echo "Starting background workers for each worker database..."
for i in 1 2 3 4; do
    echo "  Starting background.php for TEST_TOKEN=$i (database: iznik_$i, tube: phpunit_$i)"
    (
        while [ ! -f /tmp/stop_background_workers ]; do
            # Don't restart if tests are signaling background scripts to stop
            if [ -f /tmp/iznik.mail.abort ]; then
                sleep 1
                continue
            fi
            cd /var/www/iznik/scripts/cron && TEST_TOKEN=$i php ./background.php -n $i >> /tmp/iznik.background.$i.out 2>&1
            sleep 1
        done
    ) &
done

# Start exports.php for each worker database (iznik_1, iznik_2, iznik_3, iznik_4)
echo "Starting exports.php for each worker database..."
for i in 1 2 3 4; do
    echo "  Starting exports.php for TEST_TOKEN=$i (database: iznik_$i)"
    (cd /var/www/iznik/scripts/cron && TEST_TOKEN=$i STANDALONE=1 php ./exports.php >> /tmp/iznik.exports.$i.out 2>&1) &
done

# Give workers a moment to start
sleep 2

echo "Running PHPUnit tests with paratest (4 parallel workers)..."

TEST_PATH="$@"
if [[ "$TEST_PATH" == /var/www/iznik/* ]]; then
    TEST_PATH="${TEST_PATH#/var/www/iznik/}"
    echo "Adjusted test path to: $TEST_PATH"
fi

cd /var/www/iznik
export XDEBUG_MODE=coverage

echo "Debug: Running paratest with 4 workers"
echo "Debug: Test path: $TEST_PATH"

# Run paratest with 4 workers
php -d memory_limit=-1 -d max_execution_time=0 \
    /var/www/iznik/composer/vendor/bin/paratest \
    -p 4 \
    --configuration /var/www/iznik/test/ut/php/phpunit.xml \
    --coverage-clover /tmp/phpunit-coverage.xml \
    $TEST_PATH 2>&1

TEST_EXIT_CODE=$?

echo "Debug: Test execution completed with exit code: $TEST_EXIT_CODE"

# Signal background workers to stop via stop file and cleanup
echo "Stopping background workers..."
touch /tmp/stop_background_workers
sleep 2
pkill -f "background.php" 2>/dev/null
pkill -f "exports.php" 2>/dev/null
rm -f /tmp/stop_background_workers

# Check coverage file
if [ ! -f /tmp/phpunit-coverage.xml ]; then
    echo "Warning: Coverage file was not generated"
fi

echo "Cleaning up..."
rm -f /tmp/iznik.mail.abort

echo "Tests completed with exit code: $TEST_EXIT_CODE"
exit $TEST_EXIT_CODE
